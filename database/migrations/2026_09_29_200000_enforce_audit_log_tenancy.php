<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract: every audit row belongs to a Business.
 *
 * The audited record is polymorphic and the actor key is SET NULL on delete, so neither can carry a
 * composite foreign key. Both invariants are therefore verified here over existing rows — the row's
 * Business must equal its tenant-owned subject's wherever that subject still exists — and enforced
 * for new rows by AuditLogger. The listing index serves the Business's audit screen.
 */
return new class extends Migration
{
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
        if (DB::table('audit_logs')->whereNull('business_id')->exists()) {
            throw new RuntimeException('audit_logs has rows without a business.');
        }

        foreach (self::OWNED as $type => $table) {
            $mismatched = DB::table('audit_logs as a')->join("{$table} as t", 't.id', '=', 'a.auditable_id')
                ->where('a.auditable_type', $type)->whereColumn('t.business_id', '<>', 'a.business_id')->exists();

            if ($mismatched) {
                throw new RuntimeException("Audit rows about {$table} belong to a different business from the record they describe.");
            }
        }

        // MySQL can refuse to tighten a column its own foreign key uses (error 1832).
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropForeign(['business_id']));
        DB::statement('ALTER TABLE audit_logs MODIFY business_id BIGINT UNSIGNED NOT NULL');
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->index(['business_id', 'created_at', 'id'], 'audit_logs_business_listing_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->dropIndex('audit_logs_business_listing_index');
        });

        DB::statement('ALTER TABLE audit_logs MODIFY business_id BIGINT UNSIGNED NULL');
        Schema::table('audit_logs', fn (Blueprint $table) => $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete());
    }
};
