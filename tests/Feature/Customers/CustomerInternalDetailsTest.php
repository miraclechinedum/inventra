<?php

namespace Tests\Feature\Customers;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A customer's saved address and internal notes.
 *
 * Administrators and Managers read them on the profile and edit them in the shared form. A Sales
 * Representative never receives them, cannot write them, and does not erase them by saving the
 * fields they are allowed to change.
 */
class CustomerInternalDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = "Prefers a call before delivery.\n<b>Gate code</b> 4471";

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->customer = Customer::factory()->create([
            'first_name' => 'Emeka', 'last_name' => 'Obi', 'phone' => '+2348012345678', 'is_active' => true,
            'address' => '12 Allen Avenue, Ikeja', 'notes' => self::NOTE,
        ]);
    }

    public function test_management_reads_the_address_and_escaped_notes_on_the_profile_and_in_the_form(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $manager = User::factory()->create(['role' => $role]);

            $this->actingAs($manager)->get(route('customers.show', $this->customer))->assertOk()
                ->assertSee('Internal details')
                ->assertSee('12 Allen Avenue, Ikeja')
                ->assertSee(self::NOTE)
                ->assertDontSee('<b>Gate code</b>', false);

            $this->actingAs($manager)->get(route('customers.edit', $this->customer))->assertOk()
                ->assertSee('name="address"', false)
                ->assertSee('name="notes"', false)
                ->assertSee('12 Allen Avenue, Ikeja')
                ->assertSee(e(self::NOTE), false)
                ->assertDontSee('<b>Gate code</b>', false);
        }
    }

    public function test_a_sales_representative_never_receives_the_internal_details(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        foreach ([route('customers.show', $this->customer), route('customers.edit', $this->customer), route('customers.create')] as $url) {
            $this->actingAs($rep)->get($url)->assertOk()
                ->assertDontSee('Internal details')
                ->assertDontSee('name="address"', false)
                ->assertDontSee('name="notes"', false)
                ->assertDontSee('12 Allen Avenue')
                ->assertDontSee('Gate code');
        }
    }

    public function test_a_sales_representative_cannot_write_them_and_does_not_erase_them(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        $this->actingAs($rep)->put(route('customers.update', $this->customer), [
            'email' => 'emeka@example.com', 'address' => 'Somewhere else', 'notes' => 'Overwritten',
        ])->assertSessionHasErrors(['address', 'notes']);

        // Saving the fields a representative may change leaves the internal details as they were.
        $this->actingAs($rep)->put(route('customers.update', $this->customer), ['email' => 'emeka@example.com'])
            ->assertSessionHasNoErrors();

        $this->customer->refresh();
        $this->assertSame('emeka@example.com', $this->customer->email);
        $this->assertSame('12 Allen Avenue, Ikeja', $this->customer->address);
        $this->assertSame(self::NOTE, $this->customer->notes);

        $this->actingAs($rep)->post(route('customers.store'), [
            'first_name' => 'Ada', 'phone' => '08031112222', 'address' => 'Not allowed', 'notes' => 'Not allowed',
        ])->assertSessionHasErrors(['address', 'notes']);
        $this->assertFalse(Customer::query()->where('first_name', 'Ada')->exists());
    }

    public function test_management_edits_clears_and_creates_internal_details(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $profile = ['first_name' => 'Emeka', 'last_name' => 'Obi', 'phone' => '+2348012345678'];

        $this->actingAs($manager)->put(route('customers.update', $this->customer), $profile + [
            'address' => '  4 Marina Road, Lagos Island  ', 'notes' => 'Account settled by transfer only.',
        ])->assertSessionHasNoErrors()->assertRedirect(route('customers.show', $this->customer));

        $this->customer->refresh();
        $this->assertSame('4 Marina Road, Lagos Island', $this->customer->address);
        $this->assertSame('Account settled by transfer only.', $this->customer->notes);

        // The edit is audited, but private details never enter the audit trail.
        $audit = DB::table('audit_logs')->where('action', 'customer_updated')->where('auditable_id', $this->customer->id)->get();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString('Marina Road', $audit->toJson());
        $this->assertStringNotContainsString('transfer only', $audit->toJson());
        $this->assertStringNotContainsString('Gate code', $audit->toJson());

        // A saved profile that leaves the fields out keeps them; submitting them empty clears them.
        $this->actingAs($manager)->put(route('customers.update', $this->customer), $profile + ['tag' => 'Fleet'])
            ->assertSessionHasNoErrors();
        $this->assertSame('4 Marina Road, Lagos Island', $this->customer->fresh()->address);

        $this->actingAs($manager)->put(route('customers.update', $this->customer), $profile + ['address' => '', 'notes' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->customer->fresh()->address);
        $this->assertNull($this->customer->fresh()->notes);
        $this->actingAs($manager)->get(route('customers.show', $this->customer))->assertOk()
            ->assertSee('No address saved.')
            ->assertSee('No internal notes.');

        $this->actingAs($manager)->post(route('customers.store'), [
            'first_name' => 'Ngozi', 'phone' => '08035556666', 'address' => '9 Ring Road, Ibadan', 'notes' => 'Wholesale buyer.',
        ])->assertSessionHasNoErrors();
        $created = Customer::query()->where('first_name', 'Ngozi')->sole();
        $this->assertSame('9 Ring Road, Ibadan', $created->address);
        $this->assertSame('Wholesale buyer.', $created->notes);
    }

    public function test_invalid_internal_details_are_rejected_without_changing_what_is_saved(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $profile = ['first_name' => 'Emeka', 'last_name' => 'Obi', 'phone' => '+2348012345678'];

        foreach ([
            ['address' => str_repeat('a', 501)],
            ['notes' => str_repeat('n', 2001)],
            ['address' => ['12 Allen Avenue']],
            ['notes' => ['x' => 'y']],
        ] as $invalid) {
            $this->actingAs($manager)->from(route('customers.edit', $this->customer))
                ->put(route('customers.update', $this->customer), $profile + $invalid)
                ->assertRedirect(route('customers.edit', $this->customer))
                ->assertSessionHasErrors(array_keys($invalid));

            // The rejected form re-renders safely, whatever shape the old input had.
            $this->actingAs($manager)->get(route('customers.edit', $this->customer))->assertOk();
        }

        $this->customer->refresh();
        $this->assertSame('12 Allen Avenue, Ikeja', $this->customer->address);
        $this->assertSame(self::NOTE, $this->customer->notes);
    }

    public function test_another_business_cannot_read_or_change_them(): void
    {
        $outsider = User::factory()->forBusiness(Business::factory()->create())->create(['role' => UserRole::Admin]);

        $this->actingAs($outsider)->get(route('customers.show', $this->customer))->assertNotFound();
        $this->actingAs($outsider)->get(route('customers.edit', $this->customer))->assertNotFound();
        $this->actingAs($outsider)->put(route('customers.update', $this->customer), [
            'first_name' => 'Emeka', 'phone' => '+2348012345678', 'address' => 'Hijacked', 'notes' => 'Hijacked',
        ])->assertNotFound();

        $this->assertSame('12 Allen Avenue, Ikeja', $this->customer->fresh()->address);
        $this->assertSame(self::NOTE, $this->customer->fresh()->notes);
    }
}
