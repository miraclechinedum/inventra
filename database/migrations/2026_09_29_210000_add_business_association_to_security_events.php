<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security events gain the Business of the account they concern — and stay nullable, permanently.
 *
 * An event about a known account belongs to that account's Business: the subject if there is one,
 * otherwise the legacy `user_id`, otherwise the acting user. An event with no known account — a
 * failed login for an identifier that matches no one, a reset requested for an unknown address — has
 * no honest owner, and inferring one from the submitted email or phone would attribute an attacker's
 * probing to whichever tenant it named. Those rows stay NULL and are never shown to a tenant; they
 * belong to a future platform security surface.
 *
 * The user keys are SET NULL on delete, so no composite key is possible; the account/actor agreement
 * is verified here and enforced by SecurityEventRecorder for new rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('security_events', 'business_id')) {
            Schema::table('security_events', function (Blueprint $table): void {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete();
                $table->index(['business_id', 'created_at'], 'security_events_business_listing_index');
            });
        }

        foreach (['subject_user_id', 'user_id', 'actor_id'] as $column) {
            DB::statement("UPDATE security_events e JOIN users u ON u.id = e.{$column} SET e.business_id = u.business_id WHERE e.business_id IS NULL");
        }

        foreach (['subject_user_id', 'user_id', 'actor_id'] as $column) {
            $disagreeing = DB::table('security_events as e')->join('users as u', 'u.id', '=', "e.{$column}")
                ->whereColumn('u.business_id', '<>', 'e.business_id')->count();

            if ($disagreeing > 0) {
                throw new RuntimeException("{$disagreeing} security events name accounts of more than one business ({$column}).");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('businesses')->count() > 1) {
            throw new RuntimeException('Refusing to drop security event ownership while more than one business exists.');
        }

        Schema::table('security_events', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->dropIndex('security_events_business_listing_index');
            $table->dropColumn('business_id');
        });
    }
};
