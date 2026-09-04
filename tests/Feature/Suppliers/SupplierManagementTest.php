<?php

namespace Tests\Feature\Suppliers;

use App\Actions\Supplier\CreateSupplier;
use App\Enums\UserRole;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_redirects_and_sales_rep_is_denied_every_supplier_surface(): void
    {
        $supplier = $this->supplier();
        $this->get(route('suppliers.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => UserRole::SalesRep]));

        foreach ([route('suppliers.index'), route('suppliers.show', $supplier), route('suppliers.edit', $supplier)] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_manager_updates_and_cycles_status_while_identity_stays_immutable(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = $this->supplier($manager);
        $this->actingAs($manager)->put(route('suppliers.update', $supplier), [
            'name' => 'Updated Supplier', 'phone' => '08012345678', 'email' => ' OFFICE@EXAMPLE.COM ',
        ])->assertRedirect();
        $supplier->refresh();
        $this->assertSame('+2348012345678', $supplier->phone);
        $this->assertSame('office@example.com', $supplier->email);
        $this->post(route('suppliers.deactivate', $supplier))->assertRedirect();
        $this->post(route('suppliers.activate', $supplier))->assertRedirect();
        $this->assertTrue($supplier->fresh()->is_active);

        $this->expectException(LogicException::class);
        $supplier->supplier_code = 'SUP-999999';
        $supplier->save();
    }

    public function test_filters_are_array_safe_and_output_is_escaped(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $supplier = $this->supplier($manager, '<script>Supplier_100%</script>');
        $supplier->notes = '<img src=x onerror=alert(1)>';
        $supplier->save();
        $this->actingAs($manager)->get(route('suppliers.index', ['search' => ['bad'], 'status' => ['active']]))->assertOk();
        $this->get(route('suppliers.show', $supplier))
            ->assertSee('&lt;script&gt;Supplier_100%&lt;/script&gt;', false)
            ->assertDontSee('<script>Supplier_100%</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_supplier_form_displays_safe_errors_for_notes_and_forged_fields(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($manager)->followingRedirects()->from(route('suppliers.create'))->post(route('suppliers.store'), [
            'name' => 'Supplier',
            'notes' => str_repeat('x', 1001),
            'supplier_code' => 'FORGED',
            'is_active' => false,
        ])->assertOk()
            ->assertSee('Please correct the highlighted Supplier details.')
            ->assertSee('The notes field must not be greater than 1000 characters.')
            ->assertSee('The supplier code field is prohibited.')
            ->assertSee('The is active field is prohibited.');
    }

    private function supplier(?User $actor = null, string $name = 'Supplier'): Supplier
    {
        $actor ??= User::factory()->create(['role' => UserRole::Admin]);

        return app(CreateSupplier::class)->execute($actor, ['name' => $name]);
    }
}
