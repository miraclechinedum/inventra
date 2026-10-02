<?php

namespace Tests\Feature\Customers;

use App\Actions\Sale\CreateSale;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Support\ImageStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Customer module: list, form, profile and photographs.
 *
 * The rules these are really protecting: a phone number identifies one customer however it is
 * typed, a WhatsApp destination is not consent, and a photograph is never lost by omission.
 */
class CustomerModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->rep = User::factory()->create(['role' => UserRole::SalesRep]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Emeka',
            'last_name' => 'Obi',
            'phone' => '+2348012345678',
            'whatsapp_same_as_phone' => '1',
            'email' => 'emeka@example.com',
            'tag' => 'Toyota Camry 2012',
        ], $overrides);
    }

    // ── Index ────────────────────────────────────────────────────────────────────────────────

    public function test_the_list_is_behind_the_customer_policy(): void
    {
        $this->actingAs($this->admin)->get(route('customers.index'))->assertOk();
    }

    public function test_the_list_is_closed_to_a_guest(): void
    {
        $this->get(route('customers.index'))->assertRedirect(route('login'));
    }

    /** With no customers at all, the real emptiness is shown — never a placeholder count. */
    public function test_the_empty_state_appears_when_there_are_no_customers(): void
    {
        $this->actingAs($this->admin)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('No customers yet')
            ->assertSee("They'll appear here when you add them or record their first sale.", false)
            ->assertDontSee('248 customers');
    }

    /** An empty search result is a different thing from having no customers. */
    public function test_a_search_with_no_matches_is_not_the_empty_state(): void
    {
        Customer::factory()->create(['first_name' => 'Emeka', 'last_name' => 'Obi']);

        $this->actingAs($this->admin)->get(route('customers.index', ['search' => 'zzzznobody']))
            ->assertOk()
            ->assertSee('No customers match')
            ->assertDontSee('No customers yet');
    }

    public function test_the_list_shows_each_customer_with_their_figures(): void
    {
        $customer = Customer::factory()->create([
            'first_name' => 'Emeka', 'last_name' => 'Obi',
            'phone' => '+2348012345678', 'tag' => 'Toyota Camry 2012',
        ]);

        $this->sale($customer, '2', '10000.00');

        $this->actingAs($this->admin)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Emeka Obi')
            ->assertSee('+2348012345678')
            ->assertSee('Toyota Camry 2012')
            // Two units at ₦10,000, compacted for display.
            ->assertSee('20,000');
    }

    public function test_the_list_finds_a_customer_by_name_phone_or_tag(): void
    {
        Customer::factory()->create([
            'first_name' => 'Ngozi', 'last_name' => 'Eze',
            'phone' => '+2348025550192', 'tag' => 'Hilux 2018',
        ]);
        Customer::factory()->create(['first_name' => 'Someone', 'last_name' => 'Else', 'tag' => null]);

        foreach (['Ngozi', '08025550192', 'Hilux'] as $term) {
            $this->actingAs($this->admin)->get(route('customers.index', ['search' => $term]))
                ->assertOk()
                ->assertSee('Ngozi Eze')
                ->assertDontSee('Someone Else', false);
        }
    }

    public function test_the_list_paginates_ten_to_a_page_and_keeps_its_filters(): void
    {
        Customer::factory()->count(12)->create(['tag' => 'Hilux']);

        $page = $this->actingAs($this->admin)
            ->get(route('customers.index', ['search' => 'Hilux', 'sort' => 'name']))
            ->assertOk();

        $customers = $page->viewData('customers');
        $this->assertSame(10, $customers->perPage());
        $this->assertSame(12, $customers->total());
        // Search and sort survive into the page links.
        $this->assertStringContainsString('search=Hilux', $customers->nextPageUrl());
        $this->assertStringContainsString('sort=name', $customers->nextPageUrl());
    }

    /**
     * S/N numbers the rows continuously, from the paginator rather than the database.
     *
     * Page two starts at 11, not 1, and the number is never the customer's id — which would leak
     * a sequential identifier the application does not otherwise expose.
     */
    public function test_the_serial_number_continues_across_pages(): void
    {
        Customer::factory()->count(12)->create();

        $first = $this->actingAs($this->admin)->get(route('customers.index'))
            ->assertOk()->viewData('customers');
        $this->assertSame(1, $first->firstItem());
        $this->assertSame(10, $first->lastItem());

        $second = $this->actingAs($this->admin)->get(route('customers.index', ['page' => 2]))
            ->assertOk()->viewData('customers');
        $this->assertSame(11, $second->firstItem());
        $this->assertSame(12, $second->lastItem());
    }

    /** Changing the page size renumbers correctly rather than restarting. */
    public function test_the_serial_number_follows_the_page_size(): void
    {
        Customer::factory()->count(30)->create();

        $page = $this->actingAs($this->admin)
            ->get(route('customers.index', ['per_page' => 25, 'page' => 2]))
            ->assertOk()->viewData('customers');

        $this->assertSame(25, $page->perPage());
        $this->assertSame(26, $page->firstItem(), 'the second page of 25 starts at 26');
    }

    /** An empty result has no first item, and the column must not fall over on it. */
    public function test_the_serial_number_survives_an_empty_result(): void
    {
        $this->actingAs($this->admin)->get(route('customers.index', ['search' => 'zzzznobody']))
            ->assertOk();

        $customers = $this->actingAs($this->admin)
            ->get(route('customers.index', ['search' => 'zzzznobody']))
            ->viewData('customers');

        $this->assertNull($customers->firstItem());
        $this->assertSame(0, $customers->total());
    }

    /** The search field is one control: the icon sits inside it, not in a box of its own. */
    public function test_the_search_field_is_a_single_control(): void
    {
        $html = $this->actingAs($this->admin)->get(route('customers.index'))->assertOk()->getContent();

        $this->assertStringContainsString('cust-search-input', $html);
        $this->assertStringContainsString('placeholder="Search customers..."', $html);
        // The label wraps both the icon and the field, which is what makes them one control.
        $this->assertMatchesRegularExpression('/<label class="cust-search">.*?<svg.*?<input/s', $html);
    }

    public function test_the_serial_number_is_not_the_customer_id(): void
    {
        // Ids well above the row numbers, so a leak would be obvious.
        Customer::factory()->count(3)->create();
        DB::table('customers')->update(['id' => DB::raw('id + 500')]);

        $html = $this->actingAs($this->admin)->get(route('customers.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<td class="cust-sn" data-label="S/N">1</td>', $html);
        $this->assertStringNotContainsString('<td class="cust-sn" data-label="S/N">501</td>', $html);
    }

    public function test_the_list_sorts_newest_first_by_default(): void
    {
        $older = Customer::factory()->create(['created_at' => now()->subWeek()]);
        $newer = Customer::factory()->create(['created_at' => now()]);

        $customers = $this->actingAs($this->admin)->get(route('customers.index'))
            ->assertOk()->viewData('customers');

        $this->assertSame($newer->id, $customers->items()[0]->id);
        $this->assertSame($older->id, $customers->items()[1]->id);
    }

    /** The whole list in one query per page, not one per customer. */
    public function test_the_list_does_not_query_per_customer(): void
    {
        $customers = Customer::factory()->count(8)->create();
        foreach ($customers as $customer) {
            $this->sale($customer, '1', '5000.00');
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('customers.index'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Comfortably fewer than one per row; a per-customer lookup would push this past twenty.
        $this->assertLessThan(20, $queries, "the list issued {$queries} queries");
    }

    public function test_the_export_carries_the_list_and_is_policy_gated(): void
    {
        Customer::factory()->create(['first_name' => 'Emeka', 'last_name' => 'Obi', 'tag' => 'Camry']);

        $csv = $this->actingAs($this->admin)->get(route('customers.export'))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Emeka Obi', $csv);
        $this->assertStringContainsString('Camry', $csv);
        // Internal notes are not in the file: it carries only what the screen already shows.
        $this->assertStringNotContainsString('Notes', $csv);
    }

    // ── Create ───────────────────────────────────────────────────────────────────────────────

    public function test_a_customer_is_created_with_a_tag_and_no_separate_whatsapp_number(): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), $this->payload())
            ->assertRedirect();

        $customer = Customer::query()->sole();
        $this->assertSame('Emeka', $customer->first_name);
        $this->assertSame('Toyota Camry 2012', $customer->tag);
        // "Same as phone" stores null rather than a second copy of the number.
        $this->assertNull($customer->whatsapp_phone);
        $this->assertTrue($customer->whatsAppUsesPhone());
        $this->assertSame('+2348012345678', $customer->effectiveWhatsAppPhone());
    }

    public function test_a_separate_whatsapp_number_is_stored_when_the_box_is_unticked(): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), $this->payload([
            'whatsapp_same_as_phone' => null,
            'whatsapp_phone' => '08099999999',
        ]))->assertRedirect();

        $customer = Customer::query()->sole();
        // Canonicalised on the way in, like every other number.
        $this->assertSame('+2348099999999', $customer->whatsapp_phone);
        $this->assertSame('+2348099999999', $customer->effectiveWhatsAppPhone());
    }

    /** The same number in both fields means "same as phone", so the column stays null. */
    public function test_a_whatsapp_number_matching_the_phone_is_not_stored_twice(): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), $this->payload([
            'whatsapp_same_as_phone' => null,
            'whatsapp_phone' => '08012345678',
        ]))->assertRedirect();

        $this->assertNull(Customer::query()->sole()->whatsapp_phone);
    }

    /** Switching back to "same as phone" clears a previously separate number. */
    public function test_returning_to_same_as_phone_clears_the_separate_number(): void
    {
        $customer = Customer::factory()->create([
            'phone' => '+2348012345678',
            'whatsapp_phone' => '+2348099999999',
        ]);

        $this->actingAs($this->admin)->put(route('customers.update', $customer), $this->payload())
            ->assertRedirect();

        $this->assertNull($customer->fresh()->whatsapp_phone);
    }

    /** Consent is given, never inferred from having a number. */
    public function test_consent_is_only_recorded_when_it_is_given(): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), $this->payload())->assertRedirect();
        $this->assertFalse(Customer::query()->sole()->whatsapp_opt_in, 'a phone number is not consent');

        Customer::query()->delete();

        $this->actingAs($this->admin)->post(route('customers.store'), $this->payload(['whatsapp_opt_in' => '1']))
            ->assertRedirect();
        $optedIn = Customer::query()->sole();
        $this->assertTrue($optedIn->whatsapp_opt_in);
        $this->assertNotNull($optedIn->whatsapp_opt_in_at, 'consent is timestamped');
    }

    // ── Duplicate phone ──────────────────────────────────────────────────────────────────────

    /**
     * A phone number identifies one customer however it is written.
     *
     * Each of these is the same Nigerian number, and none may create a second customer.
     */
    public function test_a_duplicate_phone_is_refused_in_every_format(): void
    {
        Customer::factory()->create(['phone' => '+2348012345678']);

        foreach (['08012345678', '+2348012345678', '2348012345678'] as $variant) {
            $this->actingAs($this->admin)
                ->post(route('customers.store'), $this->payload(['phone' => $variant]))
                ->assertSessionHasErrors('phone');
        }

        $this->assertSame(1, Customer::query()->count());
    }

    /**
     * The create form names who holds a duplicate number and links to them.
     *
     * Exercised as the browser does it: the submission is refused, Laravel redirects back with the
     * old input, and the form is re-rendered by following that redirect — so this covers the real
     * round trip rather than a hand-built session.
     */
    public function test_the_form_names_the_customer_holding_a_duplicate_number(): void
    {
        $existing = Customer::factory()->create([
            'first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348012345678',
        ]);

        $this->actingAs($this->admin);

        // No session assertion between the two requests: reading the flashed bag here consumes it,
        // and the re-rendered form is what this test is actually about. The refusal itself is
        // covered by test_a_duplicate_phone_is_refused_in_every_format.
        $this->from(route('customers.create'))
            ->post(route('customers.store'), $this->payload(['phone' => '08012345678']));

        // Following the redirect in the same session is what carries the flashed error and old
        // input onto the form; a fresh `actingAs` here would consume them first.
        $page = $this->get(route('customers.create'))->assertOk();

        // The controller resolved who holds the number...
        $this->assertSame($existing->id, $page->viewData('duplicate')?->id);

        // ...and the form offers the way to them. Matched against the raw markup because the
        // error block renders escaped entities that `assertSee` would not line up with.
        $html = $page->getContent();
        $this->assertStringContainsString('View profile', $html);
        $this->assertStringContainsString(route('customers.show', $existing), $html);
        $this->assertStringContainsString('already exists', $html);
    }

    /** A customer's own number is not a duplicate of itself. */
    public function test_a_customer_may_keep_their_own_phone_when_edited(): void
    {
        $customer = Customer::factory()->create(['phone' => '+2348012345678']);

        $this->actingAs($this->admin)
            ->put(route('customers.update', $customer), $this->payload(['phone' => '08012345678']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('+2348012345678', $customer->fresh()->phone);
    }

    // ── Photographs ──────────────────────────────────────────────────────────────────────────

    public function test_a_photograph_can_be_uploaded_replaced_and_removed(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('customers.photo.store', $customer), ['photo' => UploadedFile::fake()->image('a.jpg', 400, 400)])
            ->assertRedirect();

        $first = $customer->fresh()->photo_path;
        $this->assertNotNull($first);
        $this->assertTrue(app(ImageStore::class)->exists($first));

        // Replacing points the record at the new file and does not leave the old one behind.
        $this->actingAs($this->admin)
            ->post(route('customers.photo.store', $customer), ['photo' => UploadedFile::fake()->image('b.png', 400, 400)])
            ->assertRedirect();

        $second = $customer->fresh()->photo_path;
        $this->assertNotSame($first, $second);
        $this->assertFalse(app(ImageStore::class)->exists($first), 'the superseded file was not cleaned up');

        // Removing returns the customer to the initials fallback.
        $this->actingAs($this->admin)->delete(route('customers.photo.destroy', $customer))->assertRedirect();
        $this->assertNull($customer->fresh()->photo_path);
        $this->assertFalse(app(ImageStore::class)->exists($second));
    }

    /** Saving the form without choosing a file leaves an existing photograph alone. */
    public function test_saving_the_form_without_a_file_keeps_the_existing_photograph(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAs($this->admin)
            ->post(route('customers.photo.store', $customer), ['photo' => UploadedFile::fake()->image('a.jpg', 400, 400)]);
        $path = $customer->fresh()->photo_path;

        $this->actingAs($this->admin)->put(route('customers.update', $customer), $this->payload())->assertRedirect();

        $this->assertSame($path, $customer->fresh()->photo_path, 'the photo was lost by omission');
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('customers.photo.store', $customer), ['photo' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf')])
            ->assertSessionHasErrors('photo');

        $this->assertNull($customer->fresh()->photo_path);
    }

    public function test_an_oversized_image_is_refused(): void
    {
        $customer = Customer::factory()->create();

        // Over ImageStore's 2 MB limit, expressed in kilobytes.
        $oversized = UploadedFile::fake()->create('huge.jpg', (int) (ImageStore::MAX_BYTES / 1024) + 64, 'image/jpeg');

        $this->actingAs($this->admin)
            ->post(route('customers.photo.store', $customer), ['photo' => $oversized])
            ->assertSessionHasErrors('photo');

        $this->assertNull($customer->fresh()->photo_path);
    }

    public function test_photographs_are_behind_the_customer_policy(): void
    {
        $customer = Customer::factory()->create();

        // The existing CustomerPolicy lets a Sales Rep update an *active* customer, so photographs
        // follow that same rule rather than inventing a stricter one for this field alone.
        $this->actingAs($this->rep)
            ->post(route('customers.photo.store', $customer), ['photo' => UploadedFile::fake()->image('a.png', 400, 400)])
            ->assertRedirect();

        // An archived customer is closed to them, and the photograph is closed with it.
        $archived = Customer::factory()->create(['is_active' => false]);

        $this->actingAs($this->rep)
            ->post(route('customers.photo.store', $archived), ['photo' => UploadedFile::fake()->image('b.png', 400, 400)])
            ->assertForbidden();
    }

    public function test_a_photograph_is_not_served_to_a_guest(): void
    {
        $customer = Customer::factory()->create();

        $this->get(route('customers.photo', $customer))->assertRedirect(route('login'));
    }

    // ── Profile ──────────────────────────────────────────────────────────────────────────────

    public function test_the_profile_reports_real_figures_and_history(): void
    {
        $customer = Customer::factory()->create(['first_name' => 'Emeka', 'last_name' => 'Obi']);
        $this->sale($customer, '2', '10000.00');
        $this->sale($customer, '3', '10000.00');

        $page = $this->actingAs($this->admin)->get(route('customers.show', $customer))->assertOk();
        $stats = $page->viewData('stats');

        $this->assertSame(2, $stats['purchases']);
        $this->assertSame('50000.00', $stats['spent']);
        $this->assertNotNull($stats['last_purchase']);
        $this->assertCount(2, $page->viewData('purchases'));
    }

    /** The opted-in badge follows consent, never the presence of a number. */
    public function test_the_opted_in_badge_tracks_consent_alone(): void
    {
        $notConsenting = Customer::factory()->create([
            'whatsapp_phone' => '+2348099999999',
            'whatsapp_opt_in' => false,
        ]);

        $this->actingAs($this->admin)->get(route('customers.show', $notConsenting))
            ->assertOk()
            ->assertDontSee('WhatsApp opted-in');

        $consenting = Customer::factory()->create([
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('customers.show', $consenting))
            ->assertOk()
            ->assertSee('WhatsApp opted-in');
    }

    public function test_a_customer_with_no_messages_shows_the_empty_communication_state(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('No messages sent yet')
            ->assertSee('No messages sent to this customer yet.');
    }

    /** A customer with no sales still renders, with no invented history. */
    public function test_a_customer_with_no_sales_renders_cleanly(): void
    {
        $customer = Customer::factory()->create();

        $page = $this->actingAs($this->admin)->get(route('customers.show', $customer))->assertOk();

        $this->assertSame(0, $page->viewData('stats')['purchases']);
        $this->assertSame('0.00', $page->viewData('stats')['spent']);
        $this->assertNull($page->viewData('stats')['last_purchase']);
        $page->assertSee('No purchases yet');
    }

    /** A customer predating the new columns renders with all three null. */
    public function test_a_customer_with_no_photo_tag_or_whatsapp_number_renders(): void
    {
        $customer = Customer::factory()->create([
            'photo_path' => null, 'tag' => null, 'whatsapp_phone' => null,
        ]);

        $this->actingAs($this->admin)->get(route('customers.show', $customer))->assertOk();
        $this->actingAs($this->admin)->get(route('customers.index'))->assertOk()->assertSee('Same');
        $this->actingAs($this->admin)->get(route('customers.edit', $customer))->assertOk();
    }

    /** Record Sale opens on the customer the operator was looking at. */
    public function test_record_sale_preselects_the_customer(): void
    {
        $customer = Customer::factory()->create(['is_active' => true]);

        $page = $this->actingAs($this->admin)
            ->get(route('sales.create', ['customer' => $customer->id]))->assertOk();

        $this->assertSame($customer->id, $page->viewData('preselected')['id']);
    }

    /** A tampered or unknown id simply preselects nobody. */
    public function test_record_sale_ignores_an_unusable_customer_parameter(): void
    {
        foreach (['999999', 'not-an-id'] as $value) {
            $page = $this->actingAs($this->admin)
                ->get(route('sales.create', ['customer' => $value]))->assertOk();

            $this->assertNull($page->viewData('preselected'));
        }
    }

    private function sale(Customer $customer, string $units, string $price): void
    {
        $product = Product::factory()->create(['selling_price' => $price, 'current_stock' => '100.000']);

        app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => false,
            'customer_id' => $customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $product->id, 'quantity' => $units]],
            'payment_method' => 'cash',
            'amount_paid' => '0.00',
        ]);
    }
}
