<?php

namespace Tests\Feature\Notifications;

use App\Actions\Alerts\AcknowledgeAlert;
use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\ArchiveProduct;
use App\Alerts\UnreadAlertCount;
use App\Enums\OperationalAlertStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\OperationalAlert;
use App\Models\OperationalAlertRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const FINGERPRINTED = ['operational_alerts', 'operational_alert_recipients', 'audit_logs', 'products', 'sales'];

    private AlertFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new AlertFixture;
    }

    /* --------------------------------------------------------------- authorization */

    public function test_a_guest_is_redirected_from_every_notification_route(): void
    {
        $admin = $this->fixture->admin();
        $recipient = $this->lowStockRecipient($admin);

        foreach ([
            ['get', route('notifications.index')],
            ['get', route('notifications.show', $recipient)],
            ['post', route('notifications.read', $recipient)],
            ['post', route('notifications.acknowledge', $recipient)],
            ['post', route('notifications.read-all')],
        ] as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }

        $this->assertNull($recipient->fresh()->read_at);
    }

    public function test_an_operator_may_only_reach_their_own_notification(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $this->lowStockRecipient($admin);

        $adminRow = OperationalAlertRecipient::query()->where('user_id', $admin->id)->sole();
        $managerRow = OperationalAlertRecipient::query()->where('user_id', $manager->id)->sole();

        // The Manager may not touch the Administrator's copy, and vice versa: read state records
        // what a specific person saw, so it is never another person's to set.
        $this->actingAs($manager)->get(route('notifications.show', $adminRow))->assertForbidden();
        $this->actingAs($manager)->post(route('notifications.read', $adminRow))->assertForbidden();
        $this->actingAs($manager)->post(route('notifications.acknowledge', $adminRow))->assertForbidden();
        $this->actingAs($admin)->post(route('notifications.read', $managerRow))->assertForbidden();

        $this->assertNull($adminRow->fresh()->read_at);
        $this->assertNull($adminRow->fresh()->acknowledged_at);
        $this->assertNull($managerRow->fresh()->read_at);
    }

    public function test_being_an_administrator_confers_no_authority_over_another_operators_state(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $this->lowStockRecipient($admin);
        $managerRow = OperationalAlertRecipient::query()->where('user_id', $manager->id)->sole();

        $this->actingAs($admin)->get(route('notifications.show', $managerRow))->assertForbidden();
        $this->actingAs($admin)->post(route('notifications.acknowledge', $managerRow))->assertForbidden();

        $this->assertNull($managerRow->fresh()->acknowledged_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'operational_alert_acknowledged')->count());
    }

    public function test_a_sales_representative_sees_an_empty_list_and_no_privileged_content(): void
    {
        $admin = $this->fixture->admin();
        $rep = $this->fixture->rep();
        $this->lowStockRecipient($admin);
        $this->fixture->saleWithBalance($admin);

        $response = $this->actingAs($rep)->get(route('notifications.index'))->assertOk();

        $this->assertSame(0, OperationalAlertRecipient::query()->where('user_id', $rep->id)->count());
        $response->assertSee('You have no notifications', false);
        // Nothing about business-wide receivables or stock leaks into a representative's page.
        foreach (OperationalAlert::query()->pluck('message') as $message) {
            $response->assertDontSee($message, false);
        }
    }

    public function test_the_navigation_offers_notifications_to_management_only(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $rep = $this->fixture->rep();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Notifications');
        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertSee('Notifications');

        $repPage = $this->actingAs($rep)->get(route('dashboard'))->assertOk();
        $this->assertStringNotContainsString('notifications.index', $repPage->getContent());
        // Authorization never depends on the nav: the route itself is still safely scoped.
        $this->actingAs($rep)->get(route('notifications.index'))->assertOk();
    }

    /* ---------------------------------------------------------------- read / ack */

    public function test_read_state_is_claimed_by_an_explicit_post_and_is_idempotent(): void
    {
        $admin = $this->fixture->admin();
        $recipient = $this->lowStockRecipient($admin);
        $this->assertNull($recipient->read_at);

        $this->actingAs($admin)->post(route('notifications.read', $recipient))->assertRedirect();
        $first = $recipient->fresh()->read_at;
        $this->assertNotNull($first);

        $this->travel(2)->seconds();
        $this->actingAs($admin)->post(route('notifications.read', $recipient))->assertRedirect();

        $this->assertEquals($first, $recipient->fresh()->read_at,
            'read_at answers when they first saw it, so a repeat must not move it');
        $this->assertSame(0, AuditLog::query()->where('action', 'operational_alert_read')->count(),
            'Read state is private housekeeping and stays out of the Audit Trail');
    }

    public function test_opening_the_detail_page_does_not_mark_it_read(): void
    {
        $admin = $this->fixture->admin();
        $recipient = $this->lowStockRecipient($admin);

        $this->actingAs($admin)->get(route('notifications.show', $recipient))->assertOk();

        $this->assertNull($recipient->fresh()->read_at, 'A GET must never mutate read state');
    }

    public function test_acknowledging_implies_read_records_a_timestamp_and_is_idempotent(): void
    {
        $admin = $this->fixture->admin();
        $recipient = $this->lowStockRecipient($admin);

        $this->actingAs($admin)->post(route('notifications.acknowledge', $recipient))->assertRedirect();

        $fresh = $recipient->fresh();
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertNotNull($fresh->read_at, 'Acknowledging implies having seen it');
        $this->assertSame(1, AuditLog::query()->where('action', 'operational_alert_acknowledged')->count());

        $this->travel(2)->seconds();
        $this->actingAs($admin)->post(route('notifications.acknowledge', $recipient))->assertRedirect();

        $this->assertEquals($fresh->acknowledged_at, $recipient->fresh()->acknowledged_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'operational_alert_acknowledged')->count(),
            'Acknowledging twice is not two acknowledgements');
    }

    public function test_acknowledging_preserves_an_earlier_read_timestamp(): void
    {
        $admin = $this->fixture->admin();
        $recipient = $this->lowStockRecipient($admin);

        $this->actingAs($admin)->post(route('notifications.read', $recipient));
        $readAt = $recipient->fresh()->read_at;

        $this->travel(5)->seconds();
        $this->actingAs($admin)->post(route('notifications.acknowledge', $recipient));

        $this->assertEquals($readAt, $recipient->fresh()->read_at);
        $this->assertNotEquals($readAt, $recipient->fresh()->acknowledged_at);
    }

    public function test_acknowledgement_does_not_resolve_the_underlying_condition(): void
    {
        $admin = $this->fixture->admin();
        $recipient = $this->lowStockRecipient($admin);

        $this->actingAs($admin)->post(route('notifications.acknowledge', $recipient));

        $this->assertSame(OperationalAlertStatus::Active, $recipient->fresh()->alert()->sole()->status,
            'An operator saying "I am aware" must not close a condition that is still true');
    }

    public function test_a_resolved_alert_can_still_be_read_and_acknowledged(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $recipient = OperationalAlertRecipient::query()->where('user_id', $admin->id)->sole();

        app(AdjustStock::class)->execute($admin, $product->fresh(), [
            'type' => 'restock', 'operation' => 'increase', 'quantity' => '100', 'reason' => 'Delivery',
        ]);

        $this->actingAs($admin)->post(route('notifications.read', $recipient))->assertRedirect();
        $this->actingAs($admin)->post(route('notifications.acknowledge', $recipient))->assertRedirect();

        $this->assertNotNull($recipient->fresh()->read_at);
        $this->assertNotNull($recipient->fresh()->acknowledged_at);
    }

    public function test_the_request_cannot_forge_timestamps_status_or_ownership(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $this->lowStockRecipient($admin);
        $recipient = OperationalAlertRecipient::query()->where('user_id', $admin->id)->sole();
        $alert = $recipient->alert()->sole();

        $this->actingAs($admin)->post(route('notifications.acknowledge', $recipient), [
            'user_id' => $manager->id,
            'operational_alert_id' => 999,
            'read_at' => '2000-01-01 00:00:00',
            'acknowledged_at' => '2000-01-01 00:00:00',
            'severity' => 'info', 'type' => 'data_integrity_warning', 'status' => 'resolved',
            'resolved_at' => '2000-01-01 00:00:00', 'active_key' => 'forged', 'occurrence' => 99,
            'subject_type' => 'sale', 'subject_id' => 1, 'title' => 'forged', 'message' => 'forged',
        ])->assertRedirect();

        $fresh = $recipient->fresh();
        $this->assertSame((int) $admin->id, (int) $fresh->user_id);
        $this->assertNotEquals('2000-01-01 00:00:00', $fresh->acknowledged_at->toDateTimeString());
        $this->assertNotEquals('2000-01-01 00:00:00', $fresh->read_at->toDateTimeString());

        $alert->refresh();
        $this->assertSame('inventory_low_stock', $alert->type->value);
        $this->assertSame('warning', $alert->severity->value);
        $this->assertSame('active', $alert->status->value);
        $this->assertSame('product', $alert->subject_type);
        $this->assertSame(1, (int) $alert->occurrence);
        $this->assertNotSame('forged', $alert->title);
        $this->assertNotSame('forged', $alert->active_key);
    }

    /* ------------------------------------------------------------------- read all */

    public function test_read_all_touches_only_the_current_operators_unread_rows(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5']);

        $this->assertSame(2, OperationalAlertRecipient::query()->where('user_id', $manager->id)->whereNull('read_at')->count());

        $this->actingAs($admin)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertSame(0, OperationalAlertRecipient::query()->where('user_id', $admin->id)->whereNull('read_at')->count());
        $this->assertSame(2, OperationalAlertRecipient::query()->where('user_id', $manager->id)->whereNull('read_at')->count(),
            'One operator marking all read must never touch another account');
    }

    public function test_read_all_is_a_single_bounded_statement_and_reports_no_change(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);

        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'update `operational_alert_recipients`')) {
                $updates++;
            }
        });

        $this->actingAs($admin)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(1, $updates, 'Marking all read must be one scoped UPDATE, not a row-by-row loop');

        DB::getEventDispatcher()->forget(QueryExecuted::class);
        $this->actingAs($admin)->post(route('notifications.read-all'))
            ->assertSessionHas('status', 'No unread notifications to mark.');
    }

    /* ---------------------------------------------------------------- unread count */

    public function test_the_unread_badge_counts_only_the_current_operator_and_survives_resolution(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $product = $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);

        $counter = app(UnreadAlertCount::class);
        $this->assertSame(1, $counter->for($admin));
        $this->assertSame(1, $counter->for($manager));

        app(AdjustStock::class)->execute($admin, $product->fresh(), [
            'type' => 'restock', 'operation' => 'increase', 'quantity' => '100', 'reason' => 'Delivery',
        ]);
        $counter->forget();

        // A resolved alert the operator never opened is still something they have not seen.
        $this->assertSame(1, $counter->for($admin));

        $this->actingAs($admin)->post(route('notifications.read-all'));
        $counter->forget();
        $this->assertSame(0, $counter->for($admin));
        $this->assertSame(1, $counter->for($manager));
    }

    public function test_the_badge_is_capped_and_costs_one_query_per_page(): void
    {
        $admin = $this->fixture->admin();
        $alert = $this->seedBulkAlerts($admin, UnreadAlertCount::CAP + 5);

        $counter = app(UnreadAlertCount::class);
        $this->assertSame(UnreadAlertCount::CAP.'+', $counter->badge($admin));

        $counter->forget();
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'operational_alert_recipients') && str_contains(strtolower($query->sql), 'count')) {
                $queries++;
            }
        });
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertLessThanOrEqual(1, $queries, 'The badge must not re-count per render');
        unset($alert);
    }

    /* ---------------------------------------------------------------------- XSS */

    public function test_hostile_business_data_is_escaped_everywhere_it_surfaces(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->product($admin, [
            'name' => '<script>alert(1)</script>',
            'sku' => '"><svg onload=alert(3)>',
            'initial_stock' => '1',
            'reorder_level' => '5',
        ]);
        $recipient = OperationalAlertRecipient::query()->where('user_id', $admin->id)->sole();

        foreach ([route('notifications.index'), route('notifications.show', $recipient), route('dashboard')] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            foreach (['<script>alert(1)', '<img src=x onerror', '<svg onload=alert'] as $executable) {
                $this->assertStringNotContainsString($executable, $html, "Executable markup rendered at {$url}");
            }
        }

        $listing = $this->actingAs($admin)->get(route('notifications.index'))->getContent();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $listing);

        foreach (['notifications/index.blade.php', 'notifications/show.blade.php'] as $view) {
            $this->assertStringNotContainsString('{!!', file_get_contents(resource_path('views/'.$view)));
        }
    }

    /* --------------------------------------------------------------- link safety */

    public function test_an_archived_subject_renders_without_a_broken_destination(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5']);
        $recipient = OperationalAlertRecipient::query()->where('user_id', $admin->id)->sole();

        // Products are soft-deleted; an inventory_movements foreign key forbids a hard delete, so
        // archiving is what "the subject is gone" actually looks like in this application.
        app(ArchiveProduct::class)->execute($admin, $product->fresh());

        $response = $this->actingAs($admin)->get(route('notifications.show', $recipient))->assertOk();
        $response->assertSee('Subject is no longer available');
        $this->assertStringNotContainsString('javascript:', $response->getContent());
        $this->actingAs($admin)->get(route('notifications.index'))->assertOk();
    }

    public function test_an_alert_whose_subject_row_no_longer_exists_still_renders(): void
    {
        $admin = $this->fixture->admin();
        $this->lowStockRecipient($admin);

        // Point the alert at an id nothing occupies, the shape a hard-deleted subject would leave.
        DB::table('operational_alerts')->update(['subject_id' => 999999]);
        $recipient = OperationalAlertRecipient::query()->where('user_id', $admin->id)->sole();

        $response = $this->actingAs($admin)->get(route('notifications.show', $recipient))->assertOk();
        $response->assertSee('Subject is no longer available');
        $this->actingAs($admin)->get(route('notifications.index'))->assertOk();
    }

    /* ------------------------------------------------------------ GET read-only */

    public function test_no_get_request_mutates_alert_state(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->manager();
        $recipient = $this->lowStockRecipient($admin);
        $this->fixture->saleWithBalance($admin);

        $before = $this->fingerprint();

        foreach ([
            route('notifications.index'),
            route('notifications.index').'?status=active&read=unread&severity=critical&type=inventory_low_stock',
            route('notifications.index').'?status[]=active&read=%00&severity=nonsense&page=2',
            route('notifications.show', $recipient),
            route('dashboard'),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->assertSame($before, $this->fingerprint(), 'A GET must not create, resolve, read or acknowledge anything');
    }

    /* ----------------------------------------------------------------- filters */

    public function test_filters_are_allowlisted_and_hostile_values_never_break_the_page(): void
    {
        $admin = $this->fixture->admin();
        $this->lowStockRecipient($admin);

        foreach ([
            '?status=active', '?status=resolved', '?read=unread', '?read=read', '?read=acknowledged',
            '?severity=critical', '?type=inventory_low_stock',
            '?status=nonsense', '?read[]=unread', '?severity[]=1&type[]=2',
            '?status[nested][deep]=active', '?read='.str_repeat('a', 5000), '?type=%00',
            '?status=active&read=unread&severity=warning&type=inventory_low_stock',
        ] as $query) {
            $this->actingAs($admin)->get(route('notifications.index').$query)->assertOk();
        }
    }

    public function test_ordering_stays_deterministic_when_timestamps_collide(): void
    {
        $admin = $this->fixture->admin();
        // Every alert shares one created_at, so only the tiebreakers can order them.
        $this->seedBulkAlerts($admin, 30, identicalTimestamps: true);

        $size = PerPage::DEFAULT;
        $firstPage = $this->idsOn($admin, route('notifications.index'));
        $secondPage = $this->idsOn($admin, route('notifications.index').'?page=2');
        $thirdPage = $this->idsOn($admin, route('notifications.index').'?page=3');

        $this->assertCount($size, $firstPage);
        $this->assertCount($size, $secondPage);
        $this->assertCount(30 - (2 * $size), $thirdPage);
        $this->assertSame($firstPage, $this->idsOn($admin, route('notifications.index')), 'Repeated loads must agree');
        $this->assertSame($firstPage, $this->idsOn($admin, route('notifications.index')), 'And keep agreeing');
        $this->assertSame([], array_intersect($firstPage, $secondPage), 'No row may appear on two pages');
        $this->assertSame([], array_intersect($secondPage, $thirdPage), 'And none on the next boundary either');

        $descending = $firstPage;
        rsort($descending);
        $this->assertSame($descending, $firstPage, 'Newest first, by a total order');
        $this->assertSame(30, count(array_unique([...$firstPage, ...$secondPage, ...$thirdPage])), 'Every row is reachable exactly once');
    }

    /* ------------------------------------------------------------------- audit */

    public function test_the_acknowledgement_audit_event_is_precise_and_carries_no_request_data(): void
    {
        $admin = $this->fixture->admin('Ada Admin');
        $recipient = $this->lowStockRecipient($admin);
        $alert = $recipient->alert()->sole();

        $this->actingAs($admin)->post(route('notifications.acknowledge', $recipient), [
            'note' => 'arbitrary request text', 'severity' => 'critical', 'password' => 'p4ssw0rd',
        ])->assertRedirect();

        $log = AuditLog::query()->where('action', 'operational_alert_acknowledged')->sole();
        $this->assertSame((int) $admin->id, (int) $log->actor_id);
        $this->assertSame('Ada Admin', $log->actor_name_snapshot);
        $this->assertSame(UserRole::Admin->value, $log->actor_role_snapshot);
        $this->assertSame($alert->getMorphClass(), $log->auditable_type);
        $this->assertSame((int) $alert->id, (int) $log->auditable_id);
        $this->assertSame(['alert_type' => 'inventory_low_stock', 'alert_severity' => 'warning'], $log->metadata);
        $this->assertNull($log->old_values);
        $this->assertNull($log->new_values);

        $blob = strtolower((string) json_encode($log->toArray()));
        foreach (['arbitrary request text', 'p4ssw0rd'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $blob);
        }
    }

    public function test_a_failing_audit_write_rolls_back_the_acknowledgement(): void
    {
        $admin = $this->fixture->admin();
        $recipient = $this->lowStockRecipient($admin);

        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, ?User $actor,
                array $oldValues = [], array $newValues = [], array $metadata = [],
                bool $explicitDiff = false): void
            {
                throw new RuntimeException('audit unavailable');
            }
        });

        try {
            app(AcknowledgeAlert::class)->execute($admin, $recipient);
            $this->fail('The acknowledgement must not commit without its audit evidence.');
        } catch (RuntimeException) {
            // expected
        }

        $fresh = $recipient->fresh();
        $this->assertNull($fresh->acknowledged_at);
        $this->assertNull($fresh->read_at, 'A rolled-back acknowledgement must not leave a half-applied read');
        $this->assertSame(0, AuditLog::query()->where('action', 'operational_alert_acknowledged')->count());
    }

    /* ------------------------------------------------------------- query counts */

    public function test_the_list_and_detail_pages_stay_flat_as_alerts_grow(): void
    {
        $admin = $this->fixture->admin();
        $this->seedBulkAlerts($admin, 10);
        $small = $this->queryCount($admin, route('notifications.index'));

        $this->seedBulkAlerts($admin, 200, startAt: 1000);
        $this->seedBulkAlerts($admin, 800, startAt: 20000);
        $large = $this->queryCount($admin, route('notifications.index'));

        $this->assertSame($small, $large, "Index query count moved from {$small} to {$large} as data grew");
        $this->assertLessThanOrEqual(12, $large, "Index issued {$large} queries");

        $recipient = OperationalAlertRecipient::query()->forUser($admin)->firstOrFail();
        $detail = $this->queryCount($admin, route('notifications.show', $recipient));
        $this->assertLessThanOrEqual(12, $detail, "Detail issued {$detail} queries");
    }

    /* --------------------------------------------------------------------- helpers */

    private function lowStockRecipient(User $user): OperationalAlertRecipient
    {
        $this->fixture->product($user, ['initial_stock' => '2', 'reorder_level' => '5']);

        return OperationalAlertRecipient::query()->where('user_id', $user->id)->sole();
    }

    /** Bulk alert rows written directly: this probes the read paths, not the evaluator. */
    private function seedBulkAlerts(User $user, int $count, bool $identicalTimestamps = false, int $startAt = 0): void
    {
        $now = now();
        $alerts = [];
        $offset = OperationalAlert::query()->max('id') ?? 0;

        for ($i = 0; $i < $count; $i++) {
            $created = $identicalTimestamps ? $now : $now->copy()->subMinutes($count - $i);
            $alerts[] = [
                'type' => 'inventory_low_stock', 'severity' => 'warning', 'status' => 'active',
                'subject_type' => 'product', 'subject_id' => $startAt + $offset + $i + 1,
                'subject_label_snapshot' => 'Bulk '.$i, 'title' => 'Bulk alert '.$i, 'message' => 'Bulk message '.$i,
                'active_key' => 'bulk:'.$startAt.':'.($offset + $i), 'occurrence' => 1,
                'first_detected_at' => $created, 'created_at' => $created, 'updated_at' => $created,
            ];
        }

        foreach (array_chunk($alerts, 200) as $chunk) {
            DB::table('operational_alerts')->insert($chunk);
        }

        $recipients = OperationalAlert::query()->whereNotIn('id', OperationalAlertRecipient::query()->select('operational_alert_id'))
            ->pluck('id')->map(fn ($id): array => [
                'operational_alert_id' => $id, 'user_id' => $user->id,
                'created_at' => $now, 'updated_at' => $now,
            ])->all();

        foreach (array_chunk($recipients, 200) as $chunk) {
            DB::table('operational_alert_recipients')->insert($chunk);
        }
    }

    /** @return list<int> recipient ids in the order the page rendered them */
    private function idsOn(User $user, string $url): array
    {
        return $this->actingAs($user)->get($url)->assertOk()
            ->viewData('notifications')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function queryCount(User $user, string $url): int
    {
        // The badge memo is request-scoped in production but survives between requests inside one
        // test process, so drop it to measure what a real page load actually costs.
        app(UnreadAlertCount::class)->forget();
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            if (! str_contains($query->sql, '`sessions`')) {
                $count++;
            }
        });
        $this->actingAs($user)->get($url)->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }

    private function fingerprint(): array
    {
        return collect(self::FINGERPRINTED)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->count().':'.hash('sha256', DB::table($table)->orderBy('id')->get()->toJson()),
        ])->all();
    }
}
