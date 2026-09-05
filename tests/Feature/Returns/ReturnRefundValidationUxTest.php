<?php

namespace Tests\Feature\Returns;

use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturnRequest;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReturnRefundValidationUxTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_NOTE = 'SENTINEL-UX-PRIVATE-NOTE';

    public function test_rejected_return_submissions_redirect_back_and_show_the_operator_why(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        [, $otherSale, $otherItem] = $this->fixture('100000.00', $actor);
        $this->actingAs($actor)->startSession();
        $cookie = config('session.cookie');

        // Business rejection raised inside the action: over-return.
        [$token, $session] = $this->issuedReturnToken($sale);
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.returns.store', $sale), $this->returnPayload($token, $item, '99.000'))
            ->assertOk()
            ->assertSee('Returned quantity exceeds the remaining returnable quantity.');

        // Form validation rejection: non-positive quantity.
        [$token, $session] = $this->issuedReturnToken($sale);
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.returns.store', $sale), $this->returnPayload($token, $item, '0'))
            ->assertOk()
            ->assertSee('The selected items.0.quantity is invalid.');

        // Form validation rejection: malformed quantity.
        [$token, $session] = $this->issuedReturnToken($sale);
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.returns.store', $sale), $this->returnPayload($token, $item, '0.0001'))
            ->assertOk()
            ->assertSee('The items.0.quantity field format is invalid.');

        // SaleItem belonging to a different Sale.
        [$token, $session] = $this->issuedReturnToken($sale);
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.returns.store', $sale), $this->returnPayload($token, $otherItem, '0.100'))
            ->assertOk()
            ->assertSee('A selected item does not belong to this Sale.');

        // Duplicate Sale item lines.
        [$token, $session] = $this->issuedReturnToken($sale);
        $duplicate = ['request_token' => $token, 'reason' => 'Duplicate lines', 'items' => [
            ['sale_item_id' => $item->id, 'quantity' => '0.100', 'disposition' => 'restock'],
            ['sale_item_id' => $item->id, 'quantity' => '0.100', 'disposition' => 'restock'],
        ]];
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.returns.store', $sale), $duplicate)
            ->assertOk()
            ->assertSee('The items.0.sale_item_id field has a duplicate value.');

        // Expired confirmation token.
        [$token, $session] = $this->issuedReturnToken($sale);
        SaleReturnRequest::where('token_hash', hash('sha256', $token))->update(['expires_at' => now()->subSecond()]);
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.returns.store', $sale), $this->returnPayload($token, $item, '0.100'))
            ->assertOk()
            ->assertSee('This return confirmation has expired. Refresh and try again.');

        // Replay of a consumed token with different details.
        [$token, $session] = $this->issuedReturnToken($sale);
        $payload = $this->returnPayload($token, $item, '0.100');
        $this->withCookie($cookie, $session)->post(route('sales.returns.store', $sale), $payload)->assertSessionHasNoErrors();
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.returns.store', $sale), array_merge($payload, ['reason' => 'Changed reason']))
            ->assertOk()
            ->assertSee('This return confirmation was already used with different details.');

        // Cross-session use of a valid token.
        [$token, $session] = $this->issuedReturnToken($sale);
        $this->withCookie($cookie, 'a-different-session')
            ->followingRedirects()
            ->post(route('sales.returns.store', $sale), $this->returnPayload($token, $item, '0.100'))
            ->assertOk()
            ->assertSee('This return confirmation has expired. Refresh and try again.');

        $this->assertDatabaseCount('sale_returns', 1);
    }

    public function test_rejected_refund_submissions_redirect_back_and_show_the_operator_why(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $this->actingAs($actor)->startSession();
        $cookie = config('session.cookie');
        [$token, $session] = $this->issuedReturnToken($sale);
        $this->withCookie($cookie, $session)->post(route('sales.returns.store', $sale), $this->returnPayload($token, $item, '1.000'))->assertSessionHasNoErrors();
        $this->assertSame('50000.00', $sale->fresh()->refundable_credit);

        // Business rejection raised inside the action: over-refund.
        [$token, $session] = $this->issuedRefundToken($sale);
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.refunds.store', $sale), $this->refundPayload($token, '60000.00'))
            ->assertOk()
            ->assertSee('Refund cannot exceed the currently refundable customer credit.');

        // Form validation rejection: unsupported method.
        [$token, $session] = $this->issuedRefundToken($sale);
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.refunds.store', $sale), $this->refundPayload($token, '10.00', 'pos'))
            ->assertOk()
            ->assertSee('The selected payment method is invalid.');

        // Form validation rejection: malformed amount.
        [$token, $session] = $this->issuedRefundToken($sale);
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.refunds.store', $sale), $this->refundPayload($token, '10.999'))
            ->assertOk()
            ->assertSee('The amount field format is invalid.');

        // Expired confirmation token.
        [$token, $session] = $this->issuedRefundToken($sale);
        SaleRefundRequest::where('token_hash', hash('sha256', $token))->update(['expires_at' => now()->subSecond()]);
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.refunds.store', $sale), $this->refundPayload($token, '10.00'))
            ->assertOk()
            ->assertSee('This refund confirmation has expired. Refresh and try again.');

        // Replay of a consumed token with different details.
        [$token, $session] = $this->issuedRefundToken($sale);
        $payload = $this->refundPayload($token, '10.00');
        $this->withCookie($cookie, $session)->post(route('sales.refunds.store', $sale), $payload)->assertSessionHasNoErrors();
        $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.refunds.store', $sale), array_merge($payload, ['amount' => '11.00']))
            ->assertOk()
            ->assertSee('This refund confirmation was already used with different details.');

        // Cross-session use of a valid token.
        [$token, $session] = $this->issuedRefundToken($sale);
        $this->withCookie($cookie, 'a-different-session')
            ->followingRedirects()
            ->post(route('sales.refunds.store', $sale), $this->refundPayload($token, '10.00'))
            ->assertOk()
            ->assertSee('This refund confirmation has expired. Refresh and try again.');

        $this->assertDatabaseCount('sale_refunds', 1);
    }

    public function test_rejected_submissions_never_render_tokens_sessions_notes_or_stack_traces(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $this->actingAs($actor)->startSession();
        $cookie = config('session.cookie');

        [$token, $session] = $this->issuedReturnToken($sale);
        $returnPage = $this->withCookie($cookie, $session)
            ->followingRedirects()
            ->post(route('sales.returns.store', $sale), array_merge(
                $this->returnPayload($token, $item, '99.000'),
                ['note' => self::PRIVATE_NOTE],
            ))->assertOk();
        $this->assertRejectionIsSafe($returnPage->getContent(), $token, $session, 'Returned quantity exceeds the remaining returnable quantity.');

        [$refundToken, $refundSession] = $this->issuedRefundToken($sale);
        $refundPage = $this->withCookie($cookie, $refundSession)
            ->followingRedirects()
            ->post(route('sales.refunds.store', $sale), array_merge(
                $this->refundPayload($refundToken, '999999.00'),
                ['note' => self::PRIVATE_NOTE],
            ))->assertOk();
        $this->assertRejectionIsSafe($refundPage->getContent(), $refundToken, $refundSession, 'Refund cannot exceed the currently refundable customer credit.');
    }

    public function test_refund_action_rejects_non_positive_amounts_before_reaching_the_database(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $this->actingAs($actor);
        app(RecordSaleReturn::class)->execute($actor, $sale->fresh(), [
            'request_token' => $this->mintReturnToken($actor, $sale, 'guard-session'),
            'reason' => 'Credit source',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '1.000', 'disposition' => 'non_restock']],
        ], 'guard-session');

        foreach (['0.00', '0', '-0.01', '-100.00'] as $amount) {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            try {
                app(RecordSaleRefund::class)->execute($actor, $sale->fresh(), [
                    'request_token' => $this->mintRefundToken($actor, $sale, 'guard-session'),
                    'amount' => $amount,
                    'payment_method' => 'cash',
                    'reason' => 'Non-positive probe',
                ], 'guard-session');
                $this->fail("Refund of {$amount} was accepted.");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    'Refund amount must be greater than zero.',
                    $exception->validator->errors()->first('amount'),
                );
            } finally {
                DB::getEventDispatcher()->forget(QueryExecuted::class);
            }
        }

        $this->assertDatabaseCount('sale_refunds', 0);
        $this->assertSame('50000.00', $sale->fresh()->refundable_credit);
    }

    public function test_reporting_detects_return_and_refund_ledger_drift_without_repairing_it(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $this->actingAs($actor);
        app(RecordSaleReturn::class)->execute($actor, $sale->fresh(), [
            'request_token' => $this->mintReturnToken($actor, $sale, 'drift-session'),
            'reason' => 'Drift source',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '1.000', 'disposition' => 'non_restock']],
        ], 'drift-session');
        app(RecordSaleRefund::class)->execute($actor, $sale->fresh(), [
            'request_token' => $this->mintRefundToken($actor, $sale, 'drift-session'),
            'amount' => '10000.00',
            'payment_method' => 'cash',
            'reason' => 'Drift source',
        ], 'drift-session');

        // An unrelated outstanding Sale keeps the receivables report non-empty.
        $this->fixture('0.00', $actor);
        $truth = ['returned_amount' => '50000.00', 'refunded_amount' => '10000.00', 'balance_due' => '0.00', 'refundable_credit' => '40000.00'];
        $this->assertSame($truth['returned_amount'], $sale->fresh()->returned_amount);
        $this->assertSame($truth['refunded_amount'], $sale->fresh()->refunded_amount);
        $this->get(route('reports.receivables'))->assertOk()->assertDontSee('inconsistency detected');
        $this->get(route('reports.summary'))->assertOk()->assertDontSee('inconsistency detected');

        // Each drifted state still satisfies sales_return_financials_reconcile, so only a
        // ledger comparison can catch it. total - returned + credit = paid - refunded + balance.
        $driftStates = [
            'returned_amount diverges' => ['returned_amount' => '40000.00', 'refunded_amount' => '10000.00', 'balance_due' => '0.00', 'refundable_credit' => '30000.00'],
            'refunded_amount diverges' => ['returned_amount' => '50000.00', 'refunded_amount' => '9000.00', 'balance_due' => '0.00', 'refundable_credit' => '41000.00'],
        ];
        foreach ($driftStates as $label => $drifted) {
            $snapshot = $this->ledgerSnapshot($sale->id);
            DB::table('sales')->where('id', $sale->id)->update($drifted);
            $this->get(route('reports.receivables'))->assertOk()
                ->assertSee('Sale aggregate and ledger inconsistency detected. No records were changed.');
            $this->get(route('reports.summary'))->assertOk()
                ->assertSee('Sale aggregate and ledger inconsistency detected. No records were changed.');
            $this->assertSame($snapshot, $this->ledgerSnapshot($sale->id), "Reporting mutated ledger evidence: {$label}");
            $this->assertSame($drifted['returned_amount'], $sale->fresh()->returned_amount, "Reporting repaired the aggregate: {$label}");
            $this->assertSame($drifted['refunded_amount'], $sale->fresh()->refunded_amount, "Reporting repaired the aggregate: {$label}");
            DB::table('sales')->where('id', $sale->id)->update($truth);
        }

        $this->get(route('reports.receivables'))->assertOk()->assertDontSee('inconsistency detected');
    }

    private function assertRejectionIsSafe(string $html, string $token, string $session, string $expectedMessage): void
    {
        $this->assertStringContainsString($expectedMessage, $html);
        $this->assertStringNotContainsString($token, $html);
        $this->assertStringNotContainsString(hash('sha256', $token), $html);
        $this->assertStringNotContainsString($session, $html);
        $this->assertStringNotContainsString(self::PRIVATE_NOTE, $html);
        foreach (['SQLSTATE', 'vendor/laravel', 'Stack trace', 'Illuminate\\Database'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    private function ledgerSnapshot(int $saleId): string
    {
        return json_encode([
            DB::table('sale_returns')->where('sale_id', $saleId)->orderBy('id')->get()->toArray(),
            DB::table('sale_return_items')->orderBy('id')->get()->toArray(),
            DB::table('sale_refunds')->where('sale_id', $saleId)->orderBy('id')->get()->toArray(),
        ], JSON_THROW_ON_ERROR);
    }

    private function returnPayload(string $token, SaleItem $item, string $quantity): array
    {
        return ['request_token' => $token, 'reason' => 'Operator reason', 'items' => [
            ['sale_item_id' => $item->id, 'quantity' => $quantity, 'disposition' => 'restock'],
        ]];
    }

    private function refundPayload(string $token, string $amount, string $method = 'cash'): array
    {
        return ['request_token' => $token, 'amount' => $amount, 'payment_method' => $method, 'reason' => 'Operator reason'];
    }

    /** @return array{0: string, 1: string} */
    private function issuedReturnToken(Sale $sale): array
    {
        $page = $this->get(route('sales.returns.create', $sale))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $page->getContent(), $match);
        $issued = SaleReturnRequest::where('token_hash', hash('sha256', $match[1]))->firstOrFail();

        return [$match[1], $issued->session_id];
    }

    /** @return array{0: string, 1: string} */
    private function issuedRefundToken(Sale $sale): array
    {
        $page = $this->get(route('sales.refunds.create', $sale))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $page->getContent(), $match);
        $issued = SaleRefundRequest::where('token_hash', hash('sha256', $match[1]))->firstOrFail();

        return [$match[1], $issued->session_id];
    }

    private function mintReturnToken(User $actor, Sale $sale, string $session): string
    {
        return $this->mintToken(SaleReturnRequest::class, $actor, $sale, $session);
    }

    private function mintRefundToken(User $actor, Sale $sale, string $session): string
    {
        return $this->mintToken(SaleRefundRequest::class, $actor, $sale, $session);
    }

    private function mintToken(string $model, User $actor, Sale $sale, string $session): string
    {
        $token = Str::random(64);
        $request = new $model;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $actor->id,
            'session_id' => $session, 'expires_at' => now()->addMinutes(30)] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }

    /** @return array{0: User, 1: Sale, 2: SaleItem} */
    private function fixture(string $paid, ?User $actor = null): array
    {
        $actor ??= User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['current_stock' => '10.000']);
        $sale = Sale::factory()->create([
            'subtotal' => '100000.00', 'total_amount' => '100000.00', 'amount_paid' => $paid,
            'balance_due' => bcsub('100000.00', $paid, 2),
            'payment_status' => bccomp($paid, '100000.00', 2) === 0 ? PaymentStatus::Paid : ($paid === '0.00' ? PaymentStatus::Unpaid : PaymentStatus::Partial),
        ]);
        $item = new SaleItem;
        foreach (['sale_id' => $sale->id, 'product_id' => $product->id, 'product_sku_snapshot' => $product->sku,
            'product_name_snapshot' => $product->name, 'unit_snapshot' => $product->unit->value, 'quantity' => '2.000',
            'unit_price' => '50000.00', 'line_total' => '100000.00', 'created_at' => now()] as $key => $value) {
            $item->$key = $value;
        }
        $item->save();
        if (bccomp($paid, '0.00', 2) > 0) {
            $payment = new SalePayment;
            foreach (['payment_number' => 'PMT-'.Str::upper(Str::random(10)), 'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id, 'amount' => $paid, 'payment_method' => PaymentMethod::Cash,
                'payment_type' => SalePaymentType::Initial, 'recorded_by' => $actor->id,
                'recorded_by_name_snapshot' => $actor->name, 'paid_at' => now(), 'cumulative_paid_after' => $paid,
                'balance_after' => bcsub('100000.00', $paid, 2), 'payment_status_after' => $sale->payment_status,
                'initial_sale_guard' => $sale->id] as $key => $value) {
                $payment->$key = $value;
            }
            $payment->save();
        }

        return [$actor, $sale->fresh(), $item->fresh()];
    }
}
