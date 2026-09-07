<?php

namespace Tests\Feature\Notifications;

use App\Actions\Alerts\ReconcileOperationalAlerts;
use App\Alerts\OperationalAlertEvaluator;
use App\Alerts\UnreadAlertCount;
use App\Enums\OperationalAlertType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\OperationalAlert;
use App\Models\OperationalAlertRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Delivery decides who receives an alert; the holder's *current* role decides who may still read
 * it. Demoting someone removes their access without rewriting a single historical row.
 */
class AlertEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private AlertFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new AlertFixture;
    }

    /* -------------------------------------------------------- the entitlement map itself */

    public function test_entitlement_is_defined_in_one_place_for_every_type_and_role(): void
    {
        $expected = [
            'admin' => ['inventory_low_stock', 'sale_receivable_outstanding', 'sale_refundable_credit', 'data_integrity_warning'],
            'manager' => ['inventory_low_stock', 'sale_receivable_outstanding', 'sale_refundable_credit'],
            'sales_rep' => [],
        ];
        foreach ($expected as $role => $types) {
            $this->assertSame($types, OperationalAlertType::visibleToRole(UserRole::from($role)),
                "visibleToRole({$role}) drifted from the recipient map");
        }

        // allowsRole and visibleToRole must never disagree: one source of truth, two shapes.
        foreach (UserRole::cases() as $role) {
            foreach (OperationalAlertType::cases() as $type) {
                $this->assertSame(
                    $type->allowsRole($role),
                    in_array($type->value, OperationalAlertType::visibleToRole($role), true),
                    "{$type->value} disagrees for {$role->value}",
                );
            }
        }
    }

    /* ------------------------------------------------- administrator demoted to manager */

    public function test_demoting_an_administrator_revokes_the_integrity_alerts_they_hold(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->manager();
        $this->fixture->saleWithLedgerMismatch($admin);
        app(OperationalAlertEvaluator::class)->evaluateIntegrity();
        $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5']);

        $integrity = $this->rowFor($admin, OperationalAlertType::DataIntegrityWarning);
        $inventory = $this->rowFor($admin, OperationalAlertType::InventoryLowStock);

        // While still an Administrator: both visible.
        app(UnreadAlertCount::class)->forget();
        $this->assertSame(3, app(UnreadAlertCount::class)->for($admin), 'integrity + receivable + inventory');
        $this->actingAs($admin)->get(route('notifications.show', $integrity))->assertOk();

        $historyBefore = $this->recipientFingerprint();
        $admin->forceFill(['role' => UserRole::Manager])->save();
        $demoted = $admin->fresh();

        // Detail, read and acknowledge are all refused for the type they no longer hold.
        $this->actingAs($demoted)->get(route('notifications.show', $integrity))->assertForbidden();
        $this->actingAs($demoted)->post(route('notifications.read', $integrity))->assertForbidden();
        $this->actingAs($demoted)->post(route('notifications.acknowledge', $integrity))->assertForbidden();

        // The index hides it entirely — no title, message, subject snapshot or severity.
        $index = $this->actingAs($demoted)->get(route('notifications.index'))->assertOk();
        $integrityAlert = $integrity->alert()->sole();
        $index->assertDontSee($integrityAlert->title, false);
        $index->assertDontSee($integrityAlert->message, false);
        $this->assertStringNotContainsString('notifications/'.$integrity->id, $index->getContent());
        // The subject label is deliberately not asserted absent: this Sale also carries a
        // receivable alert the Manager still holds, so seeing "SALE-0001 · customer" is correct.
        // What must not leak is the integrity alert itself — its title, message and row.
        $this->assertSame(2, $index->viewData('notifications')->total(), 'Pagination total must count authorized rows only');

        // The alert they still hold remains fully usable.
        $this->actingAs($demoted)->get(route('notifications.show', $inventory))->assertOk();

        // Badge drops immediately, and nothing was written.
        app(UnreadAlertCount::class)->forget();
        $this->assertSame(2, app(UnreadAlertCount::class)->for($demoted));
        $this->assertNull($integrity->fresh()->read_at);
        $this->assertNull($integrity->fresh()->acknowledged_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'operational_alert_acknowledged')->count());
        $this->assertSame($historyBefore, $this->recipientFingerprint(), 'Demotion must not touch delivery history');
    }

    public function test_read_all_skips_the_types_a_demoted_operator_lost(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->saleWithLedgerMismatch($admin);
        app(OperationalAlertEvaluator::class)->evaluateIntegrity();
        $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5']);

        $integrity = $this->rowFor($admin, OperationalAlertType::DataIntegrityWarning);
        $admin->forceFill(['role' => UserRole::Manager])->save();

        $this->actingAs($admin->fresh())->post(route('notifications.read-all'))->assertRedirect();

        $this->assertNull($integrity->fresh()->read_at, 'read-all must not mark a row the operator can no longer see');
        $this->assertSame(1, OperationalAlertRecipient::query()->forUser($admin)->whereNull('read_at')->count());
        $this->assertNotNull($this->rowFor($admin, OperationalAlertType::InventoryLowStock)->fresh()->read_at);
    }

    /* ------------------------------------------------- manager demoted to sales rep */

    public function test_demoting_a_manager_to_sales_representative_empties_their_inbox(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5']);
        $this->fixture->saleWithBalance($admin);

        $inventory = $this->rowFor($manager, OperationalAlertType::InventoryLowStock);
        $receivable = $this->rowFor($manager, OperationalAlertType::SaleReceivableOutstanding);
        app(UnreadAlertCount::class)->forget();
        $this->assertSame(2, app(UnreadAlertCount::class)->for($manager));

        $historyBefore = $this->recipientFingerprint();
        $manager->forceFill(['role' => UserRole::SalesRep])->save();
        $rep = $manager->fresh();

        $index = $this->actingAs($rep)->get(route('notifications.index'))->assertOk();
        $this->assertSame(0, $index->viewData('notifications')->total());
        $index->assertSee('You have no notifications', false);
        foreach (OperationalAlert::query()->pluck('message') as $message) {
            $index->assertDontSee($message, false);
        }

        foreach ([$inventory, $receivable] as $row) {
            $this->actingAs($rep)->get(route('notifications.show', $row))->assertForbidden();
            $this->actingAs($rep)->post(route('notifications.read', $row))->assertForbidden();
            $this->actingAs($rep)->post(route('notifications.acknowledge', $row))->assertForbidden();
        }
        $this->actingAs($rep)->post(route('notifications.read-all'))->assertRedirect();

        app(UnreadAlertCount::class)->forget();
        $this->assertSame(0, app(UnreadAlertCount::class)->for($rep));
        $this->assertSame('', app(UnreadAlertCount::class)->badge($rep));
        $this->assertSame($historyBefore, $this->recipientFingerprint(), 'Nothing may be written or removed');
    }

    /* ---------------------------------------------------------------- re-promotion */

    public function test_restoring_a_role_restores_visibility_without_duplicating_history(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->saleWithLedgerMismatch($admin);
        app(OperationalAlertEvaluator::class)->evaluateIntegrity();
        $integrity = $this->rowFor($admin, OperationalAlertType::DataIntegrityWarning);

        $admin->forceFill(['role' => UserRole::Manager])->save();
        $this->actingAs($admin->fresh())->get(route('notifications.show', $integrity))->assertForbidden();
        $rowCount = OperationalAlertRecipient::query()->count();

        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($admin->fresh())->get(route('notifications.show', $integrity))->assertOk();

        // Re-promotion restores access to the same row; it never creates a second delivery.
        $this->assertSame($rowCount, OperationalAlertRecipient::query()->count());
        app(ReconcileOperationalAlerts::class)->execute();
        $this->assertSame($rowCount, OperationalAlertRecipient::query()->count(),
            'Reconciliation must not duplicate an existing delivery after re-promotion');
    }

    /* ------------------------------------------------------ ownership still enforced */

    public function test_ownership_still_applies_when_both_operators_hold_the_role(): void
    {
        $admin = $this->fixture->admin();
        $other = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Second Admin']);
        $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5']);

        $adminRow = $this->rowFor($admin, OperationalAlertType::InventoryLowStock);
        $otherRow = $this->rowFor($other, OperationalAlertType::InventoryLowStock);

        // Same role, same alert, different person: entitlement passes, ownership does not.
        $this->actingAs($other)->get(route('notifications.show', $adminRow))->assertForbidden();
        $this->actingAs($other)->post(route('notifications.read', $adminRow))->assertForbidden();
        $this->actingAs($admin)->post(route('notifications.acknowledge', $otherRow))->assertForbidden();

        $this->assertNull($adminRow->fresh()->read_at);
        $this->assertNull($otherRow->fresh()->acknowledged_at);
    }

    /* ------------------------------------------------------------------- helpers */

    private function rowFor(User $user, OperationalAlertType $type): OperationalAlertRecipient
    {
        return OperationalAlertRecipient::query()
            ->where('user_id', $user->getKey())
            ->whereIn('operational_alert_id', OperationalAlert::query()->where('type', $type->value)->select('id'))
            ->orderBy('id')
            ->firstOrFail();
    }

    private function recipientFingerprint(): string
    {
        return DB::table('operational_alert_recipients')->orderBy('id')
            ->get(['id', 'operational_alert_id', 'user_id', 'read_at', 'acknowledged_at'])->toJson();
    }
}
