<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business identity for the single Inventra installation. There is exactly one row, and that is a
 * database invariant rather than a convention: `singleton_key` is UNIQUE and a CHECK constraint
 * pins it to one literal, so a second row cannot be inserted by any code path, race or console.
 *
 * Currency and timezone are deliberately absent. Money is rendered as a hard-coded naira symbol in
 * 30 views and no table snapshots a currency, so an editable currency would silently reinterpret
 * every historical amount. Operator-facing dates resolve through config('business.timezone') and
 * historical Expense business dates were evaluated in it, so an editable timezone would move closed
 * periods. Both stay fixed and are surfaced read-only.
 */
return new class extends Migration
{
    private const SINGLETON_KEY = 'business';

    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 16)->unique();
            $table->string('business_name', 150);
            $table->string('legal_name', 150)->nullable();
            $table->string('business_phone', 20)->nullable();
            $table->string('business_email', 255)->nullable();
            $table->string('business_address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('receipt_footer', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE business_settings ADD CONSTRAINT business_settings_singleton_valid CHECK (singleton_key = '".self::SINGLETON_KEY."')");
        DB::statement('ALTER TABLE business_settings ADD CONSTRAINT business_settings_name_not_blank CHECK (CHAR_LENGTH(TRIM(business_name)) > 0)');

        // Bootstrapped here so a fresh install and an existing upgrade both arrive at exactly one
        // row, deterministically and without a request ever having to create it.
        DB::table('business_settings')->insert([
            'singleton_key' => self::SINGLETON_KEY,
            'business_name' => 'Inventra Smart Trade',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
