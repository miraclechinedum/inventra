<?php

namespace Tests\Feature\Tenancy;

use App\Alerts\AlertCondition;
use App\Alerts\OperationalAlertProjector;
use App\Alerts\UnreadAlertCount;
use App\Dashboard\DashboardData;
use App\Enums\OperationalAlertSeverity;
use App\Enums\OperationalAlertType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\OperationalAlert;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Reports\BusinessReports;
use App\Reports\ReportFilters;
use App\Services\AuditLogger;
use App\Services\SecurityEventRecorder;
use App\Tenancy\BusinessContextException;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Tests\TestCase;

/**
 * Staff, operational alerts, audit evidence and security events across two businesses with the same
 * staff roles. Every name carries its business ("Alpha …", "Bravo …"), so a leak shows up as text.
 */
class StaffAlertAuditIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    /** @var array<string, User> */
    private array $staffA;

    /** @var array<string, User> */
    private array $staffB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Business::factory()->create(['name' => 'Alpha Spares']);
        $this->b = Business::factory()->create(['name' => 'Bravo Hardware']);
        $this->staffA = $this->staff($this->a, 'Alpha');
        $this->staffB = $this->staff($this->b, 'Bravo');
    }

    /* ---------------------------------------------------------------------- staff */

    public function test_each_administrator_lists_and_counts_only_their_own_staff(): void
    {
        $this->actingAs($this->staffA['admin'])->get(route('staff.index'))->assertOk()
            ->assertSee('Alpha Manager')->assertSee('Alpha Rep')->assertDontSee('Bravo');
        $this->actingAs($this->staffB['admin'])->get(route('staff.index'))->assertOk()
            ->assertSee('Bravo Manager')->assertDontSee('Alpha');
        $this->actingAs($this->staffA['admin'])->get(route('staff.index', ['search' => 'Bravo']))->assertOk()->assertDontSee('Bravo Manager');
    }

    public function test_another_businesss_staff_member_is_not_found_on_every_route(): void
    {
        $target = $this->staffB['manager'];
        $before = DB::table('users')->where('id', $target->id)->first();

        foreach ([
            ['get', route('staff.show', $target)], ['get', route('staff.edit', $target)], ['put', route('staff.update', $target)],
            ['post', route('staff.role', $target)], ['post', route('staff.activate', $target)], ['post', route('staff.deactivate', $target)],
            ['post', route('staff.lock', $target)], ['post', route('staff.unlock', $target)],
            ['post', route('staff.require-password-change', $target)], ['post', route('staff.revoke-sessions', $target)],
            ['get', route('staff.activity', $target)], ['delete', route('staff.photo.destroy', $target)], ['get', route('users.photo', $target)],
        ] as [$method, $url]) {
            $this->actingAs($this->staffA['admin'])->{$method}($url, ['name' => 'Hijacked', 'email' => 'hijack@example.com', 'role' => 'sales_rep'])->assertNotFound();
        }

        $this->assertEquals($before, DB::table('users')->where('id', $target->id)->first());
    }

    public function test_the_policy_denies_another_businesss_user_even_when_loaded_directly(): void
    {
        $admin = $this->staffA['admin'];

        foreach (['view', 'update', 'changeRole', 'changeStatus', 'lock', 'unlock', 'revokeSessions', 'requirePasswordChange', 'viewActivity', 'viewPhoto', 'removePhoto'] as $ability) {
            $this->assertFalse(Gate::forUser($admin)->allows($ability, $this->staffB['manager']), $ability);
        }

        $this->assertTrue(Gate::forUser($admin)->allows('update', $this->staffA['manager']), 'same-business rules are unchanged');
    }

    public function test_pickers_filters_counts_and_the_staff_report_hold_only_own_staff(): void
    {
        // Seeded before any request: from then on the signed-in operator pins the context.
        $this->inBusiness($this->b, fn () => Sale::factory()->forBusiness($this->b)->create(['sold_by' => $this->staffB['manager']->id]));
        $this->inBusiness($this->a, fn () => Sale::factory()->forBusiness($this->a)->create(['sold_by' => $this->staffA['manager']->id]));

        $admin = $this->staffA['admin'];

        $ownStaff = DB::table('users')->where('business_id', $this->a->id)->orderBy('id')->pluck('id')->all();
        $onlyOwn = fn ($users): bool => collect($users)->pluck('id')->sort()->values()->all() === $ownStaff;

        $this->actingAs($admin)->get(route('sales.index'))->assertOk()->assertViewHas('sellers', $onlyOwn);
        $this->actingAs($admin)->get(route('sale-payments.index'))->assertOk()->assertViewHas('recorders', $onlyOwn)->assertDontSee('Bravo');
        $this->actingAs($admin)->get(route('expenses.index'))->assertOk()
            ->assertViewHas('recorders', fn ($users): bool => $users->isNotEmpty() && $users->every(fn (User $user): bool => in_array($user->id, $ownStaff, true)))->assertDontSee('Bravo');
        $this->actingAs($admin)->get(route('audit.index'))->assertOk()->assertDontSee('Bravo');

        $filters = $this->filters();
        $alpha = $this->inBusiness($this->a, fn () => app(BusinessReports::class)->staff($filters));
        $this->assertSame(['Alpha Manager'], collect($alpha['rows']->items())->pluck('name')->all());
        $this->assertSame($ownStaff, collect($alpha['staffOptions'])->pluck('id')->sort()->values()->all());

        $metrics = $this->inBusiness($this->a, fn () => collect(app(DashboardData::class)->for($admin, $filters)['currentMetrics'])->keyBy('label'));
        $this->assertSame((string) count($ownStaff), (string) $metrics['Active Staff']['value']);
    }

    public function test_login_and_password_reset_still_find_accounts_across_businesses(): void
    {
        foreach ([$this->staffA['manager'], $this->staffB['manager']] as $user) {
            $this->post(route('login.store'), ['identifier' => $user->email, 'password' => 'password'])->assertRedirect();
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'))->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => $this->staffB['rep']->email])->assertSessionHasNoErrors();
        $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertSessionHasNoErrors();
    }

    /* --------------------------------------------------------------------- alerts */

    public function test_alerts_belong_to_their_subjects_business_and_reach_only_its_managers(): void
    {
        [$productA, $productB] = $this->lowStockProducts();

        $alertA = $this->inBusiness($this->a, fn () => OperationalAlert::query()->where('subject_id', $productA->id)->sole());
        $alertB = $this->inBusiness($this->b, fn () => OperationalAlert::query()->where('subject_id', $productB->id)->sole());
        $this->assertSame($this->a->id, $alertA->business_id);
        $this->assertSame($this->b->id, $alertB->business_id);

        $this->assertEqualsCanonicalizing([$this->staffA['admin']->id, $this->staffA['manager']->id], DB::table('operational_alert_recipients')->where('operational_alert_id', $alertA->id)->pluck('user_id')->all());
        $this->assertEqualsCanonicalizing([$this->staffB['admin']->id, $this->staffB['manager']->id], DB::table('operational_alert_recipients')->where('operational_alert_id', $alertB->id)->pluck('user_id')->all());
        $this->assertSame([$this->a->id], DB::table('operational_alert_recipients')->where('operational_alert_id', $alertA->id)->distinct()->pluck('business_id')->all());
    }

    public function test_the_same_condition_may_be_active_in_both_businesses_but_only_once_in_each(): void
    {
        [$productA] = $this->lowStockProducts();
        $key = DB::table('operational_alerts')->where('subject_id', $productA->id)->value('active_key');
        $row = (array) DB::table('operational_alerts')->where('active_key', $key)->first();
        unset($row['id']);

        // The key is unique per business, so another business may hold the same condition.
        DB::table('operational_alerts')->insert(['business_id' => $this->b->id] + $row);
        $this->assertSame(2, DB::table('operational_alerts')->where('active_key', $key)->count());

        $this->expectException(QueryException::class);
        DB::table('operational_alerts')->insert($row);
    }

    public function test_inboxes_counts_and_read_state_are_confined_to_the_business(): void
    {
        [, $productB] = $this->lowStockProducts();
        $recipientB = DB::table('operational_alert_recipients')->where('user_id', $this->staffB['admin']->id)->value('id');

        $this->actingAs($this->staffA['admin'])->get(route('notifications.index'))->assertOk()->assertSee('Alpha Filter')->assertDontSee('Bravo Filter');
        $this->assertSame(1, $this->inBusiness($this->a, fn () => app(UnreadAlertCount::class)->for($this->staffA['admin'])));

        $this->actingAs($this->staffA['admin'])->get(route('notifications.show', $recipientB))->assertNotFound();
        $this->actingAs($this->staffA['admin'])->post(route('notifications.read', $recipientB))->assertNotFound();
        $this->actingAs($this->staffA['admin'])->post(route('notifications.acknowledge', $recipientB))->assertNotFound();
        $this->actingAs($this->staffA['admin'])->post(route('notifications.read-all'))->assertRedirect();

        $this->assertNull(DB::table('operational_alert_recipients')->where('id', $recipientB)->value('read_at'), 'A must not be able to mark B\'s delivery read');
        $this->assertSame(0, DB::table('operational_alert_recipients')->where('business_id', $this->a->id)->whereNull('read_at')->where('user_id', $this->staffA['admin']->id)->count());
    }

    public function test_reconcile_sweeps_each_business_independently(): void
    {
        [$productA, $productB] = $this->lowStockProducts();
        DB::table('products')->where('id', $productA->id)->update(['current_stock' => '50.000']);

        $this->artisan('inventra:reconcile-operational-alerts')->assertSuccessful();

        $this->assertSame('resolved', DB::table('operational_alerts')->where('business_id', $this->a->id)->where('subject_id', $productA->id)->value('status'));
        $this->assertSame('active', DB::table('operational_alerts')->where('business_id', $this->b->id)->where('subject_id', $productB->id)->value('status'), 'Resolving A must not touch B');
    }

    public function test_an_alert_cannot_be_raised_in_one_business_about_anothers_subject(): void
    {
        [, $productB] = $this->lowStockProducts();

        $this->expectException(LogicException::class);
        $this->inBusiness($this->a, fn () => app(OperationalAlertProjector::class)->open(new AlertCondition(
            type: OperationalAlertType::InventoryLowStock, subjectType: 'product', subjectId: $productB->id,
            severity: OperationalAlertSeverity::Warning, subjectLabel: 'x', title: 'x', message: 'x',
        )));
    }

    /* ---------------------------------------------------------------------- audit */

    public function test_audit_rows_belong_to_their_business_and_screens_show_only_that_business(): void
    {
        $this->actingAs($this->staffA['admin'])->post(route('customers.store'), $this->customer('Alpha Buyer', '08031110001'))->assertRedirect();
        $this->actingAs($this->staffB['admin'])->post(route('customers.store'), $this->customer('Bravo Buyer', '08031110001'))->assertRedirect();

        $this->assertSame($this->a->id, DB::table('audit_logs')->where('action', 'customer_created')->where('actor_id', $this->staffA['admin']->id)->value('business_id'));
        $this->assertSame($this->b->id, DB::table('audit_logs')->where('action', 'customer_created')->where('actor_id', $this->staffB['admin']->id)->value('business_id'));

        $this->actingAs($this->staffA['admin'])->get(route('audit.index'))->assertOk()->assertSee('Alpha Buyer')->assertDontSee('Bravo Buyer')->assertDontSee('Bravo Admin');
        $bRow = DB::table('audit_logs')->where('business_id', $this->b->id)->value('id');
        $this->actingAs($this->staffA['admin'])->get(route('audit.show', $bRow))->assertNotFound();
    }

    public function test_audit_evidence_must_agree_on_its_business(): void
    {
        $logger = app(AuditLogger::class);
        $customerB = $this->inBusiness($this->b, fn () => Customer::factory()->forBusiness($this->b)->create());

        // Record and actor disagree.
        try {
            $this->inBusiness($this->b, fn () => $logger->record('probe', $customerB, $this->staffA['admin']));
            $this->fail('Evidence pointing at two businesses must be refused.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        // A subject that is not itself tenant-owned — the Business record — takes the actor's business.
        $subject = $this->b;
        $this->inBusiness($this->a, fn () => $logger->record('probe_actor', $subject, $this->staffA['admin']));
        $this->assertSame($this->a->id, DB::table('audit_logs')->where('action', 'probe_actor')->value('business_id'));

        // Nothing to establish a business: refused rather than filed under a default.
        app(CurrentBusiness::class)->forget();
        $this->expectException(LogicException::class);
        $logger->record('probe_orphan', $subject, null);
    }

    public function test_audit_reads_fail_closed_without_a_business(): void
    {
        app(CurrentBusiness::class)->forget();

        $this->expectException(BusinessContextException::class);
        AuditLog::query()->count();
    }

    /* ------------------------------------------------------------ security events */

    public function test_security_events_belong_to_the_account_they_concern_or_to_no_business(): void
    {
        $this->post(route('login.store'), ['identifier' => $this->staffA['rep']->email, 'password' => 'password', 'business_id' => $this->b->id]);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['identifier' => $this->staffB['rep']->email, 'password' => 'wrong-password']);
        $this->post(route('login.store'), ['identifier' => 'nobody@example.com', 'password' => 'whatever']);

        $this->assertSame($this->a->id, DB::table('security_events')->where('event', 'login_success')->where('subject_user_id', $this->staffA['rep']->id)->value('business_id'), 'the request cannot choose the business');
        $this->assertSame($this->b->id, DB::table('security_events')->where('event', 'login_failure')->where('subject_user_id', $this->staffB['rep']->id)->value('business_id'));
        $this->assertNull(DB::table('security_events')->where('event', 'login_failure')->whereNull('subject_user_id')->latest('id')->value('business_id'), 'an unknown account has no business');
    }

    public function test_the_staff_security_page_shows_only_the_businesss_own_events(): void
    {
        $subject = $this->staffA['manager'];
        foreach (['probe_visible' => $this->a->id, 'probe_unowned' => null, 'probe_foreign' => $this->b->id] as $event => $business) {
            DB::table('security_events')->insert(['business_id' => $business, 'subject_user_id' => $subject->id, 'event' => $event, 'created_at' => now()]);
        }

        $this->actingAs($this->staffA['admin'])->get(route('staff.activity', $subject))->assertOk()
            ->assertSee('Probe Visible')->assertDontSee('Probe Unowned')->assertDontSee('Probe Foreign');
    }

    public function test_a_security_event_cannot_concern_two_businesses(): void
    {
        $this->expectException(LogicException::class);

        app(SecurityEventRecorder::class)->record('probe', $this->staffB['manager'], [], $this->staffA['admin']);
    }

    /* -------------------------------------------------------------------- helpers */

    /** @return array<string, User> */
    private function staff(Business $business, string $prefix): array
    {
        return collect(['admin' => UserRole::Admin, 'manager' => UserRole::Manager, 'rep' => UserRole::SalesRep])
            ->map(fn (UserRole $role, string $key): User => User::factory()->forBusiness($business)->create([
                'role' => $role, 'name' => $prefix.' '.ucfirst($key === 'rep' ? 'Rep' : $key),
            ]))->all();
    }

    /** @return array{0: Product, 1: Product} one low-stock product per business, alerted through the real observer */
    private function lowStockProducts(): array
    {
        return [
            $this->inBusiness($this->a, fn () => Product::factory()->forBusiness($this->a)->create(['name' => 'Alpha Filter', 'current_stock' => '1.000', 'reorder_level' => '5.000'])),
            $this->inBusiness($this->b, fn () => Product::factory()->forBusiness($this->b)->create(['name' => 'Bravo Filter', 'current_stock' => '1.000', 'reorder_level' => '5.000'])),
        ];
    }

    private function inBusiness(Business $business, \Closure $work): mixed
    {
        return app(CurrentBusiness::class)->run($business, $work);
    }

    private function filters(): ReportFilters
    {
        $today = CarbonImmutable::now(config('business.timezone'))->toDateString();

        return new ReportFilters($today, $today, '', '', '', '', '', '');
    }

    private function customer(string $name, string $phone): array
    {
        [$first, $last] = explode(' ', $name);

        return ['first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'email' => null, 'address' => null, 'city' => 'Lagos', 'notes' => null];
    }
}
