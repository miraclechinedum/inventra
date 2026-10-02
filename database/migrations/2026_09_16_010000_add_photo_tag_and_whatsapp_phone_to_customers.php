<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three fields the Customer screens need and the table did not have.
 *
 * Purely additive: every column is nullable with no default and no backfill, so existing rows stay
 * exactly as they are and every existing query keeps its meaning. Nothing is renamed, retyped or
 * repurposed — in particular `notes` is left alone, because a free-text note is not a tag.
 *
 *   photo_path      relative path to the customer's photograph on the private image disk. Only the
 *                   reference lives here; the bytes never do.
 *   tag             the short descriptor the Customer screens show — a vehicle, usually. Separate
 *                   from `notes`, which stays a free-text field for anything else.
 *   whatsapp_phone  an alternate WhatsApp destination. NULL is meaningful and is the norm: it says
 *                   "WhatsApp reaches this customer on their ordinary `phone`". A value is stored
 *                   only when the number genuinely differs, so the same number is never duplicated
 *                   across two columns where they could drift apart.
 *
 * This column is a destination, not a permission. Whether automated messages may be sent at all
 * remains `whatsapp_opt_in`, and that rule is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('email');
            $table->string('tag', 120)->nullable()->after('photo_path');
            // Held next to `phone` so the relationship between the two is obvious in the schema.
            $table->string('whatsapp_phone', 32)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['photo_path', 'tag', 'whatsapp_phone']);
        });
    }
};
