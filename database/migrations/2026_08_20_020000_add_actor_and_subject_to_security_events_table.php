<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->after('actor_id')->constrained('users')->nullOnDelete();
        });

        DB::table('security_events')->whereNotNull('user_id')->update([
            'subject_user_id' => DB::raw('user_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_user_id');
            $table->dropConstrainedForeignId('actor_id');
        });
    }
};
