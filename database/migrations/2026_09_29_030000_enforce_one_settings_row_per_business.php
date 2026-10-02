<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract: the settings row belongs to exactly one Business, and a Business has at most one.
 *
 * This replaces the installation-wide singleton. The old invariant — one row, pinned by a UNIQUE
 * `singleton_key` and a CHECK on its literal — made a second business's profile impossible; the
 * new one is the same guarantee per tenant, enforced by a NOT NULL, UNIQUE `business_id`. With
 * ownership enforced there is nothing left for `singleton_key` to mean, so it is removed rather
 * than kept as a column that could be mistaken for a "the settings" lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('business_settings')->whereNull('business_id')->exists()) {
            throw new RuntimeException('Every business_settings row must belong to a Business before ownership is enforced.');
        }

        DB::statement('ALTER TABLE business_settings MODIFY business_id BIGINT UNSIGNED NOT NULL');

        Schema::table('business_settings', function (Blueprint $table): void {
            $table->unique('business_id');
        });

        if ($this->checkExists('business_settings_singleton_valid')) {
            DB::statement('ALTER TABLE business_settings DROP CONSTRAINT business_settings_singleton_valid');
        }

        Schema::table('business_settings', function (Blueprint $table): void {
            $table->dropUnique(['singleton_key']);
            $table->dropColumn('singleton_key');
        });
    }

    public function down(): void
    {
        if (DB::table('business_settings')->count() > 1) {
            throw new RuntimeException('Refusing to restore the settings singleton while more than one business has settings.');
        }

        Schema::table('business_settings', function (Blueprint $table): void {
            $table->string('singleton_key', 16)->nullable()->after('id');
        });

        DB::table('business_settings')->update(['singleton_key' => 'business']);
        DB::statement('ALTER TABLE business_settings MODIFY singleton_key VARCHAR(16) NOT NULL');

        // MySQL retired the foreign key's own index once the UNIQUE one could serve it, so the key is
        // dropped before that index and rebuilt afterwards on the nullable column.
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->unique('singleton_key');
            $table->dropForeign(['business_id']);
            $table->dropUnique(['business_id']);
        });

        DB::statement("ALTER TABLE business_settings ADD CONSTRAINT business_settings_singleton_valid CHECK (singleton_key = 'business')");
        DB::statement('ALTER TABLE business_settings MODIFY business_id BIGINT UNSIGNED NULL');

        Schema::table('business_settings', function (Blueprint $table): void {
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });
    }

    private function checkExists(string $constraint): bool
    {
        return DB::selectOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings'
               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$constraint]
        ) !== null;
    }
};
