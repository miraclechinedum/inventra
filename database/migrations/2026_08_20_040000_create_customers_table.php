<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code', 32)->unique();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('phone', 14)->unique();
            $table->string('email')->nullable()->index();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('whatsapp_opt_in')->default(false)->index();
            $table->timestamp('whatsapp_opt_in_at')->nullable();
            $table->timestamp('whatsapp_opt_out_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
