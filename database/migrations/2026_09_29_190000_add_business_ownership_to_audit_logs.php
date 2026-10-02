<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: every audit row gains the Business it is evidence for.
 *
 * Ownership comes from the audited record wherever that record is tenant-owned and still exists —
 * the strongest evidence, since it is the thing the row describes. A historical automatic WhatsApp
 * receipt row, whose subject predates tenancy, is attributed through its sale. Otherwise the acting
 * user's Business is used: a deleted subject, or one (a WhatsApp connection, automation or message)
 * that is not yet tenant-owned itself.
 *
 * Where the record and the actor both speak and disagree, the migration stops: an audit trail is
 * evidence and is never repaired by picking a side. A row neither can attribute also stops it, and
 * is named — nothing is assigned to a default Business.
 */
return new class extends Migration
{
    /** @var array<string, string> auditable_type => tenant-owned table carrying business_id */
    private const OWNED = [
        'App\\Models\\BusinessSetting' => 'business_settings',
        'App\\Models\\User' => 'users',
        'App\\Models\\ProductCategory' => 'product_categories',
        'App\\Models\\Product' => 'products',
        'App\\Models\\InventoryMovement' => 'inventory_movements',
        'App\\Models\\Customer' => 'customers',
        'App\\Models\\Supplier' => 'suppliers',
        'App\\Models\\ExpenseCategory' => 'expense_categories',
        'App\\Models\\Expense' => 'expenses',
        'App\\Models\\Sale' => 'sales',
        'App\\Models\\SaleDraft' => 'sale_drafts',
        'App\\Models\\SaleItem' => 'sale_items',
        'App\\Models\\SaleCorrection' => 'sale_corrections',
        'App\\Models\\SalePayment' => 'sale_payments',
        'App\\Models\\SaleDiscountRequest' => 'sale_discount_requests',
        'App\\Models\\SaleReturn' => 'sale_returns',
        'App\\Models\\SaleReturnItem' => 'sale_return_items',
        'App\\Models\\SaleRefund' => 'sale_refunds',
        'App\\Models\\Purchase' => 'purchases',
        'App\\Models\\PurchaseItem' => 'purchase_items',
        'App\\Models\\OperationalAlert' => 'operational_alerts',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('audit_logs', 'business_id')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
            });
        }

        foreach (self::OWNED as $type => $table) {
            DB::statement(
                "UPDATE audit_logs a JOIN {$table} t ON t.id = a.auditable_id
                 SET a.business_id = t.business_id WHERE a.business_id IS NULL AND a.auditable_type = ?",
                [$type]
            );
        }

        if (Schema::hasTable('whatsapp_deliveries')) {
            DB::statement(
                "UPDATE audit_logs a JOIN whatsapp_deliveries d ON d.id = a.auditable_id JOIN sales s ON s.id = d.sale_id
                 SET a.business_id = s.business_id WHERE a.business_id IS NULL AND a.auditable_type = ?",
                ['App\\Models\\WhatsAppDelivery']
            );
        }

        $disagreeing = DB::table('audit_logs as a')->join('users as u', 'u.id', '=', 'a.actor_id')
            ->whereNotNull('a.business_id')->whereColumn('u.business_id', '<>', 'a.business_id')->count();

        if ($disagreeing > 0) {
            throw new RuntimeException("{$disagreeing} audit rows record an actor from a different business than the record they describe.");
        }

        DB::statement('UPDATE audit_logs a JOIN users u ON u.id = a.actor_id SET a.business_id = u.business_id WHERE a.business_id IS NULL');

        $unowned = DB::table('audit_logs')->whereNull('business_id')
            ->selectRaw('auditable_type, COUNT(*) AS total')->groupBy('auditable_type')->pluck('total', 'auditable_type');

        if ($unowned->isNotEmpty()) {
            throw new RuntimeException('Audit rows with no record or actor to establish their business: '.$unowned->map(fn ($total, $type): string => "{$type} ({$total})")->implode(', ').'.');
        }
    }

    public function down(): void
    {
        if (DB::table('businesses')->count() > 1) {
            throw new RuntimeException('Refusing to drop audit ownership while more than one business exists.');
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
