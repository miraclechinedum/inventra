<?php

namespace Tests\Feature\Reports;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportAccessAndSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_manager_access_every_report_while_rep_and_guest_are_denied(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach ($this->routes() as $route) {
                $this->get(route($route))->assertOk();
            }
        }

        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->actingAs($rep)->get(route('dashboard'))->assertOk()->assertDontSee('href="'.route('reports.index').'"', false);
        foreach ($this->routes() as $route) {
            $this->get(route($route))->assertForbidden();
        }

        auth()->logout();
        foreach ($this->routes() as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_malformed_filters_are_scalar_safe_and_invalid_range_is_controlled(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $malformed = ['from' => ['x'], 'to' => ['x'], 'staff' => ['x'], 'customer' => ['x'], 'product' => ['x'], 'category' => ['x'], 'payment_method' => ['x'], 'supplier' => ['x'], 'status' => ['x'], 'page' => ['x'], 'sort' => 'id desc', 'order' => 'drop table'];
        foreach (array_slice($this->routes(), 1) as $route) {
            $this->get(route($route, $malformed))->assertOk();
        }
        $this->from(route('reports.sales'))->get(route('reports.sales', ['from' => '2026-09-30', 'to' => '2026-09-01']))->assertRedirect(route('reports.sales'))->assertSessionHasErrors('from');
    }

    public function test_viewing_every_report_performs_no_business_or_audit_writes(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));
        $tables = ['sales', 'sale_payments', 'expenses', 'purchases', 'inventory_movements', 'customers', 'suppliers', 'audit_logs'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        foreach ($this->routes() as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' was mutated by reporting');
        }
    }

    private function routes(): array
    {
        return ['reports.index', 'reports.sales', 'reports.collections', 'reports.receivables', 'reports.expenses', 'reports.purchases', 'reports.inventory', 'reports.products', 'reports.customers', 'reports.staff', 'reports.summary'];
    }
}
