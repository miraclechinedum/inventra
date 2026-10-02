<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a WhatsApp message be addressed to the business itself.
 *
 * `whatsapp_messages_one_recipient` was written when every message went to exactly one of two
 * parties: a customer or a staff member. The low-stock alert now goes to the business's own
 * `manager_alert_number` — a number the business configured for itself, belonging to no customer
 * row and no user row — so both foreign keys are legitimately null and the old CHECK rejected the
 * insert outright.
 *
 * The constraint's actual intent is preserved, not weakened: a message must still never exist that
 * nothing can be sent to. That guarantee never really came from the foreign keys — `destination_phone`
 * is what gets dialled, and it is already NOT NULL — so the replacement keeps the part that matters
 * (a customer and a staff member can still never both be set) and drops only the requirement that
 * one of them be set.
 *
 * A NEW migration rather than an edit to 2026_09_17_030000_create_whatsapp_messages_table, which has
 * already run on development: rewriting an applied migration would leave databases reporting it as
 * done while never receiving this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DROP CONSTRAINT, not DROP CHECK: MariaDB rejects the MySQL-only spelling. Guarded so a
        // rerun after a partially-applied batch does not fail on an already-dropped constraint.
        if ($this->checkConstraintExists('whatsapp_messages_one_recipient')) {
            DB::statement('ALTER TABLE whatsapp_messages DROP CONSTRAINT whatsapp_messages_one_recipient');
        }

        // At most one named party. Both null means the business itself is the recipient, and
        // `destination_phone` (NOT NULL) remains the address that is actually dialled.
        DB::statement('ALTER TABLE whatsapp_messages ADD CONSTRAINT whatsapp_messages_one_recipient CHECK (NOT (customer_id IS NOT NULL AND user_id IS NOT NULL))');
    }

    private function checkConstraintExists(string $constraint): bool
    {
        return DB::selectOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_messages'
               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$constraint]
        ) !== null;
    }

    public function down(): void
    {
        // Restores the stricter rule. Any business-addressed rows must be resolved first; this is
        // deliberately left to fail loudly rather than silently deleting real message history.
        DB::statement('ALTER TABLE whatsapp_messages DROP CONSTRAINT whatsapp_messages_one_recipient');
        DB::statement('ALTER TABLE whatsapp_messages ADD CONSTRAINT whatsapp_messages_one_recipient CHECK ((customer_id IS NULL) <> (user_id IS NULL))');
    }
};
