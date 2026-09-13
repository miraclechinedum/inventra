<?php

namespace Tests\Feature\Ui;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PrototypeUiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_navigation_keeps_role_destinations_and_mobile_controls_available(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertSee('id="navigation-toggle"', false)
                ->assertSee('aria-controls="app-navigation"', false)
                ->assertSee('aria-label="Sign out"', false)
                ->assertSee('Skip to content');
            foreach (['staff.index', 'audit.index', 'settings.business.edit'] as $route) {
                if ($role === UserRole::Admin) {
                    $response->assertSee('href="'.route($route).'"', false);
                } else {
                    $response->assertDontSee('href="'.route($route).'"', false);
                }
            }
            foreach (['returns.index', 'refunds.index', 'reports.index', 'purchases.index'] as $route) {
                if ($role === UserRole::SalesRep) {
                    $response->assertDontSee('href="'.route($route).'"', false);
                } else {
                    $response->assertSee('href="'.route($route).'"', false);
                }
            }
        }
    }

    public function test_sale_entry_keeps_eight_server_submitted_lines_and_search_contract(): void
    {
        $user = User::factory()->create(['role' => UserRole::SalesRep]);
        $response = $this->actingAs($user)->get(route('sales.create'))->assertOk()
            ->assertSee('data-sale-product-search', false)->assertSee('data-sale-entry', false)
            ->assertSee('name="customer_id"', false)->assertSee('name="amount_paid"', false)
            ->assertSee('name="payment_method"', false)->assertSee('name="notes"', false);
        $this->assertSame(8, substr_count($response->getContent(), 'aria-label="Quantity for product '));
        $response->assertDontSee('name="total_amount"', false)->assertDontSee('name="unit_price"', false);
    }

    public function test_no_alpine_directive_uses_a_multi_statement_expression(): void
    {
        // The app ships Livewire's CSP-safe Alpine build, whose expression evaluator parses a
        // single statement per attribute. An `x-on` (or `x-init`, `x-effect`, ...) written as
        // "a = 1; b = 2" throws a parser error at runtime and the handler silently does nothing —
        // there is no build-time failure, only a broken control the browser never reports back.
        // A property assignment such as `overridden.cost = true` is fine; two of them joined by a
        // semicolon are not, so the fix is always a single method call that does both internally.
        $offenders = [];
        $views = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($views as $file) {
            if ($file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $path = $file->getPathname();
            $markup = (string) file_get_contents($path);

            if (preg_match_all('/x-(?:on:[\w.\-]+|init|effect)="([^"]*)"/', $markup, $matches)) {
                foreach ($matches[1] as $expression) {
                    // A semicolon inside a string literal (e.g. a message) is not a statement
                    // separator; only bare semicolons outside quotes indicate multiple statements.
                    $stripped = preg_replace('/\'[^\']*\'|"[^"]*"/', '', $expression);

                    if (str_contains((string) $stripped, ';')) {
                        $offenders[] = basename($path).': '.$expression;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Multi-statement Alpine expressions break under the CSP-safe build: '.implode(' | ', $offenders));
    }
}
