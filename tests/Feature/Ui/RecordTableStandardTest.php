<?php

namespace Tests\Feature\Ui;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The shared record-list contract: an S/N column that counts through the whole result set, a
 * rows-per-page selector bounded by an allowlist, a result count, and pagination that carries the
 * active filters. Staff is the worked example; the same components back every other listing.
 */
class RecordTableStandardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'name' => 'Aaa Owner Admin']);
    }

    /** @return array<int, string> the S/N cell values, in render order */
    private function serialNumbers(string $html): array
    {
        preg_match_all('/<td class="ui-sn">\s*(\d+)\s*<\/td>/', $html, $matches);

        return $matches[1];
    }

    /* ------------------------------------------------------------------ S/N column */

    public function test_the_staff_list_renders_an_s_n_column_as_its_first_column(): void
    {
        $admin = $this->admin();
        User::factory()->count(3)->create();

        $html = $this->actingAs($admin)->get(route('staff.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<th class="ui-sn">S/N</th>', $html);
        // First column: the S/N header precedes every other heading in the row.
        $this->assertLessThan(mb_strpos($html, 'Staff name'), mb_strpos($html, '<th class="ui-sn">S/N</th>'));
        $this->assertSame(['1', '2', '3', '4'], $this->serialNumbers($html));
    }

    public function test_s_n_continues_across_pages_instead_of_restarting(): void
    {
        $admin = $this->admin();
        User::factory()->count(24)->create();
        $size = PerPage::DEFAULT;

        $first = $this->serialNumbers($this->actingAs($admin)->get(route('staff.index'))->assertOk()->getContent());
        $second = $this->serialNumbers($this->actingAs($admin)->get(route('staff.index', ['page' => 2]))->assertOk()->getContent());
        $third = $this->serialNumbers($this->actingAs($admin)->get(route('staff.index', ['page' => 3]))->assertOk()->getContent());

        $this->assertSame(range(1, $size), array_map('intval', $first), 'Page one starts at 1');
        $this->assertSame(range($size + 1, $size * 2), array_map('intval', $second), 'Page two continues, it does not restart');
        $this->assertSame(range(($size * 2) + 1, 25), array_map('intval', $third), 'The final partial page keeps counting');
    }

    public function test_s_n_reflects_position_in_the_filtered_result_set(): void
    {
        $admin = $this->admin();
        User::factory()->count(12)->create(['role' => UserRole::Manager]);
        User::factory()->count(12)->create(['role' => UserRole::SalesRep]);

        $filtered = $this->actingAs($admin)->get(route('staff.index', ['role' => 'manager', 'page' => 2]))
            ->assertOk()->getContent();

        // 12 managers, ten to a page: the second page of the *filtered* set numbers 11 and 12.
        $this->assertSame(['11', '12'], $this->serialNumbers($filtered));
    }

    /* --------------------------------------------------------------- rows per page */

    public function test_the_default_page_size_is_ten_and_the_selector_offers_the_four_sizes(): void
    {
        $admin = $this->admin();
        User::factory()->count(30)->create();

        $response = $this->actingAs($admin)->get(route('staff.index'))->assertOk();
        $this->assertSame(10, $response->viewData('staff')->perPage());
        $this->assertCount(10, $response->viewData('staff')->items());

        $html = $response->getContent();
        $this->assertStringContainsString('Rows per page:', $html);
        foreach (PerPage::OPTIONS as $option) {
            $this->assertStringContainsString('value="'.$option.'"', $html);
        }
        $this->assertSame([10, 25, 50, 100], PerPage::OPTIONS);
    }

    public function test_each_allowlisted_page_size_is_honoured(): void
    {
        $admin = $this->admin();
        User::factory()->count(120)->create();

        foreach (PerPage::OPTIONS as $size) {
            $response = $this->actingAs($admin)->get(route('staff.index', ['per_page' => $size]))->assertOk();
            $this->assertSame($size, $response->viewData('staff')->perPage(), "per_page={$size} must be honoured");
            $this->assertCount($size, $response->viewData('staff')->items());
        }
    }

    public function test_page_size_values_outside_the_allowlist_fall_back_to_the_default(): void
    {
        $admin = $this->admin();
        User::factory()->count(30)->create();

        $rejected = [
            'not a number' => 'abc',
            'unlisted size' => '15',
            'unbounded' => '1000000',
            'zero' => '0',
            'negative' => '-10',
            'float' => '10.5',
            'empty' => '',
            'null byte' => "10\0",
            'sql shaped' => '10; DROP TABLE users',
            'padded' => ' 10 ',
        ];

        foreach ($rejected as $label => $value) {
            $response = $this->actingAs($admin)->get(route('staff.index', ['per_page' => $value]))->assertOk();
            $this->assertSame(PerPage::DEFAULT, $response->viewData('staff')->perPage(),
                "A {$label} per_page must fall back to the default");
        }

        // An array-shaped per_page must not reach the LIMIT clause either.
        $response = $this->actingAs($admin)->get(route('staff.index').'?per_page[]=25')->assertOk();
        $this->assertSame(PerPage::DEFAULT, $response->viewData('staff')->perPage());
    }

    /* --------------------------------------------------------------- result count */

    public function test_the_result_count_reports_the_visible_window_and_the_filtered_total(): void
    {
        $admin = $this->admin();
        User::factory()->count(24)->create();

        $this->assertStringContainsString('Showing 1&ndash;10 of 25 staff accounts',
            $this->actingAs($admin)->get(route('staff.index'))->assertOk()->getContent());
        $this->assertStringContainsString('Showing 11&ndash;20 of 25 staff accounts',
            $this->actingAs($admin)->get(route('staff.index', ['page' => 2]))->assertOk()->getContent());
        $this->assertStringContainsString('Showing 21&ndash;25 of 25 staff accounts',
            $this->actingAs($admin)->get(route('staff.index', ['page' => 3]))->assertOk()->getContent());
    }

    public function test_an_empty_result_set_reports_zero_records_and_not_a_range(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('staff.index', ['search' => 'no-such-person']))->assertOk()->getContent();

        $this->assertStringContainsString('Showing 0 staff account', $html);
        $this->assertStringNotContainsString('&ndash;', $html);
        $this->assertSame([], $this->serialNumbers($html));
        $this->assertStringContainsString('No staff accounts match these filters.', $html);
    }

    public function test_the_count_is_not_printed_twice(): void
    {
        $admin = $this->admin();
        User::factory()->count(24)->create();

        $html = $this->actingAs($admin)->get(route('staff.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Showing'), 'Exactly one result count per listing');
        // Laravel's stock paginator view prints its own count; the published view must not.
        $this->assertStringNotContainsString('results', $html);
    }

    /* ------------------------------------------- filter preservation across controls */

    public function test_pagination_links_carry_the_active_search_and_filters(): void
    {
        $admin = $this->admin();
        // Enough sales reps to need a second page at the 25-row size under test.
        User::factory()->count(40)->create(['role' => UserRole::SalesRep, 'status' => UserStatus::Active]);

        $html = $this->actingAs($admin)
            ->get(route('staff.index', ['role' => 'sales_rep', 'status' => 'active', 'per_page' => 25, 'sort' => 'email', 'direction' => 'desc']))
            ->assertOk()->getContent();

        // & is escaped to &amp; inside the attribute, so anchor on either form.
        preg_match('/href="([^"]*(?:[?&]|&amp;)page=2[^"]*)"/', $html, $link);
        $this->assertNotEmpty($link, 'A next-page link must be rendered');
        foreach (['role=sales_rep', 'status=active', 'per_page=25', 'sort=email', 'direction=desc'] as $carried) {
            $this->assertStringContainsString($carried, html_entity_decode($link[1]),
                "Pagination must carry {$carried}");
        }
    }

    public function test_the_rows_per_page_form_resubmits_every_active_filter_and_returns_to_page_one(): void
    {
        $admin = $this->admin();
        User::factory()->count(24)->create(['role' => UserRole::Manager]);

        $html = $this->actingAs($admin)
            ->get(route('staff.index', ['search' => 'Ada', 'role' => 'manager', 'status' => 'active', 'sort' => 'email', 'direction' => 'desc', 'page' => 2]))
            ->assertOk()->getContent();

        $formAt = mb_strpos($html, 'class="ui-per-page"');
        $this->assertNotFalse($formAt, 'The rows-per-page form must be rendered');
        $form = mb_substr($html, $formAt, mb_strpos($html, '</form>', $formAt) - $formAt);

        foreach ([['search', 'Ada'], ['role', 'manager'], ['status', 'active'], ['sort', 'email'], ['direction', 'desc']] as [$name, $value]) {
            $this->assertStringContainsString('name="'.$name.'" value="'.$value.'"', $form,
                "The selector must carry {$name} forward");
        }
        $this->assertStringNotContainsString('name="page"', $form, 'Changing the size must return to page one');
        $this->assertStringNotContainsString('name="per_page" value=', $form, 'per_page comes from the select, not a hidden input');
        $this->assertStringContainsString('data-table-control', $form, 'The select auto-submits through the delegated listener');
    }

    public function test_changing_the_page_size_keeps_the_filtered_result_set(): void
    {
        $admin = $this->admin();
        User::factory()->count(12)->create(['role' => UserRole::Manager]);
        User::factory()->count(40)->create(['role' => UserRole::SalesRep]);

        $response = $this->actingAs($admin)->get(route('staff.index', ['role' => 'manager', 'per_page' => 25]))->assertOk();

        $this->assertSame(12, $response->viewData('staff')->total(), 'The filter still bounds the result set');
        $this->assertSame(25, $response->viewData('staff')->perPage());
        $this->assertStringContainsString('Showing 1&ndash;12 of 12 staff accounts', $response->getContent());
    }

    /* --------------------------------------------------------------------- sorting */

    public function test_sorting_is_restricted_to_an_allowlist_and_ignores_anything_else(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['email' => 'admin@example.com'])->save();
        User::factory()->create(['name' => 'Zoe Last', 'email' => 'zoe@example.com']);
        User::factory()->create(['name' => 'Bob Middle', 'email' => 'bob@example.com']);

        $names = fn (array $query) => array_map(
            fn (User $user) => $user->name,
            $this->actingAs($admin)->get(route('staff.index', $query))->assertOk()->viewData('staff')->items(),
        );

        $this->assertSame(['Aaa Owner Admin', 'Bob Middle', 'Zoe Last'], $names([]), 'Name ascending by default');
        $this->assertSame(['Zoe Last', 'Bob Middle', 'Aaa Owner Admin'], $names(['sort' => 'name', 'direction' => 'desc']));
        $this->assertSame(['Aaa Owner Admin', 'Bob Middle', 'Zoe Last'], $names(['sort' => 'email']),
            'admin@ < bob@ < zoe@');
        $this->assertSame(['Zoe Last', 'Bob Middle', 'Aaa Owner Admin'], $names(['sort' => 'email', 'direction' => 'desc']));

        // Anything not allowlisted keeps the default ordering rather than reaching orderBy().
        foreach ([
            'unknown column' => 'quick_pin_hash',
            'raw sql' => 'name; DROP TABLE users',
            'expression' => '(select 1)',
            'password' => 'password',
            'remember token' => 'remember_token',
        ] as $label => $sort) {
            $this->assertSame(['Aaa Owner Admin', 'Bob Middle', 'Zoe Last'], $names(['sort' => $sort, 'direction' => 'desc']),
                "A {$label} sort must be ignored");
        }

        $this->assertSame(['Aaa Owner Admin', 'Bob Middle', 'Zoe Last'], $names(['sort' => ['name'], 'direction' => ['desc']]),
            'Array-shaped sort parameters must be ignored');
    }

    public function test_sortable_headings_expose_their_state_and_the_next_direction(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('staff.index', ['sort' => 'name', 'direction' => 'asc']))->assertOk()->getContent();

        $this->assertStringContainsString('aria-sort="ascending"', $html);
        $this->assertStringContainsString('sort=name&amp;direction=desc', $html, 'The active column offers the flip');
        $this->assertStringContainsString('aria-sort="none"', $html, 'Inactive sortable columns say so');
        $this->assertStringNotContainsString('sort=action', $html, 'The action column is not sortable');
    }

    public function test_the_other_sortable_listings_share_the_same_allowlisted_mechanism(): void
    {
        $admin = $this->admin();
        // No supplier factory exists; insert the two rows the ordering assertions need.
        foreach ([['SUP-Z', 'Zeta Supply'], ['SUP-A', 'Alpha Supply']] as [$code, $name]) {
            DB::table('suppliers')->insert([
                'supplier_code' => $code, 'name' => $name, 'is_active' => true,
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $category = ProductCategory::factory()->create();
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Zinc Widget']);
        Product::factory()->create(['category_id' => $category->id, 'name' => 'Anvil']);

        foreach ([
            ['suppliers.index', 'suppliers', 'name', ['Alpha Supply', 'Zeta Supply']],
            ['inventory.index', 'products', 'name', ['Anvil', 'Zinc Widget']],
        ] as [$route, $key, $sort, $ascending]) {
            $names = fn (array $query) => array_map(
                fn ($row) => $row->name,
                $this->actingAs($admin)->get(route($route, $query))->assertOk()->viewData($key)->items(),
            );

            $this->assertSame($ascending, $names([]), "{$route} default ordering");
            $this->assertSame(array_reverse($ascending), $names(['sort' => $sort, 'direction' => 'desc']),
                "{$route} must honour an allowlisted descending sort");
            // A column outside the allowlist must not reach orderBy().
            $this->assertSame($ascending, $names(['sort' => 'cost_price; DROP TABLE products', 'direction' => 'desc']),
                "{$route} must ignore an unlisted sort key");
            $this->assertStringContainsString('aria-sort=',
                $this->actingAs($admin)->get(route($route))->assertOk()->getContent(),
                "{$route} must expose sortable headings");
        }
    }

    /* ---------------------------------------------------------------- N+1 guard */

    public function test_a_larger_page_size_does_not_add_queries_per_row(): void
    {
        $admin = $this->admin();
        User::factory()->count(120)->create();

        $count = function (int $size) use ($admin): int {
            $queries = 0;
            DB::listen(function (QueryExecuted $query) use (&$queries): void {
                if (! str_contains($query->sql, '`sessions`')) {
                    $queries++;
                }
            });
            $this->actingAs($admin)->get(route('staff.index', ['per_page' => $size]))->assertOk();
            DB::getEventDispatcher()->forget(QueryExecuted::class);

            return $queries;
        };

        $this->assertSame($count(10), $count(100),
            'Ten rows and a hundred rows must cost the same number of queries');
    }

    /* ----------------------------------------------------------- authorization */

    public function test_the_shared_table_controls_do_not_widen_access(): void
    {
        $probes = [
            ['per_page' => 100],
            ['sort' => 'email', 'direction' => 'desc'],
            ['page' => 2],
        ];

        foreach ($probes as $query) {
            $this->get(route('staff.index', $query))->assertRedirect(route('login'));
        }

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            foreach ($probes as $query) {
                $this->actingAs($user)->get(route('staff.index', $query))->assertForbidden();
            }
        }

        $admin = $this->admin();
        foreach ($probes as $query) {
            $this->actingAs($admin)->get(route('staff.index', $query))->assertOk();
        }
    }
}
