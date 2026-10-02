<?php

/*
 * The plan catalogue: platform-level system data, keyed by a stable machine key.
 *
 * `inventra:sync-plans` writes these definitions to the `plans` table idempotently, by key. It is
 * a deployment step, never run by a request. Amounts are integer kobo; `price_minor => null` means
 * not yet priced. The values below are placeholders pending commercial decisions — confirm the
 * trial length, prices and limits before launch.
 */
return [
    // The plan every newly provisioned Business starts its trial on.
    'default' => env('INVENTRA_DEFAULT_PLAN', 'standard'),

    // How long a Business keeps full access after its trial or paid period ends.
    'grace_days' => (int) env('INVENTRA_GRACE_DAYS', 7),

    'definitions' => [
        'legacy' => [
            'name' => 'Legacy',
            'is_active' => false,
            'price_minor' => null,
            'billing_interval' => 'month',
            'trial_days' => 0,
            // Grandfathered: every pre-subscription Business keeps uncapped capacity.
            'entitlements' => ['max_managers' => null, 'max_sales_representatives' => null, 'max_products' => null, 'whatsapp_automation' => true],
        ],
        'standard' => [
            'name' => 'Standard',
            'is_active' => true,
            'price_minor' => null,
            'billing_interval' => 'month',
            'trial_days' => 14,
            // The owner is the Administrator and counts against neither role cap.
            'entitlements' => ['max_managers' => 1, 'max_sales_representatives' => 1, 'max_products' => 20, 'whatsapp_automation' => true],
        ],
    ],
];
