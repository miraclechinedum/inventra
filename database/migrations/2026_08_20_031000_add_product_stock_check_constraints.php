<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_current_stock_non_negative CHECK (current_stock >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_reorder_level_non_negative CHECK (reorder_level >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CHECK products_reorder_level_non_negative');
        DB::statement('ALTER TABLE products DROP CHECK products_current_stock_non_negative');
    }
};
