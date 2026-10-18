<?php

namespace Tests\Feature\Returns;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\DecideSaleDiscount;
use App\Actions\Sale\IssueRefundRequest;
use App\Actions\Sale\IssueReturnRequest;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\RequestSaleDiscount;
use App\Enums\ProductUnit;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\ReturnSettlementPreview;
use App\Support\ReturnValuation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SubmitsSales;
use Tests\TestCase;

/**
 * Returns valued net of the Sale's discount.
 *
 * The discount is allocated across the Sale's lines in proportion to their value, partial returns
 * take their share of a line's net value, the return that exhausts a line takes exactly what is
 * left, and nothing ever credits a customer more than they were charged.
 */
class DiscountAwareReturnTest extends TestCase
{
    use RefreshDatabase, SubmitsSales;

    private User $admin;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
    }

    private function product(string $price, ProductUnit $unit = ProductUnit::Piece): Product
    {
        return Product::factory()->create(['selling_price' => $price, 'current_stock' => '100.000', 'unit' => $unit]);
    }

    /** @param list<array{0: Product, 1: string}> $lines */
    private function sale(array $lines, string $paid = '0.00'): Sale
    {
        return app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => false, 'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => array_map(fn (array $line): array => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'payment_method' => 'cash', 'amount_paid' => $paid,
        ]);
    }

    private function discount(Sale $sale, string $amount): Sale
    {
        $request = app(RequestSaleDiscount::class)->execute($this->manager, $sale->fresh(), ['amount' => $amount, 'reason' => 'Agreed goodwill on the order.']);
        app(DecideSaleDiscount::class)->approve($this->admin, $request);

        return $sale->fresh();
    }

    private function return(Sale $sale, SaleItem $item, string $quantity, string $disposition = 'restock'): SaleReturn
    {
        $token = app(IssueReturnRequest::class)->execute($this->admin, $sale->fresh(), session()->driver());

        return app(RecordSaleReturn::class)->execute($this->admin, $sale->fresh(), [
            'request_token' => $token, 'reason' => 'Returned',
            'items' => [['sale_item_id' => $item->id, 'quantity' => $quantity, 'disposition' => $disposition]],
        ], session()->getId());
    }

    private function refund(Sale $sale, string $amount): void
    {
        $token = app(IssueRefundRequest::class)->execute($this->admin, $sale->fresh(), session()->driver());

        app(RecordSaleRefund::class)->execute($this->admin, $sale->fresh(), [
            'request_token' => $token, 'amount' => $amount, 'payment_method' => 'cash', 'reason' => 'Returned goods',
        ], session()->getId());
    }

    // ── Allocation ──────────────────────────────────────────────────────────────────────────────

    public function test_the_discount_is_allocated_by_line_value_exactly_and_deterministically(): void
    {
        $sale = $this->discount($this->sale([[$this->product('600.00'), '1'], [$this->product('400.00'), '1']]), '100.00');
        [$larger, $smaller] = $sale->items()->orderBy('line_total', 'desc')->get()->all();

        $shares = ReturnValuation::shares($sale, $sale->items()->get());
        $this->assertSame('60.00', $shares[$larger->id]);
        $this->assertSame('40.00', $shares[$smaller->id]);

        // Three equal lines and a discount that does not divide: the leftover kobo go to the
        // lowest line ids, so the shares always sum to the discount and always land the same way.
        $even = $this->discount($this->sale([[$this->product('100.00'), '1'], [$this->product('100.00'), '1'], [$this->product('100.00'), '1']]), '0.02');
        $lines = $even->items()->orderBy('id')->get();
        $evenShares = ReturnValuation::shares($even, $lines);
        $this->assertSame(['0.01', '0.01', '0.00'], array_values(array_map(fn ($id) => $evenShares[$id], $lines->pluck('id')->all())));
        $this->assertSame($evenShares, ReturnValuation::shares($even->fresh(), $even->items()->get()));
    }

    // ── Settlement on discounted Sales ──────────────────────────────────────────────────────────

    public function test_partial_and_full_returns_on_a_paid_discounted_sale_never_exceed_what_was_paid(): void
    {
        // Two at ₦500 with ₦100 off, paid ₦900 after the approval.
        $sale = $this->discount($this->sale([[$this->product('500.00'), '2']], '900.00'), '100.00');
        $item = $sale->items()->sole();
        $this->assertSame('900.00', $sale->total_amount);

        $this->assertSame('450.00', $this->return($sale, $item, '1')->merchandise_value);
        $this->assertSame('450.00', $this->return($sale, $item, '1')->merchandise_value);

        $sale->refresh();
        $this->assertSame('900.00', $sale->returned_amount, 'a full return is exactly the discounted total');
        $this->assertSame('900.00', $sale->refundable_credit, 'never more than the customer paid');

        $this->refund($sale, '900.00');
        try {
            $this->refund($sale->fresh(), '0.01');
            $this->fail('Nothing beyond what was paid may be refunded.');
        } catch (ValidationException) {
            $this->assertSame('0.00', $sale->fresh()->refundable_credit);
        }
    }

    public function test_an_unpaid_discounted_sale_returned_in_full_creates_no_credit(): void
    {
        $sale = $this->discount($this->sale([[$this->product('500.00'), '2']]), '100.00');
        $return = $this->return($sale, $sale->items()->sole(), '2');

        $this->assertSame('900.00', $return->merchandise_value);
        $this->assertSame('900.00', $return->receivable_reduction);
        $this->assertSame('0.00', $return->refundable_credit_created);
        $this->assertSame('0.00', $sale->fresh()->refundable_credit);
        $this->assertSame('0.00', $sale->fresh()->balance_due);
    }

    public function test_a_part_paid_discounted_sale_reduces_the_balance_before_creating_credit(): void
    {
        $sale = $this->discount($this->sale([[$this->product('500.00'), '2']], '600.00'), '100.00');
        $return = $this->return($sale, $sale->items()->sole(), '2');

        $this->assertSame('900.00', $return->merchandise_value);
        $this->assertSame('300.00', $return->receivable_reduction, 'the balance still owed is cancelled first');
        $this->assertSame('600.00', $return->refundable_credit_created, 'only what was paid can come back');
    }

    public function test_each_line_returns_at_its_own_allocated_net_value(): void
    {
        $sale = $this->discount($this->sale([[$this->product('600.00'), '1'], [$this->product('400.00'), '1']]), '100.00');
        [$larger, $smaller] = $sale->items()->orderBy('line_total', 'desc')->get()->all();

        $this->assertSame('540.00', $this->return($sale, $larger, '1')->merchandise_value);
        $this->assertSame('360.00', $this->return($sale, $smaller, '1')->merchandise_value);
        $this->assertSame('900.00', $sale->fresh()->returned_amount);
    }

    public function test_a_discount_approved_before_the_sale_is_returned_the_same_way(): void
    {
        $product = $this->product('500.00');
        $today = CarbonImmutable::now(config('business.timezone'))->toDateString();
        $cart = [['product_id' => $product->id, 'quantity' => '2']];
        $draft = $this->actingAs($this->manager)->postJson(route('sales.discounts.draft.store'), [
            'amount' => '100', 'reason' => 'Agreed bulk price for this order.', 'is_walk_in' => false,
            'customer_id' => $this->customer->id, 'sale_date' => $today, 'products' => $cart,
        ])->assertCreated();
        app(DecideSaleDiscount::class)->approve($this->admin, SaleDiscountRequest::query()->find($draft->json('discount_request_id')));
        $this->actingAs($this->manager)->post(route('sales.store'), $this->withSaleToken([
            'is_walk_in' => '0', 'customer_id' => $this->customer->id, 'sale_date' => $today, 'products' => $cart,
            'amount_paid' => '900.00', 'sale_draft_id' => $draft->json('sale_draft_id'),
        ]))->assertSessionHasNoErrors();

        $sale = Sale::query()->sole();
        $this->assertSame('450.00', $this->return($sale, $sale->items()->sole(), '1')->merchandise_value);
    }

    // ── Rounding ────────────────────────────────────────────────────────────────────────────────

    public function test_rounded_partial_returns_always_add_up_to_the_line(): void
    {
        // ₦100 off three litres at ₦1,000: a net ₦2,900 that a third of does not divide evenly.
        $sale = $this->discount($this->sale([[$this->product('1000.00', ProductUnit::Litre), '3']]), '100.00');
        $item = $sale->items()->sole();

        $values = array_map(fn (string $quantity): string => $this->return($sale, $item, $quantity)->merchandise_value, ['1', '1', '1']);
        $this->assertSame(['966.67', '966.67', '966.66'], $values);
        $this->assertSame('2900.00', $sale->fresh()->returned_amount);

        // Without a discount, halves that each round up still end on the exact line total.
        $plain = $this->sale([[$this->product('1.25', ProductUnit::Litre), '1']]);
        $line = $plain->items()->sole();
        $this->assertSame('0.63', $this->return($plain, $line, '0.5')->merchandise_value);
        $this->assertSame('0.62', $this->return($plain, $line, '0.5')->merchandise_value);
        $this->assertSame('1.25', $plain->fresh()->returned_amount);
    }

    public function test_a_sale_without_a_discount_is_valued_exactly_as_before(): void
    {
        $sale = $this->sale([[$this->product('42000.00'), '3']], '126000.00');
        $item = $sale->items()->sole();

        $return = $this->return($sale, $item, '1');
        $this->assertSame('42000.00', $return->merchandise_value);
        $row = $return->items()->sole();
        $this->assertSame('42000.00', $row->gross_line_value);
        $this->assertSame('0.00', $row->discount_share);
    }

    // ── Zero-value goods-only returns ───────────────────────────────────────────────────────────

    public function test_fully_discounted_goods_return_as_goods_only_and_can_never_be_refunded(): void
    {
        $product = $this->product('500.00');
        $sale = $this->discount($this->sale([[$product, '2']], '0.00'), '1000.00');
        $this->assertSame('0.00', $sale->total_amount);

        $return = $this->return($sale, $sale->items()->sole(), '2');

        $this->assertSame('0.00', $return->merchandise_value);
        $this->assertSame('0.00', $return->refundable_credit_created);
        $this->assertSame('100.000', $product->fresh()->current_stock, 'the goods came back');
        $row = $return->items()->sole();
        $this->assertSame('1000.00', $row->gross_line_value);
        $this->assertSame('1000.00', $row->discount_share);

        $this->expectException(ValidationException::class);
        $this->refund($sale, '0.01');
    }

    public function test_goods_sold_for_nothing_without_a_discount_are_still_refused(): void
    {
        $sale = $this->sale([[$this->product('0.00'), '1']]);

        $this->expectExceptionMessage('Returned merchandise must have a positive value.');
        $this->return($sale, $sale->items()->sole(), '1');
    }

    // ── Receipt ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_receipt_separates_the_balance_reduction_from_refundable_credit(): void
    {
        $partPaid = $this->discount($this->sale([[$this->product('500.00'), '2']], '600.00'), '100.00');
        $return = $this->return($partPaid, $partPaid->items()->sole(), '2');

        $this->actingAs($this->admin)->get(route('returns.receipt', $return))->assertOk()
            ->assertSeeInOrder(['Returned value', '₦900.00', 'Reduces balance owed', '₦300.00', 'Refundable credit', '₦600.00'])
            ->assertSee('Refundable credit is paid back separately, as a refund.')
            ->assertSee('after discount');

        $free = $this->discount($this->sale([[$this->product('500.00'), '1']]), '500.00');
        $goodsOnly = $this->return($free, $free->items()->sole(), '1');

        $this->actingAs($this->admin)->get(route('returns.receipt', $goodsOnly))->assertOk()
            ->assertSee('These goods were fully discounted, so no money changes hands.')
            ->assertDontSee('paid back separately');
    }

    // ── Preview, snapshots and evidence ─────────────────────────────────────────────────────────

    public function test_the_preview_values_a_discounted_return_exactly_as_it_is_recorded(): void
    {
        $sale = $this->discount($this->sale([[$this->product('1000.00', ProductUnit::Litre), '3']], '1000.00'), '100.00');
        $item = $sale->items()->sole();

        foreach (['1', '1.5', '0.5'] as $quantity) {
            $preview = ReturnSettlementPreview::for($sale->fresh(), [['sale_item_id' => $item->id, 'quantity' => $quantity]]);
            $return = $this->return($sale, $item, $quantity);

            $this->assertSame($preview['merchandise'], $return->merchandise_value);
            $this->assertSame($preview['reduction'], $return->receivable_reduction);
            $this->assertSame($preview['credit'], $return->refundable_credit_created);
        }
    }

    public function test_the_database_ties_each_snapshot_to_the_value_it_explains(): void
    {
        $sale = $this->discount($this->sale([[$this->product('500.00'), '2']]), '100.00');
        $row = $this->return($sale, $sale->items()->sole(), '1')->items()->sole();
        $this->assertSame('500.00', $row->gross_line_value);
        $this->assertSame('50.00', $row->discount_share);
        $this->assertSame('450.00', $row->return_line_value);

        $this->expectException(QueryException::class);
        DB::table('sale_return_items')->where('id', $row->id)->update(['discount_share' => '10.00']);
    }

    public function test_the_reconciliation_check_reports_returns_valued_before_net_valuation_and_changes_nothing(): void
    {
        $sale = $this->discount($this->sale([[$this->product('500.00'), '2']], '0.00'), '100.00');
        $item = $sale->items()->sole();
        $this->return($sale, $item, '1');

        // A row recorded before discount-aware valuation: the gross price, and no snapshots.
        DB::table('sale_return_items')->where('sale_item_id', $item->id)
            ->update(['return_line_value' => '500.00', 'gross_line_value' => null, 'discount_share' => null]);
        $before = DB::table('sale_return_items')->get()->toJson().DB::table('sales')->get()->toJson();

        $this->artisan('inventra:check-return-valuations')
            ->expectsOutputToContain("Sale {$sale->sale_number}: recorded return value exceeds the net value by 50.00")
            ->expectsOutput('Discounted Sales with returns valued before discount-aware valuation: 1')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('sale_return_items')->get()->toJson().DB::table('sales')->get()->toJson(), 'it reports and never repairs');
    }
}
