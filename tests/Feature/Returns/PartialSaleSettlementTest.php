<?php

namespace Tests\Feature\Returns;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\IssueRefundRequest;
use App\Actions\Sale\IssueReturnRequest;
use App\Actions\Sale\IssueSalePaymentRequest;
use App\Actions\Sale\RecordSalePayment;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a Return does to the money on a partially paid Sale.
 *
 * The rule the whole thing rests on: goods coming back cancel what is still owed before they refund
 * anything, and money the customer never handed over can never come back to them. A ₦100,000 sale
 * paid ₦40,000 and returned in full owes the customer ₦40,000, not ₦100,000.
 *
 * These assert that rule end to end — the stored aggregates the Sale Details panel reads, the
 * preview the panel shows, and the ledger the action writes — and that the Sale's own history is
 * left alone while all of it happens.
 */
class PartialSaleSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests write. Asserted on the connection's own name rather than the environment,
        // because only the database can say which database it is.
        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        // ₦10,000 a unit keeps every figure in the brief exactly representable.
        $this->product = Product::factory()->create(['selling_price' => '10000.00', 'current_stock' => '100.000']);
    }

    /** A Sale of `$units` × ₦10,000 with `$paid` already collected. */
    private function sale(string $units, string $paid): Sale
    {
        return app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $this->product->id, 'quantity' => $units]],
            'payment_method' => 'cash',
            'amount_paid' => $paid,
        ]);
    }

    private function returnUnits(Sale $sale, string $units): SaleReturn
    {
        $token = app(IssueReturnRequest::class)->execute($this->admin, $sale, session()->driver());

        return app(RecordSaleReturn::class)->execute($this->admin, $sale, [
            'request_token' => $token,
            'reason' => 'Faulty',
            'items' => [[
                'sale_item_id' => $sale->items()->sole()->id,
                'quantity' => $units,
                'disposition' => 'restock',
            ]],
        ], session()->getId());
    }

    /** @return array<string, string> the settlement columns the panel reads */
    private function position(Sale $sale): array
    {
        $sale = $sale->fresh();

        return [
            'total' => (string) $sale->total_amount,
            'paid' => (string) $sale->amount_paid,
            'returned' => (string) $sale->returned_amount,
            'balance' => (string) $sale->balance_due,
            'refundDue' => (string) $sale->refundable_credit,
            'refunded' => (string) $sale->refunded_amount,
            'status' => $sale->payment_status->value,
        ];
    }

    public function test_a_paid_sale_reports_nothing_outstanding(): void
    {
        $position = $this->position($this->sale('10', '100000.00'));

        $this->assertSame('100000.00', $position['total']);
        $this->assertSame('100000.00', $position['paid']);
        $this->assertSame('0.00', $position['balance']);
        $this->assertSame(PaymentStatus::Paid->value, $position['status']);
    }

    public function test_a_partial_sale_reports_what_is_still_owed(): void
    {
        $position = $this->position($this->sale('10', '40000.00'));

        $this->assertSame('100000.00', $position['total']);
        $this->assertSame('40000.00', $position['paid']);
        $this->assertSame('60000.00', $position['balance']);
        $this->assertSame(PaymentStatus::Partial->value, $position['status']);
    }

    public function test_an_unpaid_sale_owes_the_whole_total(): void
    {
        $position = $this->position($this->sale('10', '0.00'));

        $this->assertSame('100000.00', $position['total']);
        $this->assertSame('0.00', $position['paid']);
        $this->assertSame('100000.00', $position['balance']);
        $this->assertSame(PaymentStatus::Unpaid->value, $position['status']);
    }

    /**
     * Example A: a return smaller than the outstanding balance refunds nothing.
     *
     * ₦100,000 sale, ₦40,000 paid, ₦60,000 owed. ₦30,000 of goods come back: the debt drops to
     * ₦30,000 and the customer is owed nothing, because they have not overpaid for anything.
     */
    public function test_a_return_below_the_outstanding_balance_only_reduces_the_debt(): void
    {
        $sale = $this->sale('10', '40000.00');

        $return = $this->returnUnits($sale, '3');

        $this->assertSame('30000.00', $return->merchandise_value);
        $this->assertSame('30000.00', $return->receivable_reduction);
        $this->assertSame('0.00', $return->refundable_credit_created);

        $position = $this->position($sale);
        $this->assertSame('30000.00', $position['balance'], 'the debt falls by the value returned');
        $this->assertSame('0.00', $position['refundDue'], 'nothing is owed back');
        $this->assertSame('30000.00', $position['returned']);
    }

    /**
     * Example B: a return larger than the balance refunds only the excess.
     *
     * ₦80,000 of goods against a ₦60,000 debt clears the debt and leaves ₦20,000 owed to the
     * customer — not ₦80,000, which they never paid.
     */
    public function test_a_return_above_the_outstanding_balance_refunds_only_the_excess(): void
    {
        $sale = $this->sale('10', '40000.00');

        $return = $this->returnUnits($sale, '8');

        $this->assertSame('80000.00', $return->merchandise_value);
        $this->assertSame('60000.00', $return->receivable_reduction);
        $this->assertSame('20000.00', $return->refundable_credit_created);

        $position = $this->position($sale);
        $this->assertSame('0.00', $position['balance']);
        $this->assertSame('20000.00', $position['refundDue']);
        // And not a naira more than was ever collected.
        $this->assertTrue(bccomp($position['refundDue'], $position['paid'], 2) <= 0);
    }

    /**
     * Example C: returning everything refunds exactly what was paid.
     *
     * The whole ₦100,000 comes back: the ₦60,000 debt is cancelled and the ₦40,000 actually handed
     * over becomes refundable. The customer is never owed more than they paid.
     */
    public function test_a_full_return_of_a_partial_sale_refunds_only_what_was_paid(): void
    {
        $sale = $this->sale('10', '40000.00');

        $return = $this->returnUnits($sale, '10');

        $this->assertSame('100000.00', $return->merchandise_value);
        $this->assertSame('60000.00', $return->receivable_reduction);
        $this->assertSame('40000.00', $return->refundable_credit_created);

        $position = $this->position($sale);
        $this->assertSame('0.00', $position['balance']);
        $this->assertSame('40000.00', $position['refundDue']);
        $this->assertSame('40000.00', $position['paid'], 'what was collected is unchanged');
    }

    /**
     * Refund due and refund paid are different things.
     *
     * A Return creates an entitlement. Cash leaves the business only when a Refund is recorded, and
     * until then `refunded_amount` stays at zero however large the credit is.
     */
    public function test_refund_due_is_not_refund_paid(): void
    {
        $sale = $this->sale('10', '40000.00');
        $this->returnUnits($sale, '10');

        $before = $this->position($sale);
        $this->assertSame('40000.00', $before['refundDue'], 'owed to the customer');
        $this->assertSame('0.00', $before['refunded'], 'but not yet handed over');

        // Now actually pay it out, through the existing audited refund workflow.
        $token = app(IssueRefundRequest::class)->execute($this->admin, $sale->fresh(), session()->driver());
        app(RecordSaleRefund::class)->execute($this->admin, $sale->fresh(), [
            'request_token' => $token,
            'amount' => '40000.00',
            'payment_method' => 'cash',
            'reason' => 'Returned goods',
        ], session()->getId());

        $after = $this->position($sale);
        $this->assertSame('40000.00', $after['refunded'], 'cash has now gone out');
        $this->assertSame('0.00', $after['refundDue'], 'and nothing further is owed');
    }

    /** A refund cannot exceed the credit a return actually created. */
    public function test_a_refund_cannot_exceed_the_refundable_credit(): void
    {
        $sale = $this->sale('10', '40000.00');
        $this->returnUnits($sale, '8');

        $token = app(IssueRefundRequest::class)->execute($this->admin, $sale->fresh(), session()->driver());

        // ₦20,000 is refundable; ₦25,000 is not.
        $this->expectExceptionMessage('Refund cannot exceed the currently refundable customer credit.');
        app(RecordSaleRefund::class)->execute($this->admin, $sale->fresh(), [
            'request_token' => $token,
            'amount' => '25000.00',
            'payment_method' => 'cash',
            'reason' => 'Too much',
        ], session()->getId());
    }

    /** Successive returns accumulate rather than each being settled from the original balance. */
    public function test_successive_returns_settle_against_the_current_position(): void
    {
        $sale = $this->sale('10', '40000.00');

        // First ₦50,000: all of it eats the ₦60,000 debt.
        $first = $this->returnUnits($sale, '5');
        $this->assertSame('50000.00', $first->receivable_reduction);
        $this->assertSame('0.00', $first->refundable_credit_created);
        $this->assertSame('10000.00', $this->position($sale)['balance']);

        // Then ₦30,000 against a ₦10,000 debt: ₦10,000 clears it, ₦20,000 becomes refundable.
        $second = $this->returnUnits($sale->fresh(), '3');
        $this->assertSame('10000.00', $second->receivable_reduction);
        $this->assertSame('20000.00', $second->refundable_credit_created);

        $position = $this->position($sale);
        $this->assertSame('0.00', $position['balance']);
        $this->assertSame('20000.00', $position['refundDue']);
        $this->assertSame('80000.00', $position['returned']);
        // Never more back than was put in.
        $this->assertTrue(bccomp($position['refundDue'], $position['paid'], 2) <= 0);
    }

    /**
     * The Sale itself is not rewritten by a return.
     *
     * The record of what was sold stays as it was recorded; the return is a separate entry against
     * it. This is what lets the panel show a ₦100,000 total beside a reduced balance.
     */
    public function test_a_return_does_not_rewrite_the_sale_history(): void
    {
        $sale = $this->sale('10', '40000.00');
        $before = $sale->fresh()->only(['total_amount', 'subtotal', 'amount_paid', 'sale_number', 'sale_date']);

        $this->returnUnits($sale, '8');

        $after = $sale->fresh();
        $this->assertSame($before['total_amount'], (string) $after->total_amount, 'the total still says what was sold');
        $this->assertSame($before['subtotal'], (string) $after->subtotal);
        $this->assertSame($before['amount_paid'], (string) $after->amount_paid, 'what was collected is a fact');
        $this->assertSame($before['sale_number'], $after->sale_number);
        // The line items are untouched too: the return has its own rows.
        $this->assertSame('10.000', (string) $sale->items()->sole()->quantity);
    }

    /** The stored aggregates always reconcile, which the database itself also enforces. */
    public function test_the_settlement_columns_always_reconcile(): void
    {
        $sale = $this->sale('10', '40000.00');
        $this->returnUnits($sale, '8');

        $position = $this->position($sale);

        // total − returned + credit = paid − refunded + balance
        $left = bcadd(bcsub($position['total'], $position['returned'], 2), $position['refundDue'], 2);
        $right = bcadd(bcsub($position['paid'], $position['refunded'], 2), $position['balance'], 2);
        $this->assertSame($left, $right);
    }

    /** A further payment moves the position without disturbing the return. */
    public function test_a_later_payment_reduces_the_balance(): void
    {
        $sale = $this->sale('10', '40000.00');
        $this->returnUnits($sale, '3');
        $this->assertSame('30000.00', $this->position($sale)['balance']);

        // Settled through the real payment action, which writes the row and re-derives the
        // position itself — the model guards these columns against mass assignment precisely so a
        // payment cannot be conjured any other way.
        $token = app(IssueSalePaymentRequest::class)->execute($sale->fresh(), $this->admin, session()->driver());
        app(RecordSalePayment::class)->execute($this->admin, $sale->fresh(), [
            'request_token' => $token,
            'amount' => '30000.00',
            'payment_method' => 'cash',
        ], session()->getId());

        $position = $this->position($sale);
        $this->assertSame('70000.00', $position['paid']);
        $this->assertSame('0.00', $position['balance']);
        $this->assertSame(PaymentStatus::Paid->value, $position['status']);
    }

    /** The row the panel reads carries the settlement position, formatted for display. */
    public function test_the_sales_row_carries_the_settlement_position(): void
    {
        $sale = $this->sale('10', '40000.00');
        $this->returnUnits($sale, '8');

        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();

        preg_match_all('/data-sale="([^"]*)"/', $html, $matches);
        $payload = null;

        foreach ($matches[1] as $encoded) {
            $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES), true);
            if (($decoded['id'] ?? null) === $sale->public_id) {
                $payload = $decoded;
            }
        }

        $this->assertNotNull($payload);
        // Whole naira, no trailing .00, as everywhere else on these screens.
        $this->assertSame('100,000', $payload['total']);
        $this->assertSame('40,000', $payload['paid']);
        $this->assertSame('80,000', $payload['returned']);
        $this->assertSame('0', $payload['balance']);
        $this->assertSame('20,000', $payload['refundDue']);
        // The optional rows know whether they are worth showing.
        $this->assertTrue($payload['hasReturns']);
        $this->assertTrue($payload['hasRefundDue']);
        $this->assertFalse($payload['hasRefunded']);
    }

    /** The preview the panel shows and the return the ledger records are one calculation. */
    public function test_the_preview_agrees_with_the_recorded_return(): void
    {
        $sale = $this->sale('10', '40000.00');

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => '8']],
            'restock' => 1,
        ]))->assertOk()->json();

        $this->assertSame('Refund ₦20,000 · reduce balance ₦60,000 · restock 8 units', $body['sentence']);

        $return = $this->returnUnits($sale, '8');
        $this->assertSame($body['return_value'], $return->merchandise_value);
        $this->assertSame($body['balance_reduction'], $return->receivable_reduction);
        $this->assertSame($body['cash_refund'], $return->refundable_credit_created);
    }

    /** A return below the balance previews as a debt reduction, never a refund. */
    public function test_a_small_return_previews_as_a_balance_reduction(): void
    {
        $sale = $this->sale('10', '40000.00');

        $body = $this->actingAs($this->admin)->getJson(route('sales.returns.preview', $sale).'?'.http_build_query([
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => '3']],
            'restock' => 1,
        ]))->assertOk()->json();

        $this->assertSame('Reduce balance ₦30,000 · restock 3 units', $body['sentence']);
        $this->assertSame('0.00', $body['cash_refund']);
        $this->assertStringNotContainsString('Refund', $body['sentence']);
    }
}
