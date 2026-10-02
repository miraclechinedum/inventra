<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business profile attributes the Business profile screen needs.
 *
 * Additive and rollback-safe: four new columns on the existing singleton row, no existing column
 * touched, renamed or retyped, and no monetary value read or rewritten anywhere.
 *
 * On `currency` in particular. This is display/business configuration, NOT a money migration.
 * Inventra renders a hard-coded naira symbol across its views and no table snapshots a currency
 * against a historical amount, so changing this value can never reinterpret a recorded sale — and
 * nothing in this migration goes near `sales`, `sale_payments` or any other money column. The
 * column is added with an `NGN` default so every existing installation keeps behaving exactly as it
 * does today, while the value is stored as a clean ISO-4217 code so more currencies can be offered
 * later without reshaping anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            // A path into the private image disk, never image bytes. Same storage contract as
            // staff photographs and product images.
            $table->string('logo_path')->nullable()->after('legal_name');

            // Profile metadata only — nothing in the domain branches on it.
            $table->string('business_type', 60)->nullable()->after('logo_path');

            // ISO-4217. Defaulted so existing rows and every current money view are unaffected.
            $table->string('currency', 3)->default('NGN')->after('business_type');

            $table->string('tax_number', 40)->nullable()->after('currency');
        });

        // A currency code is three upper-case letters. Enforced in the database so a crafted
        // request can never store something the formatting layer would not recognise.
        DB::statement("ALTER TABLE business_settings ADD CONSTRAINT business_settings_currency_is_iso CHECK (CHAR_LENGTH(currency) = 3 AND CAST(currency AS BINARY) = CAST(UPPER(currency) AS BINARY) AND currency REGEXP '^[A-Za-z]{3}$')");

        // The existing row keeps the behaviour it already has; the default covers it, and this
        // makes that explicit rather than implicit.
        DB::table('business_settings')->whereNull('currency')->update(['currency' => 'NGN']);
    }

    public function down(): void
    {
        // DROP CONSTRAINT, not DROP CHECK: MariaDB rejects the MySQL-only spelling.
        DB::statement('ALTER TABLE business_settings DROP CONSTRAINT business_settings_currency_is_iso');

        Schema::table('business_settings', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'business_type', 'currency', 'tax_number']);
        });
    }
};
