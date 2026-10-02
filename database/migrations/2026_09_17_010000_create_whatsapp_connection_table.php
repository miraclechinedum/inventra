<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The business WhatsApp connection. Exactly one row, enforced the same way business_settings does
 * it: a UNIQUE singleton_key pinned by a CHECK constraint, so a second connection cannot be created
 * by any code path, race or console.
 *
 * What is deliberately NOT here: the access token, app secret and verify token. Those stay in the
 * environment where they already live. This table holds only the non-secret facts a page may
 * render — which number, whether it is verified, and when — so nothing that leaks from a Blade
 * view, an audit row or a log line could ever authenticate as the business.
 *
 * The pending verification lives here too, but only as a HASH. `verification_code_hash` is a bcrypt
 * digest, never the six digits; a database dump therefore cannot be replayed into a connection.
 */
return new class extends Migration
{
    private const SINGLETON_KEY = 'whatsapp';

    public function up(): void
    {
        Schema::create('whatsapp_connection', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 16)->unique();

            // The number being connected, stored in the canonical +234XXXXXXXXXX form the rest of
            // Inventra normalises to, so it can be compared without re-parsing.
            $table->string('phone_number', 20)->nullable();
            $table->string('provider', 32)->nullable();
            // Meta's Phone Number ID for the connected number. An identifier, not a credential:
            // it is useless without the access token, which is not stored here.
            $table->string('provider_account_id', 64)->nullable();

            $table->enum('status', ['disconnected', 'pending_verification', 'connected'])
                ->default('disconnected');

            // The pending verification. Hash only — see the class comment.
            $table->string('verification_code_hash')->nullable();
            $table->string('verification_phone', 20)->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->unsignedSmallInteger('verification_attempts')->default(0);
            $table->timestamp('verification_last_sent_at')->nullable();
            $table->unsignedSmallInteger('verification_sends')->default(0);

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();

            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE whatsapp_connection ADD CONSTRAINT whatsapp_connection_singleton_valid CHECK (singleton_key = '".self::SINGLETON_KEY."')");

        // A connection that claims to be connected must say which number, and must have been
        // verified. Without this the UI could show "connected" against a NULL number.
        DB::statement("ALTER TABLE whatsapp_connection ADD CONSTRAINT whatsapp_connection_connected_is_complete CHECK (status <> 'connected' OR (phone_number IS NOT NULL AND verified_at IS NOT NULL))");

        DB::table('whatsapp_connection')->insert([
            'singleton_key' => self::SINGLETON_KEY,
            'status' => 'disconnected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connection');
    }
};
