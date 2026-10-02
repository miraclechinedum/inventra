<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per automation. The four are fixed by the product, not user-creatable, so `key` is a
 * UNIQUE enum-like column and the rows are seeded here: the page always has exactly the four it
 * renders, and a missing row can never make an automation silently unavailable.
 *
 * `body` holds the template text with `{{token}}` placeholders. It is data, never code: rendering
 * is a strict allowlisted string substitution (see WhatsAppTemplateRenderer), so nothing stored
 * here can be evaluated as Blade, PHP or HTML.
 *
 * `enabled` is the automation switch and `template_active` is what the Message templates card
 * reports. They are the same fact viewed twice — the card's Active/Inactive follows `enabled` — so
 * only `enabled` is stored and the card derives its badge from it. No second source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_automations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 32)->unique();
            $table->boolean('enabled')->default(false);
            $table->text('body');
            // Only the pickup reminder uses this; the others leave it NULL. Hours, so the selector
            // can offer same-day options without a second unit column.
            $table->unsignedSmallInteger('delay_hours')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Recipients for the low-stock alert. A join table rather than a JSON column so a
        // deactivated staff member can be resolved through a real foreign key, and so the same
        // user can never be added twice.
        Schema::create('whatsapp_automation_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('whatsapp_automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            // Named explicitly: the generated name would exceed MySQL's 64-character identifier limit.
            $table->unique(['whatsapp_automation_id', 'user_id'], 'wa_automation_recipient_unique');
        });

        $now = now();
        $rows = [
            [
                'key' => 'welcome',
                'enabled' => false,
                'body' => 'Hi {{customer_name}} 👋 Welcome to {{business_name}}. We\'re glad to have you — reach out anytime.',
                'delay_hours' => null,
            ],
            [
                'key' => 'post_purchase',
                'enabled' => false,
                'body' => 'Hello {{customer_name}}, thank you for your purchase from {{business_name}}. Your total was {{sale_total}}. We appreciate your patronage.',
                'delay_hours' => null,
            ],
            [
                'key' => 'pickup_reminder',
                'enabled' => false,
                'body' => 'Hi {{customer_name}}, your order at {{business_name}} is ready for pickup. See you soon!',
                'delay_hours' => 24,
            ],
            [
                'key' => 'low_stock',
                'enabled' => false,
                'body' => '⚠ {{product_name}} is low — only {{stock_left}} left (reorder at {{reorder_level}}).',
                'delay_hours' => null,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('whatsapp_automations')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_automation_recipients');
        Schema::dropIfExists('whatsapp_automations');
    }
};
