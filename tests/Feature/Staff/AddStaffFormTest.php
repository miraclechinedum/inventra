<?php

namespace Tests\Feature\Staff;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Add Staff form.
 *
 * The rule worth protecting above all: the browser never chooses the credential. CreateStaff
 * generates it with `Str::password(20)`, the model hashes it, and it is revealed once. A password
 * input on this form would quietly move that decision to whoever is filling it in, so these assert
 * both that the field is absent and that a submitted one is ignored.
 */
class AddStaffFormTest extends TestCase
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

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ade Bello',
            'email' => 'ade@business.com',
            'phone' => '08012345678',
            'role' => 'manager',
        ], $overrides);
    }

    public function test_the_form_offers_the_two_creatable_roles_as_cards(): void
    {
        $html = $this->actingAs($this->admin)->get(route('staff.create'))->assertOk()->getContent();

        // Real radios, not a select: the whole card is a label around one.
        $this->assertStringContainsString('type="radio" name="role" value="manager"', $html);
        $this->assertStringContainsString('type="radio" name="role" value="sales_rep"', $html);
        $this->assertStringContainsString('Runs inventory, sales &amp; customers.', $html);
        $this->assertStringContainsString('Records sales &amp; views stock.', $html);

        // Administrator is not creatable here, and the copy says why.
        $this->assertStringNotContainsString('value="admin"', $html);
        $this->assertStringContainsString('bootstrapped', $html);
    }

    /**
     * No password field, by design.
     *
     * The temporary credential is the server's to generate; a field here would mean the browser
     * chose it.
     */
    public function test_the_form_has_no_password_field(): void
    {
        $html = $this->actingAs($this->admin)->get(route('staff.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="password"', $html);
        $this->assertStringNotContainsString('type="password"', $html);
        // And no client-side generator was introduced alongside it.
        $this->assertStringNotContainsString('Generate', $html);
    }

    /** A submitted password is ignored: the request does not accept one, so it cannot take effect. */
    public function test_a_submitted_password_cannot_become_the_account_password(): void
    {
        $this->actingAs($this->admin)
            ->post(route('staff.store'), $this->payload(['password' => 'chosen-by-the-browser']))
            ->assertOk();

        $created = User::query()->where('email', 'ade@business.com')->sole();

        $this->assertFalse(
            Hash::check('chosen-by-the-browser', $created->password),
            'the submitted password must never become the account password'
        );
        $this->assertTrue($created->force_password_change);
    }

    /** The credential is generated server-side, at full length, and revealed once. */
    public function test_creation_reveals_a_twenty_character_server_generated_password(): void
    {
        $page = $this->actingAs($this->admin)->post(route('staff.store'), $this->payload())->assertOk();

        $password = $page->viewData('temporaryPassword');
        $this->assertSame(20, mb_strlen($password));

        $created = User::query()->where('email', 'ade@business.com')->sole();
        // Stored hashed, never in plaintext.
        $this->assertTrue(Hash::check($password, $created->password));
        $this->assertNotSame($password, $created->password);

        $page->assertSee('Temporary password')
            ->assertSee('will not be shown again');
    }

    /** Nothing offers the plaintext again once the reveal page is left. */
    public function test_the_plaintext_credential_is_not_retrievable_afterwards(): void
    {
        $this->actingAs($this->admin)->post(route('staff.store'), $this->payload())->assertOk();
        $created = User::query()->where('email', 'ade@business.com')->sole();

        // Not on the staff record, not in the session, and not in the audit trail.
        $this->assertNull(session('temporary_password'));
        $this->actingAs($this->admin)->get(route('staff.show', $created))
            ->assertOk()
            ->assertDontSee('Temporary password');

        foreach (DB::table('audit_logs')->where('auditable_id', $created->id)->pluck('new_values') as $values) {
            $this->assertStringNotContainsString('password', (string) $values);
        }
    }

    /** A duplicate email is refused, and the message names the real reason. */
    public function test_a_duplicate_email_is_refused_under_the_email_field(): void
    {
        User::factory()->create(['email' => 'taken@business.com']);

        $this->actingAs($this->admin)
            ->from(route('staff.create'))
            ->post(route('staff.store'), $this->payload(['email' => 'taken@business.com']))
            ->assertSessionHasErrors(['email' => \App\Support\UnavailableIdentifier::EMAIL])
            // Never attributed to another field.
            ->assertSessionDoesntHaveErrors('name');

        $this->assertSame(1, User::query()->where('email', 'taken@business.com')->count());
    }

    /** A refused submission keeps everything the operator typed, including the chosen role. */
    public function test_a_refused_submission_preserves_the_entered_values(): void
    {
        User::factory()->create(['email' => 'taken@business.com']);

        $this->actingAs($this->admin);
        $this->from(route('staff.create'))->post(route('staff.store'), $this->payload([
            'email' => 'taken@business.com', 'name' => 'Funke Ojo', 'role' => 'sales_rep',
        ]));

        $html = $this->get(route('staff.create'))->assertOk()->getContent();

        $this->assertStringContainsString('value="Funke Ojo"', $html);
        $this->assertStringContainsString('value="taken@business.com"', $html);
        $this->assertStringContainsString('08012345678', $html);
        // The chosen role survives, so the operator does not have to pick it again.
        $this->assertMatchesRegularExpression('/value="sales_rep"[^>]*\schecked/', $html);
    }

    public function test_the_optional_phone_may_be_omitted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('staff.store'), $this->payload(['phone' => '']))
            ->assertOk();

        $this->assertNull(User::query()->where('email', 'ade@business.com')->sole()->phone);
    }

    public function test_the_role_must_be_one_the_form_offers(): void
    {
        $this->actingAs($this->admin)
            ->post(route('staff.store'), $this->payload(['role' => 'admin']))
            ->assertSessionHasErrors('role');

        $this->assertSame(0, User::query()->where('email', 'ade@business.com')->count());
    }

    /** Creating a staff account remains an Admin act. */
    public function test_the_form_is_closed_to_a_manager(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);

        $this->actingAs($manager)->get(route('staff.create'))->assertForbidden();
        $this->actingAs($manager)->post(route('staff.store'), $this->payload())->assertForbidden();
    }

    /** The create redesign is its own markup; Edit still uses the shared partial. */
    public function test_edit_staff_was_not_restyled(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Manager]);

        $html = $this->actingAs($this->admin)->get(route('staff.edit', $staff))->assertOk()->getContent();

        // Edit keeps the shared partial's controls and gains none of the create-only classes.
        $this->assertStringNotContainsString('stf-create', $html);
        $this->assertStringNotContainsString('stf-role', $html);
    }
}
