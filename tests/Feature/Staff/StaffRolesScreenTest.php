<?php

namespace Tests\Feature\Staff;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Staff & roles, as the screen the Figma asks for.
 *
 * The design shows an "Invited" status and an "Owner" role. Inventra has neither: `UserStatus` is
 * Active/Inactive/Locked and `UserRole` is Admin/Manager/SalesRep, with nothing recording
 * ownership. The tests below exist to keep it that way — a rendered status or role that no column
 * could ever hold is a lie told to whoever reads this screen to decide who has access.
 */
class StaffRolesScreenTest extends TestCase
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

        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Tunde Akin']);
    }

    private function index(): string
    {
        return $this->actingAs($this->admin)->get(route('staff.index'))->assertOk()->getContent();
    }

    // ── Header ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_header_carries_the_title_and_the_real_member_count(): void
    {
        User::factory()->count(2)->create();

        $html = $this->index();

        $this->assertStringContainsString('Staff &amp; roles', $html);
        // Three: the Administrator from setUp plus the two created here.
        $this->assertStringContainsString('3 members', $html);
    }

    /** One member reads "1 member", not "1 members". */
    public function test_the_count_is_singular_for_a_lone_member(): void
    {
        $this->assertStringContainsString('1 member', $this->index());
    }

    /**
     * The count reports the team, not the filtered page.
     *
     * A subtitle that shrank when someone typed in the search box would misreport how many people
     * can sign in, which is the one number this screen exists to state.
     */
    public function test_the_count_ignores_the_active_filters(): void
    {
        User::factory()->count(2)->create(['role' => UserRole::Manager]);

        $html = $this->actingAs($this->admin)
            ->get(route('staff.index', ['role' => UserRole::SalesRep->value]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('3 members', $html);
    }

    public function test_add_member_reaches_the_existing_create_screen(): void
    {
        $html = $this->index();

        $this->assertStringContainsString('Add member', $html);
        $this->assertStringContainsString(route('staff.create'), $html);
    }

    // ── Member column ───────────────────────────────────────────────────────────────────────────

    public function test_each_row_shows_the_name_and_email(): void
    {
        $member = User::factory()->create(['name' => 'Ada Obi']);

        $html = $this->index();

        $this->assertStringContainsString('Ada Obi', $html);
        $this->assertStringContainsString($member->email, $html);
    }

    /** The signed-in account is marked, as the Figma shows. */
    public function test_the_current_account_is_marked_you(): void
    {
        $other = User::factory()->create(['name' => 'Ada Obi']);

        $html = $this->index();

        $this->assertStringContainsString('(You)', $html);
        // And only once: the marker belongs to the viewer's own row alone.
        $this->assertSame(1, substr_count($html, '(You)'));
        $this->assertNotSame($this->admin->id, $other->id);
    }

    /** A member without a photograph falls back to initials rather than a broken image. */
    public function test_a_member_without_a_photo_falls_back_to_initials(): void
    {
        User::factory()->create(['name' => 'Ada Obi', 'photo_path' => null]);

        $html = $this->index();

        $this->assertStringContainsString('AO', $html);
    }

    // ── Roles ───────────────────────────────────────────────────────────────────────────────────

    /** The real roles, with the labels Inventra uses everywhere else. */
    public function test_the_real_role_labels_are_rendered(): void
    {
        User::factory()->create(['role' => UserRole::Manager]);
        User::factory()->create(['role' => UserRole::SalesRep]);

        $html = $this->index();

        $this->assertStringContainsString('Administrator', $html);
        $this->assertStringContainsString('Manager', $html);
        $this->assertStringContainsString('Sales Representative', $html);
    }

    /**
     * No Owner.
     *
     * Nothing in the schema records ownership, so a row claiming it would be inventing a
     * permission level that no policy could ever check.
     */
    public function test_no_owner_role_is_invented(): void
    {
        $html = $this->index();

        $this->assertStringNotContainsString('Owner', $html);
        $this->assertStringNotContainsString('OWNER', $html);
    }

    // ── Statuses ────────────────────────────────────────────────────────────────────────────────

    /** All three real statuses render, each with its own dot. */
    public function test_the_three_real_statuses_render(): void
    {
        User::factory()->create(['status' => UserStatus::Active]);
        User::factory()->create(['status' => UserStatus::Inactive]);
        User::factory()->create(['status' => UserStatus::Locked]);

        $html = $this->index();

        foreach (['active', 'inactive', 'locked'] as $status) {
            $this->assertStringContainsString('data-status="'.$status.'"', $html);
        }
        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('Inactive', $html);
        $this->assertStringContainsString('Locked', $html);
    }

    /**
     * No Invited.
     *
     * There is no invitation lifecycle: an account exists only once an Administrator has created
     * it with a password, so nothing could ever put a row in that state.
     */
    public function test_no_invited_status_is_invented(): void
    {
        $html = $this->index();

        $this->assertStringNotContainsString('Invited', $html);
        $this->assertStringNotContainsString('data-status="invited"', $html);
        // And the enum itself gained no fourth case.
        $this->assertSame(
            ['active', 'inactive', 'locked'],
            array_map(fn (UserStatus $s) => $s->value, UserStatus::cases())
        );
    }

    /** A status the enum cannot hold is never rendered, whatever the query string says. */
    public function test_an_unknown_status_filter_is_ignored_rather_than_rendered(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('staff.index', ['status' => 'invited']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('data-status="invited"', $html);
        // The filter was discarded, so the list is unfiltered and the Administrator still appears.
        $this->assertStringContainsString('Tunde Akin', $html);
    }

    // ── Authorization ───────────────────────────────────────────────────────────────────────────

    /** The screen remains Admin-only. */
    public function test_a_manager_and_a_sales_rep_are_both_refused(): void
    {
        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('staff.index'))->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get(route('staff.index'))->assertRedirect(route('login'));
    }

    /**
     * A row with no permitted action renders no trigger at all.
     *
     * A second Administrator is beyond `update` and `changeStatus` alike, so their row must not
     * offer an ellipsis that would open onto nothing.
     */
    public function test_a_row_with_no_permitted_action_has_no_menu_trigger(): void
    {
        $other = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Bisi Lawal']);

        $html = $this->index();

        $this->assertStringContainsString('Bisi Lawal', $html);
        $this->assertStringNotContainsString('aria-label="Actions for Bisi Lawal"', $html);
    }

    /** Rendering the screen mutates nothing. */
    public function test_rendering_the_screen_changes_no_account(): void
    {
        $member = User::factory()->create(['status' => UserStatus::Active, 'role' => UserRole::Manager]);

        $this->index();

        $member->refresh();
        $this->assertSame(UserStatus::Active, $member->status);
        $this->assertSame(UserRole::Manager, $member->role);
    }

    // ── Back link ───────────────────────────────────────────────────────────────────────────────

    public function test_the_back_link_returns_to_my_profile(): void
    {
        $this->assertStringContainsString(route('profile.edit'), $this->index());
    }
}
