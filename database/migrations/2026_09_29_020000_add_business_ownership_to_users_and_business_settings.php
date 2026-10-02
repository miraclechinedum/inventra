<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: users and the settings row gain a Business, and every existing row is
 * assigned to the installation's one Business.
 *
 * Both columns are added nullable so the ALTER succeeds on a populated database, then backfilled.
 * The backfill refuses to guess: it runs only when exactly one Business exists, which is precisely
 * the state the previous migration leaves an existing installation in.
 *
 * `users.business_id` stays nullable after this migration. It is made NOT NULL by the Phase 2
 * contract migration, once this backfill has been verified on production and every User creation
 * path is known to set it. `business_settings.business_id` is contracted immediately by the next
 * migration, because it is one row and its ownership is what every settings read now depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
        });

        Schema::table('business_settings', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
        });

        $businessIds = DB::table('businesses')->pluck('id');

        if ($businessIds->count() !== 1) {
            throw new RuntimeException(
                'Existing users and settings can only be assigned when exactly one Business exists; found '.$businessIds->count().'.'
            );
        }

        $businessId = $businessIds->first();

        DB::table('users')->whereNull('business_id')->update(['business_id' => $businessId]);
        DB::table('business_settings')->whereNull('business_id')->update(['business_id' => $businessId]);

        foreach (['users', 'business_settings'] as $table) {
            if (DB::table($table)->whereNull('business_id')->exists()) {
                throw new RuntimeException("Backfill left {$table} rows without a Business.");
            }
        }
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
