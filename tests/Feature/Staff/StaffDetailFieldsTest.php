<?php

namespace Tests\Feature\Staff;

use App\Actions\Staff\CreateStaff;
use App\Actions\Staff\RequireStaffPasswordChange;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Staff detail page no longer surfaces the last-login IP or the password-change flag. Both are
 * still recorded and still enforced: this is a presentation change, so the tests below assert the
 * fields are gone from the page *and* that the behaviour behind them is untouched.
 */
class StaffDetailFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function accountCard(string $html): string
    {
        $start = mb_strpos($html, 'Account information');
        $this->assertNotFalse($start, 'The Account information card must be rendered');
        $end = mb_strpos($html, '</dl>', $start);
        $this->assertNotFalse($end);

        return mb_substr($html, $start, $end - $start);
    }

    /* -------------------------------------------------------------- removed fields */

    public function test_the_detail_page_no_longer_shows_the_last_login_ip_or_password_change_flag(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create([
            'role' => UserRole::Manager,
            'last_login_ip' => '203.0.113.77',
            'force_password_change' => true,
        ]);

        $html = $this->actingAs($admin)->get(route('staff.show', $staff))->assertOk()->getContent();

        foreach (['Last login IP', 'LAST LOGIN IP', 'Password change required', 'PASSWORD CHANGE REQUIRED'] as $label) {
            $this->assertStringNotContainsString($label, $html, "{$label} must be gone from the page");
        }
        // Not merely relabelled: the values themselves must not appear anywhere on the page.
        $this->assertStringNotContainsString('203.0.113.77', $html, 'The recorded IP must not be exposed');
        $this->assertStringNotContainsString('last_login_ip', $html);
        $this->assertStringNotContainsString('force_password_change', $html);
    }

    public function test_the_account_card_keeps_exactly_the_six_retained_fields_with_no_blank_cells(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => UserRole::Manager, 'phone' => null]);

        $card = $this->accountCard($this->actingAs($admin)->get(route('staff.show', $staff))->assertOk()->getContent());

        $expected = ['Email address', 'Phone', 'Last login', 'Quick PIN configured', 'Account created', 'Created by'];
        foreach ($expected as $label) {
            $this->assertStringContainsString($label, $card, "{$label} must be retained");
        }

        // Six terms and six definitions: three even rows of two, and no placeholder left behind.
        $this->assertSame(6, substr_count($card, '<dt'), 'Exactly six field labels');
        $this->assertSame(6, substr_count($card, '<dd'), 'Exactly six field values, so no cell is blank');
        $this->assertStringContainsString('sm:grid-cols-2', $card, 'Two columns on desktop, stacked on mobile');
        $this->assertStringContainsString('Not provided', $card, 'A missing phone still renders a real value');
        $this->assertDoesNotMatchRegularExpression('/<dd[^>]*>\s*<\/dd>/', $card, 'No empty value cell');
    }

    /* ------------------------------------------------- underlying data still tracked */

    public function test_the_last_login_ip_is_still_recorded_on_a_successful_login(): void
    {
        $staff = User::factory()->create([
            'role' => UserRole::Manager,
            'email' => 'tracked@example.com',
            'password' => Hash::make('Correct-Horse-9'),
            'force_password_change' => false,
            'quick_pin_setup_completed' => true,
            'last_login_ip' => null,
        ]);

        $this->post(route('login.store'), ['identifier' => 'tracked@example.com', 'password' => 'Correct-Horse-9'])
            ->assertRedirect();

        $this->assertNotNull(DB::table('users')->where('id', $staff->id)->value('last_login_ip'),
            'Login must still write the IP even though the page no longer shows it');
        $this->assertNotNull(DB::table('users')->where('id', $staff->id)->value('last_login_at'));
    }

    public function test_the_password_change_requirement_is_still_set_and_still_enforced(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => UserRole::Manager, 'force_password_change' => false]);

        // The administrative control still exists and still flips the column.
        $this->actingAs($admin)->post(route('staff.require-password-change', $staff))->assertRedirect();
        $this->assertSame(1, (int) DB::table('users')->where('id', $staff->id)->value('force_password_change'));

        // And the middleware still diverts that staff member to the onboarding screen.
        $this->actingAs($staff->fresh())->get(route('dashboard'))
            ->assertRedirect(route('onboarding.password.edit'));
    }

    public function test_new_staff_are_still_created_requiring_a_password_change(): void
    {
        $admin = $this->admin();

        $result = app(CreateStaff::class)->execute($admin,
            ['name' => 'Fresh Hire', 'email' => 'fresh@example.com', 'phone' => '08031112222'],
            UserRole::SalesRep);

        $user = $result instanceof User ? $result : ($result['user'] ?? User::query()->where('email', 'fresh@example.com')->firstOrFail());
        $this->assertSame(1, (int) DB::table('users')->where('id', $user->id)->value('force_password_change'),
            'The onboarding requirement is unchanged by the presentation cleanup');
    }

    public function test_the_require_password_change_action_still_records_a_security_event_and_revokes_sessions(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => UserRole::Manager, 'force_password_change' => false]);
        DB::table('sessions')->insert([
            'id' => 'session-under-test', 'user_id' => $staff->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => base64_encode(serialize([])), 'last_activity' => time(),
        ]);

        app(RequireStaffPasswordChange::class)->execute($admin, $staff);

        $this->assertSame(1, (int) DB::table('users')->where('id', $staff->id)->value('force_password_change'));
        $this->assertTrue(
            DB::table('security_events')
                ->where('user_id', $staff->id)
                ->where('event', 'staff_password_change_required')
                ->exists(),
            'The security action still leaves its evidence');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count(),
            'Requiring a password change still revokes the staff sessions');
    }

    /* ----------------------------------------------- security activity still visible */

    public function test_the_ip_remains_available_on_the_security_activity_page(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($admin)->post(route('staff.lock', $staff))->assertRedirect();

        // The IP is not on the profile card, but security activity is where it belongs.
        $this->actingAs($admin)->get(route('staff.activity', $staff))->assertOk()->assertSee('IP:');
    }

    /* ----------------------------------------------------------------- authorization */

    public function test_authorization_on_the_detail_page_is_unchanged(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Manager]);

        $this->get(route('staff.show', $staff))->assertRedirect(route('login'));

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('staff.show', $staff))->assertForbidden();
        }

        $this->actingAs($this->admin())->get(route('staff.show', $staff))->assertOk();
    }
}
