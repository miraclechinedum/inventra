<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The real Meta connection identity.
 *
 * Embedded Signup returns the customer's own WABA ID, business phone number ID and an exchangeable
 * code which the server swaps for a customer-scoped business token. Those are what let Inventra
 * send AS that business, and none of them existed here: the table previously stored the operator's
 * typed number plus the application's own env Phone Number ID, which meant every message left from
 * the installation's number no matter which number the page claimed was connected.
 *
 * Additive only. Every column is nullable, so the existing row keeps its current meaning until a
 * genuine Embedded Signup completes.
 *
 * `singleton_key` is deliberately NOT dropped here. Inventra has no business/tenant entity — there
 * is one enforced `business_settings` row and no `tenant_id` anywhere — so inventing one would be
 * the unrelated SaaS rewrite the brief forbids. Instead the UNIQUE constraint moves from a literal
 * to `waba_id`, which is the real ownership key: one row per connected WhatsApp Business Account.
 * A future business entity adds `business_id` beside it without reshaping anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each step checks the live schema first. MySQL and MariaDB commit DDL outside a
        // transaction, so a statement failing partway through leaves everything before it applied;
        // this migration must therefore be able to resume from whatever it managed last time.
        Schema::table('whatsapp_connection', function (Blueprint $table): void {
            // Identity returned by Embedded Signup and re-verified server-side against the token.
            if (! Schema::hasColumn('whatsapp_connection', 'waba_id')) {
                $table->string('waba_id', 64)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('whatsapp_connection', 'phone_number_id')) {
                $table->string('phone_number_id', 64)->nullable()->after('waba_id');
            }
            if (! Schema::hasColumn('whatsapp_connection', 'display_phone_number')) {
                $table->string('display_phone_number', 32)->nullable()->after('phone_number_id');
            }
            if (! Schema::hasColumn('whatsapp_connection', 'verified_name')) {
                $table->string('verified_name', 150)->nullable()->after('display_phone_number');
            }
            if (! Schema::hasColumn('whatsapp_connection', 'business_id')) {
                $table->string('business_id', 64)->nullable()->after('verified_name');
            }

            // The customer-scoped business token. Encrypted by the model's `encrypted` cast, hidden
            // from serialisation, and never rendered, logged or audited.
            if (! Schema::hasColumn('whatsapp_connection', 'access_token')) {
                $table->text('access_token')->nullable()->after('business_id');
            }
            if (! Schema::hasColumn('whatsapp_connection', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('access_token');
            }

            // Why a connection needs attention, when Meta stops accepting it.
            if (! Schema::hasColumn('whatsapp_connection', 'failure_reason')) {
                $table->string('failure_reason', 500)->nullable()->after('disconnected_at');
            }
        });

        // One connection per WhatsApp Business Account. This is the ownership key that replaces
        // the singleton assumption without requiring a tenant model.
        if (! $this->indexExists('whatsapp_connection_waba_id_unique')) {
            Schema::table('whatsapp_connection', function (Blueprint $table): void {
                $table->unique('waba_id');
            });
        }

        if (! $this->indexExists('whatsapp_connection_phone_number_id_index')) {
            Schema::table('whatsapp_connection', function (Blueprint $table): void {
                $table->index('phone_number_id');
            });
        }

        // The singleton CHECK is replaced rather than dropped: it kept `singleton_key` pinned to one
        // literal, which is what made a second connection impossible. The column stays (existing
        // code reads it) but no longer constrains how many rows may exist.
        if ($this->checkConstraintExists('whatsapp_connection_singleton_valid')) {
            // DROP CONSTRAINT, not DROP CHECK: MariaDB rejects the MySQL-only spelling.
            DB::statement('ALTER TABLE whatsapp_connection DROP CONSTRAINT whatsapp_connection_singleton_valid');
        }

        // `singleton_key` must stop being UNIQUE for a second connection to exist at all.
        if ($this->indexExists('whatsapp_connection_singleton_key_unique')) {
            Schema::table('whatsapp_connection', function (Blueprint $table): void {
                $table->dropUnique('whatsapp_connection_singleton_key_unique');
            });
        }

        // The plain index replaces the UNIQUE one. Guarded separately: dropping the unique index
        // and adding this one are two statements, so a run can end between them.
        if (! $this->indexExists('whatsapp_connection_singleton_key_index')) {
            Schema::table('whatsapp_connection', function (Blueprint $table): void {
                $table->index('singleton_key');
            });
        }

        // A connection may only claim to be connected once Meta has actually supplied the identity
        // it would send with. This is the database refusing the false-connected state the audit
        // found — no code path can mark a row connected without a WABA and a phone number id.
        if ($this->checkConstraintExists('whatsapp_connection_connected_is_complete')) {
            DB::statement('ALTER TABLE whatsapp_connection DROP CONSTRAINT whatsapp_connection_connected_is_complete');
        }

        DB::statement("ALTER TABLE whatsapp_connection ADD CONSTRAINT whatsapp_connection_connected_is_complete CHECK (status <> 'connected' OR (waba_id IS NOT NULL AND phone_number_id IS NOT NULL AND access_token IS NOT NULL AND verified_at IS NOT NULL))");
    }

    /**
     * Whether a named index exists on the table. `Schema::hasIndex()` is not available on every
     * supported driver here, so this reads information_schema directly.
     */
    private function indexExists(string $index): bool
    {
        return DB::selectOne(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_connection'
               AND INDEX_NAME = ?",
            [$index]
        ) !== null;
    }

    private function checkConstraintExists(string $constraint): bool
    {
        return DB::selectOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_connection'
               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$constraint]
        ) !== null;
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE whatsapp_connection DROP CONSTRAINT whatsapp_connection_connected_is_complete');

        Schema::table('whatsapp_connection', function (Blueprint $table): void {
            $table->dropUnique(['waba_id']);
            $table->dropIndex(['phone_number_id']);
            $table->dropColumn([
                'waba_id', 'phone_number_id', 'display_phone_number', 'verified_name',
                'business_id', 'access_token', 'token_expires_at', 'failure_reason',
            ]);
        });

        DB::statement("ALTER TABLE whatsapp_connection ADD CONSTRAINT whatsapp_connection_connected_is_complete CHECK (status <> 'connected' OR (phone_number IS NOT NULL AND verified_at IS NOT NULL))");
    }
};
