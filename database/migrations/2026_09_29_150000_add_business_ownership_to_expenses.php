<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: an expense belongs to its category's Business. The recording user is
 * cross-checked against it, and a disagreement stops the migration rather than being repaired.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resumable: a column already added by an interrupted run is kept, and only unowned rows are
        // backfilled, so the migration can simply be run again.
        if (! Schema::hasColumn('expenses', 'business_id')) {
            Schema::table('expenses', function (Blueprint $blueprint): void {
                $blueprint->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
            });
        }

        DB::statement('UPDATE expenses e JOIN expense_categories c ON c.id = e.expense_category_id SET e.business_id = c.business_id WHERE e.business_id IS NULL');

        if (DB::table('expenses')->whereNull('business_id')->exists()) {
            throw new RuntimeException('Backfill left expenses without a business.');
        }

        $mismatched = DB::table('expenses')->join('users', 'users.id', '=', 'expenses.recorded_by')
            ->whereColumn('users.business_id', '<>', 'expenses.business_id')->exists();

        if ($mismatched) {
            throw new RuntimeException('An expense was recorded by a user of another business.');
        }
    }

    public function down(): void
    {
        if (DB::table('businesses')->count() > 1) {
            throw new RuntimeException('Refusing to drop expense ownership while more than one business exists.');
        }

        Schema::table('expenses', function (Blueprint $blueprint): void {
            $blueprint->dropConstrainedForeignId('business_id');
        });
    }
};
