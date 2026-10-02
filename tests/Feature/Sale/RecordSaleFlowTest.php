<?php

namespace Tests\Feature\Sale;

use App\Actions\Sale\DecideSaleDiscount;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\SaleDraft;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Record Sale journey as the browser drives it.
 *
 * These go through real routes with the payload the page actually posts, so they cover the wiring
 * between the screen and the approved backend: what the form submits, what the endpoints answer,
 * and which of those the server refuses to take on trust.
 */
class RecordSaleFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $rep;

    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->product = Product::factory()->create(['selling_price' => '1000.00', 'current_stock' => '20.000']);
    }

    private function today(): string
    {
        return CarbonImmutable::now(config('business.timezone'))->toDateString();
    }

    /** The exact shape the Record Sale form posts. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'is_walk_in' => '0',
            'customer_id' => $this->customer->id,
            'sale_date' => $this->today(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
            'amount_paid' => '2000.00',
        ], $overrides);
    }

    /**
     * The Record Sale screen no longer asks how the money arrived.
     *
     * `payment_method` is still a NOT NULL column that every receipt and report reads and that is
     * copied onto each sale_payments row, so it is defaulted rather than dropped: a sale that does
     * not state a method is cash. A caller that does state one is still validated against the enum.
     */
    public function test_a_sale_without_a_payment_method_is_recorded_as_cash(): void
    {
        $page = $this->actingAs($this->rep)->get(route('sales.create'))->assertOk()->getContent();
        $opens = strpos($page, 'class="ui-sale-form"');
        $form = substr($page, $opens, strpos($page, '</form>', $opens) - $opens);

        $this->assertStringNotContainsString('name="payment_method"', $form);
        $this->assertStringNotContainsString('Payment method', $form);

        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $sale = Sale::query()->sole();
        $this->assertSame(PaymentMethod::Cash, $sale->payment_method);
        // The method flows onto the ledger row as it always did.
        $this->assertSame(PaymentMethod::Cash, $sale->payments()->first()->payment_method);
    }

    public function test_an_explicit_payment_method_is_still_honoured_and_validated(): void
    {
        $this->actingAs($this->rep)
            ->post(route('sales.store'), $this->payload(['payment_method' => 'transfer']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(PaymentMethod::Transfer, Sale::query()->sole()->payment_method);

        $this->actingAs($this->rep)
            ->post(route('sales.store'), $this->payload(['payment_method' => 'bitcoin']))
            ->assertSessionHasErrors('payment_method');
    }

    public function test_the_page_renders_its_workflow_states_and_endpoints(): void
    {
        $response = $this->actingAs($this->rep)->get(route('sales.create'))->assertOk();

        $response->assertSee('x-data="recordSale"', false)
            ->assertSee(route('sales.customers.search'), false)
            ->assertSee(route('sales.discounts.draft.store'), false)
            ->assertSee('Waiting for approval')
            ->assertSee('Discount approved')
            ->assertSee('Discount declined')
            ->assertSee('Walk-in customer');

        // Money is never posted as authority; the server derives every figure itself.
        $response->assertDontSee('name="discount_amount"', false)
            ->assertDontSee('name="subtotal"', false)
            ->assertDontSee('name="total_amount"', false);
    }

    public function test_a_registered_sale_posts_and_lands_on_the_completion_state(): void
    {
        $this->actingAs($this->rep)
            ->post(route('sales.store'), $this->payload())
            ->assertRedirect(route('sales.create'))
            ->assertSessionHas('completedSale');

        $sale = Sale::query()->sole();
        $this->assertSame('2000.00', $sale->total_amount);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertFalse($sale->isWalkIn());

        // The success modal is rendered from the recorded Sale, not from anything the form said.
        $this->actingAs($this->rep)->get(route('sales.create'))->assertOk()
            ->assertSee('Sale complete')
            ->assertSee($sale->sale_number);
    }

    public function test_a_walk_in_sale_records_without_a_customer_and_offers_no_whatsapp(): void
    {
        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload([
            'is_walk_in' => '1',
            'customer_id' => null,
        ]))->assertRedirect(route('sales.create'));

        $sale = Sale::query()->sole();
        $this->assertTrue($sale->isWalkIn());
        $this->assertNull($sale->customer_id);

        $this->actingAs($this->rep)->get(route('sales.create'))->assertOk()
            // The completion modal reports the walk-in state truthfully and offers no send action,
            // because there is no number and a recorded Sale's buyer identity cannot be changed.
            ->assertSee('no customer number to send to', false)
            ->assertSee('ui-complete-whatsapp is-disabled', false)
            ->assertDontSee('Add customer &amp; consent', false);
    }

    public function test_partial_and_unpaid_sales_record_the_balance_they_claim(): void
    {
        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload(['amount_paid' => '500.00']))
            ->assertRedirect();
        $partial = Sale::query()->sole();
        $this->assertSame(PaymentStatus::Partial, $partial->payment_status);
        $this->assertSame('1500.00', $partial->balance_due);

        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload(['amount_paid' => '0.00']))
            ->assertRedirect();
        $unpaid = Sale::query()->latest('id')->first();
        $this->assertSame(PaymentStatus::Unpaid, $unpaid->payment_status);
        $this->assertSame('2000.00', $unpaid->balance_due);
    }

    public function test_the_server_refuses_a_future_sale_date_and_an_oversell(): void
    {
        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload([
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->addDay()->toDateString(),
        ]))->assertSessionHasErrors('sale_date');

        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload([
            'products' => [['product_id' => $this->product->id, 'quantity' => '999']],
            'amount_paid' => '0.00',
        ]))->assertSessionHasErrors();

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_the_discount_round_trip_runs_from_request_to_approved_sale(): void
    {
        // Requesting creates a draft and a pending request — and no Sale, no stock movement.
        $stockBefore = $this->product->current_stock;
        $response = $this->actingAs($this->rep)->postJson(route('sales.discounts.draft.store'), [
            'amount' => '300',
            'reason' => 'Regular fleet customer discount',
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => $this->today(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
        ])->assertCreated();

        $requestId = $response->json('discount_request_id');
        $draftId = $response->json('sale_draft_id');
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame($stockBefore, $this->product->fresh()->current_stock);

        // The page polls this while it waits.
        $this->actingAs($this->rep)->getJson(route('sales.discounts.draft.status', $requestId))
            ->assertOk()->assertJsonPath('status', 'pending');

        app(DecideSaleDiscount::class)->approve($this->admin, SaleDiscountRequest::query()->find($requestId), null);

        $this->actingAs($this->rep)->getJson(route('sales.discounts.draft.status', $requestId))
            ->assertOk()->assertJsonPath('status', 'approved')
            ->assertJsonPath('requested_amount', '300.00');

        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload([
            'sale_draft_id' => $draftId,
            'amount_paid' => '1700.00',
        ]))->assertRedirect();

        $sale = Sale::query()->sole();
        $this->assertSame('300.00', $sale->discount_amount);
        $this->assertSame('1700.00', $sale->total_amount);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
    }

    public function test_a_declined_discount_never_reduces_the_total(): void
    {
        $response = $this->actingAs($this->rep)->postJson(route('sales.discounts.draft.store'), [
            'amount' => '300',
            'reason' => 'Asking for a discount that is refused',
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => $this->today(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
        ])->assertCreated();

        app(DecideSaleDiscount::class)->decline(
            $this->admin,
            SaleDiscountRequest::query()->find($response->json('discount_request_id')),
            'Margin is already thin on this line.',
        );

        // Submitting the declined draft is refused outright; the sale must be recorded at full price.
        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload([
            'sale_draft_id' => $response->json('sale_draft_id'),
        ]))->assertSessionHasErrors();

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_changing_the_cart_after_approval_is_refused_by_the_server(): void
    {
        $response = $this->actingAs($this->rep)->postJson(route('sales.discounts.draft.store'), [
            'amount' => '300',
            'reason' => 'Approved against one specific cart',
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => $this->today(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
        ])->assertCreated();

        app(DecideSaleDiscount::class)->approve(
            $this->admin,
            SaleDiscountRequest::query()->find($response->json('discount_request_id')),
            null,
        );

        // A different quantity is a different sale, whatever the page believes.
        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload([
            'sale_draft_id' => $response->json('sale_draft_id'),
            'products' => [['product_id' => $this->product->id, 'quantity' => '3']],
            'amount_paid' => '0.00',
        ]))->assertSessionHasErrors();

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_saved_pending_sale_can_be_resumed_from_its_draft(): void
    {
        $response = $this->actingAs($this->rep)->postJson(route('sales.discounts.draft.store'), [
            'amount' => '250',
            'reason' => 'Left waiting and picked up later',
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => $this->today(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
        ])->assertCreated();

        // The wait was abandoned; the request and its cart are still on record for this user.
        $resumable = $this->actingAs($this->rep)->getJson(route('sales.discounts.draft.resumable'))->assertOk();
        $resumable->assertJsonPath('drafts.0.sale_draft_id', $response->json('sale_draft_id'))
            ->assertJsonPath('drafts.0.status', 'pending')
            ->assertJsonPath('drafts.0.lines.0.product_id', $this->product->id);

        // Another user's drafts are not offered.
        $other = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->actingAs($other)->getJson(route('sales.discounts.draft.resumable'))
            ->assertOk()->assertJsonCount(0, 'drafts');
    }

    public function test_a_consumed_draft_is_no_longer_offered_for_resuming(): void
    {
        $response = $this->actingAs($this->rep)->postJson(route('sales.discounts.draft.store'), [
            'amount' => '250',
            'reason' => 'Spent once and then finished',
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => $this->today(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
        ])->assertCreated();

        app(DecideSaleDiscount::class)->approve(
            $this->admin,
            SaleDiscountRequest::query()->find($response->json('discount_request_id')),
            null,
        );

        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload([
            'sale_draft_id' => $response->json('sale_draft_id'),
            'amount_paid' => '1750.00',
        ]))->assertRedirect();

        $this->assertNotNull(SaleDraft::query()->find($response->json('sale_draft_id'))->consumed_by_sale_id);
        $this->actingAs($this->rep)->getJson(route('sales.discounts.draft.resumable'))
            ->assertOk()->assertJsonCount(0, 'drafts');
    }

    public function test_the_customer_search_returns_only_active_customers(): void
    {
        Customer::factory()->create(['first_name' => 'Retired', 'last_name' => 'Account', 'is_active' => false]);

        $this->actingAs($this->rep)->getJson(route('sales.customers.search', ['q' => 'Retired']))
            ->assertOk()->assertJsonCount(0, 'customers');

        $this->actingAs($this->rep)->getJson(route('sales.customers.search', ['q' => $this->customer->first_name]))
            ->assertOk()->assertJsonPath('customers.0.id', $this->customer->id);
    }

    public function test_the_product_picker_never_offers_an_archived_product_or_its_cost(): void
    {
        Product::factory()->create(['name' => 'Retired Filter', 'is_active' => false, 'cost_price' => '400.00']);

        $body = $this->actingAs($this->rep)
            ->getJson(route('sales.create', ['product_search' => 'Retired']))
            ->assertOk()->json();

        $this->assertCount(0, $body['products']);

        $all = $this->actingAs($this->rep)->getJson(route('sales.create'))->assertOk()->json();
        $this->assertStringNotContainsString('cost', json_encode($all));
    }

    /**
     * Quantities are stored as decimal:3 and arrive as "40.000". Nothing a user reads should say
     * that: stock, sold quantities and line items are trimmed for display only. Trimming never
     * rounds, so a genuinely fractional quantity keeps its decimals.
     */
    public function test_quantities_are_displayed_without_trailing_zeros(): void
    {
        $body = $this->actingAs($this->rep)->getJson(route('sales.create'))->assertOk()->json();
        $stock = collect($body['products'])->firstWhere('id', $this->product->id)['stock'];

        $this->assertSame('20', $stock, 'Stock of 20.000 must read as 20 in the product picker.');
        $this->assertStringNotContainsString('.000', json_encode($body));

        // A fractional quantity keeps its decimals — trimming is not rounding.
        $half = Product::factory()->create(['name' => 'Cut Hose', 'current_stock' => '0.500']);
        $fractional = $this->actingAs($this->rep)
            ->getJson(route('sales.create', ['product_search' => 'Cut Hose']))
            ->assertOk()->json('products.0.stock');
        $this->assertSame('0.5', $fractional);
        $this->assertSame($half->id, (int) $this->actingAs($this->rep)
            ->getJson(route('sales.create', ['product_search' => 'Cut Hose']))->json('products.0.id'));

        // And the recorded sale's own line reads the same way on the receipt.
        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload())->assertRedirect();
        $sale = Sale::query()->sole();

        $this->actingAs($this->rep)->get(route('sales.receipt', $sale))->assertOk()
            ->assertDontSee('2.000');
    }

    /**
     * A grouped amount is money, not a different number.
     *
     * The form used to post the display rendering of the total — "12,500.00" — which `decimal:0,2`
     * rejects, so any sale of a thousand naira or more failed on a figure the page itself had
     * produced. The field now carries a plain decimal, and the request normalises grouping as a
     * second line of defence for anything typed by hand.
     */
    public static function amountPaidProvider(): array
    {
        return [
            'zero' => ['0', true],
            'zero with decimals' => ['0.00', true],
            'one decimal' => ['0.0', true],
            'whole naira' => ['5', true],
            'half naira' => ['5.5', true],
            'two decimals' => ['5.50', true],
            'thousands' => ['5000', true],
            'thousands with decimals' => ['5000.00', true],
            'kobo' => ['12.34', true],
            'grouped' => ['12,500.00', true],
            'grouped with symbol' => ['₦12,500.00', true],
            'three decimals' => ['12.345', false],
            'negative' => ['-5.00', false],
            'decimal comma' => ['0,00', false],
            'text' => ['abc', false],
            'scientific' => ['1e3', false],
        ];
    }

    #[DataProvider('amountPaidProvider')]
    public function test_amount_paid_accepts_money_and_rejects_everything_else(string $amount, bool $valid): void
    {
        // Priced so every accepted amount is at or below the total and none is an overpayment.
        $product = Product::factory()->create(['selling_price' => '12500.00', 'current_stock' => '50.000']);

        $response = $this->actingAs($this->rep)->post(route('sales.store'), $this->payload([
            'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'amount_paid' => $amount,
        ]));

        if ($valid) {
            $response->assertSessionDoesntHaveErrors('amount_paid');
        } else {
            $response->assertSessionHasErrors('amount_paid');
        }
    }

    /** A rejected submission comes back with the sale intact, rebuilt from current records. */
    public function test_a_rejected_submission_restores_the_operators_work(): void
    {
        $second = Product::factory()->create(['name' => 'Oil Filter', 'selling_price' => '2500.00', 'current_stock' => '30.000']);

        // Rejected on the sale date, so everything else is valid and must survive.
        $this->actingAs($this->rep)->from(route('sales.create'))
            ->post(route('sales.store'), $this->payload([
                'sale_date' => CarbonImmutable::now(config('business.timezone'))->addDay()->toDateString(),
                'products' => [
                    ['product_id' => $this->product->id, 'quantity' => '3'],
                    ['product_id' => $second->id, 'quantity' => '2'],
                ],
                'amount_paid' => '1500.00',
            ]))
            ->assertRedirect(route('sales.create'))
            ->assertSessionHasErrors('sale_date');

        $restored = $this->actingAs($this->rep)->get(route('sales.create'))->assertOk()->viewData('restored');

        $this->assertNotNull($restored, 'A rejected submission must come back with its input.');
        $this->assertFalse($restored['isWalkIn']);
        $this->assertSame($this->customer->id, $restored['customer']['id']);
        $this->assertCount(2, $restored['lines']);
        $this->assertSame($this->product->id, $restored['lines'][0]['product']['id']);
        $this->assertSame('3', $restored['lines'][0]['quantity']);
        $this->assertSame($second->id, $restored['lines'][1]['product']['id']);
        $this->assertSame('2', $restored['lines'][1]['quantity']);
        $this->assertSame('1500.00', $restored['amountPaid']);
        $this->assertNotNull($restored['saleDate']);
    }

    public function test_a_rejected_walk_in_submission_restores_walk_in_and_no_customer(): void
    {
        $this->actingAs($this->rep)->from(route('sales.create'))
            ->post(route('sales.store'), $this->payload([
                'is_walk_in' => '1',
                'customer_id' => null,
                'sale_date' => CarbonImmutable::now(config('business.timezone'))->addDay()->toDateString(),
            ]))->assertSessionHasErrors('sale_date');

        $restored = $this->actingAs($this->rep)->get(route('sales.create'))->assertOk()->viewData('restored');

        $this->assertTrue($restored['isWalkIn']);
        $this->assertNull($restored['customer'], 'A walk-in has no customer to restore.');
    }

    /**
     * Prices, names and stock come back from the database, never from the browser. A price the user
     * could have edited must not reappear as though the server had agreed to it.
     */
    public function test_restored_rows_carry_authoritative_prices_not_client_ones(): void
    {
        $this->actingAs($this->rep)->from(route('sales.create'))
            ->post(route('sales.store'), $this->payload([
                'sale_date' => CarbonImmutable::now(config('business.timezone'))->addDay()->toDateString(),
                'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
            ]))->assertSessionHasErrors('sale_date');

        // The catalogue moves between attempts.
        $this->product->forceFill(['selling_price' => '1750.00', 'name' => 'Renamed Pad'])->saveQuietly();

        $restored = $this->actingAs($this->rep)->get(route('sales.create'))->assertOk()->viewData('restored');

        $this->assertSame('1750.00', $restored['lines'][0]['product']['price']);
        $this->assertSame('Renamed Pad', $restored['lines'][0]['product']['name']);
        $this->assertSame('20', $restored['lines'][0]['product']['stock']);
    }

    /** A product archived between attempts is not offered back — it cannot be sold. */
    public function test_a_product_archived_between_attempts_is_not_restored(): void
    {
        $this->actingAs($this->rep)->from(route('sales.create'))
            ->post(route('sales.store'), $this->payload([
                'sale_date' => CarbonImmutable::now(config('business.timezone'))->addDay()->toDateString(),
            ]))->assertSessionHasErrors('sale_date');

        $this->product->forceFill(['is_active' => false])->saveQuietly();

        $restored = $this->actingAs($this->rep)->get(route('sales.create'))->assertOk()->viewData('restored');

        $this->assertSame([], $restored['lines']);
        $this->assertNull($restored['saleDraftId'], 'A draft cannot survive a cart that could not be restored.');
    }

    /** A customer deactivated between attempts is dropped rather than silently resubmitted. */
    public function test_a_customer_deactivated_between_attempts_is_not_restored(): void
    {
        $this->actingAs($this->rep)->from(route('sales.create'))
            ->post(route('sales.store'), $this->payload([
                'sale_date' => CarbonImmutable::now(config('business.timezone'))->addDay()->toDateString(),
            ]))->assertSessionHasErrors('sale_date');

        $this->customer->forceFill(['is_active' => false])->saveQuietly();

        $restored = $this->actingAs($this->rep)->get(route('sales.create'))->assertOk()->viewData('restored');

        $this->assertNull($restored['customer']);
    }

    /** Fixing the money rule must not make an empty sale recordable. */
    public function test_an_empty_sale_is_still_rejected(): void
    {
        $this->actingAs($this->rep)->post(route('sales.store'), [
            'is_walk_in' => '0',
            'customer_id' => null,
            'sale_date' => $this->today(),
            'products' => [],
            'amount_paid' => '0.00',
        ])->assertSessionHasErrors(['customer_id', 'products']);

        $this->assertSame(0, Sale::query()->count());

        // A walk-in with no lines is equally not a sale.
        $this->actingAs($this->rep)->post(route('sales.store'), [
            'is_walk_in' => '1',
            'sale_date' => $this->today(),
            'products' => [],
            'amount_paid' => '0.00',
        ])->assertSessionHasErrors('products');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_the_receipt_pdf_is_served_to_a_viewer_and_withheld_from_others(): void
    {
        $this->actingAs($this->rep)->post(route('sales.store'), $this->payload())->assertRedirect();
        $sale = Sale::query()->sole();

        $response = $this->actingAs($this->rep)->get(route('sales.receipt.pdf', $sale))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // A different Sales Rep may not read someone else's sale, in PDF any more than on screen.
        $other = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->actingAs($other)->get(route('sales.receipt.pdf', $sale))->assertForbidden();
    }
}
