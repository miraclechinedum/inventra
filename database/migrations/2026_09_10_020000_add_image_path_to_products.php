<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records where a product's photograph is stored on the private disk. Additive and nullable, so
 * every existing product keeps working with no photograph and no backfill is required.
 *
 * The column holds a disk-relative path such as `product-images/<40 random chars>.webp`, never a
 * URL and never a client-supplied filename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        // Drops the reference only. Stored image files are left on disk rather than deleted, so a
        // rollback can never destroy something a re-migration cannot recover.
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
