<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE whatsapp_deliveries MODIFY status ENUM('pending','accepted','sent','delivered','read','failed','unresolved') NOT NULL");

        Schema::table('whatsapp_deliveries', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('failed_at');
            $table->foreignId('resolved_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
            $table->string('resolution_note', 500)->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        $hasResolutionHistory = DB::table('whatsapp_deliveries')
            ->where('status', 'unresolved')
            ->orWhereNotNull('resolved_at')
            ->orWhereNotNull('resolved_by')
            ->orWhereNotNull('resolution_note')
            ->exists();

        if ($hasResolutionHistory) {
            throw new RuntimeException(
                'Cannot roll back WhatsApp manual resolution fields while resolution history exists.'
            );
        }

        Schema::table('whatsapp_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'resolution_note']);
        });

        DB::statement("ALTER TABLE whatsapp_deliveries MODIFY status ENUM('pending','accepted','sent','delivered','read','failed') NOT NULL");
    }
};
