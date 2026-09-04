<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t): void {
            $t->id();
            $t->string('supplier_code', 32)->unique();
            $t->string('name')->index();
            $t->string('contact_person')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('email')->nullable();
            $t->string('address', 500)->nullable();
            $t->string('city')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('purchases', function (Blueprint $t): void {
            $t->id();
            $t->string('purchase_number', 32)->unique();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('supplier_code_snapshot', 32);
            $t->string('supplier_name_snapshot');
            $t->string('supplier_phone_snapshot', 32)->nullable();
            $t->decimal('subtotal', 15, 2);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2);
            $t->string('reference_number')->nullable();
            $t->string('note', 1000)->nullable();
            $t->enum('status', ['received'])->index();
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->string('received_by_name_snapshot');
            $t->timestamp('received_at')->index();
            $t->timestamps();
            $t->index(['supplier_id', 'received_at']);
            $t->index(['received_by', 'received_at']);
        });
        Schema::create('purchase_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('product_sku_snapshot', 64);
            $t->string('product_name_snapshot');
            $t->string('product_unit_snapshot', 32);
            $t->decimal('quantity', 15, 3);
            $t->decimal('unit_cost', 15, 2);
            $t->decimal('line_total', 15, 2);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['purchase_id', 'product_id']);
            $t->index(['product_id', 'created_at']);
        });
        Schema::create('purchase_requests', function (Blueprint $t): void {
            $t->id();
            $t->char('token_hash', 64)->unique();
            $t->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $t->string('session_id');
            $t->timestamp('expires_at')->index();
            $t->timestamp('used_at')->nullable();
            $t->foreignId('purchase_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->timestamps();
        });
        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_positive CHECK (quantity > 0 AND unit_cost > 0 AND line_total > 0)');
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_totals_valid CHECK (subtotal >= 0 AND discount_amount >= 0 AND total_amount = subtotal - discount_amount)');
        DB::statement("ALTER TABLE inventory_movements MODIFY type ENUM('initial','restock','adjustment','damage','loss','correction','sale','sale_void','purchase') NOT NULL");
    }

    public function down(): void
    {
        $hasPurchases = Schema::hasTable('purchases') && DB::table('purchases')->exists();
        $hasSuppliers = Schema::hasTable('suppliers') && DB::table('suppliers')->exists();

        if ($hasPurchases || $hasSuppliers) {
            throw new RuntimeException('Cannot roll back procurement tables while Supplier or Purchase history exists.');
        }
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
        DB::statement("ALTER TABLE inventory_movements MODIFY type ENUM('initial','restock','adjustment','damage','loss','correction','sale','sale_void') NOT NULL");
    }
};
