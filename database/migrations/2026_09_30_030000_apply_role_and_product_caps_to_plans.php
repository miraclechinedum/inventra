<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the single staff cap with separate role and product caps.
 *
 *  - `standard` — the plan every new Business starts on — allows one Manager, one Sales
 *    Representative and twenty products. The Administrator is the provisioned owner and counts
 *    against neither role.
 *  - `legacy` — the grant every pre-subscription Business holds — stays uncapped: NULL is no limit.
 *
 * Plans are updated by stable key, never by id. Nothing about any Business or its subscription
 * changes; only what the two system plans grant.
 */
return new class extends Migration
{
    private const CAPS = [
        'legacy' => ['max_managers' => null, 'max_sales_representatives' => null, 'max_products' => null, 'whatsapp_automation' => true],
        'standard' => ['max_managers' => 1, 'max_sales_representatives' => 1, 'max_products' => 20, 'whatsapp_automation' => true],
    ];

    private const PREVIOUS = ['max_staff' => null, 'whatsapp_automation' => true];

    public function up(): void
    {
        foreach (self::CAPS as $key => $entitlements) {
            $updated = DB::table('plans')->where('key', $key)
                ->update(['entitlements' => json_encode($entitlements), 'updated_at' => now()]);

            if ($updated !== 1) {
                throw new RuntimeException("The {$key} plan is missing; the subscription tables migration must run first.");
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::CAPS) as $key) {
            $current = json_decode((string) DB::table('plans')->where('key', $key)->value('entitlements'), true);
            $expected = self::CAPS[$key];

            // MySQL's JSON type stores object keys in its own order, so the comparison ignores order.
            if (is_array($current)) {
                ksort($current);
            }
            ksort($expected);

            // Refuses to overwrite a plan someone has since re-synchronised to other values.
            if ($current !== $expected) {
                throw new RuntimeException("The {$key} plan no longer holds the values this migration wrote; restore it deliberately.");
            }

            DB::table('plans')->where('key', $key)->update(['entitlements' => json_encode(self::PREVIOUS), 'updated_at' => now()]);
        }
    }
};
