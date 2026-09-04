<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('category_code', 32)->unique();
            $table->string('name')->index();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('expense_number', 32)->unique();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('category_code_snapshot', 32);
            $table->string('category_name_snapshot');
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['cash', 'transfer', 'pos'])->index();
            $table->string('payee')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('description', 500);
            $table->string('note', 1000)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('recorded_by_name_snapshot');
            $table->date('incurred_at')->index();
            $table->timestamps();
            $table->index(['expense_category_id', 'incurred_at']);
            $table->index(['recorded_by', 'incurred_at']);
        });

        Schema::create('expense_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_id');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('expense_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        $hasExpenses = Schema::hasTable('expenses') && DB::table('expenses')->exists();
        $hasCategories = Schema::hasTable('expense_categories') && DB::table('expense_categories')->exists();

        if ($hasExpenses || $hasCategories) {
            throw new RuntimeException('Cannot roll back Expense tables while Category or Expense history exists.');
        }

        Schema::dropIfExists('expense_requests');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
