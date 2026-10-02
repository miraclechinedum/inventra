<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every WhatsApp message Inventra sends, whatever caused it.
 *
 * This replaces the receipt-specific `whatsapp_deliveries`, whose `sale_id` was NOT NULL — a shape
 * that cannot hold a welcome message (a customer event), a low-stock alert (a product event) or a
 * test send (no business event at all). The subject here is polymorphic instead, so each message
 * points at whatever actually caused it, or at nothing.
 *
 * `idempotency_key` is the load-bearing column. It is UNIQUE, and every automatic send derives it
 * deterministically from the event — `welcome:{customer}`, `post_purchase:{sale}`,
 * `low_stock:{product}:{episode}`, `pickup:{sale}`. Duplicate prevention is therefore the database
 * refusing the second INSERT, not application code reading before writing: a browser retry, a
 * queue retry, a scheduler overlap, two workers racing or a repeated model save all collide on this
 * key and exactly one row survives. Nothing relies on `if (! exists()) create()`.
 *
 * `attempt` + `retry_of_id` preserve history: a retry INSERTs a new row pointing at the failed one,
 * so the failed attempt is never overwritten and the trail stays auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->id();

            // Which automation produced it — or NULL for a test send, which belongs to no automation
            // lifecycle. Restricted on delete: a message's provenance must not vanish.
            $table->foreignId('whatsapp_automation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32)->index();

            // Who it went to. `customer_id` when a customer, `user_id` when a staff member (the
            // low-stock alert and every test send). Exactly one is set, enforced by CHECK below.
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();

            // The business event that caused it: a Sale, a Customer, a Product, or nothing.
            $table->nullableMorphs('subject');

            // Snapshot of the recipient's name at send time, so the log stays truthful if the
            // customer is later renamed — the same reasoning as the sale snapshots.
            $table->string('recipient_name', 255);
            $table->string('destination_phone', 20);
            $table->text('body');

            $table->string('idempotency_key', 191)->unique();
            $table->enum('origin', ['automatic', 'manual', 'test'])->default('automatic');
            $table->enum('status', ['queued', 'sent', 'delivered', 'read', 'failed'])->default('queued')->index();

            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->timestamp('queued_at');
            // The database-backed claim that stops two dispatcher runs sending the same row, using
            // the same conditional-UPDATE pattern the old receipt dispatcher proved out.
            $table->timestamp('dispatch_claimed_at')->nullable();
            // When the message becomes eligible to send. Immediate for most; the pickup reminder
            // sets it into the future, which is how "1 day after" is honoured without a daemon.
            $table->timestamp('send_after')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->unsignedInteger('attempt')->default(1);
            $table->foreignId('retry_of_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'dispatch_claimed_at', 'send_after'], 'whatsapp_messages_dispatch_index');
            $table->index(['type', 'status']);
            $table->index('created_at');
        });

        // A message goes to exactly one recipient: a customer or a staff member, never both and
        // never neither. Without this a row could exist that nothing could actually be sent to.
        DB::statement('ALTER TABLE whatsapp_messages ADD CONSTRAINT whatsapp_messages_one_recipient CHECK ((customer_id IS NULL) <> (user_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
