<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract: every User belongs to a Business.
 *
 * Every tenant-user creation path assigns one — staff inherit the creating Administrator's Business
 * and the bootstrap command uses the installation's only Business — so a NULL here is a defect, not
 * a state to tolerate. Login identity is untouched: `email` and `phone` stay globally unique.
 * `(business_id, id)` is the key later composite foreign keys use to prove a referenced user belongs
 * to the same Business as the row referencing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->whereNull('business_id')->exists()) {
            throw new RuntimeException('Every user must belong to a business before ownership can be enforced.');
        }

        DB::statement('ALTER TABLE users MODIFY business_id BIGINT UNSIGNED NOT NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['business_id', 'id'], 'users_business_id_id_unique');
        });
    }

    public function down(): void
    {
        // The composite index may be serving the business_id foreign key, so the key is rebuilt
        // around its removal rather than left without an index.
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->dropUnique('users_business_id_id_unique');
        });

        DB::statement('ALTER TABLE users MODIFY business_id BIGINT UNSIGNED NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
        });
    }
};
