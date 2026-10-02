<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `whatsapp_connection.business_id` was Meta's business identifier, stored as a string. Inventra's
 * tenant key has the same name everywhere else, so the Meta value moves to `meta_business_id` —
 * renamed in place, value untouched — before the tenant key is introduced. One column, one meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->columnType('business_id') === 'varchar' && ! Schema::hasColumn('whatsapp_connection', 'meta_business_id')) {
            Schema::table('whatsapp_connection', fn (Blueprint $table) => $table->renameColumn('business_id', 'meta_business_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('whatsapp_connection', 'business_id')) {
            throw new RuntimeException('whatsapp_connection still has a tenant business_id; roll back WhatsApp ownership first.');
        }

        if (Schema::hasColumn('whatsapp_connection', 'meta_business_id')) {
            Schema::table('whatsapp_connection', fn (Blueprint $table) => $table->renameColumn('meta_business_id', 'business_id'));
        }
    }

    private function columnType(string $column): ?string
    {
        return DB::selectOne(
            "SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_connection' AND COLUMN_NAME = ?",
            [$column]
        )?->type;
    }
};
