<?php

namespace Tests\Feature\Dashboard;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The navbar type-ahead.
 *
 * The properties worth protecting are about disclosure, not matching. A search box is an easy place
 * to leak a field nobody meant to publish, so these assert the shape of the payload as firmly as
 * its contents: each half gated by its own policy, only the columns the dropdown draws, and
 * `cost_price` — which ProductPolicy::viewCost governs elsewhere — never present at all.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    private function search(string $term, ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->admin)
            ->getJson(route('search', ['q' => $term]))
            ->assertOk()
            ->json();
    }

    // ── Customers ───────────────────────────────────────────────────────────────────────────────

    public function test_a_customer_is_found_by_name_and_returns_only_display_fields(): void
    {
        Customer::factory()->create(['first_name' => 'Emeka', 'last_name' => 'Obi', 'phone' => '+2348012345678']);

        $result = $this->search('Emeka');

        $this->assertCount(1, $result['customers']);
        $customer = $result['customers'][0];

        $this->assertSame('Emeka Obi', $customer['name']);
        $this->assertSame('+2348012345678', $customer['phone']);
        $this->assertSame('EO', $customer['initials']);
        // Exactly the keys the dropdown draws — no address, notes, consent or internal ids.
        $this->assertEqualsCanonicalizing(['name', 'phone', 'initials', 'url'], array_keys($customer));
    }

    public function test_a_customer_is_found_by_phone(): void
    {
        Customer::factory()->create(['first_name' => 'Ada', 'phone' => '+2348099998888']);

        $this->assertCount(1, $this->search('+234809')['customers']);
    }

    public function test_an_inactive_customer_is_not_suggested(): void
    {
        Customer::factory()->create(['first_name' => 'Hidden', 'is_active' => false]);

        $this->assertSame([], $this->search('Hidden')['customers']);
    }

    // ── Products ────────────────────────────────────────────────────────────────────────────────

    public function test_a_product_is_found_by_name_with_price_sku_and_stock(): void
    {
        Product::factory()->create([
            'name' => 'Camry oil filter', 'sku' => 'FLT-0921',
            'selling_price' => '3500.00', 'current_stock' => '42.000', 'cost_price' => '1200.00',
        ]);

        $result = $this->search('Camry');

        $this->assertCount(1, $result['products']);
        $product = $result['products'][0];

        $this->assertSame('Camry oil filter', $product['name']);
        $this->assertSame('FLT-0921', $product['sku']);
        $this->assertSame('42', $product['stock']);
        $this->assertTrue($product['inStock']);
        $this->assertEqualsCanonicalizing(['name', 'sku', 'price', 'stock', 'inStock', 'url'], array_keys($product));
    }

    /**
     * A product is found by a word anywhere in its name.
     *
     * Names are multi-word, so an operator typing "filter" means the word wherever it falls. A
     * prefix-only match returned nothing for exactly this case.
     */
    public function test_a_product_is_found_by_a_word_inside_its_name(): void
    {
        Product::factory()->create(['name' => 'Camry oil filter']);
        Product::factory()->create(['name' => 'Air filter']);
        Product::factory()->create(['name' => 'Brake pads']);

        $names = array_column($this->search('filter')['products'], 'name');

        $this->assertEqualsCanonicalizing(['Air filter', 'Camry oil filter'], $names);
    }

    public function test_a_product_is_found_by_sku(): void
    {
        Product::factory()->create(['name' => 'Air filter', 'sku' => 'AIR-3302']);

        $this->assertCount(1, $this->search('AIR-33')['products']);
    }

    /**
     * Cost price never reaches the dropdown.
     *
     * It is governed by ProductPolicy::viewCost on the pages that show it; a search box that
     * returned it would quietly route around that policy for anyone who can search.
     */
    public function test_cost_price_is_never_returned(): void
    {
        Product::factory()->create(['name' => 'Costly item', 'cost_price' => '98765.00', 'selling_price' => '1.00']);

        $body = json_encode($this->search('Costly'));

        $this->assertStringNotContainsString('98765', $body);
        $this->assertStringNotContainsString('cost_price', $body);
    }

    public function test_an_out_of_stock_product_is_flagged_rather_than_hidden(): void
    {
        Product::factory()->create(['name' => 'Fuel filter', 'current_stock' => '0.000']);

        $this->assertFalse($this->search('Fuel')['products'][0]['inStock']);
    }

    // ── Shape and limits ────────────────────────────────────────────────────────────────────────

    public function test_results_are_limited(): void
    {
        foreach (range(1, 12) as $index) {
            Product::factory()->create(['name' => 'Widget '.$index]);
            Customer::factory()->create(['first_name' => 'Widgetina', 'last_name' => 'N'.$index]);
        }

        $result = $this->search('Widget');

        $this->assertLessThanOrEqual(5, count($result['products']));
        $this->assertLessThanOrEqual(5, count($result['customers']));
    }

    public function test_a_very_short_query_returns_nothing_rather_than_the_whole_table(): void
    {
        Product::factory()->create(['name' => 'Anything']);

        $result = $this->search('A');

        $this->assertSame([], $result['products']);
        $this->assertSame([], $result['customers']);
    }

    public function test_an_over_long_query_is_rejected_by_validation(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('search', ['q' => str_repeat('a', 300)]))
            ->assertStatus(422);
    }

    /** LIKE wildcards typed by a caller match themselves rather than everything. */
    public function test_like_wildcards_in_the_query_are_escaped(): void
    {
        Product::factory()->create(['name' => 'Regular product']);

        $this->assertSame([], $this->search('%%')['products']);
    }

    public function test_the_query_is_reflected_verbatim_for_the_view_to_escape(): void
    {
        $result = $this->search('<script>');

        // Returned as data, not markup: JSON encoding plus the view's own escaping handle it.
        $this->assertSame('<script>', $result['query']);
    }

    // ── Authorization ───────────────────────────────────────────────────────────────────────────

    public function test_a_guest_cannot_search(): void
    {
        $this->getJson(route('search', ['q' => 'anything']))->assertUnauthorized();
    }

    /**
     * Each half is gated independently.
     *
     * A role that may look up a customer but not browse the catalogue gets the half it is entitled
     * to, and `searched` reports which halves were actually looked in — which is what lets the
     * no-results copy stay truthful.
     */
    public function test_each_half_is_gated_by_its_own_policy(): void
    {
        Customer::factory()->create(['first_name' => 'Findable']);
        Product::factory()->create(['name' => 'Findable thing']);

        foreach ([UserRole::Admin, UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $result = $this->search('Find', $user);

            $this->assertSame(
                $user->can('viewAny', Customer::class) ? 1 : 0,
                count($result['customers']),
                "customers for {$role->value}"
            );
            $this->assertSame(
                $user->can('viewAny', Product::class) ? 1 : 0,
                count($result['products']),
                "products for {$role->value}"
            );
            $this->assertSame(
                $user->can('viewAny', Customer::class),
                in_array('customers', $result['searched'], true)
            );
        }
    }

    /** The create-customer affordance follows the policy, not the query. */
    public function test_the_add_customer_affordance_follows_the_create_policy(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->assertSame(
                $user->can('create', Customer::class),
                $this->search('Nobody', $user)['canCreateCustomer'],
                "create affordance for {$role->value}"
            );
        }
    }

    // ── Prefill ─────────────────────────────────────────────────────────────────────────────────

    /** The name carried from the navbar reaches the existing form through its normal old() path. */
    public function test_the_customer_create_form_prefills_the_searched_name(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('customers.create', ['name' => 'Chidi Eze']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="Chidi Eze"', $html);
    }

    public function test_a_hostile_prefill_is_escaped_not_executed(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('customers.create', ['name' => '"><script>alert(1)</script>']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('"><script>alert(1)</script>', $html);
    }

    // ── Search box in the shell ─────────────────────────────────────────────────────────────────

    public function test_the_navbar_renders_an_accessible_search_box(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Search customers and products', $html);
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString(route('search'), $html);
    }
}
