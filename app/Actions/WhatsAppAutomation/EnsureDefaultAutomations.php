<?php

namespace App\Actions\WhatsAppAutomation;

use App\Models\Business;
use App\Models\WhatsAppAutomation;
use Illuminate\Support\Facades\DB;

/**
 * The one place a Business's default automation set comes from.
 *
 * It acts on the Business it is given and creates only what is missing: each row is an insert that
 * the UNIQUE (business_id, key) index turns into a no-op when the key already exists, so repeated
 * or concurrent calls converge on exactly one row per key and never touch an existing row's
 * configuration. Every default starts switched off and with no template mapped.
 */
class EnsureDefaultAutomations
{
    /** @var array<string, array{body: string, delay_hours: int|null}> */
    public const DEFAULTS = [
        WhatsAppAutomation::WELCOME => [
            'body' => 'Hi {{customer_name}} 👋 Welcome to {{business_name}}. We\'re glad to have you — reach out anytime.',
            'delay_hours' => null,
        ],
        WhatsAppAutomation::POST_PURCHASE => [
            'body' => 'Hello {{customer_name}}, thank you for your purchase from {{business_name}}. Your total was {{sale_total}}. We appreciate your patronage.',
            'delay_hours' => null,
        ],
        WhatsAppAutomation::PICKUP_REMINDER => [
            'body' => 'Hi {{customer_name}}, your order at {{business_name}} is ready for pickup. See you soon!',
            'delay_hours' => 24,
        ],
        WhatsAppAutomation::LOW_STOCK => [
            'body' => '⚠ {{product_name}} is low — only {{stock_left}} left (reorder at {{reorder_level}}).',
            'delay_hours' => null,
        ],
    ];

    /** @return int how many defaults were missing and have now been created */
    public function for(Business $business): int
    {
        $now = now();

        return DB::table('whatsapp_automations')->insertOrIgnore(array_map(
            fn (string $key, array $default): array => [
                'business_id' => $business->getKey(),
                'key' => $key,
                'enabled' => false,
                'body' => $default['body'],
                'delay_hours' => $default['delay_hours'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_keys(self::DEFAULTS),
            self::DEFAULTS,
        ));
    }
}
