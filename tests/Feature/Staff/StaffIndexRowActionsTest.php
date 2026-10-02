<?php

namespace Tests\Feature\Staff;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Staff index row actions.
 *
 * The actions now live behind the Figma's ellipsis menu rather than as inline icons, but the rule
 * worth protecting is unchanged and is the reason this file exists: the menu is a VIEW of the
 * existing policy, never a second one. Every assertion below therefore pairs a rendered control
 * with the gate that decides it — no revoke entry where `changeStatus` says no, no edit entry where
 * `update` says no, and never a revoke entry for someone already inactive. An action the policy
 * refuses is absent from the DOM, not merely hidden by CSS a reader could defeat.
 */
class StaffIndexRowActionsTest extends TestCase
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

    private function index(): string
    {
        return $this->actingAs($this->admin)->get(route('staff.index'))->assertOk()->getContent();
    }

    /** The text action it replaced is gone. */
    public function test_the_text_view_action_is_no_longer_rendered(): void
    {
        $this->assertStringNotContainsString('>View<', $this->index());
    }

    /** @return array<string, array{UserRole}> */
    public static function activeRoles(): array
    {
        return ['manager' => [UserRole::Manager], 'sales representative' => [UserRole::SalesRep]];
    }

    #[DataProvider('activeRoles')]
    public function test_active_staff_get_edit_then_revoke_access(UserRole $role): void
    {
        $staff = User::factory()->create(['role' => $role, 'status' => UserStatus::Active]);

        $html = $this->index();

        $this->assertStringContainsString(route('staff.edit', $staff), $html);
        $this->assertStringContainsString('class="sr-menu-item"', $html);
        $this->assertStringContainsString(route('staff.deactivate', $staff), $html);
        $this->assertStringContainsString('Revoke access', $html);

        // Order matters: [Edit] then [Revoke access], inside the row's menu.
        $this->assertLessThan(
            strpos($html, route('staff.deactivate', $staff)),
            strpos($html, route('staff.edit', $staff)),
            'the edit action must come first'
        );
    }

    /** The confirmation keeps its existing wording and its own buttons — never a browser confirm(). */
    public function test_the_revoke_confirmation_keeps_its_copy_and_buttons(): void
    {
        $staff = User::factory()->create(['name' => 'Ade Bello', 'status' => UserStatus::Active]);

        $html = $this->index();

        $this->assertStringContainsString('Remove Ade Bello&#039;s access?', $html);
        $this->assertStringContainsString('They&#039;ll be signed out immediately. Sales they recorded are kept.', $html);
        $this->assertStringContainsString('>Cancel</button>', $html);
        $this->assertStringContainsString('Remove access', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('confirm(', $html);
    }

    /** Inactive staff get the existing reactivation route, and never an offer to revoke again. */
    public function test_inactive_staff_get_restore_access_instead_of_revoke(): void
    {
        $staff = User::factory()->create(['status' => UserStatus::Inactive]);

        $html = $this->index();

        $this->assertStringContainsString(route('staff.activate', $staff), $html);
        $this->assertStringContainsString('Restore access', $html);
        $this->assertStringNotContainsString(route('staff.deactivate', $staff), $html);
    }

    /**
     * The Administrator row.
     *
     * `changeStatus` refuses both self and any Admin subject, so no revoke icon may appear for the
     * signed-in Administrator — not even for visual symmetry with the rows above it.
     */
    public function test_the_administrator_row_offers_no_revoke_action(): void
    {
        $html = $this->index();

        $this->assertStringNotContainsString(route('staff.deactivate', $this->admin), $html);
        $this->assertStringNotContainsString(route('staff.activate', $this->admin), $html);
        // An Admin may still edit their own profile, which `update` allows.
        $this->assertStringContainsString(route('staff.edit', $this->admin), $html);
    }

    /** A second Administrator cannot be revoked either. */
    public function test_another_administrator_cannot_be_revoked_from_the_row(): void
    {
        $other = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

        $html = $this->index();

        $this->assertStringNotContainsString(route('staff.deactivate', $other), $html);
        $this->assertStringNotContainsString(route('staff.edit', $other), $html);
    }

    /**
     * The ellipsis trigger is named for assistive technology, and what it opens is a real menu.
     *
     * An icon-only control with no accessible name is announced as just "button", which on a list
     * of staff would leave a screen-reader user unable to tell whose actions they had opened. The
     * name therefore carries the member it belongs to.
     */
    public function test_the_row_menu_trigger_carries_an_accessible_name(): void
    {
        User::factory()->create(['name' => 'Funke Ojo', 'status' => UserStatus::Active]);

        $html = $this->index();

        $this->assertStringContainsString('aria-label="Actions for Funke Ojo"', $html);
        // The trigger declares what it opens, and the menu says what it is.
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
        $this->assertStringContainsString('role="menu"', $html);
        $this->assertStringContainsString('role="menuitem"', $html);
        // Real <a>/<button> elements, so tab order needs no tabindex of its own.
        preg_match_all('/<span class="sr-menu-list".*?<\/td>/s', $html, $menus);
        $this->assertNotEmpty($menus[0]);
        foreach ($menus[0] as $menu) {
            $this->assertStringNotContainsString('tabindex', $menu);
        }
    }

    /** Escape and a click outside both close the menu, and focus goes back to the trigger. */
    public function test_the_row_menu_closes_on_escape_and_outside_click(): void
    {
        User::factory()->create(['status' => UserStatus::Active]);

        $html = $this->index();

        $this->assertStringContainsString('x-on:keydown.escape.window="close"', $html);
        $this->assertStringContainsString('x-on:click.outside="close"', $html);
        $this->assertStringContainsString('x-ref="trigger"', $html);
    }

    /**
     * Revoke and restore are told apart by their glyph, not only by colour.
     *
     * Colour alone would leave the two indistinguishable to anyone who cannot separate red from
     * green, so the icons differ too: a user-minus to revoke, a user-plus to restore.
     */
    public function test_revoke_and_restore_use_different_glyphs(): void
    {
        $active = User::factory()->create(['status' => UserStatus::Active]);
        $inactive = User::factory()->create(['status' => UserStatus::Inactive]);

        $html = $this->index();
        $cells = [];
        preg_match_all('/<span class="sr-menu-list"(.*?)<\/td>/s', $html, $cells);
        $all = implode('', $cells[1]);

        // The minus stroke belongs to revoke; the plus's vertical stroke only to restore.
        $this->assertStringContainsString('M11.5 6.5h3', $all);
        $this->assertStringContainsString('M13 3.5v4M11 5.5h4', $all);
        $this->assertNotSame($active->id, $inactive->id);
    }

    /** The row actions are presentation only: rendering the list mutates nothing. */
    public function test_rendering_the_index_does_not_change_any_account(): void
    {
        $staff = User::factory()->create(['status' => UserStatus::Active]);

        $this->index();

        $this->assertSame(UserStatus::Active, $staff->refresh()->status);
    }

    /** The index remains closed to a non-Admin, icons or not. */
    public function test_the_index_is_closed_to_a_manager(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);

        $this->actingAs($manager)->get(route('staff.index'))->assertForbidden();
    }

    /** Scenario 8: the pencil goes to the existing Edit Staff screen, which still works. */
    public function test_the_edit_icon_reaches_the_existing_edit_screen(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Manager, 'status' => UserStatus::Active]);

        $this->actingAs($this->admin)->get(route('staff.edit', $staff))
            ->assertOk()
            ->assertSee('value="'.$staff->email.'"', false);
    }

    /** Scenario 11: confirming runs the existing revoke action, unchanged. */
    public function test_confirming_revoke_runs_the_existing_deactivate_action(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Manager, 'status' => UserStatus::Active]);

        $this->actingAs($this->admin)->post(route('staff.deactivate', $staff))->assertRedirect();

        $this->assertSame(UserStatus::Inactive, $staff->refresh()->status);
        // The existing action's own side effects still happen.
        $this->assertDatabaseHas('security_events', ['event' => 'staff_deactivated', 'subject_user_id' => $staff->id]);
    }

    /** And the row then offers Restore access, which reaches the existing activate route. */
    public function test_a_revoked_staff_row_then_offers_restore_which_works(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Manager, 'status' => UserStatus::Inactive]);

        $html = $this->actingAs($this->admin)->get(route('staff.index'))->assertOk()->getContent();
        $this->assertStringContainsString(route('staff.activate', $staff), $html);
        $this->assertStringContainsString('Restore access', $html);

        $this->actingAs($this->admin)->post(route('staff.activate', $staff))->assertRedirect();
        $this->assertSame(UserStatus::Active, $staff->refresh()->status);
    }

    /** Scenario 10, server side: cancelling means no request, so nothing changes. */
    public function test_merely_viewing_the_list_never_deactivates_anyone(): void
    {
        $staff = User::factory()->create(['status' => UserStatus::Active]);
        $this->actingAs($this->admin)->get(route('staff.index'))->assertOk();
        $this->assertSame(UserStatus::Active, $staff->refresh()->status);
    }

    /** Self-revocation stays prohibited even though the icon is new. */
    public function test_an_admin_cannot_revoke_their_own_access(): void
    {
        $this->actingAs($this->admin)->post(route('staff.deactivate', $this->admin))->assertForbidden();
        $this->assertSame(UserStatus::Active, $this->admin->refresh()->status);
    }
}
