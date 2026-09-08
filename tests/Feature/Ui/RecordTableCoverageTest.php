<?php

namespace Tests\Feature\Ui;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Sweeps every standardized record list in one pass, so a listing cannot quietly drop out of the
 * shared contract. Each route must render the rows-per-page selector, a single result count, and
 * must honour an allowlisted per_page while rejecting an unlisted one.
 */
class RecordTableCoverageTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Route name => the view key holding its paginator. Every paginated listing in the
     * application appears here; adding one without a footer fails this test.
     *
     * @return array<string, string>
     */
    public static function listings(): array
    {
        return [
            'staff.index' => 'staff',
            'inventory.index' => 'products',
            'inventory.categories.index' => 'categories',
            'customers.index' => 'customers',
            'sales.index' => 'sales',
            'sale-payments.index' => 'payments',
            'suppliers.index' => 'suppliers',
            'purchases.index' => 'purchases',
            'expenses.index' => 'expenses',
            'expense-categories.index' => 'categories',
            'returns.index' => 'rows',
            'refunds.index' => 'rows',
            'notifications.index' => 'notifications',
            'audit.index' => 'events',
            'whatsapp.deliveries.index' => 'deliveries',
            'reports.sales' => 'rows',
            'reports.collections' => 'rows',
            'reports.receivables' => 'rows',
            'reports.expenses' => 'rows',
            'reports.purchases' => 'rows',
            'reports.inventory' => 'rows',
            'reports.products' => 'rows',
            'reports.customers' => 'rows',
            'reports.staff' => 'rows',
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_every_standardized_listing_renders_the_shared_footer(): void
    {
        $admin = $this->admin();

        foreach (array_keys(self::listings()) as $name) {
            $html = $this->actingAs($admin)->get(route($name))->assertOk()->getContent();

            $this->assertStringContainsString('Rows per page:', $html, "{$name} must offer a rows-per-page selector");
            $this->assertStringContainsString('data-table-control', $html, "{$name} selector must auto-submit");
            $this->assertSame(1, substr_count($html, 'ui-result-count'), "{$name} must show exactly one result count");
            $this->assertSame(1, substr_count($html, 'Showing'), "{$name} must not print the count twice");
            $this->assertStringNotContainsString('results</span>', $html, "{$name} must not fall back to the stock paginator text");
            foreach (PerPage::OPTIONS as $option) {
                $this->assertStringContainsString('<option value="'.$option.'"', $html,
                    "{$name} must offer the {$option}-row size");
            }
        }
    }

    public function test_every_standardized_listing_honours_and_bounds_per_page(): void
    {
        $admin = $this->admin();

        foreach (self::listings() as $name => $key) {
            $honoured = $this->actingAs($admin)->get(route($name, ['per_page' => 50]))->assertOk();
            $this->assertSame(50, $honoured->viewData($key)->perPage(), "{$name} must honour per_page=50");

            $rejected = $this->actingAs($admin)->get(route($name, ['per_page' => '7777']))->assertOk();
            $this->assertSame(PerPage::DEFAULT, $rejected->viewData($key)->perPage(),
                "{$name} must clamp an unlisted per_page to the default");
        }
    }

    public function test_every_standardized_listing_defaults_to_ten_rows(): void
    {
        $admin = $this->admin();

        foreach (self::listings() as $name => $key) {
            $this->assertSame(PerPage::DEFAULT, $this->actingAs($admin)->get(route($name))->assertOk()->viewData($key)->perPage(),
                "{$name} must default to the shared page size");
        }
    }

    public function test_listings_that_render_a_table_put_s_n_first(): void
    {
        $admin = $this->admin();

        // Every listing whose rows are <tr>s carries the S/N header cell ahead of its own columns.
        $tabular = [
            'staff.index' => 'Staff name',
            'inventory.index' => 'SKU',
            'customers.index' => 'Customer',
            'sales.index' => null,
            'sale-payments.index' => null,
            'suppliers.index' => 'Supplier',
            'purchases.index' => 'Purchase',
            'returns.index' => 'Return',
            'refunds.index' => 'Refund',
            'audit.index' => 'Event',
            'whatsapp.deliveries.index' => null,
            'reports.sales' => 'Report rows',
        ];

        foreach ($tabular as $name => $firstOwnColumn) {
            $html = $this->actingAs($admin)->get(route($name))->assertOk()->getContent();
            $snAt = mb_strpos($html, '<th class="ui-sn">S/N</th>');
            $this->assertNotFalse($snAt, "{$name} must render an S/N header");

            if ($firstOwnColumn !== null) {
                $this->assertLessThan(mb_strpos($html, $firstOwnColumn, $snAt - 1), $snAt,
                    "{$name} must place S/N before {$firstOwnColumn}");
            }
        }
    }

    public function test_the_two_non_tabular_listings_still_number_their_records(): void
    {
        $admin = $this->admin();

        // The notification inbox and the inline-edit category cards keep their layouts; the
        // ordinal badge is their S/N.
        foreach (['notifications.index', 'inventory.categories.index'] as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk()
                ->assertSee('Rows per page:', false);
        }
        $this->assertStringContainsString('ui-feed-ordinal',
            file_get_contents(resource_path('views/notifications/index.blade.php')));
        $this->assertStringContainsString('ui-feed-ordinal',
            file_get_contents(resource_path('views/inventory/categories/index.blade.php')));
    }

    public function test_no_listing_bypasses_the_shared_footer(): void
    {
        // A raw ->links() call in a page view means that listing skipped the shared component.
        foreach (glob(resource_path('views/**/*.blade.php')) + glob(resource_path('views/**/**/*.blade.php')) as $path) {
            if (str_contains($path, 'components/table-footer') || str_contains($path, 'vendor/pagination')) {
                continue;
            }
            $this->assertStringNotContainsString('->links()', (string) file_get_contents($path),
                basename($path).' must paginate through <x-table-footer>');
        }
    }

    public function test_the_summary_report_is_intentionally_unpaginated(): void
    {
        $admin = $this->admin();

        // Summary is a bounded set of aggregate metrics, not a row list: forcing pagination onto
        // it would change what the report means.
        $response = $this->actingAs($admin)->get(route('reports.summary'))->assertOk();
        $this->assertNull($response->viewData('rows'));
        $this->assertStringNotContainsString('Rows per page:', $response->getContent());
    }
}
