<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One server-issued attempt to connect a Business's WhatsApp account through Meta Embedded Signup.
 *
 * The browser holds only a random state value; this row holds its SHA-256 and is bound to the
 * Business, the initiating Administrator and a hash of their session. It is short-lived and
 * consumed on first presentation whatever the outcome, so a state can neither be replayed nor used
 * by another operator, session or Business. The outcome is recorded as a failure code or the
 * resulting connection — never the authorization code, the token or the PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_onboarding_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('state_hash', 64)->unique();
            $table->char('session_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->foreignId('whatsapp_connection_id')->nullable()->constrained('whatsapp_connection')->nullOnDelete();
            $table->timestamps();

            // The initiating operator must belong to the attempt's Business.
            $table->foreign(['business_id', 'user_id'], 'wa_onboarding_user_tenant_fk')
                ->references(['business_id', 'id'])->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_onboarding_attempts');
    }
};
