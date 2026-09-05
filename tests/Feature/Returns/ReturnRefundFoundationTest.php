<?php

namespace Tests\Feature\Returns;

use App\Actions\Sale\RecordSalePayment;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\VoidSale;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SalePaymentRequest;
use App\Models\SaleRefund;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SaleReturnRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReturnRefundFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_reduces_receivable_and_creates_credit_without_rewriting_sale_evidence(): void
    {
        [$actor, $sale, $item] = $this->fixture('70000.00');
        $return = $this->recordReturn($actor, $sale, $item, '1.000', 'restock');

        $this->assertSame('50000.00', $return->merchandise_value);
        $this->assertSame('30000.00', $return->receivable_reduction);
        $this->assertSame('20000.00', $return->refundable_credit_created);
        $this->assertSame('100000.00', $sale->fresh()->total_amount);
        $this->assertSame('70000.00', $sale->fresh()->amount_paid);
        $this->assertSame('0.00', $sale->fresh()->balance_due);
        $this->assertSame(PaymentStatus::Paid, $sale->fresh()->payment_status);
        $this->assertSame('11.000', $item->product->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'sale_return', 'reference_id' => $return->id, 'quantity_change' => '1.000']);
    }

    public function test_non_restock_multiple_returns_enforce_cumulative_quantity(): void
    {
        [$actor, $sale, $item] = $this->fixture('20000.00');
        $this->recordReturn($actor, $sale, $item, '0.500', 'non_restock');
        $this->recordReturn($actor, $sale, $item, '1.000', 'non_restock');
        $this->assertSame('10.000', $item->product->fresh()->current_stock);

        $this->expectException(ValidationException::class);
        $this->recordReturn($actor, $sale, $item, '0.501', 'restock');
    }

    public function test_refunds_are_separate_immutable_cash_out_and_cannot_exceed_credit(): void
    {
        [$actor, $sale, $item] = $this->fixture('70000.00');
        $this->recordReturn($actor, $sale, $item, '1.000', 'non_restock');
        $refund = $this->recordRefund($actor, $sale, '15000.00');
        $this->assertSame('15000.00', $refund->amount);
        $this->assertSame('70000.00', $sale->fresh()->amount_paid);
        $this->assertSame('0.00', $sale->fresh()->balance_due);

        $this->expectException(ValidationException::class);
        $this->recordRefund($actor, $sale, '6000.00');
    }

    public function test_unpaid_and_fully_paid_return_fixtures_reconcile(): void
    {
        [$actor, $partial, $partialItem] = $this->fixture('20000.00');
        $return = $this->recordReturn($actor, $partial, $partialItem, '0.600', 'non_restock');
        $this->assertSame('30000.00', $return->merchandise_value);
        $this->assertSame('50000.00', $partial->fresh()->balance_due);
        $this->assertSame('0.00', $return->refundable_credit_created);

        [, $paid, $paidItem] = $this->fixture('100000.00', $actor);
        $paidReturn = $this->recordReturn($actor, $paid, $paidItem, '0.600', 'non_restock');
        $this->assertSame('0.00', $paid->fresh()->balance_due);
        $this->assertSame('30000.00', $paidReturn->refundable_credit_created);
    }

    public function test_sales_rep_is_denied_and_array_payloads_are_controlled(): void
    {
        [$actor, $sale] = $this->fixture('0.00');
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->actingAs($rep)->get(route('sales.returns.create', $sale))->assertForbidden();
        $this->actingAs($actor)->post(route('sales.returns.store', $sale), ['request_token' => [], 'items' => 'bad', 'reason' => []])->assertSessionHasErrors();
    }

    public function test_real_http_return_and_refund_replays_are_single_use_and_receipts_are_authorized(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $this->actingAs($actor)->startSession();
        $create = $this->get(route('sales.returns.create', $sale))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $create->getContent(), $match);
        $issued = SaleReturnRequest::where('token_hash', hash('sha256', $match[1]))->firstOrFail();
        $this->assertSame($sale->id, $issued->sale_id);
        $this->assertSame($actor->id, $issued->actor_id);
        $this->assertSame(session()->getId(), $issued->session_id);
        $this->assertFalse($issued->expires_at->isPast());
        $payload = ['request_token' => $match[1], 'reason' => 'Sealed return', 'items' => [['sale_item_id' => $item->id, 'quantity' => '0.600', 'disposition' => 'non_restock']]];
        $cookie = config('session.cookie');
        $first = $this->withCookie($cookie, $issued->session_id)->post(route('sales.returns.store', $sale), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->withCookie($cookie, $issued->session_id)->post(route('sales.returns.store', $sale), $payload)->assertRedirect($first->headers->get('Location'));
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('sale_return_items', 1);
        $this->assertSame(1, AuditLog::where('action', 'sale_return_recorded')->count());
        $this->withCookie($cookie, $issued->session_id)->post(route('sales.returns.store', $sale), array_merge($payload, ['reason' => 'Changed']))->assertSessionHasErrors('request_token');
        $this->withCookie($cookie, 'second-session')->post(route('sales.returns.store', $sale), $payload)->assertSessionHasErrors('request_token');
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($manager)->withCookie($cookie, $issued->session_id)->post(route('sales.returns.store', $sale), $payload)->assertSessionHasErrors('request_token');
        $this->actingAs($actor);
        $expiredReturn = $this->returnToken($actor, $sale, $issued->session_id);
        SaleReturnRequest::where('token_hash', hash('sha256', $expiredReturn))->update(['expires_at' => now()->subSecond()]);
        $this->withCookie($cookie, $issued->session_id)->post(route('sales.returns.store', $sale), array_merge($payload, ['request_token' => $expiredReturn]))->assertSessionHasErrors('request_token');
        $this->withCookie($cookie, $issued->session_id)->post(route('sales.returns.store', $sale), array_merge($payload, ['request_token' => 'malformed']))->assertSessionHasErrors('request_token');
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('sale_return_items', 1);
        $this->assertSame(1, AuditLog::where('action', 'sale_return_recorded')->count());

        $refundPage = $this->get(route('sales.refunds.create', $sale))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $refundPage->getContent(), $refundMatch);
        $refundIssued = SaleRefundRequest::where('token_hash', hash('sha256', $refundMatch[1]))->firstOrFail();
        $refundPayload = ['request_token' => $refundMatch[1], 'amount' => '10.00', 'payment_method' => 'transfer', 'reason' => 'Approved'];
        $refundFirst = $this->withCookie($cookie, $refundIssued->session_id)->post(route('sales.refunds.store', $sale), $refundPayload)->assertRedirect();
        $this->withCookie($cookie, $refundIssued->session_id)->post(route('sales.refunds.store', $sale), $refundPayload)->assertRedirect($refundFirst->headers->get('Location'));
        $this->withCookie($cookie, $refundIssued->session_id)->post(route('sales.refunds.store', $sale), array_merge($refundPayload, ['amount' => '9.00']))->assertSessionHasErrors('request_token');
        $this->withCookie($cookie, 'second-refund-session')->post(route('sales.refunds.store', $sale), $refundPayload)->assertSessionHasErrors('request_token');
        $this->actingAs($manager)->withCookie($cookie, $refundIssued->session_id)->post(route('sales.refunds.store', $sale), $refundPayload)->assertSessionHasErrors('request_token');
        $this->actingAs($actor);
        $expiredRefund = $this->refundToken($actor, $sale, $refundIssued->session_id);
        SaleRefundRequest::where('token_hash', hash('sha256', $expiredRefund))->update(['expires_at' => now()->subSecond()]);
        $this->withCookie($cookie, $refundIssued->session_id)->post(route('sales.refunds.store', $sale), array_merge($refundPayload, ['request_token' => $expiredRefund]))->assertSessionHasErrors('request_token');
        $this->withCookie($cookie, $refundIssued->session_id)->post(route('sales.refunds.store', $sale), array_merge($refundPayload, ['request_token' => 'malformed']))->assertSessionHasErrors('request_token');
        $this->assertDatabaseCount('sale_refunds', 1);
        $this->assertSame(1, AuditLog::where('action', 'sale_refund_recorded')->count());
        $refund = SaleRefund::firstOrFail();
        $this->get(route('refunds.receipt', $refund))->assertOk()->assertSee('Refund Receipt')->assertDontSee('Internal note');
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->actingAs($rep)->get(route('refunds.receipt', $refund))->assertForbidden();
        $this->get(route('returns.index', ['search' => [], 'from' => [], 'page' => []]))->assertForbidden();
    }

    public function test_wrong_sale_duplicate_forged_values_and_void_interactions_fail_safely(): void
    {
        [$actor, $sale, $item] = $this->fixture('20000.00');
        [, $otherSale, $otherItem] = $this->fixture('0.00', $actor);
        $token = $this->returnToken($actor, $sale);
        $this->actingAs($actor)->post(route('sales.returns.store', $sale), ['request_token' => $token, 'reason' => 'Wrong', 'product_id' => $item->product_id, 'unit_price' => '0.01', 'line_total' => '0.01', 'items' => [['sale_item_id' => $otherItem->id, 'quantity' => '1', 'disposition' => 'restock']]])->assertSessionHasErrors();
        $this->assertDatabaseCount('sale_returns', 0);
        $this->recordReturn($actor, $sale, $item, '0.125', 'restock');
        $before = $item->product->fresh()->current_stock;
        try {
            app(VoidSale::class)->execute($actor, $sale, 'Invalid after Return');
            $this->fail('Void should fail.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $item->product->fresh()->current_stock);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_voided', 'auditable_id' => $sale->id]);

        $duplicateToken = $this->returnToken($actor, $otherSale);
        $duplicate = ['request_token' => $duplicateToken, 'reason' => 'Duplicate', 'items' => [['sale_item_id' => $otherItem->id, 'quantity' => '0.1', 'disposition' => 'restock'], ['sale_item_id' => $otherItem->id, 'quantity' => '0.1', 'disposition' => 'restock']]];
        $this->post(route('sales.returns.store', $otherSale), $duplicate)->assertSessionHasErrors();
    }

    public function test_immutable_models_database_checks_and_reciprocal_deletion_barriers_hold(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $return = $this->recordReturn($actor, $sale, $item, '0.600', 'non_restock');
        $refund = $this->recordRefund($actor, $sale, '10000.00');
        foreach ([$return, $return->items()->firstOrFail(), $refund] as $model) {
            try {
                $model->forceFill(['reason' => 'changed'])->save();
                $this->fail('Update should fail.');
            } catch (LogicException) {
            }
            try {
                $model->delete();
                $this->fail('Delete should fail.');
            } catch (LogicException) {
            }
        }
        foreach ([['sale_returns', $return->id], ['sale_refunds', $refund->id], ['sale_return_requests', $return->sale_return_request_id], ['sale_refund_requests', $refund->sale_refund_request_id]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail($table.' deletion should fail.');
            } catch (QueryException) {
            }
        }
        foreach ([['returned_amount' => '-0.01'], ['returned_amount' => '100000.01'], ['refunded_amount' => '-0.01'], ['balance_due' => '-0.01'], ['refundable_credit' => '-0.01']] as $change) {
            try {
                DB::table('sales')->where('id', $sale->id)->update($change);
                $this->fail('Invalid aggregate should fail.');
            } catch (QueryException) {
            }
        }
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertDatabaseCount('sale_refunds', 1);
    }

    public function test_filters_xss_snapshots_and_refund_validation_are_safe(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $return = $this->recordReturn($actor, $sale, $item, '0.600', 'non_restock');
        $refund = $this->recordRefund($actor, $sale, '10000.00');
        $return->setRawAttributes($return->getAttributes());
        $this->actingAs($actor)->get(route('returns.index', ['search' => ['x'], 'from' => ['x'], 'customer' => ['x'], 'page' => ['x']]))->assertOk();
        $this->get(route('refunds.index', ['search' => ['x'], 'method' => ['x'], 'staff' => ['x'], 'page' => ['x']]))->assertOk();
        $this->get(route('returns.receipt', $return))->assertOk()->assertSee('Return Receipt')->assertDontSee('PRIVATE');
        $this->get(route('refunds.show', $refund))->assertOk()->assertSee($sale->customer_name_snapshot);
        foreach (['pos', 'arbitrary'] as $method) {
            $token = $this->refundToken($actor, $sale);
            $this->post(route('sales.refunds.store', $sale), ['request_token' => $token, 'amount' => '1.00', 'payment_method' => $method, 'reason' => 'x'])->assertSessionHasErrors('payment_method');
        }
        foreach (['0', '-1', '0.001', '1e3', '1,000', 'NaN', 'Infinity'] as $amount) {
            $token = $this->refundToken($actor, $sale);
            $this->post(route('sales.refunds.store', $sale), ['request_token' => $token, 'amount' => $amount, 'payment_method' => 'cash', 'reason' => 'x'])->assertSessionHasErrors('amount');
        }
    }

    public function test_independent_processes_cannot_over_return_or_over_refund(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for independent-process concurrency probes.');
        }
        [$actor, $sale, $item] = $this->fixture('20000.00');
        DB::table('sale_items')->where('id', $item->id)->update(['quantity' => '10.000', 'unit_price' => '10000.00']);
        $item->refresh();
        $this->recordReturn($actor, $sale, $item, '4.000', 'restock');
        $returnTokens = [$this->returnToken($actor, $sale, 'parallel'), $this->returnToken($actor, $sale, 'parallel')];
        DB::commit();

        try {
            $statuses = $this->forkTwo(function (int $index) use ($actor, $sale, $item, $returnTokens): void {
                app(RecordSaleReturn::class)->execute(User::findOrFail($actor->id), Sale::findOrFail($sale->id), ['request_token' => $returnTokens[$index], 'reason' => 'Concurrent', 'items' => [['sale_item_id' => $item->id, 'quantity' => '4.000', 'disposition' => 'restock']]], 'parallel');
            });
            DB::purge();
            $this->assertSame([0, 1], collect($statuses)->sort()->values()->all());
            $this->assertLessThanOrEqual(10.0, (float) DB::table('sale_return_items')->where('sale_item_id', $item->id)->sum('quantity_returned'));
            $this->assertSame(2, SaleReturn::where('sale_id', $sale->id)->count());
            $this->assertSame(2, SaleReturn::where('sale_id', $sale->id)->distinct()->count('return_number'));
            $this->assertSame(2, AuditLog::where('action', 'sale_return_recorded')->where('new_values->sale_id', $sale->id)->count());
            $this->assertSame(2, DB::table('inventory_movements')->where('type', 'sale_return')->whereIn('reference_id', SaleReturn::where('sale_id', $sale->id)->pluck('id'))->count());
            $this->assertSame('18.000', Product::findOrFail($item->product_id)->current_stock);
            $this->assertLedgerState($sale->id);

            [$actor2, $sale2, $item2] = $this->fixture('70000.00');
            $this->recordReturn($actor2, $sale2, $item2, '1.000', 'non_restock');
            $refundTokens = [$this->refundToken($actor2, $sale2, 'parallel-refund'), $this->refundToken($actor2, $sale2, 'parallel-refund')];
            $refundStatuses = $this->forkTwo(function (int $index) use ($actor2, $sale2, $refundTokens): void {
                app(RecordSaleRefund::class)->execute(User::findOrFail($actor2->id), Sale::findOrFail($sale2->id), ['request_token' => $refundTokens[$index], 'amount' => '15000.00', 'payment_method' => 'cash', 'reason' => 'Concurrent'], 'parallel-refund');
            });
            DB::purge();
            $this->assertSame([0, 1], collect($refundStatuses)->sort()->values()->all());
            $this->assertLessThanOrEqual(20000.0, (float) DB::table('sale_refunds')->where('sale_id', $sale2->id)->sum('amount'));
            $this->assertGreaterThanOrEqual(0, (float) Sale::findOrFail($sale2->id)->refundable_credit);
            $this->assertSame(1, SaleRefund::where('sale_id', $sale2->id)->count());
            $this->assertSame(1, SaleRefund::where('sale_id', $sale2->id)->distinct()->count('refund_number'));
            $this->assertSame(1, AuditLog::where('action', 'sale_refund_recorded')->where('new_values->sale_id', $sale2->id)->count());
            $this->assertLedgerState($sale2->id);

            [$actor3, $sale3, $item3] = $this->fixture('20000.00');
            $returnToken = $this->returnToken($actor3, $sale3, 'return-payment');
            $paymentToken = $this->paymentToken($actor3, $sale3, 'return-payment');
            $mixed = $this->forkTwo(function (int $index) use ($actor3, $sale3, $item3, $returnToken, $paymentToken): void {
                $user = User::findOrFail($actor3->id);
                $freshSale = Sale::findOrFail($sale3->id);
                if ($index === 0) {
                    app(RecordSaleReturn::class)->execute($user, $freshSale, ['request_token' => $returnToken, 'reason' => 'Concurrent', 'items' => [['sale_item_id' => $item3->id, 'quantity' => '0.600', 'disposition' => 'non_restock']]], 'return-payment');
                } else {
                    app(RecordSalePayment::class)->execute($user, $freshSale, ['request_token' => $paymentToken, 'amount' => '60000.00', 'payment_method' => 'cash', 'note' => null], 'return-payment');
                }
            });
            DB::purge();
            $this->assertContains(0, $mixed);
            $state = Sale::findOrFail($sale3->id);
            $this->assertGreaterThanOrEqual(0, (float) $state->balance_due);
            $this->assertGreaterThanOrEqual(0, (float) $state->refundable_credit);
            $this->assertLedgerState($sale3->id);

            [$actor4, $sale4, $item4] = $this->fixture('70000.00');
            $this->recordReturn($actor4, $sale4, $item4, '1.000', 'non_restock');
            $refundToken = $this->refundToken($actor4, $sale4, 'refund-payment');
            $latePaymentToken = $this->paymentToken($actor4, $sale4, 'refund-payment');
            $refundPayment = $this->forkTwo(function (int $index) use ($actor4, $sale4, $refundToken, $latePaymentToken): void {
                $user = User::findOrFail($actor4->id);
                $freshSale = Sale::findOrFail($sale4->id);
                if ($index === 0) {
                    app(RecordSaleRefund::class)->execute($user, $freshSale, ['request_token' => $refundToken, 'amount' => '15000.00', 'payment_method' => 'cash', 'reason' => 'Concurrent'], 'refund-payment');
                } else {
                    app(RecordSalePayment::class)->execute($user, $freshSale, ['request_token' => $latePaymentToken, 'amount' => '1.00', 'payment_method' => 'cash', 'note' => null], 'refund-payment');
                }
            });
            DB::purge();
            $this->assertSame([0, 1], collect($refundPayment)->sort()->values()->all());
            $this->assertSame('5000.00', Sale::findOrFail($sale4->id)->refundable_credit);
            $this->assertLedgerState($sale4->id);
        } finally {
            DB::purge();
            Artisan::call('migrate:fresh', ['--force' => true]);
            DB::connection()->beginTransaction();
        }
    }

    public function test_return_and_refund_audit_failures_roll_back_every_stage(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $returnToken = $this->returnToken($actor, $sale);
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        try {
            (new RecordSaleReturn($audit))->execute($actor, $sale, ['request_token' => $returnToken, 'reason' => 'Rollback', 'items' => [['sale_item_id' => $item->id, 'quantity' => '0.600', 'disposition' => 'restock']]], 'test');
            $this->fail('Return should roll back.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('sale_returns', 0);
            $this->assertDatabaseCount('sale_return_items', 0);
            $this->assertDatabaseMissing('inventory_movements', ['type' => 'sale_return']);
            $this->assertSame('10.000', $item->product->fresh()->current_stock);
            $this->assertSame('0.00', $sale->fresh()->returned_amount);
            $this->assertNull(SaleReturnRequest::where('token_hash', hash('sha256', $returnToken))->firstOrFail()->used_at);
        }

        $return = $this->recordReturn($actor, $sale, $item, '0.600', 'non_restock');
        $refundToken = $this->refundToken($actor, $sale);
        $refundAudit = Mockery::mock(AuditLogger::class);
        $refundAudit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        try {
            (new RecordSaleRefund($refundAudit))->execute($actor, $sale, ['request_token' => $refundToken, 'amount' => '10000.00', 'payment_method' => 'cash', 'reason' => 'Rollback'], 'test');
            $this->fail('Refund should roll back.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('sale_refunds', 0);
            $this->assertSame('0.00', $sale->fresh()->refunded_amount);
            $this->assertSame($return->refundable_credit_created, $sale->fresh()->refundable_credit);
            $this->assertNull(SaleRefundRequest::where('token_hash', hash('sha256', $refundToken))->firstOrFail()->used_at);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_refund_recorded']);
        }
    }

    public function test_every_return_transaction_stage_rolls_back_atomically(): void
    {
        $stages = [
            'return insert' => ['eloquent.created: '.SaleReturn::class, null],
            'item insert' => ['eloquent.created: '.SaleReturnItem::class, null],
            'movement insert' => ['eloquent.created: '.InventoryMovement::class, null],
            'stock mutation' => ['eloquent.updated: '.Product::class, null],
            'aggregate sync' => ['eloquent.updated: '.Sale::class, null],
            'token link' => ['eloquent.updated: '.SaleReturnRequest::class, null],
            'audit write' => [null, 'audit'],
        ];

        foreach ($stages as $name => [$event, $kind]) {
            [$actor, $sale, $item] = $this->fixture('100000.00');
            $token = $this->returnToken($actor, $sale);
            $before = $sale->fresh()->only(['returned_amount', 'refunded_amount', 'balance_due', 'refundable_credit', 'payment_status']);
            $stock = $item->product->current_stock;
            $original = Model::getEventDispatcher();
            $dispatcher = new Dispatcher(app());
            if ($event) {
                $dispatcher->listen($event, fn () => throw new RuntimeException('forced '.$name));
            }
            Model::setEventDispatcher($dispatcher);
            $audit = $kind === 'audit' ? Mockery::mock(AuditLogger::class) : app(AuditLogger::class);
            if ($kind === 'audit') {
                $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('forced audit write'));
            }
            try {
                (new RecordSaleReturn($audit))->execute($actor, $sale, ['request_token' => $token, 'reason' => 'Atomicity', 'items' => [['sale_item_id' => $item->id, 'quantity' => '0.600', 'disposition' => 'restock']]], 'test');
                $this->fail($name.' did not fail.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('sale_returns', 0);
                $this->assertDatabaseCount('sale_return_items', 0);
                $this->assertDatabaseMissing('inventory_movements', ['type' => 'sale_return']);
                $this->assertSame($stock, $item->product->fresh()->current_stock, $name);
                $fresh = $sale->fresh();
                foreach ($before as $field => $value) {
                    $this->assertEquals($value, $fresh->$field, $name.' '.$field);
                }
                $request = SaleReturnRequest::where('token_hash', hash('sha256', $token))->firstOrFail();
                $this->assertNull($request->used_at, $name);
                $this->assertNull($request->sale_return_id, $name);
                $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_return_recorded']);
            } finally {
                Model::setEventDispatcher($original);
            }
        }
    }

    public function test_every_refund_transaction_stage_rolls_back_atomically(): void
    {
        $stages = [
            'refund insert' => ['eloquent.created: '.SaleRefund::class, null],
            'aggregate sync' => ['eloquent.updated: '.Sale::class, null],
            'token link' => ['eloquent.updated: '.SaleRefundRequest::class, null],
            'audit write' => [null, 'audit'],
        ];

        foreach ($stages as $name => [$event, $kind]) {
            [$actor, $sale, $item] = $this->fixture('100000.00');
            $this->recordReturn($actor, $sale, $item, '0.600', 'non_restock');
            $token = $this->refundToken($actor, $sale);
            $before = $sale->fresh()->only(['returned_amount', 'refunded_amount', 'balance_due', 'refundable_credit', 'payment_status']);
            $original = Model::getEventDispatcher();
            $dispatcher = new Dispatcher(app());
            if ($event) {
                $dispatcher->listen($event, fn () => throw new RuntimeException('forced '.$name));
            }
            Model::setEventDispatcher($dispatcher);
            $audit = $kind === 'audit' ? Mockery::mock(AuditLogger::class) : app(AuditLogger::class);
            if ($kind === 'audit') {
                $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('forced audit write'));
            }
            try {
                (new RecordSaleRefund($audit))->execute($actor, $sale, ['request_token' => $token, 'amount' => '10000.00', 'payment_method' => 'cash', 'reason' => 'Atomicity'], 'test');
                $this->fail($name.' did not fail.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('sale_refunds', 0);
                $fresh = $sale->fresh();
                foreach ($before as $field => $value) {
                    $this->assertEquals($value, $fresh->$field, $name.' '.$field);
                }
                $request = SaleRefundRequest::where('token_hash', hash('sha256', $token))->firstOrFail();
                $this->assertNull($request->used_at, $name);
                $this->assertNull($request->sale_refund_id, $name);
                $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_refund_recorded']);
            } finally {
                Model::setEventDispatcher($original);
            }
        }
    }

    public function test_archived_product_snapshots_audit_privacy_and_token_pruning_are_preserved(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $oldCustomer = $sale->customer_name_snapshot;
        $oldProduct = $item->product_name_snapshot;
        $oldStaff = $actor->name;
        DB::table('products')->where('id', $item->product_id)->update(['is_active' => false, 'deleted_at' => now()]);
        $token = $this->returnToken($actor, $sale);
        $return = app(RecordSaleReturn::class)->execute($actor, $sale, ['request_token' => $token, 'reason' => '<script>alert(1)</script>', 'note' => 'PRIVATE-RETURN-NOTE', 'items' => [['sale_item_id' => $item->id, 'quantity' => '0.600', 'disposition' => 'restock']]], 'test');
        $this->assertFalse((bool) $item->product()->withTrashed()->firstOrFail()->is_active);
        $this->assertNotNull($item->product()->withTrashed()->firstOrFail()->deleted_at);
        DB::table('customers')->where('id', $sale->customer_id)->update(['first_name' => 'Renamed']);
        DB::table('products')->where('id', $item->product_id)->update(['name' => 'Renamed']);
        DB::table('users')->where('id', $actor->id)->update(['name' => 'Renamed']);
        $this->actingAs($actor->fresh())->get(route('returns.receipt', $return))->assertOk()->assertSee($oldCustomer)->assertSee($oldProduct)->assertSee($oldStaff)->assertDontSee('PRIVATE-RETURN-NOTE');
        $this->get(route('returns.show', $return))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>', false);
        $auditJson = AuditLog::where('action', 'sale_return_recorded')->firstOrFail()->toJson();
        $this->assertStringNotContainsString('PRIVATE-RETURN-NOTE', $auditJson);
        $this->assertStringNotContainsString($token, $auditJson);

        $expired = $this->returnToken($actor->fresh(), $sale, 'expired');
        SaleReturnRequest::where('token_hash', hash('sha256', $expired))->update(['expires_at' => now()->subMinute()]);
        $this->assertTrue(SaleReturnRequest::where('token_hash', hash('sha256', $expired))->firstOrFail()->prunable()->where('token_hash', hash('sha256', $expired))->exists());
        $this->assertFalse($return->fresh()->sale_return_request_id === null);
    }

    public function test_return_and_refund_request_pruning_retains_every_non_prunable_state(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $linkedReturn = $this->recordReturn($actor, $sale, $item, '0.600', 'non_restock');
        $linkedRefund = $this->recordRefund($actor, $sale, '10000.00');
        SaleReturnRequest::whereKey($linkedReturn->sale_return_request_id)->update(['expires_at' => now()->subMinute()]);
        SaleRefundRequest::whereKey($linkedRefund->sale_refund_request_id)->update(['expires_at' => now()->subMinute()]);

        foreach ([[SaleReturnRequest::class, 'sale_return_id'], [SaleRefundRequest::class, 'sale_refund_id']] as [$model, $link]) {
            $expiredUnused = $this->requestState($model, $actor, $sale, now()->subMinute());
            $expiredConsumed = $this->requestState($model, $actor, $sale, now()->subMinute(), now());
            $unexpiredUnused = $this->requestState($model, $actor, $sale, now()->addMinute());
            $linkedId = $model === SaleReturnRequest::class ? $linkedReturn->sale_return_request_id : $linkedRefund->sale_refund_request_id;

            $model::query()->whereKey($expiredUnused)->firstOrFail()->prunable()->delete();

            $this->assertDatabaseMissing((new $model)->getTable(), ['id' => $expiredUnused]);
            $this->assertDatabaseHas((new $model)->getTable(), ['id' => $expiredConsumed]);
            $this->assertDatabaseHas((new $model)->getTable(), ['id' => $unexpiredUnused]);
            $this->assertDatabaseHas((new $model)->getTable(), ['id' => $linkedId]);
            $this->assertNotNull($model::findOrFail($linkedId)->$link);
        }
    }

    public function test_sales_representative_sale_detail_contains_no_return_or_refund_confidential_data(): void
    {
        [$admin, $sale, $item] = $this->fixture('100000.00');
        $returnToken = $this->returnToken($admin, $sale);
        $return = app(RecordSaleReturn::class)->execute($admin, $sale, ['request_token' => $returnToken, 'reason' => 'VISIBLE-RETURN-REASON', 'note' => 'PRIVATE-RETURN-NOTE', 'items' => [['sale_item_id' => $item->id, 'quantity' => '0.600', 'disposition' => 'non_restock']]], 'test');
        $refundToken = $this->refundToken($admin, $sale);
        $refund = app(RecordSaleRefund::class)->execute($admin, $sale, ['request_token' => $refundToken, 'amount' => '10000.00', 'payment_method' => 'cash', 'reason' => 'VISIBLE-REFUND-REASON', 'note' => 'PRIVATE-REFUND-NOTE'], 'test');
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        DB::table('sales')->where('id', $sale->id)->update(['sold_by' => $rep->id]);

        $response = $this->actingAs($rep)->get(route('sales.show', $sale))->assertOk();
        foreach ([$return->return_number, $refund->refund_number, 'VISIBLE-RETURN-REASON', 'VISIBLE-REFUND-REASON', 'PRIVATE-RETURN-NOTE', 'PRIVATE-REFUND-NOTE', 'Record Return', 'Record Refund'] as $secret) {
            $response->assertDontSee($secret, false);
        }
    }

    public function test_reports_keep_gross_sales_and_collections_historical_after_return(): void
    {
        [$admin, $sale, $item] = $this->fixture('20000.00');
        $this->recordReturn($admin, $sale, $item, '0.600', 'non_restock');

        $this->actingAs($admin)->get(route('reports.summary'))->assertOk()
            ->assertSeeInOrder(['Gross Sales', '₦100,000.00'])
            ->assertSeeInOrder(['Customer Collections', '₦20,000.00'])
            ->assertSeeInOrder(['Current Outstanding Receivables', '₦50,000.00'])
            ->assertSeeInOrder(['Operating Expenses', '₦0.00'])
            ->assertSeeInOrder(['Inventory Purchases', '₦0.00']);
        $this->assertSame('completed', $sale->fresh()->status->value);
        $this->assertSame('30000.00', $sale->fresh()->returned_amount);
    }

    public function test_reports_keep_collections_historical_as_refundable_credit_is_exhausted(): void
    {
        [$admin, $sale, $item] = $this->fixture('100000.00');
        $this->recordReturn($admin, $sale, $item, '0.600', 'non_restock');
        $this->recordRefund($admin, $sale, '20000.00');
        $summary = $this->actingAs($admin)->get(route('reports.summary'))->assertOk();
        $summary->assertSeeInOrder(['Gross Sales', '₦100,000.00'])
            ->assertSeeInOrder(['Customer Collections', '₦100,000.00'])
            ->assertSeeInOrder(['Current Outstanding Receivables', '₦0.00'])
            ->assertSeeInOrder(['Operating Expenses', '₦0.00'])
            ->assertSeeInOrder(['Inventory Purchases', '₦0.00']);
        $this->assertSame('10000.00', $sale->fresh()->refundable_credit);

        $this->recordRefund($admin, $sale, '10000.00');
        $this->assertSame('0.00', $sale->fresh()->refundable_credit);
        $this->get(route('reports.summary'))->assertOk()->assertSeeInOrder(['Customer Collections', '₦100,000.00'])->assertSeeInOrder(['Current Outstanding Receivables', '₦0.00']);
        try {
            $this->recordRefund($admin, $sale, '0.01');
            $this->fail('Exhausted credit accepted another Refund.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('sale_refunds', 2);
        }
    }

    public function test_every_return_and_refund_get_route_enforces_the_role_matrix_and_binding(): void
    {
        [$admin, $sale, $item] = $this->fixture('100000.00');
        $return = $this->recordReturn($admin, $sale, $item, '0.600', 'non_restock');
        $refund = $this->recordRefund($admin, $sale, '10000.00');
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $routes = [
            route('returns.index'), route('returns.show', $return), route('returns.receipt', $return),
            route('refunds.index'), route('refunds.show', $refund), route('refunds.receipt', $refund),
            route('sales.returns.create', $sale), route('sales.refunds.create', $sale),
        ];

        foreach ([$admin, $manager] as $allowed) {
            foreach ($routes as $url) {
                $this->actingAs($allowed)->get($url)->assertOk();
            }
        }
        foreach ($routes as $url) {
            $this->actingAs($rep)->get($url)->assertForbidden();
            auth()->logout();
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->actingAs($admin)->get('/returns/999999999/receipt')->assertNotFound();
        $this->get('/refunds/999999999/receipt')->assertNotFound();

        [, $otherSale, $otherItem] = $this->fixture('100000.00', $admin);
        $otherReturn = $this->recordReturn($admin, $otherSale, $otherItem, '0.200', 'non_restock');
        $otherReceipt = $this->get(route('returns.receipt', $otherReturn))->assertOk();
        $otherReceipt->assertSee($otherReturn->return_number)->assertDontSee($return->return_number);

        [, $managerSale, $managerItem] = $this->fixture('100000.00', $manager);
        $this->actingAs($manager)->startSession();
        $returnPage = $this->get(route('sales.returns.create', $managerSale))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $returnPage->getContent(), $returnToken);
        $this->post(route('sales.returns.store', $managerSale), ['request_token' => $returnToken[1], 'reason' => 'Manager Return', 'items' => [['sale_item_id' => $managerItem->id, 'quantity' => '0.600', 'disposition' => 'non_restock']]])->assertRedirect();
        $refundPage = $this->get(route('sales.refunds.create', $managerSale))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $refundPage->getContent(), $refundToken);
        $this->post(route('sales.refunds.store', $managerSale), ['request_token' => $refundToken[1], 'amount' => '1.00', 'payment_method' => 'cash', 'reason' => 'Manager Refund'])->assertRedirect();
        foreach ([route('sales.returns.store', $managerSale), route('sales.refunds.store', $managerSale)] as $url) {
            $this->actingAs($rep)->post($url)->assertForbidden();
            auth()->logout();
            $this->post($url)->assertRedirect(route('login'));
        }
    }

    public function test_cross_domain_fingerprints_change_only_intended_tables(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $tables = ['expenses', 'expense_requests', 'purchases', 'purchase_items', 'purchase_requests', 'suppliers', 'whatsapp_deliveries', 'customers', 'products', 'inventory_movements', 'sales', 'sale_items', 'sale_payments', 'sale_returns', 'sale_return_items', 'sale_refunds', 'sale_return_requests', 'sale_refund_requests', 'audit_logs'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => $this->fingerprint($table)]);

        $return = $this->recordReturn($actor, $sale, $item, '0.600', 'restock');
        $refund = $this->recordRefund($actor, $sale, '10000.00');
        foreach ([route('returns.index'), route('refunds.index'), route('returns.receipt', $return), route('refunds.receipt', $refund), route('sales.show', $sale), route('reports.summary')] as $url) {
            $this->actingAs($actor)->get($url)->assertOk();
        }
        $after = collect($tables)->mapWithKeys(fn (string $table) => [$table => $this->fingerprint($table)]);
        $changed = $tables;
        $changed = collect($changed)->filter(fn (string $table) => $before[$table] !== $after[$table])->values()->all();

        $this->assertSame(['products', 'inventory_movements', 'sales', 'sale_returns', 'sale_return_items', 'sale_refunds', 'sale_return_requests', 'sale_refund_requests', 'audit_logs'], $changed);
        foreach (['expenses', 'expense_requests', 'purchases', 'purchase_items', 'purchase_requests', 'suppliers', 'whatsapp_deliveries', 'customers', 'sale_items', 'sale_payments'] as $table) {
            $this->assertSame($before[$table], $after[$table], $table);
        }
    }

    public function test_actual_log_files_never_receive_return_or_refund_secrets(): void
    {
        $sentinels = [
            'return_note' => 'SENTINEL-RETURN-PRIVATE-NOTE',
            'refund_note' => 'SENTINEL-REFUND-PRIVATE-NOTE',
            'return_token' => str_pad('SENTINEL-RETURN-TOKEN', 64, 'R'),
            'refund_token' => str_pad('SENTINEL-REFUND-TOKEN', 64, 'F'),
            'session' => 'SENTINEL-SESSION-ID',
        ];
        $logs = glob(storage_path('logs/*.log')) ?: [];
        $offsets = collect($logs)->mapWithKeys(fn (string $path) => [$path => filesize($path)]);
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $this->requestWithToken(SaleReturnRequest::class, $sentinels['return_token'], $actor, $sale, $sentinels['session']);
        $returnData = ['request_token' => $sentinels['return_token'], 'reason' => 'Accepted', 'note' => $sentinels['return_note'], 'items' => [['sale_item_id' => $item->id, 'quantity' => '0.600', 'disposition' => 'non_restock']]];
        app(RecordSaleReturn::class)->execute($actor, $sale, $returnData, $sentinels['session']);
        $this->requestWithToken(SaleRefundRequest::class, $sentinels['refund_token'], $actor, $sale, $sentinels['session']);
        $refundData = ['request_token' => $sentinels['refund_token'], 'amount' => '10000.00', 'payment_method' => 'cash', 'reason' => 'Accepted', 'note' => $sentinels['refund_note']];
        app(RecordSaleRefund::class)->execute($actor, $sale, $refundData, $sentinels['session']);
        [, $otherSale, $otherItem] = $this->fixture('0.00', $actor);
        $overReturnToken = $this->returnToken($actor, $sale, $sentinels['session']);
        $wrongSaleToken = $this->returnToken($actor, $sale, $sentinels['session']);
        $expiredToken = $this->returnToken($actor, $sale, $sentinels['session']);
        SaleReturnRequest::where('token_hash', hash('sha256', $expiredToken))->update(['expires_at' => now()->subSecond()]);
        $differentActorToken = $this->refundToken($actor, $sale, $sentinels['session']);
        $otherActor = User::factory()->create(['role' => UserRole::Manager]);

        $attempts = [
            fn () => app(RecordSaleReturn::class)->execute($actor, $sale, $returnData, $sentinels['session']),
            fn () => app(RecordSaleReturn::class)->execute($actor, $sale, array_replace($returnData, ['reason' => 'changed']), $sentinels['session']),
            fn () => app(RecordSaleReturn::class)->execute($actor, $sale, array_replace($returnData, ['request_token' => $overReturnToken, 'items' => [['sale_item_id' => $item->id, 'quantity' => '99.000', 'disposition' => 'restock']]]), $sentinels['session']),
            fn () => app(RecordSaleReturn::class)->execute($actor, $sale, array_replace($returnData, ['request_token' => $wrongSaleToken, 'items' => [['sale_item_id' => $otherItem->id, 'quantity' => '0.001', 'disposition' => 'restock']]]), $sentinels['session']),
            fn () => app(RecordSaleReturn::class)->execute($actor, $sale, array_replace($returnData, ['request_token' => $expiredToken]), $sentinels['session']),
            fn () => app(RecordSaleRefund::class)->execute($actor, $sale, array_replace($refundData, ['amount' => '999999.00']), $sentinels['session']),
            fn () => app(RecordSaleRefund::class)->execute($actor, $sale, array_replace($refundData, ['request_token' => 'malformed']), $sentinels['session']),
            fn () => app(RecordSaleRefund::class)->execute($otherActor, $sale, array_replace($refundData, ['request_token' => $differentActorToken]), $sentinels['session']),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (\Throwable) {
            }
        }

        $newLog = '';
        foreach (glob(storage_path('logs/*.log')) ?: [] as $path) {
            $newLog .= substr((string) file_get_contents($path), (int) ($offsets[$path] ?? 0));
        }
        foreach ($sentinels as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $newLog);
        }
        $this->assertStringNotContainsString(json_encode($returnData, JSON_THROW_ON_ERROR), $newLog);
        $this->assertStringNotContainsString(json_encode($refundData, JSON_THROW_ON_ERROR), $newLog);
    }

    public function test_representative_lists_have_constant_queries_stable_pages_and_preserved_filters(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $secondItem = $item->replicate();
        $secondItem->product_id = Product::factory()->create()->id;
        $secondItem->product_sku_snapshot = 'SECOND-SKU';
        $secondItem->product_name_snapshot = 'Second Product';
        $secondItem->save();
        $smallReturnQueries = $this->queryCount(fn () => $this->actingAs($actor)->get(route('returns.index'))->assertOk());
        $smallRefundQueries = $this->queryCount(fn () => $this->get(route('refunds.index'))->assertOk());
        [$returns, $refunds] = $this->seedRepresentativeHistory($actor, $sale, [$item, $secondItem]);

        $returnPage1 = $this->queryCount(fn () => $this->get(route('returns.index', ['search' => 'RET-VOL']))->assertOk());
        $returnPage2 = $this->queryCount(fn () => $this->get(route('returns.index', ['search' => 'RET-VOL', 'page' => 2]))->assertOk());
        $refundPage1 = $this->queryCount(fn () => $this->get(route('refunds.index', ['search' => 'REF-VOL']))->assertOk());
        $refundPage2 = $this->queryCount(fn () => $this->get(route('refunds.index', ['search' => 'REF-VOL', 'page' => 2]))->assertOk());
        $this->assertLessThanOrEqual($smallReturnQueries + 1, $returnPage1);
        $this->assertSame($returnPage1, $returnPage2);
        $this->assertLessThanOrEqual($smallRefundQueries + 1, $refundPage1);
        $this->assertSame($refundPage1, $refundPage2);
        $this->assertLessThanOrEqual(6, $this->queryCount(fn () => $this->get(route('returns.show', $returns[0]))->assertOk()));
        $this->assertLessThanOrEqual(5, $this->queryCount(fn () => $this->get(route('refunds.show', $refunds[0]))->assertOk()));
        $this->assertLessThanOrEqual(15, $this->queryCount(fn () => $this->get(route('sales.show', $sale))->assertOk()));

        foreach ([['returns.index', 'RET-VOL', 50], ['refunds.index', 'REF-VOL', 35]] as [$routeName, $search, $total]) {
            $seen = [];
            $pages = (int) ceil($total / 15);
            foreach (range(1, $pages) as $page) {
                $html = $this->get(route($routeName, ['search' => $search, 'page' => $page]))->assertOk()->getContent();
                preg_match_all('/'.preg_quote(substr($search, 0, 3), '/').'-VOL-\d{3}/', $html, $matches);
                $expected = $page < $pages ? 15 : $total - (15 * ($pages - 1));
                $this->assertCount($expected, array_unique($matches[0]));
                $this->assertEmpty(array_intersect($seen, $matches[0]));
                $seen = array_merge($seen, $matches[0]);
                if ($page < $pages) {
                    $this->assertStringContainsString('search='.$search, $html);
                }
            }
            $this->assertCount($total, array_unique($seen));
        }
    }

    public function test_models_reject_every_update_and_delete_api_and_no_mutation_routes_exist(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $return = $this->recordReturn($actor, $sale, $item, '0.600', 'non_restock');
        $refund = $this->recordRefund($actor, $sale, '10000.00');
        foreach ([$return, $return->items()->firstOrFail(), $refund] as $model) {
            $original = $model->fresh()->getRawOriginal();
            foreach (['update', 'save', 'force_save', 'delete', 'destroy'] as $operation) {
                $fresh = $model->fresh();
                try {
                    match ($operation) {
                        'update' => $fresh->update(['reason' => 'MUTATED']),
                        'save' => tap($fresh, fn ($record) => $record->setAttribute($record instanceof SaleReturnItem ? 'quantity_returned' : 'reason', $record instanceof SaleReturnItem ? '9.999' : 'MUTATED'))->save(),
                        'force_save' => $fresh->forceFill([$fresh instanceof SaleReturnItem ? 'quantity_returned' : 'reason' => $fresh instanceof SaleReturnItem ? '9.999' : 'MUTATED'])->save(),
                        'delete' => $fresh->delete(),
                        'destroy' => $fresh::destroy($fresh->id),
                    };
                    $this->fail($model::class.' accepted '.$operation);
                } catch (\Throwable) {
                    $this->assertSame($original, $model->fresh()->getRawOriginal(), $model::class.' '.$operation);
                }
            }
        }
        $mutationRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => in_array($route->getName(), ['returns.update', 'returns.destroy', 'refunds.update', 'refunds.destroy'], true));
        $this->assertCount(0, $mutationRoutes);
    }

    public function test_complete_refund_method_and_amount_validation_matrix(): void
    {
        [$actor, $sale, $item] = $this->fixture('100000.00');
        $this->recordReturn($actor, $sale, $item, '2.000', 'non_restock');
        foreach (['cash', 'transfer'] as $method) {
            $token = $this->refundToken($actor, $sale);
            $refund = app(RecordSaleRefund::class)->execute($actor, $sale, ['request_token' => $token, 'amount' => '1.00', 'payment_method' => $method, 'reason' => 'Valid'], 'test');
            $this->assertSame($method, $refund->payment_method->value);
        }
        foreach (['POS', 'arbitrary', null, [], [['nested']]] as $method) {
            $this->actingAs($actor)->post(route('sales.refunds.store', $sale), ['request_token' => Str::random(64), 'amount' => '1.00', 'payment_method' => $method, 'reason' => 'Invalid'])->assertSessionHasErrors('payment_method');
        }
        foreach (['0', '-0.01', '0.001', '10.999', '1e3', '1,000', 'NaN', 'Infinity', ' ', [], [['nested']], '10000000000000.00'] as $amount) {
            $this->post(route('sales.refunds.store', $sale), ['request_token' => Str::random(64), 'amount' => $amount, 'payment_method' => 'cash', 'reason' => 'Invalid'])->assertSessionHasErrors('amount');
        }
        foreach (['0.01', '1.00', '10.50'] as $amount) {
            $refund = $this->recordRefund($actor, $sale, $amount);
            $this->assertSame($amount, $refund->amount);
        }
        $html = $this->get(route('sales.refunds.create', $sale))->assertOk()->getContent();
        $this->assertStringContainsString('value="cash"', $html);
        $this->assertStringContainsString('value="transfer"', $html);
        $this->assertStringNotContainsString('value="pos"', strtolower($html));
    }

    public function test_largest_decimal_refund_is_accepted_when_fully_backed_by_credit(): void
    {
        $maximum = '9999999999999.99';
        [$actor, $sale, $item] = $this->fixture('100000.00');
        DB::table('sale_items')->where('id', $item->id)->update(['quantity' => '1.000', 'unit_price' => $maximum, 'line_total' => $maximum]);
        DB::table('sale_payments')->where('sale_id', $sale->id)->update(['amount' => $maximum, 'cumulative_paid_after' => $maximum, 'balance_after' => '0.00']);
        DB::table('sales')->where('id', $sale->id)->update(['subtotal' => $maximum, 'total_amount' => $maximum, 'amount_paid' => $maximum, 'balance_due' => '0.00']);
        $return = $this->recordReturn($actor, $sale->fresh(), $item->fresh(), '1.000', 'non_restock');
        $this->assertSame($maximum, $return->merchandise_value);
        $refund = $this->recordRefund($actor, $sale->fresh(), $maximum);
        $this->assertSame($maximum, $refund->amount);
        $this->assertSame('0.00', $sale->fresh()->refundable_credit);
    }

    public function test_complete_return_quantity_validation_matrix(): void
    {
        [$actor, $sale, $item] = $this->fixture('0.00');
        DB::table('sale_items')->where('id', $item->id)->update(['quantity' => '10.000', 'unit_price' => '10000.00', 'line_total' => '100000.00']);
        $item->refresh();
        foreach (['0', '-0.001', '0.0001', '1e1', '1,000', 'NaN', 'Infinity', [], [['nested']], '1000000000000.000'] as $quantity) {
            $this->actingAs($actor)->post(route('sales.returns.store', $sale), ['request_token' => Str::random(64), 'reason' => 'Invalid', 'items' => [['sale_item_id' => $item->id, 'quantity' => $quantity, 'disposition' => 'non_restock']]])->assertSessionHasErrors('items.0.quantity');
        }
        foreach (['0.001', '1', '1.250'] as $quantity) {
            $return = $this->recordReturn($actor, $sale, $item, $quantity, 'non_restock');
            $this->assertSame($quantity === '1' ? '1.000' : $quantity, $return->items()->firstOrFail()->quantity_returned);
        }
    }

    public function test_every_void_order_is_fail_closed_without_duplicate_stock_or_audit(): void
    {
        [$actor, $returnSale, $returnItem] = $this->fixture('100000.00');
        $this->recordReturn($actor, $returnSale, $returnItem, '0.600', 'restock');
        $stock = $returnItem->product->fresh()->current_stock;
        $this->assertVoidRejected($actor, $returnSale);
        $this->assertSame($stock, $returnItem->product->fresh()->current_stock);

        [, $refundSale, $refundItem] = $this->fixture('100000.00', $actor);
        $this->recordReturn($actor, $refundSale, $refundItem, '0.600', 'non_restock');
        $this->recordRefund($actor, $refundSale, '10000.00');
        $this->assertVoidRejected($actor, $refundSale);
        $this->assertDatabaseCount('sale_refunds', 1);

        [, $voidSale, $voidItem] = $this->fixture('0.00', $actor);
        app(VoidSale::class)->execute($actor, $voidSale, 'Legitimate void');
        try {
            $this->recordReturn($actor, $voidSale, $voidItem, '0.600', 'restock');
            $this->fail('Voided Sale accepted Return.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('sale_returns', ['sale_id' => $voidSale->id]);
        }
        DB::table('sales')->where('id', $voidSale->id)->update(['returned_amount' => '30000.00', 'amount_paid' => '30000.00', 'refundable_credit' => '0.00', 'balance_due' => '40000.00', 'payment_status' => 'partial']);
        try {
            $this->recordRefund($actor, $voidSale->fresh(), '0.01');
            $this->fail('Voided Sale accepted Refund.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('sale_refunds', ['sale_id' => $voidSale->id]);
        }
        $this->assertSame(1, AuditLog::where('action', 'sale_voided')->where('auditable_id', $voidSale->id)->count());
    }

    private function forkTwo(callable $work): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'inventra-return-');
        unlink($barrier);
        $pids = [];
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                while (! file_exists($barrier)) {
                    usleep(1_000);
                }
                try {
                    DB::purge();
                    $work($index);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        touch($barrier);
        $statuses = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }
        if (file_exists($barrier)) {
            unlink($barrier);
        }

        return $statuses;
    }

    private function fixture(string $paid, ?User $actor = null): array
    {
        $actor ??= User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['current_stock' => '10.000']);
        $sale = Sale::factory()->create(['subtotal' => '100000.00', 'total_amount' => '100000.00', 'amount_paid' => $paid, 'balance_due' => bcsub('100000.00', $paid, 2), 'payment_status' => $paid === '0.00' ? PaymentStatus::Unpaid : ($paid === '100000.00' ? PaymentStatus::Paid : PaymentStatus::Partial)]);
        $item = new SaleItem;
        foreach (['sale_id' => $sale->id, 'product_id' => $product->id, 'product_sku_snapshot' => $product->sku, 'product_name_snapshot' => $product->name, 'unit_snapshot' => $product->unit->value, 'quantity' => '2.000', 'unit_price' => '50000.00', 'line_total' => '100000.00', 'created_at' => now()] as $key => $value) {
            $item->$key = $value;
        }
        $item->save();
        if (bccomp($paid, '0.00', 2) > 0) {
            $payment = new SalePayment;
            foreach (['payment_number' => 'PMT-'.Str::upper(Str::random(10)), 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'amount' => $paid, 'payment_method' => PaymentMethod::Cash, 'payment_type' => SalePaymentType::Initial, 'recorded_by' => $actor->id, 'recorded_by_name_snapshot' => $actor->name, 'paid_at' => now(), 'cumulative_paid_after' => $paid, 'balance_after' => bcsub('100000.00', $paid, 2), 'payment_status_after' => $sale->payment_status, 'initial_sale_guard' => $sale->id] as $key => $value) {
                $payment->$key = $value;
            }
            $payment->save();
        }

        return [$actor, $sale, $item];
    }

    private function recordReturn(User $actor, Sale $sale, SaleItem $item, string $quantity, string $disposition)
    {
        $token = $this->returnToken($actor, $sale);

        return app(RecordSaleReturn::class)->execute($actor, $sale, ['request_token' => $token, 'reason' => 'Customer return', 'items' => [['sale_item_id' => $item->id, 'quantity' => $quantity, 'disposition' => $disposition]]], 'test');
    }

    private function recordRefund(User $actor, Sale $sale, string $amount)
    {
        $token = $this->refundToken($actor, $sale);

        return app(RecordSaleRefund::class)->execute($actor, $sale, ['request_token' => $token, 'amount' => $amount, 'payment_method' => 'cash', 'reason' => 'Approved refund'], 'test');
    }

    private function returnToken(User $actor, Sale $sale, string $session = 'test'): string
    {
        $token = Str::random(64);
        $request = new SaleReturnRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $actor->id, 'session_id' => $session, 'expires_at' => now()->addMinute()] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }

    private function refundToken(User $actor, Sale $sale, string $session = 'test'): string
    {
        $token = Str::random(64);
        $request = new SaleRefundRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $actor->id, 'session_id' => $session, 'expires_at' => now()->addMinute()] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }

    private function paymentToken(User $actor, Sale $sale, string $session): string
    {
        $token = Str::random(64);
        $request = new SalePaymentRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $actor->id, 'session_id' => $session, 'expires_at' => now()->addMinute()] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }

    private function requestState(string $model, User $actor, Sale $sale, \DateTimeInterface $expiresAt, ?\DateTimeInterface $usedAt = null): int
    {
        $request = new $model;
        foreach (['token_hash' => hash('sha256', Str::random(64)), 'sale_id' => $sale->id, 'actor_id' => $actor->id, 'session_id' => Str::random(40), 'expires_at' => $expiresAt, 'used_at' => $usedAt] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $request->id;
    }

    private function requestWithToken(string $model, string $token, User $actor, Sale $sale, string $session): void
    {
        $request = new $model;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $actor->id, 'session_id' => $session, 'expires_at' => now()->addMinute()] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();
    }

    private function fingerprint(string $table): array
    {
        $rows = DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();

        return [
            'count' => count($rows),
            'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            'max_updated_at' => collect($rows)->pluck('updated_at')->filter()->max(),
        ];
    }

    /**
     * Counts the request's own queries. Session-driver reads and writes are excluded because a
     * cold session inserts while a warm one updates, which varies the total without saying
     * anything about the cost of the page under test.
     */
    private function queryCount(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(array_filter(DB::getQueryLog(), fn (array $entry) => ! str_contains($entry['query'], '`sessions`')));
        DB::disableQueryLog();

        return $count;
    }

    private function seedRepresentativeHistory(User $actor, Sale $sale, array $items): array
    {
        $returns = [];
        for ($index = 1; $index <= 50; $index++) {
            $return = new SaleReturn;
            foreach (['return_number' => sprintf('RET-VOL-%03d', $index), 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'sale_number_snapshot' => $sale->sale_number, 'customer_code_snapshot' => $sale->customer_code_snapshot, 'customer_name_snapshot' => $sale->customer_name_snapshot, 'returned_by' => $actor->id, 'returned_by_name_snapshot' => $actor->name, 'merchandise_value' => '2.00', 'receivable_reduction' => '2.00', 'refundable_credit_created' => '0.00', 'reason' => 'Volume evidence', 'returned_at' => now()->subSeconds(51 - $index)] as $key => $value) {
                $return->$key = $value;
            }
            $return->save();
            foreach ($items as $item) {
                $line = new SaleReturnItem;
                foreach (['sale_return_id' => $return->id, 'sale_item_id' => $item->id, 'product_id' => $item->product_id, 'product_sku_snapshot' => $item->product_sku_snapshot, 'product_name_snapshot' => $item->product_name_snapshot, 'unit_snapshot' => $item->unit_snapshot, 'quantity_returned' => '0.001', 'original_unit_price' => '1000.00', 'return_line_value' => '1.00', 'disposition' => 'non_restock', 'created_at' => now()] as $key => $value) {
                    $line->$key = $value;
                }
                $line->save();
            }
            $returns[] = $return;
        }
        $refunds = [];
        for ($index = 1; $index <= 35; $index++) {
            $refund = new SaleRefund;
            foreach (['refund_number' => sprintf('REF-VOL-%03d', $index), 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'sale_number_snapshot' => $sale->sale_number, 'customer_code_snapshot' => $sale->customer_code_snapshot, 'customer_name_snapshot' => $sale->customer_name_snapshot, 'amount' => '1.00', 'payment_method' => 'cash', 'reason' => 'Volume evidence', 'refunded_by' => $actor->id, 'refunded_by_name_snapshot' => $actor->name, 'refunded_at' => now()->subSeconds(36 - $index)] as $key => $value) {
                $refund->$key = $value;
            }
            $refund->save();
            $refunds[] = $refund;
        }

        return [$returns, $refunds];
    }

    private function assertVoidRejected(User $actor, Sale $sale): void
    {
        try {
            app(VoidSale::class)->execute($actor, $sale, 'Unsafe void');
            $this->fail('Sale with Return or Refund history was voided.');
        } catch (ValidationException) {
            $this->assertSame('completed', $sale->fresh()->status->value);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'sale_voided', 'auditable_id' => $sale->id]);
        }
    }

    private function assertLedgerState(int $saleId): void
    {
        $sale = Sale::findOrFail($saleId);
        $returned = (string) DB::table('sale_returns')->where('sale_id', $saleId)->sum('merchandise_value');
        $refunded = (string) DB::table('sale_refunds')->where('sale_id', $saleId)->sum('amount');
        $paid = (string) DB::table('sale_payments')->where('sale_id', $saleId)->sum('amount');
        $returned = bcadd($returned, '0.00', 2);
        $refunded = bcadd($refunded, '0.00', 2);
        $paid = bcadd($paid, '0.00', 2);
        $obligation = bcsub($sale->total_amount, $returned, 2);
        $netCash = bcsub($paid, $refunded, 2);
        $balance = bccomp($obligation, $netCash, 2) > 0 ? bcsub($obligation, $netCash, 2) : '0.00';
        $credit = bccomp($netCash, $obligation, 2) > 0 ? bcsub($netCash, $obligation, 2) : '0.00';
        $status = bccomp($balance, '0.00', 2) === 0 ? 'paid' : (bccomp($paid, '0.00', 2) === 0 ? 'unpaid' : 'partial');

        $this->assertSame($returned, $sale->returned_amount);
        $this->assertSame($refunded, $sale->refunded_amount);
        $this->assertSame($paid, $sale->amount_paid);
        $this->assertSame($balance, $sale->balance_due);
        $this->assertSame($credit, $sale->refundable_credit);
        $this->assertSame($status, $sale->payment_status->value);
    }
}
