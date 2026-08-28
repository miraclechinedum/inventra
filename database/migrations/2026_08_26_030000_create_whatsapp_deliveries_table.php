<?php

use App\Enums\WhatsAppDeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('destination_phone', 14);
            $table->timestamp('consent_checked_at');
            $table->timestamp('consent_opt_in_at_snapshot')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('provider_message_id')->nullable()->unique();
            $table->enum('status', array_column(WhatsAppDeliveryStatus::cases(), 'value'))->index();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->unsignedInteger('attempt');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['sale_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->unique(['sale_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_deliveries');
    }
};
