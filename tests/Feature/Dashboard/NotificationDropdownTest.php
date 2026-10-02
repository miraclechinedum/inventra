<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Alerts\ReconcileOperationalAlerts;
use App\Enums\UserRole;
use App\Models\OperationalAlertRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Notifications\AlertFixture;
use Tests\TestCase;

/**
 * The navbar notification dropdown.
 *
 * The preview reuses AlertInbox's own scoped query rather than re-implementing one, which is the
 * property these tests protect: a dropdown that built its own query could drift from the inbox and
 * surface an alert the page itself would refuse. Everything else — read state, mark-all-read — is
 * the existing backend, exercised here rather than mocked.
 */
class NotificationDropdownTest extends TestCase
{
    use RefreshDatabase;

    private AlertFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->fixture = new AlertFixture;
    }

    /** Produces at least one real low-stock alert through the domain, not by inserting rows. */
    private function raiseLowStockAlert(User $actor): void
    {
        $this->fixture->product($actor, ['initial_stock' => '1', 'reorder_level' => '20']);
        app(ReconcileOperationalAlerts::class)->execute();
    }

    private function dashboard(User $user): string
    {
        return $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
    }

    // ── Rendering ───────────────────────────────────────────────────────────────────────────────

    public function test_the_dropdown_renders_real_alerts_with_a_working_mark_all_read_form(): void
    {
        $admin = $this->fixture->admin();
        $this->raiseLowStockAlert($admin);

        $html = $this->dashboard($admin);

        $this->assertStringContainsString('Notifications', $html);
        $this->assertStringContainsString('Mark all read', $html);
        // The real action, CSRF-protected — not a visual fake.
        $this->assertStringContainsString('action="'.route('notifications.read-all').'"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('View all notifications', $html);
        $this->assertStringContainsString(route('notifications.index'), $html);
    }

    public function test_an_alert_row_links_to_its_own_notification(): void
    {
        $admin = $this->fixture->admin();
        $this->raiseLowStockAlert($admin);

        $notification = OperationalAlertRecipient::query()->forUser($admin)->firstOrFail();

        $this->assertStringContainsString(route('notifications.show', $notification), $this->dashboard($admin));
    }

    public function test_the_preview_is_bounded(): void
    {
        $admin = $this->fixture->admin();
        foreach (range(1, 6) as $index) {
            $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '20']);
        }
        app(ReconcileOperationalAlerts::class)->execute();

        $this->assertGreaterThan(3, OperationalAlertRecipient::query()->forUser($admin)->count());

        $html = $this->dashboard($admin);
        $this->assertLessThanOrEqual(3, substr_count($html, 'class="topbar-notification '), 'the preview stays short');
    }

    public function test_an_operator_with_no_alerts_sees_a_truthful_empty_state(): void
    {
        $html = $this->dashboard($this->fixture->admin());

        $this->assertStringContainsString('You have no notifications.', $html);
    }

    // ── Scoping ─────────────────────────────────────────────────────────────────────────────────

    /**
     * The preview never carries another operator's alerts.
     *
     * Delivery is per user, and the preview is scoped to the viewer's own recipient rows — so an
     * alert raised for one Admin is invisible to a second one who was not a recipient.
     */
    public function test_the_preview_shows_only_the_viewers_own_deliveries(): void
    {
        $admin = $this->fixture->admin('First Admin');
        $this->raiseLowStockAlert($admin);

        $mine = OperationalAlertRecipient::query()->forUser($admin)->count();
        $this->assertGreaterThan(0, $mine);

        // A second Admin created afterwards received none of the existing deliveries.
        $other = $this->fixture->admin('Second Admin');
        $this->assertSame(0, OperationalAlertRecipient::query()->forUser($other)->count());
        $this->assertStringContainsString('You have no notifications.', $this->dashboard($other));
    }

    /** A Sales Representative is entitled to no alert types, so the bell is not rendered at all. */
    public function test_a_sales_representative_gets_no_bell_and_no_preview(): void
    {
        $admin = $this->fixture->admin();
        $this->raiseLowStockAlert($admin);

        $html = $this->dashboard($this->fixture->rep());

        $this->assertStringNotContainsString('topbar-notification-panel', $html);
        $this->assertStringNotContainsString('Mark all read', $html);
    }

    // ── Behaviour ───────────────────────────────────────────────────────────────────────────────

    public function test_mark_all_read_uses_the_existing_backend_and_clears_the_badge(): void
    {
        $admin = $this->fixture->admin();
        $this->raiseLowStockAlert($admin);

        $this->assertGreaterThan(0, OperationalAlertRecipient::query()->forUser($admin)->unread()->count());

        $this->actingAs($admin)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertSame(0, OperationalAlertRecipient::query()->forUser($admin)->unread()->count());
    }

    public function test_a_guest_cannot_mark_notifications_read(): void
    {
        $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
    }

    /** An unread delivery is marked in the markup, so the state is visible, not only coloured. */
    public function test_unread_alerts_are_marked_in_the_dropdown(): void
    {
        $admin = $this->fixture->admin();
        $this->raiseLowStockAlert($admin);

        $this->assertStringContainsString('is-unread', $this->dashboard($admin));
        $this->assertStringContainsString('aria-label="Unread"', $this->dashboard($admin));
    }

    // ── Accessibility ───────────────────────────────────────────────────────────────────────────

    public function test_the_bell_declares_what_it_opens(): void
    {
        $admin = $this->fixture->admin();
        $this->raiseLowStockAlert($admin);

        $html = $this->dashboard($admin);

        $this->assertStringContainsString('aria-haspopup="menu"', $html);
        $this->assertStringContainsString('aria-controls="topbar-notification-panel"', $html);
        // The unread count reaches assistive technology, not only the coloured badge.
        $this->assertMatchesRegularExpression('/aria-label="Notifications, \d+ unread"/', $html);
    }

    public function test_the_dropdown_closes_on_escape_and_outside_click(): void
    {
        $html = $this->dashboard($this->fixture->admin());

        $this->assertStringContainsString('x-on:keydown.escape.window="close"', $html);
        $this->assertStringContainsString('x-on:click.outside="close"', $html);
    }
}
