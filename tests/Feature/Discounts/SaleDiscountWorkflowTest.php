<?php

namespace Tests\Feature\Discounts;

use App\Actions\Sale\DecideSaleDiscount;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\RequestSaleDiscount;
use App\Actions\Sale\VoidSale;
use App\Enums\DiscountRequestStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturnRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Discount request and approval.
 *
 * The reason this workflow exists is that a completed Sale is immutable, so "editing" one to change
 * its price is not available. A discount therefore has to reconcile rather than rewrite, and the
 * assertions below are mostly about that: after an approval the payment rows are byte-for-byte
 * unchanged, `amount_paid` is unchanged, the subtotal is unchanged, and every database CHECK on
 * `sales` still holds — including the reconciliation identity
 * `(total - returned) + credit = (paid - refunded) + balance`.
 */
class SaleDiscountWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => UserRole::Manager]);
    }

    /** A completed Sale of 100,000.00 with `$paid` already collected. */
    private function sale(string $paid = '0.00', ?User $seller = null): array
    {
        $seller ??= User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '10.000']);

        $status = bccomp($paid, '0.00', 2) === 0
            ? PaymentStatus::Unpaid
            : (bccomp($paid, '100000.00', 2) >= 0 ? PaymentStatus::Paid : PaymentStatus::Partial);

        $sale = Sale::factory()->create([
            'customer_id' => $customer->id,
            'sold_by' => $seller->id,
            'status' => SaleStatus::Completed,
            'subtotal' => '100000.00',
            'discount_amount' => '0.00',
            'total_amount' => '100000.00',
            'amount_paid' => $paid,
            'balance_due' => bcsub('100000.00', $paid, 2),
            'payment_status' => $status,
        ]);

        $item = new SaleItem;
        foreach ([
            'business_id' => $sale->business_id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_sku_snapshot' => $product->sku, 'product_name_snapshot' => $product->name,
            'unit_snapshot' => $product->unit->value, 'quantity' => '2.000',
            'unit_price' => '50000.00', 'line_total' => '100000.00', 'created_at' => now(),
        ] as $key => $value) {
            $item->$key = $value;
        }
        $item->save();

        if (bccomp($paid, '0.00', 2) > 0) {
            $payment = new SalePayment;
            foreach ([
                'payment_number' => 'PMT-'.Str::upper(Str::random(10)), 'business_id' => $sale->business_id, 'sale_id' => $sale->id,
                'customer_id' => $customer->id, 'amount' => $paid,
                'payment_method' => PaymentMethod::Cash, 'payment_type' => SalePaymentType::Initial,
                'recorded_by' => $seller->id, 'recorded_by_name_snapshot' => $seller->name,
                'paid_at' => now(), 'cumulative_paid_after' => $paid,
                'balance_after' => bcsub('100000.00', $paid, 2), 'payment_status_after' => $status,
                'initial_sale_guard' => $sale->id,
            ] as $key => $value) {
                $payment->$key = $value;
            }
            $payment->save();
        }

        return [$seller, $sale->fresh(), $item];
    }

    private function request(User $actor, Sale $sale, string $amount, string $reason = 'Goodwill on a damaged carton.'): SaleDiscountRequest
    {
        return app(RequestSaleDiscount::class)->execute($actor, $sale, ['amount' => $amount, 'reason' => $reason]);
    }

    /** Asserts the database-level reconciliation identity still holds for this Sale. */
    private function assertReconciles(Sale $sale): void
    {
        $sale = $sale->fresh();
        $left = bcadd(bcsub($sale->total_amount, $sale->returned_amount, 2), $sale->refundable_credit, 2);
        $right = bcadd(bcsub($sale->amount_paid, $sale->refunded_amount, 2), $sale->balance_due, 2);

        $this->assertSame(0, bccomp($left, $right, 2),
            "sales reconciliation identity broken: {$left} != {$right}");
        $this->assertSame(0, bccomp($sale->total_amount, bcsub($sale->subtotal, $sale->discount_amount, 2), 2),
            'total_amount must remain subtotal - discount_amount');
        foreach (['subtotal', 'discount_amount', 'total_amount', 'amount_paid', 'balance_due', 'refundable_credit'] as $column) {
            $this->assertGreaterThanOrEqual(0, (float) $sale->$column, "{$column} must never go negative");
        }
    }

    // ──────────────────────────────────── raising a request ─────────────────────────────────────

    public function test_a_request_changes_nothing_about_the_sale(): void
    {
        [, $sale] = $this->sale('40000.00');
        $before = $sale->only(['subtotal', 'discount_amount', 'total_amount', 'amount_paid', 'balance_due', 'payment_status']);

        $request = $this->request($this->manager(), $sale, '5000.00');

        $this->assertSame(DiscountRequestStatus::Pending, $request->status);
        $this->assertSame($before, $sale->fresh()->only(array_keys($before)),
            'a pending request must leave the Sale untouched');
        $this->assertSame($sale->id, (int) $request->pending_sale_guard);
    }

    public function test_only_one_request_may_be_open_per_sale(): void
    {
        [, $sale] = $this->sale('0.00');
        $manager = $this->manager();
        $this->request($manager, $sale, '1000.00');

        $this->expectException(ValidationException::class);
        $this->request($manager, $sale, '2000.00');
    }

    public function test_a_decided_request_frees_the_sale_for_another(): void
    {
        [, $sale] = $this->sale('0.00');
        $admin = $this->admin();
        $first = $this->request($this->manager(), $sale, '1000.00');
        app(DecideSaleDiscount::class)->decline($admin, $first, 'Not justified.');

        // The guard is released, so a fresh request is possible.
        $second = $this->request($this->manager(), $sale, '2000.00');
        $this->assertSame(DiscountRequestStatus::Pending, $second->status);
        $this->assertNull($first->fresh()->pending_sale_guard);
    }

    public function test_a_discount_larger_than_the_total_is_refused(): void
    {
        [, $sale] = $this->sale('0.00');

        $this->expectException(ValidationException::class);
        $this->request($this->manager(), $sale, '100000.01');
    }

    public function test_a_zero_or_negative_discount_is_refused(): void
    {
        [, $sale] = $this->sale('0.00');
        $manager = $this->manager();

        foreach (['0.00', '-5.00'] as $amount) {
            try {
                $this->request($manager, $sale, $amount);
                $this->fail("{$amount} should not be accepted as a discount");
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertDatabaseCount('sale_discount_requests', 0);
    }

    public function test_the_database_refuses_a_non_positive_amount_directly(): void
    {
        [, $sale] = $this->sale('0.00');
        $manager = $this->manager();

        $this->expectException(QueryException::class);
        DB::table('sale_discount_requests')->insert([
            'business_id' => $sale->business_id, 'sale_id' => $sale->id, 'requested_amount' => '0.00', 'reason' => 'probe',
            'status' => 'pending', 'requested_by' => $manager->id,
            'requested_by_name_snapshot' => $manager->name, 'requested_at' => now(),
            'pending_sale_guard' => $sale->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ───────────────────────────── approval: unpaid and part-paid ───────────────────────────────

    public function test_approving_on_an_unpaid_sale_reduces_the_balance(): void
    {
        [, $sale] = $this->sale('0.00');
        $request = $this->request($this->manager(), $sale, '25000.00');

        app(DecideSaleDiscount::class)->approve($this->admin(), $request);

        $sale = $sale->fresh();
        $this->assertSame('100000.00', $sale->subtotal, 'the subtotal is evidence and must not move');
        $this->assertSame('25000.00', $sale->discount_amount);
        $this->assertSame('75000.00', $sale->total_amount);
        $this->assertSame('0.00', $sale->amount_paid);
        $this->assertSame('75000.00', $sale->balance_due);
        $this->assertSame('0.00', $sale->refundable_credit);
        $this->assertSame(PaymentStatus::Unpaid, $sale->payment_status);
        $this->assertReconciles($sale);
    }

    public function test_approving_on_a_part_paid_sale_reduces_the_balance_and_keeps_payments(): void
    {
        [, $sale] = $this->sale('40000.00');
        $paymentsBefore = SalePayment::query()->where('sale_id', $sale->id)->get()->toArray();
        $request = $this->request($this->manager(), $sale, '25000.00');

        app(DecideSaleDiscount::class)->approve($this->admin(), $request);

        $sale = $sale->fresh();
        $this->assertSame('75000.00', $sale->total_amount);
        $this->assertSame('40000.00', $sale->amount_paid, 'what was paid is historical fact');
        $this->assertSame('35000.00', $sale->balance_due);
        $this->assertSame('0.00', $sale->refundable_credit);
        $this->assertSame(PaymentStatus::Partial, $sale->payment_status);
        $this->assertSame($paymentsBefore, SalePayment::query()->where('sale_id', $sale->id)->get()->toArray(),
            'no sale_payments row may be rewritten by a discount');
        $this->assertReconciles($sale);
    }

    public function test_a_discount_that_settles_the_sale_marks_it_paid(): void
    {
        [, $sale] = $this->sale('75000.00');
        $request = $this->request($this->manager(), $sale, '25000.00');

        app(DecideSaleDiscount::class)->approve($this->admin(), $request);

        $sale = $sale->fresh();
        $this->assertSame('75000.00', $sale->total_amount);
        $this->assertSame('75000.00', $sale->amount_paid);
        $this->assertSame('0.00', $sale->balance_due);
        $this->assertSame('0.00', $sale->refundable_credit);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertReconciles($sale);
    }

    // ───────────────────────── approval creating overpayment -> credit ──────────────────────────

    public function test_a_discount_below_what_was_paid_creates_refundable_credit(): void
    {
        [, $sale] = $this->sale('100000.00');
        $paymentsBefore = SalePayment::query()->where('sale_id', $sale->id)->get()->toArray();
        $request = $this->request($this->manager(), $sale, '30000.00');

        app(DecideSaleDiscount::class)->approve($this->admin(), $request);

        $sale = $sale->fresh();
        $this->assertSame('70000.00', $sale->total_amount);
        $this->assertSame('100000.00', $sale->amount_paid, 'the payment stands as recorded');
        $this->assertSame('0.00', $sale->balance_due, 'an overpayment must never become a negative balance');
        $this->assertSame('30000.00', $sale->refundable_credit,
            'the overpaid amount becomes refundable credit through the existing model');
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertSame($paymentsBefore, SalePayment::query()->where('sale_id', $sale->id)->get()->toArray());
        $this->assertReconciles($sale);
    }

    public function test_the_credit_a_discount_creates_can_actually_be_refunded(): void
    {
        [$seller, $sale] = $this->sale('100000.00');
        $admin = $this->admin();
        $request = $this->request($this->manager(), $sale, '30000.00');
        app(DecideSaleDiscount::class)->approve($admin, $request);

        // The credit is real: the existing refund workflow will pay it out.
        $token = Str::random(64);
        $refundRequest = new SaleRefundRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $admin->id,
            'session_id' => 'test', 'expires_at' => now()->addMinute()] as $key => $value) {
            $refundRequest->$key = $value;
        }
        $refundRequest->save();

        $refund = app(RecordSaleRefund::class)->execute($admin, $sale->fresh(), [
            'request_token' => $token, 'amount' => '30000.00',
            'payment_method' => 'cash', 'reason' => 'Refund of an approved discount.',
        ], 'test');

        $this->assertSame('30000.00', $refund->amount);
        $sale = $sale->fresh();
        $this->assertSame('30000.00', $sale->refunded_amount);
        $this->assertSame('0.00', $sale->refundable_credit);
        $this->assertSame('100000.00', $sale->amount_paid, 'refunding does not rewrite the payment either');
        $this->assertReconciles($sale);
    }

    public function test_a_full_discount_is_allowed_and_reconciles(): void
    {
        [, $sale] = $this->sale('100000.00');
        $request = $this->request($this->manager(), $sale, '100000.00');

        app(DecideSaleDiscount::class)->approve($this->admin(), $request);

        $sale = $sale->fresh();
        $this->assertSame('0.00', $sale->total_amount);
        $this->assertSame('100000.00', $sale->refundable_credit);
        $this->assertSame('0.00', $sale->balance_due);
        $this->assertReconciles($sale);
    }

    // ───────────────────────────────────── eligibility ──────────────────────────────────────────

    public function test_a_voided_sale_cannot_be_discounted(): void
    {
        [, $sale] = $this->sale('0.00');
        $request = $this->request($this->manager(), $sale, '5000.00');
        app(VoidSale::class)->execute($this->admin(), $sale->fresh(), 'Wrong customer entirely.');

        $this->expectException(ValidationException::class);
        app(DecideSaleDiscount::class)->approve($this->admin(), $request->fresh());
    }

    public function test_a_sale_with_a_return_cannot_be_discounted(): void
    {
        [$seller, $sale, $item] = $this->sale('100000.00');
        $admin = $this->admin();

        $token = Str::random(64);
        $returnRequest = new SaleReturnRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $admin->id,
            'session_id' => 'test', 'expires_at' => now()->addMinute()] as $key => $value) {
            $returnRequest->$key = $value;
        }
        $returnRequest->save();
        app(RecordSaleReturn::class)->execute($admin, $sale->fresh(), [
            'request_token' => $token, 'reason' => 'Customer return',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '1.000', 'disposition' => 'restock']],
        ], 'test');

        $this->expectException(ValidationException::class);
        $this->request($this->manager(), $sale->fresh(), '5000.00');
    }

    public function test_a_request_is_re_checked_at_approval_not_just_when_raised(): void
    {
        [$seller, $sale, $item] = $this->sale('100000.00');
        $admin = $this->admin();
        $request = $this->request($this->manager(), $sale, '5000.00');

        // A return lands after the request was raised but before the decision.
        $token = Str::random(64);
        $returnRequest = new SaleReturnRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $admin->id,
            'session_id' => 'test', 'expires_at' => now()->addMinute()] as $key => $value) {
            $returnRequest->$key = $value;
        }
        $returnRequest->save();
        app(RecordSaleReturn::class)->execute($admin, $sale->fresh(), [
            'request_token' => $token, 'reason' => 'Customer return',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '1.000', 'disposition' => 'restock']],
        ], 'test');

        $this->expectException(ValidationException::class);
        app(DecideSaleDiscount::class)->approve($admin, $request->fresh());
    }

    // ─────────────────────────────────────── declining ──────────────────────────────────────────

    public function test_declining_changes_only_the_request(): void
    {
        [, $sale] = $this->sale('40000.00');
        $before = $sale->only(['subtotal', 'discount_amount', 'total_amount', 'amount_paid', 'balance_due', 'refundable_credit', 'payment_status']);
        $request = $this->request($this->manager(), $sale, '5000.00');

        $declined = app(DecideSaleDiscount::class)->decline($this->admin(), $request, 'Manager overreached; no goodwill due.');

        $this->assertSame(DiscountRequestStatus::Declined, $declined->status);
        $this->assertSame('Manager overreached; no goodwill due.', $declined->decision_note);
        $this->assertSame($before, $sale->fresh()->only(array_keys($before)),
            'a decline must have zero financial effect');
        $this->assertNull($declined->discount_after);
        $this->assertNull($declined->total_after);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        [, $sale] = $this->sale('0.00');
        $admin = $this->admin();
        $request = $this->request($this->manager(), $sale, '5000.00');
        app(DecideSaleDiscount::class)->approve($admin, $request);

        $this->expectException(ValidationException::class);
        app(DecideSaleDiscount::class)->approve($admin, $request->fresh());
    }

    public function test_a_request_cannot_be_mutated_outside_a_recorded_decision(): void
    {
        [, $sale] = $this->sale('0.00');
        $request = $this->request($this->manager(), $sale, '5000.00');

        $this->expectException(LogicException::class);
        $request->forceFill(['requested_amount' => '99999.00'])->save();
    }

    // ───────────────────────────────────── authorization ────────────────────────────────────────

    public function test_a_manager_may_request_but_not_decide(): void
    {
        [, $sale] = $this->sale('0.00');
        $manager = $this->manager();
        $request = $this->request($manager, $sale, '5000.00');

        $this->assertTrue($manager->can('create', [SaleDiscountRequest::class, $sale]));
        $this->assertFalse($manager->can('approve', $request));
        $this->assertFalse($manager->can('decline', $request));

        $this->actingAs($manager)->post(route('discounts.approve', $request), ['decision_note' => 'Approving my own.'])
            ->assertForbidden();
        $this->assertSame(DiscountRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_an_admin_cannot_approve_their_own_request(): void
    {
        [, $sale] = $this->sale('0.00');
        $admin = $this->admin();
        $request = $this->request($admin, $sale, '5000.00');

        $this->assertFalse($admin->can('approve', $request),
            'a price change must involve two people');
        $this->actingAs($admin)->post(route('discounts.approve', $request))->assertForbidden();

        // They may still close it, since declining moves no money.
        $this->assertTrue($admin->can('decline', $request));
    }

    public function test_a_sales_rep_may_request_only_on_their_own_sale(): void
    {
        [$owner, $own] = $this->sale('0.00');
        [, $foreign] = $this->sale('0.00');

        $this->assertTrue($owner->can('create', [SaleDiscountRequest::class, $own]));
        $this->assertFalse($owner->can('create', [SaleDiscountRequest::class, $foreign]));

        $this->actingAs($owner)->post(route('sales.discounts.store', $foreign), [
            'amount' => '100.00', 'reason' => 'Trying a colleague\'s sale.',
        ])->assertForbidden();
    }

    public function test_a_sales_rep_cannot_see_the_approvals_queue(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        $this->actingAs($rep)->get(route('discounts.index'))->assertForbidden();
    }

    // ─────────────────────────────────── end-to-end via HTTP ────────────────────────────────────

    public function test_the_whole_workflow_runs_through_the_interface(): void
    {
        [, $sale] = $this->sale('100000.00');
        $manager = $this->manager();
        $admin = $this->admin();

        // The Sale page offers the request form.
        $salePage = $this->actingAs($manager)->get(route('sales.show', $sale))->assertOk()->getContent();
        $this->assertStringContainsString('Price adjustment', $salePage);
        $this->assertStringContainsString('is never edited', $salePage);

        $this->actingAs($manager)->post(route('sales.discounts.store', $sale), [
            'amount' => '30000.00', 'reason' => 'Two cartons arrived dented; agreed goodwill.',
        ])->assertRedirect(route('sales.show', $sale))->assertSessionDoesntHaveErrors();

        $request = SaleDiscountRequest::query()->sole();

        // It shows up in the Admin queue.
        $queue = $this->actingAs($admin)->get(route('discounts.index'))->assertOk()->getContent();
        $this->assertStringContainsString($sale->sale_number, $queue);
        $this->assertStringContainsString('awaiting a decision', $queue);

        // And approving reconciles the Sale.
        $this->actingAs($admin)->post(route('discounts.approve', $request), ['decision_note' => 'Agreed with the customer.'])
            ->assertRedirect(route('discounts.show', $request))->assertSessionDoesntHaveErrors();

        $sale = $sale->fresh();
        $this->assertSame('70000.00', $sale->total_amount);
        $this->assertSame('30000.00', $sale->refundable_credit);
        $this->assertReconciles($sale);

        $detail = $this->actingAs($admin)->get(route('discounts.show', $request))->assertOk()->getContent();
        $this->assertStringContainsString('Refundable credit after', $detail);
        $this->assertStringContainsString('Recorded payments were not altered', $detail);
    }

    // ──────────────────────────────────────── audit ─────────────────────────────────────────────

    public function test_the_audit_trail_records_the_request_and_the_decision(): void
    {
        [, $sale] = $this->sale('100000.00');
        $manager = $this->manager();
        $admin = $this->admin();
        $request = $this->request($manager, $sale, '30000.00');
        app(DecideSaleDiscount::class)->approve($admin, $request, 'Agreed.');

        $requested = AuditLog::query()->where('action', 'sale_discount_requested')->sole();
        $this->assertSame($manager->id, $requested->actor_id);

        $approved = AuditLog::query()->where('action', 'sale_discount_approved')->sole();
        $this->assertSame($admin->id, $approved->actor_id);
        $this->assertSame('70000.00', $approved->new_values['total_amount']);
        $this->assertSame('100000.00', $approved->new_values['amount_paid']);
        $this->assertSame('30000.00', $approved->new_values['refundable_credit']);
    }

    public function test_the_approval_stores_its_own_financial_evidence(): void
    {
        [, $sale] = $this->sale('100000.00');
        $request = $this->request($this->manager(), $sale, '30000.00');

        $approved = app(DecideSaleDiscount::class)->approve($this->admin(), $request);

        $this->assertSame('0.00', $approved->discount_before);
        $this->assertSame('30000.00', $approved->discount_after);
        $this->assertSame('100000.00', $approved->total_before);
        $this->assertSame('70000.00', $approved->total_after);
        $this->assertSame('0.00', $approved->balance_after);
        $this->assertSame('30000.00', $approved->refundable_credit_after);
    }
}
