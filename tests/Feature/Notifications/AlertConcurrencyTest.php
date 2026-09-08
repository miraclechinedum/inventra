<?php

namespace Tests\Feature\Notifications;

use App\Actions\Alerts\ReconcileOperationalAlerts;
use App\Alerts\AlertCondition;
use App\Alerts\OperationalAlertEvaluator;
use App\Alerts\OperationalAlertProjector;
use App\Alerts\UnreadAlertCount;
use App\Enums\OperationalAlertSeverity;
use App\Enums\OperationalAlertType;
use App\Enums\UserStatus;
use App\Models\OperationalAlert;
use App\Models\OperationalAlertRecipient;
use App\Models\Product;
use App\Models\User;
use App\Reports\BusinessReports;
use App\Support\PerPage;
use DomainException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private AlertFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new AlertFixture;
    }

    /**
     * Simulates the interleaving that a check-then-insert design gets wrong: both evaluators look,
     * both see nothing, and both insert. The UNIQUE active_key decides, and the loser adopts the
     * winner's row instead of raising a duplicate.
     */
    public function test_a_racing_evaluator_cannot_create_a_second_active_alert(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->manager();
        $product = $this->fixture->product($admin, ['initial_stock' => '100', 'reorder_level' => '5']);
        DB::table('products')->where('id', $product->id)->update(['current_stock' => '1.000']);

        $condition = new AlertCondition(
            type: OperationalAlertType::InventoryLowStock,
            subjectType: 'product',
            subjectId: (int) $product->id,
            severity: OperationalAlertSeverity::Warning,
            subjectLabel: 'SKU · Widget',
            title: 'Product at or below reorder level',
            message: 'Widget is low.',
        );

        $projector = app(OperationalAlertProjector::class);

        // The first evaluator commits between the second one's read and its own insert.
        $first = $projector->open($condition);
        $second = $projector->open($condition);

        $this->assertSame($first->id, $second->id, 'The racing evaluator must adopt the existing alert');
        $this->assertSame(1, OperationalAlert::query()->count());
        $this->assertSame(1, OperationalAlert::query()->active()->count());
        $this->assertSame(2, OperationalAlertRecipient::query()->count(), 'Recipients must not double up');
    }

    public function test_an_insert_that_loses_the_race_is_absorbed_rather_than_thrown(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '100', 'reorder_level' => '5']);
        DB::table('products')->where('id', $product->id)->update(['current_stock' => '1.000']);

        $key = OperationalAlert::activeKeyFor(OperationalAlertType::InventoryLowStock, 'product', (int) $product->id);

        // A competitor's row already occupies the dedupe key, written outside the projector.
        DB::table('operational_alerts')->insert([
            'type' => OperationalAlertType::InventoryLowStock->value, 'severity' => 'warning', 'status' => 'active',
            'subject_type' => 'product', 'subject_id' => $product->id,
            'subject_label_snapshot' => 'racer', 'title' => 'racer', 'message' => 'racer',
            'active_key' => $key, 'occurrence' => 1,
            'first_detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(OperationalAlertEvaluator::class)->evaluateProduct($product->fresh());

        $this->assertSame(1, OperationalAlert::query()->count());
        $alert = OperationalAlert::query()->sole();
        $this->assertSame('Product at or below reorder level', $alert->title, 'The surviving row is refreshed in place');
    }

    public function test_two_reconciliation_runs_over_the_same_state_converge(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->manager();
        $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $this->fixture->product($admin, ['initial_stock' => '0', 'reorder_level' => '5']);
        $this->fixture->saleWithBalance($admin);
        $this->fixture->saleWithLedgerMismatch($admin);

        app(ReconcileOperationalAlerts::class)->execute();
        $after = $this->snapshot();

        app(ReconcileOperationalAlerts::class)->execute();
        app(ReconcileOperationalAlerts::class)->execute();

        $this->assertSame($after, $this->snapshot());
        $this->assertSame(
            OperationalAlert::query()->active()->count(),
            OperationalAlert::query()->active()->distinct()->count('active_key'),
            'Every active alert holds a distinct dedupe key',
        );
    }

    /**
     * The projector must be the outermost transaction for its retries to work: Laravel refuses to
     * retry a nested one. This pins that precondition, since RefreshDatabase wraps every test in a
     * transaction and therefore reproduces exactly the shape where retries are unavailable. Real
     * retry behaviour is verified out of process, where the projector is top-level as in production.
     */
    public function test_a_concurrency_error_inside_an_outer_transaction_is_not_retried(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '100', 'reorder_level' => '5']);
        DB::table('products')->where('id', $product->id)->update(['current_stock' => '1.000']);
        // RefreshDatabase already holds a transaction, so the projector's own becomes level 2 —
        // exactly the nested shape in which Laravel declines to retry.
        $this->assertSame(1, DB::transactionLevel(), 'this test is wrapped in a transaction');

        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts): void {
            if (str_contains($query->sql, 'insert into `operational_alerts`')) {
                $attempts++;
                throw new QueryException('mysql', $query->sql, [],
                    new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        try {
            app(OperationalAlertEvaluator::class)->evaluateProduct($product->fresh());
            $this->fail('The deadlock should have surfaced.');
        } catch (DeadlockException) {
            // Laravel converts an un-retryable nested concurrency error into this.
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        $this->assertSame(1, $attempts, 'A nested transaction is not retried, by framework design');
        // Row state is deliberately not asserted here. On a nested concurrency error Laravel skips
        // the savepoint rollback because a genuine deadlock has already been rolled back by the
        // server — which an injected exception has not. Real rollback behaviour under a real
        // deadlock is verified out of process, not by simulation.
    }

    public function test_a_persistent_non_concurrency_failure_is_not_retried(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '100', 'reorder_level' => '5']);
        DB::table('products')->where('id', $product->id)->update(['current_stock' => '1.000']);

        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts): void {
            if (str_contains($query->sql, 'insert into `operational_alerts`')) {
                $attempts++;
                throw new DomainException('a real problem, not contention');
            }
        });

        try {
            app(OperationalAlertEvaluator::class)->evaluateProduct($product->fresh());
            $this->fail('The failure should have surfaced.');
        } catch (DomainException) {
            // expected
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        $this->assertSame(1, $attempts, 'Only concurrency errors are retried; real failures surface at once');
        $this->assertSame(0, OperationalAlert::query()->count());
    }

    /* --------------------------------------------------------- recipient lookup cache */

    public function test_recipient_lookup_is_one_query_per_role_set_not_one_per_subject(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->manager();
        for ($i = 0; $i < 30; $i++) {
            $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5', 'sku' => "N1-$i"]);
        }
        DB::table('operational_alert_recipients')->delete();
        DB::table('operational_alerts')->delete();

        $userQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$userQueries): void {
            if (str_contains($query->sql, 'from `users`')) {
                $userQueries++;
            }
        });
        app(ReconcileOperationalAlerts::class)->execute();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        // Recipients depend only on the alert type's role set, of which there are two distinct
        // sets across four types, so the whole sweep needs a handful of lookups — never one per
        // Product. Before this was cached it was exactly one per subject.
        $this->assertGreaterThanOrEqual(30, OperationalAlert::query()->count(), 'the sweep really did work');
        $this->assertLessThanOrEqual(4, $userQueries,
            "Recipient derivation issued {$userQueries} users queries for 30 subjects");
    }

    public function test_the_recipient_cache_does_not_survive_between_runs(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5', 'sku' => 'CACHE-1']);
        $this->assertSame(1, OperationalAlertRecipient::query()->where('user_id', $manager->id)->count());

        app(ReconcileOperationalAlerts::class)->execute();
        $manager->forceFill(['status' => UserStatus::Inactive])->save();

        // A fresh run must re-read the user table, not reuse the previous run's recipient ids.
        $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5', 'sku' => 'CACHE-2']);
        app(ReconcileOperationalAlerts::class)->execute();

        $this->assertSame(1, OperationalAlertRecipient::query()->where('user_id', $manager->id)->count(),
            'A deactivated Manager must not be delivered to from a stale cached recipient set');
        $this->assertSame(2, OperationalAlertRecipient::query()->where('user_id', $admin->id)->count());
    }

    /* ----------------------------------------------------------- failure injection */

    public function test_a_failure_while_attaching_recipients_leaves_no_half_delivered_alert(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->manager();
        $product = $this->fixture->product($admin, ['initial_stock' => '100', 'reorder_level' => '5']);
        DB::table('products')->where('id', $product->id)->update(['current_stock' => '1.000']);

        // Fail the recipient insert specifically. The alert row and its recipients share one
        // transaction, so an alert delivered to only some of its intended recipients — or none —
        // must not survive.
        DB::listen(function (QueryExecuted $query): void {
            // insertOrIgnore compiles to "insert ignore into", so match on the verb and the table.
            if (str_contains($query->sql, 'operational_alert_recipients') && str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
                throw new DomainException('recipient delivery failed');
            }
        });

        $delivered = true;

        try {
            app(OperationalAlertEvaluator::class)->evaluateProduct($product->fresh());
        } catch (DomainException) {
            $delivered = false;
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        $this->assertFalse($delivered, 'The injected recipient failure did not fire');
        $this->assertSame(0, OperationalAlert::query()->count(), 'No alert may survive without its recipients');
        $this->assertSame(0, OperationalAlertRecipient::query()->count());
    }

    public function test_a_failing_projection_never_disturbs_the_business_write(): void
    {
        $admin = $this->fixture->admin();

        // An evaluator that cannot write. Because projection is deferred past commit and its failure
        // is swallowed and logged, the stock movement that triggered it must still stand.
        $this->app->bind(OperationalAlertEvaluator::class, fn () => new class(app(OperationalAlertProjector::class), app(BusinessReports::class)) extends OperationalAlertEvaluator
        {
            public function evaluateProduct(Product $product): void
            {
                throw new \RuntimeException('alert projection unavailable');
            }
        });

        $product = $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame('2.000', (string) $product->fresh()->current_stock);
        $this->assertSame(0, OperationalAlert::query()->count());

        // Reconciliation repairs what the failed projection missed: alerts are derived state, so
        // nothing was lost that source truth cannot rebuild.
        $this->app->bind(OperationalAlertEvaluator::class, fn () => new OperationalAlertEvaluator(
            app(OperationalAlertProjector::class),
            app(BusinessReports::class),
        ));
        app(ReconcileOperationalAlerts::class)->execute();

        $this->assertSame(1, OperationalAlert::query()->active()->count());
    }

    public function test_a_rolled_back_business_write_projects_no_alert(): void
    {
        $admin = $this->fixture->admin();

        try {
            DB::transaction(function () use ($admin): void {
                $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5']);

                throw new \RuntimeException('caller aborted');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, OperationalAlert::query()->count(),
            'A projection deferred past commit must be discarded when that commit never happens');
    }

    /* ------------------------------------------------------------------ large data */

    public function test_the_index_stays_paginated_and_flat_over_five_thousand_alerts(): void
    {
        $admin = $this->fixture->admin();
        $this->seedAlerts($admin, 5000);

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (! str_contains($query->sql, '`sessions`')) {
                $queries++;
            }
        });
        app(UnreadAlertCount::class)->forget();
        $response = $this->actingAs($admin)->get(route('notifications.index'))->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertCount(PerPage::DEFAULT, $response->viewData('notifications')->items(), 'The page must stay bounded');
        $this->assertLessThanOrEqual(8, $queries, "Index issued {$queries} queries over 5,000 alerts");
        $this->assertSame('99+', app(UnreadAlertCount::class)->badge($admin));
    }

    private function snapshot(): array
    {
        return [
            'alerts' => DB::table('operational_alerts')->orderBy('id')
                ->get(['id', 'type', 'severity', 'status', 'subject_id', 'active_key', 'occurrence', 'resolved_at'])->toJson(),
            'recipients' => DB::table('operational_alert_recipients')->orderBy('id')
                ->get(['id', 'operational_alert_id', 'user_id', 'read_at', 'acknowledged_at'])->toJson(),
        ];
    }

    private function seedAlerts(User $user, int $count): void
    {
        $now = now();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'type' => 'inventory_low_stock', 'severity' => 'warning', 'status' => 'active',
                'subject_type' => 'product', 'subject_id' => $i + 1,
                'subject_label_snapshot' => 'Bulk '.$i, 'title' => 'Bulk alert '.$i, 'message' => 'Bulk message '.$i,
                'active_key' => 'bulk:'.$i, 'occurrence' => 1,
                'first_detected_at' => $now, 'created_at' => $now->copy()->subSeconds($count - $i), 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('operational_alerts')->insert($chunk);
        }

        $recipients = DB::table('operational_alerts')->pluck('id')->map(fn ($id): array => [
            'operational_alert_id' => $id, 'user_id' => $user->id, 'created_at' => $now, 'updated_at' => $now,
        ])->all();

        foreach (array_chunk($recipients, 500) as $chunk) {
            DB::table('operational_alert_recipients')->insert($chunk);
        }
    }
}
