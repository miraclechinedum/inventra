<?php

namespace Tests\Feature\Tenancy;

use App\Alerts\OperationalAlertEvaluator;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The alert, audit and security-event ownership migrations over real history, on the test database only.
 *
 * Two businesses raise alerts, write audit evidence and produce security events through the real code;
 * that ownership is then erased and the migrations must derive it again — alerts from their subject,
 * deliveries from their alert, audit rows from their record or actor, security events from the account
 * they concern — without changing a row, and refusing every disagreement instead of guessing.
 */
class StaffAlertAuditOwnershipMigrationTest extends TestCase
{
    private const OWNED = ['operational_alerts', 'operational_alert_recipients', 'audit_logs', 'security_events'];

    /** @var array<string, object> */
    private array $migrations = [];

    private Business $a;

    private Business $b;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        // These tests run migration down() and up() directly, so the live connection is re-proven first.
        static::assertSafeTestDatabase(DB::connection());
        $this->rebuildTestSchema();

        foreach ([
            'alerts' => '2026_09_29_170000_add_business_ownership_to_operational_alerts',
            'alertsTenancy' => '2026_09_29_180000_enforce_operational_alert_tenancy',
            'audit' => '2026_09_29_190000_add_business_ownership_to_audit_logs',
            'auditTenancy' => '2026_09_29_200000_enforce_audit_log_tenancy',
            'security' => '2026_09_29_210000_add_business_association_to_security_events',
        ] as $key => $name) {
            $this->migrations[$key] = require database_path("migrations/{$name}.php");
        }

        $this->a = Business::query()->orderBy('id')->firstOrFail();
        $this->b = Business::factory()->create(['name' => 'Bravo Hardware']);

        foreach (['a' => $this->a, 'b' => $this->b] as $key => $business) {
            $this->users["{$key}Admin"] = User::factory()->forBusiness($business)->create(['role' => UserRole::Admin]);
            $this->users["{$key}Manager"] = User::factory()->forBusiness($business)->create(['role' => UserRole::Manager]);

            app(CurrentBusiness::class)->run($business, function () use ($business, $key): void {
                // Low stock, raised and delivered by the real evaluator. It is called directly because the
                // schema rebuild leaves a transaction open, so the observer's after-commit hook never fires.
                app(OperationalAlertEvaluator::class)->evaluateProduct(
                    Product::factory()->forBusiness($business)->create(['current_stock' => '1.000', 'reorder_level' => '5.000'])
                );
                $customer = Customer::factory()->forBusiness($business)->create();
                app(AuditLogger::class)->record('customer_created', $customer, $this->users["{$key}Admin"]);
                // Evidence about a record that is not tenant-owned: only its actor can place it.
                app(AuditLogger::class)->record('business_reviewed', $business, $this->users["{$key}Admin"]);
            });
        }

        // Evidence whose subject was since removed: the actor is the only remaining witness.
        DB::table('audit_logs')->insert([
            'business_id' => $this->b->id, 'actor_id' => $this->users['bAdmin']->id, 'actor_name_snapshot' => 'Bravo Admin',
            'action' => 'customer_created', 'auditable_type' => 'App\\Models\\Customer', 'auditable_id' => 999999,
            'subject_label_snapshot' => 'Removed', 'created_at' => now(),
        ]);

        foreach ([
            ['business_id' => $this->a->id, 'subject_user_id' => $this->users['aManager']->id, 'event' => 'login_success'],
            ['business_id' => $this->b->id, 'user_id' => $this->users['bManager']->id, 'event' => 'login_success'],
            ['business_id' => $this->b->id, 'actor_id' => $this->users['bAdmin']->id, 'event' => 'staff_created'],
            ['business_id' => null, 'event' => 'login_failure'],
        ] as $event) {
            DB::table('security_events')->insert($event + ['created_at' => now()]);
        }
    }

    public function test_erased_ownership_is_derived_again_from_what_each_row_describes(): void
    {
        try {
            $owners = $this->owners();
            $facts = $this->facts();
            $this->assertGreaterThan(0, DB::table('operational_alert_recipients')->count());

            $this->eraseOwnership();
            $this->up('alerts', 'alertsTenancy', 'audit', 'auditTenancy', 'security');

            $this->assertSame($owners, $this->owners(), 'Every row must be derived back to the business that owns it');
            $this->assertSame($facts, $this->facts(), 'No row may change');
            $this->assertNull(DB::table('security_events')->where('event', 'login_failure')->value('business_id'), 'An event about no known account has no business');
            $this->assertSame($this->b->id, DB::table('audit_logs')->where('auditable_id', 999999)->value('business_id'));

            foreach (['operational_alerts', 'operational_alert_recipients', 'audit_logs'] as $table) {
                $this->assertSame('NO', $this->nullable($table), "{$table}.business_id must be NOT NULL");
            }
            $this->assertSame('YES', $this->nullable('security_events'), 'security events stay nullable for unknown accounts');

            $this->assertTrue($this->indexExists('operational_alerts_business_id_active_key_unique'));
            $this->assertFalse($this->indexExists('operational_alerts_active_key_unique'), 'global alert deduplication must be replaced');

            // The same condition may be active once per business, never twice in one.
            $row = (array) DB::table('operational_alerts')->where('business_id', $this->a->id)->whereNotNull('active_key')->first();
            unset($row['id']);
            DB::table('operational_alerts')->insert(['business_id' => $this->b->id] + $row);

            $this->expectException(QueryException::class);
            DB::table('operational_alerts')->insert($row);
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_a_delivery_cannot_reach_a_user_of_another_business(): void
    {
        try {
            $this->assertRejected(fn () => DB::table('operational_alert_recipients')->where('business_id', $this->a->id)->limit(1)
                ->update(['user_id' => $this->users['bManager']->id]), 'the composite key must refuse a cross-business delivery');

            $this->eraseOwnership();
            $recipient = DB::table('operational_alert_recipients')->join('operational_alerts', 'operational_alerts.id', '=', 'operational_alert_recipients.operational_alert_id')
                ->join('products', 'products.id', '=', 'operational_alerts.subject_id')->where('products.business_id', $this->a->id)
                ->value('operational_alert_recipients.id');
            DB::table('operational_alert_recipients')->where('id', $recipient)->update(['user_id' => $this->users['bManager']->id]);

            $this->expectRefusal('alerts', 'An alert was delivered to a user of another business.');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_an_alert_whose_subject_cannot_be_found_is_named_not_assigned(): void
    {
        try {
            $this->eraseOwnership();
            DB::table('operational_alerts')->limit(1)->update(['subject_type' => 'supplier']);

            $this->expectRefusal('alerts', 'Operational alerts whose subject cannot establish a business: supplier (1).');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_audit_evidence_whose_record_and_actor_disagree_stops_the_backfill(): void
    {
        try {
            $this->eraseOwnership();
            DB::table('audit_logs')->where('action', 'customer_created')->where('actor_id', $this->users['aAdmin']->id)
                ->update(['actor_id' => $this->users['bAdmin']->id]);

            $this->expectRefusal('audit', 'audit rows record an actor from a different business than the record they describe.');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_audit_evidence_with_no_record_or_actor_is_named_not_assigned(): void
    {
        try {
            $this->eraseOwnership();
            DB::table('audit_logs')->where('auditable_id', 999999)->update(['actor_id' => null]);

            $this->expectRefusal('audit', 'Audit rows with no record or actor to establish their business: App\\Models\\Customer (1).');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_a_security_event_naming_accounts_of_two_businesses_stops_the_backfill(): void
    {
        try {
            $this->eraseOwnership();
            DB::table('security_events')->where('subject_user_id', $this->users['aManager']->id)->update(['actor_id' => $this->users['bAdmin']->id]);

            $this->expectRefusal('security', 'security events name accounts of more than one business (actor_id).');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_rollback_refuses_when_two_businesses_share_an_active_condition(): void
    {
        try {
            $row = (array) DB::table('operational_alerts')->where('business_id', $this->a->id)->whereNotNull('active_key')->first();
            unset($row['id']);
            DB::table('operational_alerts')->insert(['business_id' => $this->b->id] + $row);

            $this->expectRefusal('alertsTenancy', 'Two businesses have the same condition active', direction: 'down');
            $this->assertTrue($this->indexExists('operational_alerts_business_id_active_key_unique'), 'a refused rollback changes nothing');

            foreach (['alerts', 'audit', 'security'] as $expand) {
                $this->expectRefusal($expand, 'while more than one business exists', $expand, 'down');
            }
        } finally {
            $this->rebuildTestSchema();
        }
    }

    private function eraseOwnership(): void
    {
        foreach (['auditTenancy', 'alertsTenancy'] as $contract) {
            $this->migrations[$contract]->down();
        }

        foreach (self::OWNED as $table) {
            DB::table($table)->update(['business_id' => null]);
        }
    }

    /** @return array<string, array<int, int|null>> */
    private function owners(): array
    {
        return collect(self::OWNED)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->pluck('business_id', 'id')->all(),
        ])->all();
    }

    /** @return array<string, list<array<string, mixed>>> every column except the ownership being derived */
    private function facts(): array
    {
        return collect(self::OWNED)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->get()->map(function (object $row): array {
                $row = (array) $row;
                unset($row['business_id']);

                return $row;
            })->all(),
        ])->all();
    }

    private function up(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->migrations[$key]->up();
        }
    }

    private function expectRefusal(string $migration, string $reason, string $label = '', string $direction = 'up'): void
    {
        try {
            $this->migrations[$migration]->{$direction}();
            $this->fail("The migration must refuse: {$label} {$reason}");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage(), $label);
        }
    }

    private function assertRejected(\Closure $write, string $message): void
    {
        try {
            $write();
            $this->fail($message);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function nullable(string $table): string
    {
        return DB::selectOne(
            "SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'business_id'",
            [$table]
        )->nullable;
    }

    private function indexExists(string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ? LIMIT 1',
            [$index]
        ) !== null;
    }
}
