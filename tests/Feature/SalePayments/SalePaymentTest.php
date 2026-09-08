<?php

namespace Tests\Feature\SalePayments;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\IssueSalePaymentRequest;
use App\Actions\Sale\RecordSalePayment;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SalePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_creation_creates_initial_ledger_only_for_positive_payment(): void
    {
        [$actor, $customer] = $this->principals();
        $unpaid = $this->sale($actor, $customer, '0');
        $partial = $this->sale($actor, $customer, '40', 'transfer');
        $paid = $this->sale($actor, $customer, '100', 'pos');

        $this->assertCount(0, $unpaid->payments);
        $this->assertCount(1, $partial->payments);
        $this->assertCount(1, $paid->payments);
        $payment = $partial->payments->sole();
        $this->assertSame('40.00', $payment->amount);
        $this->assertSame('transfer', $payment->payment_method->value);
        $this->assertSame('initial', $payment->payment_type->value);
        $this->assertSame($actor->name, $payment->recorded_by_name_snapshot);
        $this->assertSame('40.00', $payment->cumulative_paid_after);
        $this->assertSame('60.00', $payment->balance_after);
        $this->assertMatchesRegularExpression('/^PAY-\d{6,}$/', $payment->payment_number);
    }

    public function test_settlements_derive_partial_and_paid_aggregates_and_snapshots(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        $first = $this->record($actor, $sale, '40', 'cash');
        $second = $this->record($actor, $sale->fresh(), '60', 'pos');

        $this->assertSame(['40.00', '40.00', '60.00', 'partial'], [
            $first->amount, $first->cumulative_paid_after, $first->balance_after, $first->payment_status_after->value,
        ]);
        $this->assertSame(['100.00', '0.00', 'paid'], [
            $second->cumulative_paid_after, $second->balance_after, $second->payment_status_after->value,
        ]);
        $sale->refresh();
        $this->assertSame('100.00', $sale->amount_paid);
        $this->assertSame('0.00', $sale->balance_due);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
    }

    public function test_partial_sale_can_be_settled_below_and_exactly_to_balance(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '20');
        $this->record($actor, $sale, '30');
        $this->assertSame(['50.00', '50.00', 'partial'], [
            $sale->fresh()->amount_paid, $sale->fresh()->balance_due, $sale->fresh()->payment_status->value,
        ]);
        $this->record($actor, $sale->fresh(), '50');
        $this->assertSame(['100.00', '0.00', 'paid'], [
            $sale->fresh()->amount_paid, $sale->fresh()->balance_due, $sale->fresh()->payment_status->value,
        ]);
    }

    public function test_overpayment_and_ineligible_sale_states_fail_without_ledger_change(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        $this->expectValidation(fn () => $this->record($actor, $sale, '100.01'), 'amount');
        $this->record($actor, $sale, '100');
        $this->expectValidation(fn () => $this->record($actor, $sale->fresh(), '1'), 'sale');
        $this->assertDatabaseCount('sale_payments', 1);
    }

    public function test_voided_sale_rejects_payment(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        DB::table('sales')->where('id', $sale->id)->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => 'test']);
        $this->expectValidation(fn () => $this->record($actor, $sale->fresh(), '10'), 'sale');
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_server_token_replay_records_one_payment_and_one_audit_event(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        [$token, $sessionId] = $this->token($actor, $sale);
        $data = ['amount' => '25', 'payment_method' => 'cash', 'note' => null, 'request_token' => $token];
        $first = app(RecordSalePayment::class)->execute($actor, $sale, $data, $sessionId);
        $second = app(RecordSalePayment::class)->execute($actor, $sale, $data, $sessionId);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'sale_payment_recorded')->count());
        $this->assertSame('25.00', $sale->fresh()->amount_paid);
    }

    public function test_token_is_bound_to_sale_actor_session_and_expiry(): void
    {
        [$actor, $customer] = $this->principals();
        $other = User::factory()->create(['role' => UserRole::Manager]);
        $saleA = $this->sale($actor, $customer, '0');
        $saleB = $this->sale($actor, $customer, '0');
        [$token, $sessionId] = $this->token($actor, $saleA);
        $payload = ['amount' => '10', 'payment_method' => 'cash', 'note' => null, 'request_token' => $token];

        $this->expectValidation(fn () => app(RecordSalePayment::class)->execute($actor, $saleB, $payload, $sessionId), 'request_token');
        $this->expectValidation(fn () => app(RecordSalePayment::class)->execute($other, $saleA, $payload, $sessionId), 'request_token');
        $this->expectValidation(fn () => app(RecordSalePayment::class)->execute($actor, $saleA, $payload, 'wrong-session'), 'request_token');
        DB::table('sale_payment_requests')->where('token_hash', hash('sha256', $token))->update(['expires_at' => now()->subMinute()]);
        $this->expectValidation(fn () => app(RecordSalePayment::class)->execute($actor, $saleA, $payload, $sessionId), 'request_token');
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_two_independent_exact_balance_requests_allow_only_one(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        [$tokenA, $session] = $this->token($actor, $sale);
        [$tokenB] = $this->token($actor, $sale);
        $action = app(RecordSalePayment::class);
        $payload = fn (string $token) => ['amount' => '100', 'payment_method' => 'cash', 'note' => null, 'request_token' => $token];
        $action->execute($actor, $sale, $payload($tokenA), $session);
        $this->expectValidation(fn () => $action->execute($actor, $sale, $payload($tokenB), $session), 'sale');

        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertSame('100.00', $sale->fresh()->amount_paid);
        $this->assertSame('0.00', $sale->fresh()->balance_due);
    }

    public function test_authorization_global_ledger_and_nested_idor(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $owner = User::factory()->create(['role' => UserRole::SalesRep]);
        $otherRep = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $sale = $this->sale($owner, $customer, '0');
        $payment = $this->record($owner, $sale, '10');
        $otherSale = $this->sale($otherRep, $customer, '0');

        foreach ([$admin, $manager] as $viewer) {
            $this->actingAs($viewer)->get(route('sale-payments.index'))->assertOk();
            $this->get(route('sales.payments.receipt', [$sale, $payment]))->assertOk();
        }
        $this->actingAs($owner)->get(route('sale-payments.index'))->assertForbidden();
        $this->get(route('sales.payments.show', [$sale, $payment]))->assertOk();
        $this->actingAs($otherRep)->get(route('sales.payments.show', [$sale, $payment]))->assertForbidden();
        $this->actingAs($admin)->get(route('sales.payments.show', [$otherSale, $payment]))->assertNotFound();
        $this->get('/sales/'.$sale->id.'/payments/999999')->assertNotFound();
        $this->get(route('sale-payments.index'))->assertOk();
        $this->post(route('logout'));
        $this->get(route('sale-payments.index'))->assertRedirect(route('login'));
    }

    public function test_payment_is_immutable_and_operator_snapshot_and_note_render_safely(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        $payment = $this->record($actor, $sale, '10', 'cash', '<script>alert(1)</script>');
        $viewer = User::factory()->create(['role' => UserRole::Admin]);
        $actor->forceFill(['name' => 'Renamed Operator'])->save();

        $this->actingAs($viewer)->get(route('sales.payments.show', [$sale, $payment]))
            ->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($viewer)->get(route('sales.payments.receipt', [$sale, $payment]))
            ->assertOk()->assertSee($payment->recorded_by_name_snapshot)->assertDontSee('Renamed Operator')
            ->assertDontSee('alert(1)', false)->assertSee('data-print-trigger', false)
            ->assertDontSee('cost_price');
        try {
            $payment->amount = '99';
            $payment->save();
            $this->fail('Payment mutation should fail.');
        } catch (LogicException) {
            $this->assertSame('10.00', $payment->fresh()->amount);
        }
        $this->expectException(LogicException::class);
        $payment->delete();
    }

    public function test_settlement_blocks_sale_void_without_stock_change(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '10', 'selling_price' => '100']);
        $sale = app(CreateSale::class)->execute($admin, $this->payload($customer, $product, '0', 'cash'));
        $this->record($admin, $sale, '10');

        $this->actingAs($admin)->post(route('sales.void', $sale), ['reason' => 'mistake'])->assertSessionHasErrors('sale');
        $this->assertSame('9.000', $product->fresh()->current_stock);
        $this->assertSame('completed', $sale->fresh()->status->value);
        $this->assertDatabaseMissing('inventory_movements', ['type' => 'sale_void', 'reference_id' => $sale->id]);
    }

    public function test_http_validation_rejects_invalid_amount_method_note_and_privileged_fields(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        $this->actingAs($actor)->startSession();
        foreach (['0', '-1', '1.001', ['10']] as $amount) {
            $this->post(route('sales.payments.store', $sale), ['amount' => $amount, 'payment_method' => 'cash', 'request_token' => str_repeat('x', 64)])->assertSessionHasErrors('amount');
        }
        $this->post(route('sales.payments.store', $sale), ['amount' => '1', 'payment_method' => 'crypto', 'note' => str_repeat('x', 501), 'request_token' => str_repeat('x', 64), 'customer_id' => 999])->assertSessionHasErrors(['payment_method', 'note', 'customer_id']);
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_database_constraints_reject_nonpositive_amount_and_duplicate_initial_guard(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '10');
        $initial = $sale->payments()->sole()->getAttributes();
        unset($initial['id']);
        $initial['payment_number'] = 'PAY-DUPLICATE';
        $initial['amount'] = '0.00';
        try {
            DB::table('sale_payments')->insert($initial);
            $this->fail('Nonpositive amount must fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('sale_payments', 1);
        }
        $initial['amount'] = '1.00';
        try {
            DB::table('sale_payments')->insert($initial);
            $this->fail('Duplicate initial payment must fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('sale_payments', 1);
        }
    }

    public function test_initial_payment_failure_rolls_back_sale_stock_and_entire_ledger(): void
    {
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        [$actor, $customer] = $this->principals();
        $product = Product::factory()->create(['current_stock' => '10', 'selling_price' => '100']);

        try {
            (new CreateSale($audit))->execute($actor, $this->payload($customer, $product, '50', 'cash'));
            $this->fail('The Sale transaction should roll back.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('sale_payments', 0);
            $this->assertSame('10.000', $product->fresh()->current_stock);
            $this->assertDatabaseMissing('inventory_movements', ['type' => 'sale']);
        }
    }

    public function test_customer_receivables_scope_sales_rep_to_own_sales(): void
    {
        $owner = User::factory()->create(['role' => UserRole::SalesRep]);
        $other = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $ownSale = $this->sale($owner, $customer, '20');
        $otherSale = $this->sale($other, $customer, '70');
        $ownPayment = $ownSale->payments()->sole();
        $otherPayment = $otherSale->payments()->sole();

        $this->actingAs($owner)->get(route('customers.show', $customer))->assertOk()
            ->assertSee($ownPayment->payment_number)->assertDontSee($otherPayment->payment_number)
            ->assertSee('20.00')->assertSee('80.00')->assertDontSee('170.00');
    }

    public function test_settlement_audit_failure_rolls_back_payment_aggregates_and_token_use(): void
    {
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        [$token, $sessionId] = $this->token($actor, $sale);
        $payload = ['amount' => '25', 'payment_method' => 'cash', 'note' => null, 'request_token' => $token];

        try {
            (new RecordSalePayment($audit))->execute($actor, $sale, $payload, $sessionId);
            $this->fail('The settlement should roll back.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('sale_payments', 0);
            $this->assertSame('0.00', $sale->fresh()->amount_paid);
            $this->assertSame('100.00', $sale->fresh()->balance_due);
            $this->assertDatabaseHas('sale_payment_requests', ['token_hash' => hash('sha256', $token), 'used_at' => null]);
        }
    }

    public function test_initial_guard_database_constraint_rejects_every_invalid_branch(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        $otherSale = $this->sale($actor, $customer, '0');

        $this->assertDirectPaymentRejected($this->rawPayment($sale, 'PAY-NULL', 'initial', null));
        $this->assertDirectPaymentRejected($this->rawPayment($sale, 'PAY-WRONG', 'initial', $otherSale->id));
        DB::table('sale_payments')->insert($this->rawPayment($sale, 'PAY-VALID', 'initial', $sale->id));
        DB::table('sale_payments')->insert($this->rawPayment($sale, 'PAY-SETTLEMENT', 'settlement', null));
        $this->assertDirectPaymentRejected($this->rawPayment($otherSale, 'PAY-SETTLEMENT-GUARD', 'settlement', $otherSale->id));
        $this->assertDirectPaymentRejected($this->rawPayment($sale, 'PAY-DUPLICATE-INITIAL', 'initial', $sale->id));

        $this->assertDatabaseCount('sale_payments', 2);
    }

    public function test_global_ledger_ignores_array_and_nested_filters_without_view_errors(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get(route('sale-payments.index', [
            'search' => ['x'], 'from' => ['x'], 'to' => ['x'], 'recorded_by' => ['1'], 'payment_method' => ['x'],
        ]))->assertOk()->assertSee('Sale payments');
    }

    public function test_http_sale_detail_token_records_once_replays_idempotently_and_rejects_another_session(): void
    {
        [$actor, $customer] = $this->principals();
        $sale = $this->sale($actor, $customer, '0');
        $this->actingAs($actor)->startSession();
        $response = $this->get(route('sales.show', $sale))->assertOk();
        $paymentForm = preg_quote(route('sales.payments.store', $sale), '/');
        preg_match('/action="'.$paymentForm.'".*?name="request_token" value="([A-Za-z0-9]{64})"/s', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $token = $matches[1];
        $issuedRequest = DB::table('sale_payment_requests')->where('token_hash', hash('sha256', $token))->first();
        $this->assertNotNull($issuedRequest);
        $this->assertSame($this->app['session']->driver()->getId(), $issuedRequest->session_id);
        $this->withCookie(config('session.cookie'), $issuedRequest->session_id);
        $payload = ['amount' => '25.00', 'payment_method' => 'transfer', 'note' => 'Deposit', 'request_token' => $token];

        $this->post(route('sales.payments.store', $sale), $payload)->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post(route('sales.payments.store', $sale), $payload)->assertRedirect();
        $payment = $sale->payments()->sole();
        $this->assertSame('25.00', $payment->amount);
        $this->assertSame('25.00', $sale->fresh()->amount_paid);
        $this->assertSame('75.00', $sale->fresh()->balance_due);
        $this->assertNotNull(DB::table('sale_payment_requests')->where('sale_payment_id', $payment->id)->value('used_at'));
        $this->assertSame(1, AuditLog::query()->where('action', 'sale_payment_recorded')->count());

        $otherSale = $this->sale($actor, $customer, '0');
        $otherResponse = $this->get(route('sales.show', $otherSale))->assertOk();
        $otherPaymentForm = preg_quote(route('sales.payments.store', $otherSale), '/');
        preg_match('/action="'.$otherPaymentForm.'".*?name="request_token" value="([A-Za-z0-9]{64})"/s', $otherResponse->getContent(), $otherMatches);
        $otherToken = $otherMatches[1];
        $this->app['session']->driver()->invalidate();
        $this->app['session']->driver()->start();
        $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());
        $this->post(route('sales.payments.store', $otherSale), array_merge($payload, ['request_token' => $otherToken]))
            ->assertSessionHasErrors('request_token');
        $this->assertDatabaseMissing('sale_payments', ['sale_id' => $otherSale->id]);
    }

    private function principals(): array
    {
        return [User::factory()->create(['role' => UserRole::Manager]), Customer::factory()->create()];
    }

    private function sale(User $actor, Customer $customer, string $paid, string $method = 'cash'): Sale
    {
        $product = Product::factory()->create(['current_stock' => '10', 'selling_price' => '100']);

        return app(CreateSale::class)->execute($actor, $this->payload($customer, $product, $paid, $method))->load('payments');
    }

    private function payload(Customer $customer, Product $product, string $paid, string $method): array
    {
        return ['customer_id' => $customer->id, 'products' => [['product_id' => $product->id, 'quantity' => '1']], 'payment_method' => $method, 'amount_paid' => $paid, 'notes' => null];
    }

    private function token(User $actor, Sale $sale): array
    {
        $this->actingAs($actor)->startSession();
        $session = app('session')->driver();

        return [app(IssueSalePaymentRequest::class)->execute($sale, $actor, $session), $session->getId()];
    }

    private function record(User $actor, Sale $sale, string $amount, string $method = 'cash', ?string $note = null): SalePayment
    {
        [$token, $sessionId] = $this->token($actor, $sale);

        return app(RecordSalePayment::class)->execute($actor, $sale, ['amount' => $amount, 'payment_method' => $method, 'note' => $note, 'request_token' => $token], $sessionId);
    }

    private function expectValidation(callable $operation, string $key): void
    {
        try {
            $operation();
            $this->fail('Expected a controlled validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function rawPayment(Sale $sale, string $number, string $type, ?int $guard): array
    {
        return [
            'payment_number' => $number, 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id,
            'amount' => '1.00', 'payment_method' => 'cash', 'payment_type' => $type,
            'recorded_by' => $sale->sold_by, 'recorded_by_name_snapshot' => $sale->sold_by_name_snapshot,
            'paid_at' => now(), 'note' => null, 'cumulative_paid_after' => '1.00', 'balance_after' => '99.00',
            'payment_status_after' => 'partial', 'initial_sale_guard' => $guard, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function assertDirectPaymentRejected(array $attributes): void
    {
        try {
            DB::table('sale_payments')->insert($attributes);
            $this->fail('Invalid payment guard state must be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
