<?php

use App\Enums\WhatsAppDeliveryOrigin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic receipt delivery is dispatched by the scheduler, never inside the sale-completion
 * transaction. Two columns make that safe:
 *
 *  - `origin` separates a scheduler-owned row from a staff-initiated one. Without it the dispatcher
 *    could not tell an unclaimed automatic row apart from a manual send that is momentarily pending
 *    between its own commit and the provider's reply, and would send that manual receipt a second
 *    time.
 *  - `dispatch_claimed_at` is the database-backed claim. The dispatcher takes a row with a
 *    conditional UPDATE that only matches while the column is still NULL, so a second scheduler
 *    tick — or an overlapping run — can never claim the same row twice.
 *
 * Every existing row predates automatic dispatch and is therefore `manual`, which the column
 * default backfills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_deliveries', function (Blueprint $table) {
            $table->enum('origin', array_column(WhatsAppDeliveryOrigin::cases(), 'value'))
                ->default(WhatsAppDeliveryOrigin::Manual->value)
                ->after('request_id');
            $table->timestamp('dispatch_claimed_at')->nullable()->after('requested_at');
            $table->index(['origin', 'status', 'dispatch_claimed_at'], 'whatsapp_deliveries_dispatch_queue_index');
        });

        // A manual send is dispatched inline by the request that created it and never enters the
        // scheduler queue, so a claim on a manual row would mean the dispatcher had overreached.
        DB::statement("ALTER TABLE whatsapp_deliveries ADD CONSTRAINT whatsapp_deliveries_claim_requires_automatic CHECK (origin = 'automatic' OR dispatch_claimed_at IS NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE whatsapp_deliveries DROP CONSTRAINT whatsapp_deliveries_claim_requires_automatic');

        Schema::table('whatsapp_deliveries', function (Blueprint $table) {
            $table->dropIndex('whatsapp_deliveries_dispatch_queue_index');
            $table->dropColumn(['origin', 'dispatch_claimed_at']);
        });
    }
};
