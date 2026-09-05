<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_number', 32)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('sale_number_snapshot', 32);
            $table->string('customer_code_snapshot', 32);
            $table->string('customer_name_snapshot');
            $table->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $table->string('returned_by_name_snapshot');
            $table->decimal('merchandise_value', 15, 2);
            $table->decimal('receivable_reduction', 15, 2);
            $table->decimal('refundable_credit_created', 15, 2);
            $table->string('reason', 500);
            $table->string('note', 500)->nullable();
            $table->timestamp('returned_at')->index();
            $table->timestamps();
            $table->index(['sale_id', 'returned_at']);
        });
        DB::statement('ALTER TABLE sale_returns ADD CONSTRAINT sale_returns_values_valid CHECK (merchandise_value > 0 AND receivable_reduction >= 0 AND refundable_credit_created >= 0 AND merchandise_value = receivable_reduction + refundable_credit_created)');

        Schema::create('sale_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_sku_snapshot', 64);
            $table->string('product_name_snapshot');
            $table->string('unit_snapshot', 32);
            $table->decimal('quantity_returned', 15, 3);
            $table->decimal('original_unit_price', 15, 2);
            $table->decimal('return_line_value', 15, 2);
            $table->enum('disposition', ['restock', 'non_restock']);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['sale_return_id', 'sale_item_id']);
            $table->index(['sale_item_id', 'created_at']);
        });
        DB::statement('ALTER TABLE sale_return_items ADD CONSTRAINT sale_return_items_values_valid CHECK (quantity_returned > 0 AND original_unit_price >= 0 AND return_line_value > 0)');

        Schema::create('sale_refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('refund_number', 32)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('sale_number_snapshot', 32);
            $table->string('customer_code_snapshot', 32);
            $table->string('customer_name_snapshot');
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['cash', 'transfer']);
            $table->string('reason', 500);
            $table->string('note', 500)->nullable();
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->string('refunded_by_name_snapshot');
            $table->timestamp('refunded_at')->index();
            $table->timestamps();
            $table->index(['sale_id', 'refunded_at']);
        });
        DB::statement('ALTER TABLE sale_refunds ADD CONSTRAINT sale_refunds_amount_positive CHECK (amount > 0)');

        foreach (['sale_return_requests' => 'sale_return_id', 'sale_refund_requests' => 'sale_refund_id'] as $name => $result) {
            Schema::create($name, function (Blueprint $table) use ($result): void {
                $table->id();
                $table->char('token_hash', 64)->unique();
                $table->char('payload_hash', 64)->nullable();
                $table->foreignId('sale_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->string('session_id');
                $table->timestamp('expires_at')->index();
                $table->timestamp('used_at')->nullable();
                $table->unsignedBigInteger($result)->nullable()->unique();
                $table->timestamps();
            });
        }
        Schema::table('sale_return_requests', fn (Blueprint $table) => $table->foreign('sale_return_id')->references('id')->on('sale_returns')->restrictOnDelete());
        Schema::table('sale_refund_requests', fn (Blueprint $table) => $table->foreign('sale_refund_id')->references('id')->on('sale_refunds')->restrictOnDelete());
        DB::statement("ALTER TABLE inventory_movements MODIFY type ENUM('initial','restock','adjustment','damage','loss','correction','sale','sale_void','purchase','sale_return') NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('sale_returns')->exists() || DB::table('sale_refunds')->exists()) {
            throw new RuntimeException('Cannot roll back Return or Refund history.');
        }
        Schema::dropIfExists('sale_refund_requests');
        Schema::dropIfExists('sale_return_requests');
        Schema::dropIfExists('sale_refunds');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        DB::statement("ALTER TABLE inventory_movements MODIFY type ENUM('initial','restock','adjustment','damage','loss','correction','sale','sale_void','purchase') NOT NULL");
    }
};
