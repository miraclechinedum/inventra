<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meta template mapping, and the connection each message belongs to.
 *
 * Two facts the audit established drive this:
 *
 *  1. All four automations are business-initiated, so Meta only accepts them as an APPROVED
 *     template — never as free-form `type: text`. An automation therefore needs a template name,
 *     a language and Meta's own status for it, and the editor's body becomes a DRAFT of what will
 *     be submitted rather than what is sent.
 *  2. A message must record which connection sent it, so a webhook arriving for one business's
 *     phone number can never resolve onto another business's message.
 *
 * Additive and nullable throughout: existing rows keep their meaning, and an automation with no
 * mapped template simply cannot send until one is approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_automations', function (Blueprint $table): void {
            // The approved template this automation sends as. Null until mapped.
            $table->string('template_name', 512)->nullable()->after('body');
            $table->string('template_language', 16)->default('en')->after('template_name');

            // Meta's own status, mirrored — never asserted by Inventra. Null means "not yet known";
            // only APPROVED permits a production send.
            $table->string('template_status', 32)->nullable()->after('template_language');
            $table->timestamp('template_synced_at')->nullable()->after('template_status');

            // Which allowlisted variables map to Meta's positional body parameters, in order.
            $table->json('template_variables')->nullable()->after('template_synced_at');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            // The connection that sent it. Restricted on delete: a message must never lose the
            // identity it went out under.
            $table->foreignId('whatsapp_connection_id')->nullable()->after('whatsapp_automation_id')
                ->constrained('whatsapp_connection')->restrictOnDelete();
            // Snapshot of the sending phone number id, so webhook routing can be verified against
            // what was actually used even if the connection is later re-pointed.
            $table->string('sender_phone_number_id', 64)->nullable()->after('provider');
            // The resolved variable values for this message, captured when the event happened.
            // Meta needs ordered positional parameters at send time, and the facts they describe
            // (a sale total, a stock level) may have moved by then — so they are snapshotted here
            // rather than re-derived, exactly as the rendered body already was.
            $table->json('template_values')->nullable()->after('body');
            $table->index('sender_phone_number_id');
        });

        // The four automations keep their Inventra identity; only the template mapping is new, and
        // it is deliberately left empty. Nothing here may imply an approval Meta has not granted.
        DB::table('whatsapp_automations')->update(['template_status' => null]);
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->dropIndex(['sender_phone_number_id']);
            $table->dropConstrainedForeignId('whatsapp_connection_id');
            $table->dropColumn(['sender_phone_number_id', 'template_values']);
        });

        Schema::table('whatsapp_automations', function (Blueprint $table): void {
            $table->dropColumn([
                'template_name', 'template_language', 'template_status',
                'template_synced_at', 'template_variables',
            ]);
        });
    }
};
