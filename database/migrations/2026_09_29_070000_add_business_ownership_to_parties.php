<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: customers, suppliers and expense categories gain a Business.
 *
 * All three are roots, so an existing row can only belong to the installation's one Business, and
 * the backfill refuses to guess when there is not exactly one.
 */
return new class extends Migration
{
    private const TABLES = ['customers', 'suppliers', 'expense_categories'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
            });
        }

        $unowned = collect(self::TABLES)->contains(fn (string $table): bool => DB::table($table)->whereNull('business_id')->exists());

        if ($unowned) {
            $businessIds = DB::table('businesses')->pluck('id');

            if ($businessIds->count() !== 1) {
                throw new RuntimeException(
                    'Existing customers, suppliers and expense categories can only be assigned when exactly one business exists; found '.$businessIds->count().'.'
                );
            }

            foreach (self::TABLES as $table) {
                DB::table($table)->whereNull('business_id')->update(['business_id' => $businessIds->first()]);
            }
        }

        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereNull('business_id')->exists()) {
                throw new RuntimeException("Backfill left {$table} rows without a business.");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('businesses')->count() > 1) {
            throw new RuntimeException('Refusing to drop party ownership while more than one business exists.');
        }

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('business_id');
            });
        }
    }
};
