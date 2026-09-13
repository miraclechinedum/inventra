<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records where a staff member's profile photograph is stored on the private disk. Additive and
 * nullable: every existing account keeps working with no photograph, and the initials placeholder
 * continues to be shown until someone uploads one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        // Drops the reference only; stored files are left alone so a rollback destroys nothing.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
