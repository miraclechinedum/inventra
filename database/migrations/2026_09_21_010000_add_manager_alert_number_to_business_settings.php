<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business-level destination for low-stock WhatsApp alerts.
 *
 * A NEW migration rather than an extension of 2026_09_19_010000_add_profile_fields_to_business_settings:
 * that one has already run on development (batch 10), so editing it would change the semantics of a
 * migration other databases have already recorded as applied — they would never receive this column
 * while still reporting the migration as done. Migration-history safety is worth one extra file.
 *
 * Additive and rollback-safe: one nullable column, no existing column touched, retyped or read.
 *
 * Length: 17 characters, matching `users.phone`'s validation ceiling and comfortably fitting the
 * canonical `+234XXXXXXXXXX` (14) this column is constrained to store. The value is normalised by
 * CanonicalLoginIdentifier before it ever reaches here, exactly as every other Inventra phone is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->string('manager_alert_number', 17)->nullable()->after('business_phone');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->dropColumn('manager_alert_number');
        });
    }
};
