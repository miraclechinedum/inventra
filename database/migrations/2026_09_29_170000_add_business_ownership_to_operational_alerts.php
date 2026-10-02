<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: operational alerts and their deliveries gain a Business.
 *
 * An alert belongs to the Business of the product or sale it is about — the only two subject types
 * alerts have — and never to a default. A delivery belongs to its alert's Business, and must have
 * been delivered to a user of that same Business. An alert whose subject no longer exists, or of a
 * subject type this does not know, cannot be attributed honestly, so the migration stops and names
 * it rather than guessing.
 */
return new class extends Migration
{
    /** @var array<string, string> alert subject_type => owning table */
    private const SUBJECTS = ['product' => 'products', 'sale' => 'sales'];

    public function up(): void
    {
        // Resumable: an existing column is kept and only unowned rows are backfilled.
        foreach (['operational_alerts', 'operational_alert_recipients'] as $table) {
            if (! Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
                });
            }
        }

        foreach (self::SUBJECTS as $type => $table) {
            DB::statement(
                "UPDATE operational_alerts a JOIN {$table} s ON s.id = a.subject_id
                 SET a.business_id = s.business_id WHERE a.business_id IS NULL AND a.subject_type = ?",
                [$type]
            );
        }

        DB::statement(
            'UPDATE operational_alert_recipients r JOIN operational_alerts a ON a.id = r.operational_alert_id
             SET r.business_id = a.business_id WHERE r.business_id IS NULL'
        );

        $unowned = DB::table('operational_alerts')->whereNull('business_id')
            ->selectRaw('subject_type, COUNT(*) AS total')->groupBy('subject_type')->pluck('total', 'subject_type');

        if ($unowned->isNotEmpty()) {
            throw new RuntimeException('Operational alerts whose subject cannot establish a business: '.$unowned->map(fn ($total, $type): string => "{$type} ({$total})")->implode(', ').'.');
        }

        if (DB::table('operational_alert_recipients')->whereNull('business_id')->exists()) {
            throw new RuntimeException('Backfill left alert deliveries without a business.');
        }

        $crossDelivered = DB::table('operational_alert_recipients as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->whereColumn('u.business_id', '<>', 'r.business_id')
            ->exists();

        if ($crossDelivered) {
            throw new RuntimeException('An alert was delivered to a user of another business.');
        }
    }

    public function down(): void
    {
        if (DB::table('businesses')->count() > 1) {
            throw new RuntimeException('Refusing to drop alert ownership while more than one business exists.');
        }

        foreach (['operational_alert_recipients', 'operational_alerts'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('business_id');
            });
        }
    }
};
