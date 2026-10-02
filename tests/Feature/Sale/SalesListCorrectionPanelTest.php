<?php

namespace Tests\Feature\Sale;

use App\Actions\Sale\CreateSale;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a Sale from the Sales list.
 *
 * The panel is a different wrapper around the same correction flow, so these check the wiring —
 * who may open it, what happens to a rejected submission, and where a successful one lands — while
 * SaleCorrectionTest continues to own the domain rules themselves.
 */
class SalesListCorrectionPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $rep;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->customer = Customer::factory()->create(['first_name' => 'Emeka', 'last_name' => 'Obi', 'is_active' => true]);
        $this->product = Product::factory()->create(['selling_price' => '12500.00', 'current_stock' => '50.000']);
    }

    private function sale(?User $seller = null): Sale
    {
        return app(CreateSale::class)->execute($seller ?? $this->admin, [
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '4']],
            'payment_method' => 'cash',
            'amount_paid' => '0.00',
        ]);
    }

    public function test_the_correction_panel_fragment_respects_the_same_policy_as_the_page(): void
    {
        $adminSale = $this->sale($this->admin);
        $managerSale = $this->sale($this->manager);

        // Admin: any eligible sale.
        $this->actingAs($this->admin)->get(route('sales.corrections.panel', $adminSale))
            ->assertOk()->assertSee('Correct quantity')->assertSee('Reason for the correction');

        // Manager: own only.
        $this->actingAs($this->manager)->get(route('sales.corrections.panel', $managerSale))->assertOk();
        $this->actingAs($this->manager)->get(route('sales.corrections.panel', $adminSale))->assertForbidden();

        // Sales Rep: never.
        $this->actingAs($this->rep)->get(route('sales.corrections.panel', $adminSale))->assertForbidden();
        $this->actingAs($this->rep)->get(route('sales.corrections.panel', $managerSale))->assertForbidden();
    }

    /** The fragment must never offer a field the correction request prohibits. */
    public function test_the_panel_fragment_exposes_no_immutable_field(): void
    {
        $sale = $this->sale($this->admin);
        $html = $this->actingAs($this->admin)->get(route('sales.corrections.panel', $sale))->assertOk()->getContent();

        foreach (['amount_paid', 'payment_status', 'sale_number', 'sold_by', 'total_amount', 'discount_amount', 'public_id'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html);
        }

        // What it does carry: quantities, the optional customer, the reason and notes.
        $this->assertStringContainsString('name="products[0][quantity]"', $html);
        $this->assertStringContainsString('name="reason"', $html);
    }

    public function test_a_rejected_correction_returns_to_the_list_and_reopens_the_panel(): void
    {
        $sale = $this->sale($this->admin);

        // Too short a reason: the one rule most likely to be tripped in practice.
        $this->actingAs($this->admin)->from(route('sales.index'))
            ->post(route('sales.corrections.store', $sale), [
                'return_to' => 'index',
                'reason' => 'too short',
                'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
            ])
            ->assertRedirect(route('sales.index'))
            ->assertSessionHasErrors('reason')
            // Names the Sale so the list can reopen its panel on the right row.
            ->assertSessionHas('correctionFailedFor', $sale->public_id);

        // And what the operator typed comes back with them.
        $this->assertSame('too short', session()->getOldInput('reason'));
        $this->assertSame('4.000', $sale->fresh()->items()->first()->quantity);
    }

    public function test_a_successful_correction_from_the_panel_lands_on_the_list_with_a_banner(): void
    {
        $sale = $this->sale($this->admin);

        $this->actingAs($this->admin)
            ->post(route('sales.corrections.store', $sale), [
                'return_to' => 'index',
                'reason' => 'Recorded four by mistake; the customer took two.',
                'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
            ])
            ->assertRedirect(route('sales.index'))
            ->assertSessionHas('saleCorrected');

        $message = session('saleCorrected');
        $this->assertStringContainsString('Emeka Obi', $message);
        $this->assertStringContainsString('50,000.00', $message, 'the total before the correction');
        $this->assertStringContainsString('25,000.00', $message, 'and the total after');

        // The banner renders on the list, auto-dismissing through the shared 7-second component.
        $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()
            ->assertSee('Sale updated')
            ->assertSee('x-data="autoDismiss"', false);

        $this->assertSame('25000.00', $sale->fresh()->total_amount);
    }

    /** A correction started from the Sale page still returns there. */
    public function test_a_correction_from_the_full_page_returns_to_the_sale(): void
    {
        $sale = $this->sale($this->admin);

        $this->actingAs($this->admin)
            ->post(route('sales.corrections.store', $sale), [
                'return_to' => 'show',
                'reason' => 'Recorded four by mistake; the customer took two.',
                'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
            ])
            ->assertRedirect(route('sales.show', $sale))
            ->assertSessionHas('saleCorrected');
    }

    /** `return_to` picks between two fixed routes; it can never be a URL. */
    public function test_return_to_cannot_redirect_anywhere_the_application_did_not_choose(): void
    {
        $sale = $this->sale($this->admin);

        $this->actingAs($this->admin)
            ->post(route('sales.corrections.store', $sale), [
                'return_to' => 'https://example.com/phish',
                'reason' => 'Recorded four by mistake; the customer took two.',
                'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
            ])
            ->assertRedirect(route('sales.show', $sale));
    }
}
