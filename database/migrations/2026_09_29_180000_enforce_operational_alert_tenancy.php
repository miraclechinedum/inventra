<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract: an alert and every delivery of it belong to one Business, enforced by the database.
 *
 *  - Deduplication becomes per Business: `(business_id, active_key)` replaces the global
 *    `active_key` unique, so the same condition may be active in two businesses at once. A resolved
 *    alert still releases its key (NULL), exactly as before.
 *  - A delivery's alert and its recipient must both belong to the delivery's Business: composite
 *    keys onto `(business_id, id)` of alerts and users. Both existing keys are RESTRICT, so the
 *    composite ones keep that behaviour.
 *
 * The subject is polymorphic, which no foreign key can express; it is verified here and enforced
 * on creation by the model.
 */
return new class extends Migration
{
    private const SUBJECTS = ['product' => 'products', 'sale' => 'sales'];

    public function up(): void
    {
        foreach (['operational_alerts', 'operational_alert_recipients'] as $table) {
            $this->refuseWhen(DB::table($table)->whereNull('business_id')->exists(), "{$table} has rows without a business.");
        }

        foreach (self::SUBJECTS as $type => $table) {
            $mismatched = DB::table('operational_alerts as a')->join("{$table} as s", 's.id', '=', 'a.subject_id')
                ->where('a.subject_type', $type)->whereColumn('s.business_id', '<>', 'a.business_id')->exists();
            $this->refuseWhen($mismatched, "An alert about a {$type} belongs to a different business from its subject.");
        }

        $this->refuseWhen(DB::table('operational_alert_recipients as r')->join('operational_alerts as a', 'a.id', '=', 'r.operational_alert_id')
            ->whereColumn('a.business_id', '<>', 'r.business_id')->exists(), 'A delivery belongs to a different business from its alert.');
        $this->refuseWhen(DB::table('operational_alert_recipients as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->whereColumn('u.business_id', '<>', 'r.business_id')->exists(), 'An alert was delivered to a user of another business.');

        foreach (['operational_alerts', 'operational_alert_recipients'] as $table) {
            // MySQL can refuse to tighten a column its own foreign key uses (error 1832).
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(['business_id']));
            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NOT NULL");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete());
        }

        Schema::table('operational_alerts', function (Blueprint $table): void {
            $table->unique(['business_id', 'id'], 'operational_alerts_business_id_id_unique');
            $table->unique(['business_id', 'active_key'], 'operational_alerts_business_id_active_key_unique');
            $table->dropUnique(['active_key']);
        });

        Schema::table('operational_alert_recipients', function (Blueprint $table): void {
            $table->foreign(['business_id', 'operational_alert_id'], 'operational_alert_recipients_alert_tenant_fk')
                ->references(['business_id', 'id'])->on('operational_alerts')->restrictOnDelete();
            $table->foreign(['business_id', 'user_id'], 'operational_alert_recipients_user_tenant_fk')
                ->references(['business_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $shared = DB::table('operational_alerts')->whereNotNull('active_key')
            ->select('active_key')->groupBy('active_key')->havingRaw('COUNT(*) > 1')->exists();
        $this->refuseWhen($shared, 'Two businesses have the same condition active; global alert deduplication cannot be restored.');

        Schema::table('operational_alert_recipients', function (Blueprint $table): void {
            $table->dropForeign('operational_alert_recipients_alert_tenant_fk');
            $table->dropForeign('operational_alert_recipients_user_tenant_fk');
            $table->dropForeign(['business_id']);
        });

        // MySQL keeps the index it created for each dropped key; left behind, it would collide with
        // the key's name on a re-apply.
        foreach (['operational_alert_recipients_alert_tenant_fk', 'operational_alert_recipients_user_tenant_fk'] as $index) {
            if ($this->indexExists('operational_alert_recipients', $index)) {
                Schema::table('operational_alert_recipients', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }

        Schema::table('operational_alerts', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->unique('active_key');
            $table->dropUnique('operational_alerts_business_id_active_key_unique');
            $table->dropUnique('operational_alerts_business_id_id_unique');
        });

        foreach (['operational_alerts', 'operational_alert_recipients'] as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NULL");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete());
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }

    private function refuseWhen(bool $condition, string $reason): void
    {
        if ($condition) {
            throw new RuntimeException($reason);
        }
    }
};
