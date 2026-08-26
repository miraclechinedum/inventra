<?php

use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $movementTypes = implode("','", array_column(InventoryMovementType::cases(), 'value'));
        DB::statement("ALTER TABLE inventory_movements MODIFY type ENUM('{$movementTypes}') NOT NULL");

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_number', 32)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('customer_code_snapshot', 32);
            $table->string('customer_name_snapshot');
            $table->string('customer_phone_snapshot', 14);
            $table->enum('status', array_column(SaleStatus::cases(), 'value'))->index();
            $table->enum('payment_method', array_column(PaymentMethod::cases(), 'value'))->index();
            $table->enum('payment_status', array_column(PaymentStatus::cases(), 'value'))->index();
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('amount_paid', 15, 2);
            $table->decimal('balance_due', 15, 2);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('sold_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
            $table->index(['sold_by', 'created_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_sku_snapshot', 64);
            $table->string('product_name_snapshot');
            $table->string('unit_snapshot', 32);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['sale_id', 'product_id']);
        });

        foreach ([
            'sales_subtotal_non_negative' => 'subtotal >= 0',
            'sales_discount_non_negative' => 'discount_amount >= 0',
            'sales_total_non_negative' => 'total_amount >= 0',
            'sales_amount_paid_non_negative' => 'amount_paid >= 0',
            'sales_balance_non_negative' => 'balance_due >= 0',
        ] as $name => $expression) {
            DB::statement("ALTER TABLE sales ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_unit_price_non_negative CHECK (unit_price >= 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_line_total_non_negative CHECK (line_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        $movementTypes = implode("','", array_column(array_filter(
            InventoryMovementType::cases(),
            fn (InventoryMovementType $type): bool => ! in_array($type, [InventoryMovementType::Sale, InventoryMovementType::SaleVoid], true),
        ), 'value'));
        DB::statement("ALTER TABLE inventory_movements MODIFY type ENUM('{$movementTypes}') NOT NULL");
    }
};
