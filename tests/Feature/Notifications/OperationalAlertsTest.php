<?php

namespace Tests\Feature\Notifications;

use App\Actions\Alerts\ReconcileOperationalAlerts;
use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\SetProductActiveState;
use App\Actions\Sale\RecordSalePayment;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Alerts\OperationalAlertEvaluator;
use App\Enums\OperationalAlertSeverity;
use App\Enums\OperationalAlertStatus;
use App\Enums\OperationalAlertType;
use App\Enums\UserStatus;
use App\Models\OperationalAlert;
use App\Models\OperationalAlertRecipient;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePaymentRequest;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturnRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const FINGERPRINTED = ['operational_alerts', 'operational_alert_recipients', 'audit_logs', 'products', 'sales'];

    private AlertFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new AlertFixture;
    }

    /* ------------------------------------------------------------- low stock lifecycle */

    public function test_a_product_above_its_reorder_level_raises_no_alert(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->product($admin, ['initial_stock' => '100', 'reorder_level' => '5']);

        $this->assertSame(0, OperationalAlert::query()->count());
    }

    public function test_reaching_the_reorder_level_opens_a_warning_alert(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '5', 'reorder_level' => '5']);

        $alert = OperationalAlert::query()->sole();
        $this->assertSame(OperationalAlertType::InventoryLowStock, $alert->type);
        $this->assertSame(OperationalAlertSeverity::Warning, $alert->severity);
        $this->assertSame(OperationalAlertStatus::Active, $alert->status);
        $this->assertSame('product', $alert->subject_type);
        $this->assertSame((int) $product->id, (int) $alert->subject_id);
        $this->assertSame(1, (int) $alert->occurrence);
        $this->assertNotNull($alert->active_key);
        $this->assertNull($alert->resolved_at);
    }

    public function test_zero_stock_is_critical_on_the_same_lifecycle(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '5', 'reorder_level' => '5']);
        $opened = OperationalAlert::query()->sole();

        app(AdjustStock::class)->execute($admin, $product->fresh(), [
            'type' => 'loss', 'operation' => 'decrease', 'quantity' => '5', 'reason' => 'Damaged',
        ]);

        $alert = OperationalAlert::query()->sole();
        $this->assertSame($opened->id, $alert->id, 'Escalation must stay one lifecycle, not open a second alert');
        $this->assertSame(OperationalAlertSeverity::Critical, $alert->severity);
        $this->assertSame(OperationalAlertStatus::Active, $alert->status);
        $this->assertStringContainsString('out of stock', $alert->title);
    }

    public function test_restocking_resolves_the_alert_and_keeps_its_history(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $recipients = OperationalAlertRecipient::query()->count();

        app(AdjustStock::class)->execute($admin, $product->fresh(), [
            'type' => 'restock', 'operation' => 'increase', 'quantity' => '100', 'reason' => 'Delivery',
        ]);

        $alert = OperationalAlert::query()->sole();
        $this->assertSame(OperationalAlertStatus::Resolved, $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertNull($alert->active_key, 'Resolving must release the dedupe key so the condition can recur');
        $this->assertSame($recipients, OperationalAlertRecipient::query()->count(), 'Resolution must not delete delivery history');
    }

    public function test_a_recurrence_opens_a_new_occurrence_rather_than_reviving_history(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $first = OperationalAlert::query()->sole();

        app(AdjustStock::class)->execute($admin, $product->fresh(), ['type' => 'restock', 'operation' => 'increase', 'quantity' => '100', 'reason' => 'Delivery']);
        app(AdjustStock::class)->execute($admin, $product->fresh(), ['type' => 'loss', 'operation' => 'decrease', 'quantity' => '99', 'reason' => 'Shrinkage']);

        $alerts = OperationalAlert::query()->orderBy('id')->get();
        $this->assertCount(2, $alerts, 'A recurrence is a new lifecycle occurrence');
        $this->assertSame(1, (int) $alerts[0]->occurrence);
        $this->assertSame(2, (int) $alerts[1]->occurrence);
        $this->assertSame(OperationalAlertStatus::Resolved, $alerts[0]->status);
        $this->assertSame(OperationalAlertStatus::Active, $alerts[1]->status);
        // The first occurrence still answers "when did this first appear and when did it clear".
        $this->assertEquals($first->first_detected_at, $alerts[0]->first_detected_at);
        $this->assertNotNull($alerts[0]->resolved_at);
    }

    public function test_archiving_a_low_stock_product_resolves_its_alert(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $this->assertSame(OperationalAlertStatus::Active, OperationalAlert::query()->sole()->status);

        app(SetProductActiveState::class)->execute($admin, $product->fresh(), false);

        $this->assertSame(OperationalAlertStatus::Resolved, OperationalAlert::query()->sole()->status);
    }

    /* --------------------------------------------------------------- deduplication */

    public function test_repeated_evaluation_never_duplicates_an_active_alert(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->manager();
        $product = $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);

        foreach (range(1, 10) as $ignored) {
            app(OperationalAlertEvaluator::class)->evaluateProduct($product->fresh());
        }

        $this->assertSame(1, OperationalAlert::query()->count());
        $this->assertSame(2, OperationalAlertRecipient::query()->count(), 'Recipients must not multiply');
    }

    public function test_the_database_refuses_a_second_active_alert_for_the_same_condition(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $alert = OperationalAlert::query()->sole();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('operational_alerts')->insert([
            'type' => $alert->type->value, 'severity' => 'warning', 'status' => 'active',
            'subject_type' => 'product', 'subject_id' => $alert->subject_id,
            'subject_label_snapshot' => 'dup', 'title' => 'dup', 'message' => 'dup',
            'active_key' => $alert->active_key, 'occurrence' => 2,
            'first_detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_an_incoherent_lifecycle_row(): void
    {
        $blocked = 0;

        foreach ([
            'active with resolved_at' => ['status' => 'active', 'active_key' => 'k1', 'resolved_at' => now()],
            'active without key' => ['status' => 'active', 'active_key' => null, 'resolved_at' => null],
            'resolved keeping key' => ['status' => 'resolved', 'active_key' => 'k2', 'resolved_at' => now()],
            'resolved without time' => ['status' => 'resolved', 'active_key' => null, 'resolved_at' => null],
            'unknown status' => ['status' => 'archived', 'active_key' => null, 'resolved_at' => now()],
            'unknown severity' => ['status' => 'active', 'active_key' => 'k3', 'resolved_at' => null, 'severity' => 'apocalyptic'],
        ] as $row) {
            try {
                DB::table('operational_alerts')->insert(array_merge([
                    'type' => 'inventory_low_stock', 'severity' => 'warning',
                    'subject_type' => 'product', 'subject_id' => 1,
                    'subject_label_snapshot' => 'x', 'title' => 'x', 'message' => 'x', 'occurrence' => 1,
                    'first_detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ], $row));
            } catch (\Throwable) {
                $blocked++;
            }
        }

        $this->assertSame(6, $blocked, 'Every incoherent lifecycle combination must be rejected by the database');
        $this->assertSame(0, OperationalAlert::query()->count());
    }

    public function test_a_recipient_cannot_be_delivered_the_same_alert_twice(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $recipient = OperationalAlertRecipient::query()->firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('operational_alert_recipients')->insert([
            'operational_alert_id' => $recipient->operational_alert_id,
            'user_id' => $recipient->user_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------- recipients */

    public function test_recipients_are_derived_from_role_and_exclude_sales_representatives(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $rep = $this->fixture->rep();

        $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);

        $delivered = OperationalAlertRecipient::query()->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$admin->id, $manager->id], $delivered);
        $this->assertNotContains($rep->id, $delivered, 'Sales Representatives receive no operational alerts in this foundation');
    }

    public function test_integrity_warnings_reach_administrators_only(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $this->fixture->saleWithLedgerMismatch($admin);
        app(OperationalAlertEvaluator::class)->evaluateIntegrity();

        $alert = OperationalAlert::query()->where('type', OperationalAlertType::DataIntegrityWarning->value)->sole();
        $this->assertSame([$admin->id], $alert->recipients()->pluck('user_id')->all());
        $this->assertNotContains($manager->id, $alert->recipients()->pluck('user_id')->all());
        $this->assertSame(OperationalAlertSeverity::Critical, $alert->severity);
    }

    public function test_the_integrity_message_exposes_no_internal_detail(): void
    {
        $admin = $this->fixture->admin();
        $sale = $this->fixture->saleWithLedgerMismatch($admin);
        app(OperationalAlertEvaluator::class)->evaluateIntegrity();

        $alert = OperationalAlert::query()->where('type', OperationalAlertType::DataIntegrityWarning->value)->sole();

        foreach (['SELECT', 'JOIN', 'amount_paid', 'ledger_paid', 'COALESCE', 'sale_payments', 'Exception', '#0 '] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $alert->message);
        }
        $this->assertStringContainsString($sale->sale_number, $alert->message);
    }

    public function test_inactive_and_locked_accounts_receive_no_new_deliveries_but_keep_history(): void
    {
        $admin = $this->fixture->admin();
        $manager = $this->fixture->manager();
        $product = $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $this->assertSame(2, OperationalAlertRecipient::query()->count());

        $manager->forceFill(['status' => UserStatus::Inactive])->save();
        $historic = OperationalAlertRecipient::query()->where('user_id', $manager->id)->count();

        // A second, unrelated low-stock condition while the Manager is inactive.
        $this->fixture->product($admin, ['initial_stock' => '1', 'reorder_level' => '5']);

        $this->assertSame($historic, OperationalAlertRecipient::query()->where('user_id', $manager->id)->count(),
            'An inactive account must not receive new deliveries, and must not lose old ones');
        $this->assertSame(2, OperationalAlertRecipient::query()->where('user_id', $admin->id)->count());
        unset($product);
    }

    /* ------------------------------------------------------------- sale financials */

    public function test_an_outstanding_balance_opens_and_resolves_with_the_balance(): void
    {
        $admin = $this->fixture->admin();
        $sale = $this->fixture->saleWithBalance($admin);

        $alert = OperationalAlert::query()->where('type', OperationalAlertType::SaleReceivableOutstanding->value)->sole();
        $this->assertSame(OperationalAlertStatus::Active, $alert->status);
        $this->assertStringNotContainsStringIgnoringCase('overdue', $alert->message);

        // Partial payment: still outstanding.
        app(RecordSalePayment::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->fixture->requestToken(new SalePaymentRequest, $admin, $sale),
            'amount' => '100.00', 'payment_method' => 'cash', 'note' => null,
        ], 'alerts');
        $this->assertSame(OperationalAlertStatus::Active, $alert->fresh()->status);

        // Settled in full: resolves.
        $remaining = (string) Sale::query()->whereKey($sale->id)->value('balance_due');
        app(RecordSalePayment::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->fixture->requestToken(new SalePaymentRequest, $admin, $sale),
            'amount' => $remaining, 'payment_method' => 'cash', 'note' => null,
        ], 'alerts');

        $this->assertSame(OperationalAlertStatus::Resolved, $alert->fresh()->status);
        $this->assertSame(1, OperationalAlert::query()->where('type', OperationalAlertType::SaleReceivableOutstanding->value)->count());
    }

    public function test_a_fully_paid_sale_never_opens_a_receivable_alert(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->saleWithBalance($admin, '5000.00');

        $this->assertSame(0, OperationalAlert::query()->where('type', OperationalAlertType::SaleReceivableOutstanding->value)->count());
    }

    public function test_refundable_credit_opens_and_resolves_with_the_credit(): void
    {
        $admin = $this->fixture->admin();
        $sale = $this->fixture->saleWithBalance($admin, '5000.00');
        $item = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame(0, OperationalAlert::query()->where('type', OperationalAlertType::SaleRefundableCredit->value)->count());

        app(RecordSaleReturn::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->fixture->requestToken(new SaleReturnRequest, $admin, $sale),
            'reason' => 'Alert fixture',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '2.000', 'disposition' => 'non_restock']],
        ], 'alerts');

        $credit = OperationalAlert::query()->where('type', OperationalAlertType::SaleRefundableCredit->value)->sole();
        $this->assertSame(OperationalAlertStatus::Active, $credit->status);
        $this->assertSame(OperationalAlertSeverity::Info, $credit->severity);

        $outstanding = (string) Sale::query()->whereKey($sale->id)->value('refundable_credit');
        app(RecordSaleRefund::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->fixture->requestToken(new SaleRefundRequest, $admin, $sale),
            'amount' => $outstanding, 'payment_method' => 'cash', 'reason' => 'Alert fixture',
        ], 'alerts');

        $this->assertSame(OperationalAlertStatus::Resolved, $credit->fresh()->status);
    }

    /* ------------------------------------------------------------- reconciliation */

    public function test_reconciliation_is_idempotent_across_repeated_runs(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->manager();
        $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $this->fixture->saleWithBalance($admin);

        $before = $this->fingerprint();

        foreach (range(1, 10) as $ignored) {
            app(ReconcileOperationalAlerts::class)->execute();
        }

        $this->assertSame($before, $this->fingerprint(), 'Reconciliation must converge, not accumulate');
    }

    public function test_reconciliation_repairs_a_missed_projection(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->manager();
        $product = $this->fixture->product($admin, ['initial_stock' => '100', 'reorder_level' => '5']);

        // A stock change that bypasses the domain entirely, as a dropped projection would look.
        DB::table('products')->where('id', $product->id)->update(['current_stock' => '1.000']);
        $this->assertSame(0, OperationalAlert::query()->count());

        $result = app(ReconcileOperationalAlerts::class)->execute();

        $this->assertSame(1, OperationalAlert::query()->active()->count());
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, OperationalAlertRecipient::query()->count());
    }

    public function test_reconciliation_resolves_an_alert_whose_condition_silently_cleared(): void
    {
        $admin = $this->fixture->admin();
        $product = $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $this->assertSame(1, OperationalAlert::query()->active()->count());

        DB::table('products')->where('id', $product->id)->update(['current_stock' => '900.000']);
        app(ReconcileOperationalAlerts::class)->execute();

        $this->assertSame(0, OperationalAlert::query()->active()->count());
        $this->assertSame(1, OperationalAlert::query()->count(), 'History is preserved, not deleted');
    }

    public function test_the_reconciliation_command_runs_and_reports_bounded_counts(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $sale = $this->fixture->saleWithBalance($admin);

        $this->artisan('inventra:reconcile-operational-alerts')
            ->expectsOutputToContain('Subjects evaluated:')
            ->expectsOutputToContain('Alerts resolved:')
            ->expectsOutputToContain('Operational alerts reconciled.')
            ->assertSuccessful();

        // No customer name, product name or amount leaks into scheduler output.
        $this->artisan('inventra:reconcile-operational-alerts')
            ->doesntExpectOutputToContain($sale->customer_name_snapshot)
            ->doesntExpectOutputToContain('₦')
            ->assertSuccessful();
    }

    public function test_reconciliation_never_writes_to_business_records(): void
    {
        $admin = $this->fixture->admin();
        $this->fixture->product($admin, ['initial_stock' => '2', 'reorder_level' => '5']);
        $this->fixture->saleWithLedgerMismatch($admin);

        $business = $this->businessFingerprint();
        app(ReconcileOperationalAlerts::class)->execute();

        $this->assertSame($business, $this->businessFingerprint(),
            'Alerts observe domain truth; reconciliation must never repair or alter it');
        $this->assertSame(1, OperationalAlert::query()->where('type', OperationalAlertType::DataIntegrityWarning->value)->count());
    }

    /* --------------------------------------------------------------------- helpers */

    private function fingerprint(): array
    {
        return collect(self::FINGERPRINTED)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->count().':'.hash('sha256', DB::table($table)->orderBy('id')->get()->toJson()),
        ])->all();
    }

    private function businessFingerprint(): array
    {
        return collect(['products', 'sales', 'sale_items', 'sale_payments', 'sale_returns', 'sale_refunds', 'inventory_movements', 'customers'])
            ->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->count().':'.hash('sha256', DB::table($table)->orderBy('id')->get()->toJson()),
            ])->all();
    }
}
