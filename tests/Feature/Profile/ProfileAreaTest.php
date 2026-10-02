<?php

namespace Tests\Feature\Profile;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The account area: My profile, the logout gate, and the error states.
 *
 * The rules worth protecting: signing out is never a GET, an error page never carries an exception,
 * and an unauthenticated visitor is never shown the authenticated shell.
 */
class ProfileAreaTest extends TestCase
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

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin, 'name' => 'Tunde Akin', 'phone' => '+2348025550190',
        ]);
    }

    // ── My profile ──────────────────────────────────────────────────────────────────────────────

    public function test_a_signed_in_user_sees_their_own_real_details(): void
    {
        $html = $this->actingAs($this->admin)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('My profile', $html);
        $this->assertStringContainsString('Tunde Akin', $html);
        $this->assertStringContainsString($this->admin->email, $html);
        $this->assertStringContainsString('+2348025550190', $html);
    }

    /**
     * Name, email and phone are self-service.
     *
     * This replaces an earlier rule that they were administrator-managed. The old helper sentence
     * must be gone rather than merely hidden: leaving it beside editable inputs would tell the
     * account holder to go and ask somebody for a change they can make themselves.
     */
    public function test_identity_fields_are_editable_and_the_admin_managed_notice_is_gone(): void
    {
        $html = $this->actingAs($this->admin)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertTrue(Route::has('profile.update'));
        $this->assertStringContainsString('action="'.route('profile.update').'"', $html);

        foreach (['name', 'email', 'phone'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }
        $this->assertStringContainsString('Save changes', $html);

        // The retired sentence, in full and in fragments.
        $this->assertStringNotContainsString(
            'Your name, email and phone are managed by an administrator. Ask them to change any of these.',
            $html
        );
        $this->assertStringNotContainsString('managed by an administrator', $html);
        $this->assertStringNotContainsString('Ask them to change', $html);
    }

    /** The profile is self-service: every signed-in role reaches their own, and only their own. */
    public function test_every_role_can_open_their_own_profile(): void
    {
        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();

            // Their own name, and never another user's. Compared against the escaped form: a
            // faker name like "Leonor O'Hara" is rendered as "O&#039;Hara", and asserting on the
            // raw string made this test fail roughly one run in forty for no real reason.
            $this->assertStringContainsString(e($user->name), $html);
            $this->assertStringNotContainsString($this->admin->email, $html);
        }
    }

    /** A guest cannot reach the profile at all. */
    public function test_a_guest_cannot_open_the_profile(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    /**
     * The Business & team rows respect the policies that own those pages, so a Sales Rep is not
     * offered a door that would only return 403.
     */
    public function test_the_business_and_staff_rows_follow_the_existing_policies(): void
    {
        $admin = $this->actingAs($this->admin)->get(route('profile.edit'))->getContent();
        $this->assertStringContainsString(route('staff.index'), $admin);
        $this->assertStringContainsString(route('settings.business.edit'), $admin);

        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $html = $this->actingAs($rep)->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('staff.index'), $html);
    }

    /** Billing does not exist, so the row is inert rather than a link to nothing. */
    public function test_billing_is_shown_disabled_and_is_not_a_link(): void
    {
        $html = $this->actingAs($this->admin)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('Billing &amp; plan', $html);
        $this->assertStringContainsString('pf-row is-disabled', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
    }

    // ── Logout ──────────────────────────────────────────────────────────────────────────────────

    /** Signing out is a POST. A GET must never end a session. */
    public function test_logout_is_not_reachable_by_get(): void
    {
        $this->actingAs($this->admin)->get('/logout')->assertStatus(405);
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_a_posted_logout_signs_the_user_out(): void
    {
        $this->actingAs($this->admin)->post(route('logout'))->assertRedirect();

        $this->assertGuest();
    }

    /** The confirmation is rendered, and the sidebar icon opens it rather than signing out. */
    public function test_the_logout_confirmation_is_rendered(): void
    {
        $html = $this->actingAs($this->admin)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('Log out of Inventra?', $html);
        $this->assertStringContainsString("You'll need to sign in again to access your business.", $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        // The real POST, with its token, still does the work.
        $this->assertStringContainsString('action="'.route('logout').'"', $html);
    }

    // ── Password ────────────────────────────────────────────────────────────────────────────────

    /** Change password uses the existing flow; no second implementation was created. */
    public function test_change_password_points_at_the_existing_flow(): void
    {
        $html = $this->actingAs($this->admin)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertStringContainsString(route('password.request'), $html);
        $this->assertFalse(Route::has('profile.password.update'));
    }

    // ── Error states ────────────────────────────────────────────────────────────────────────────

    public function test_a_missing_page_returns_404_with_the_designed_state(): void
    {
        $response = $this->actingAs($this->admin)->get('/a-page-that-does-not-exist');

        $response->assertStatus(404);
        $response->assertSee('Page not found');
        $response->assertSee("doesn't exist or has moved", false);
    }

    /**
     * An unauthenticated 404 must not render the application shell.
     *
     * The shell contains the navigation and the account card — a real name and role — so showing it
     * to a signed-out visitor would leak who uses this installation.
     */
    public function test_an_unauthenticated_404_does_not_leak_the_app_shell(): void
    {
        $response = $this->get('/a-page-that-does-not-exist');

        $response->assertStatus(404);
        $response->assertSee('Page not found');
        $response->assertDontSee('Tunde Akin');
        $response->assertDontSee('inventra-user-card', false);
        $response->assertDontSee('WhatsApp Automation');
    }

    /**
     * Session/CSRF expiry is truthful.
     *
     * The Figma says "Unsaved changes are kept on this device." Inventra stores no local form
     * state, so that sentence is deliberately absent — promising a recovery that will not happen
     * would be worse than the shorter message.
     */
    public function test_the_session_expired_page_makes_no_false_persistence_claim(): void
    {
        $html = view('errors.419')->render();

        $this->assertStringContainsString('Your session has expired', $html);
        $this->assertStringContainsString('please log in again to continue', $html);
        $this->assertStringNotContainsString('Unsaved changes are kept', $html);
        $this->assertStringContainsString(route('login'), $html);
    }

    /** The 500 page never carries the exception. */
    public function test_the_error_page_does_not_expose_exception_detail(): void
    {
        $html = view('errors.500')->render();

        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringContainsString('An unexpected error occurred on our end.', $html);

        foreach (['Exception', 'Stack trace', 'SQLSTATE', '/Users/', 'vendor/laravel'] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
    }
}
