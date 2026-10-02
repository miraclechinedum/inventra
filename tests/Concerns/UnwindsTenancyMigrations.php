<?php

namespace Tests\Concerns;

/**
 * For tests that roll a base migration back: the domain tenancy migrations that later altered or
 * referenced those tables must be unwound first, newest first, and restored afterwards in order.
 */
trait UnwindsTenancyMigrations
{
    /** @var list<string> every domain tenancy migration, in the order it runs */
    private static array $tenancyMigrations = [
        '2026_09_29_040000_enforce_business_ownership_of_users',
        '2026_09_29_050000_add_business_ownership_to_catalog',
        '2026_09_29_060000_enforce_catalog_tenancy',
        '2026_09_29_070000_add_business_ownership_to_parties',
        '2026_09_29_080000_enforce_party_tenancy',
        '2026_09_29_090000_add_business_ownership_to_sales',
        '2026_09_29_100000_enforce_sales_tenancy',
        '2026_09_29_110000_add_business_ownership_to_sale_children',
        '2026_09_29_120000_enforce_sale_children_tenancy',
        '2026_09_29_130000_add_business_ownership_to_purchasing',
        '2026_09_29_140000_enforce_purchasing_tenancy',
        '2026_09_29_150000_add_business_ownership_to_expenses',
        '2026_09_29_160000_enforce_expense_tenancy',
        '2026_09_29_170000_add_business_ownership_to_operational_alerts',
        '2026_09_29_180000_enforce_operational_alert_tenancy',
        '2026_09_29_190000_add_business_ownership_to_audit_logs',
        '2026_09_29_200000_enforce_audit_log_tenancy',
        '2026_09_29_210000_add_business_association_to_security_events',
        '2026_09_29_220000_rename_whatsapp_meta_business_identifier',
        '2026_09_29_221000_add_business_ownership_to_whatsapp',
        '2026_09_29_222000_enforce_whatsapp_tenancy',
        '2026_09_29_223000_add_business_ownership_to_request_tokens',
        '2026_09_29_224000_enforce_request_token_tenancy',
        // Created tenant-owned, with a composite key onto users (business_id, id).
        '2026_09_30_010000_create_whatsapp_onboarding_attempts_table',
        // Business-owned subscriptions: a foreign key onto businesses.
        '2026_09_30_020000_create_subscription_tables',
        // Writes the role and product caps onto the plans created just above.
        '2026_09_30_030000_apply_role_and_product_caps_to_plans',
    ];

    /** Rolls back the tenancy migrations from $from (through $through, else the last), newest first. */
    protected function unwindTenancyFrom(string $from, ?string $through = null): void
    {
        foreach (array_reverse($this->tenancyMigrationsFrom($from, $through)) as $migration) {
            $migration->down();
        }
    }

    /** Re-applies the tenancy migrations from $from (through $through, else the last), oldest first. */
    protected function restoreTenancyFrom(string $from, ?string $through = null): void
    {
        foreach ($this->tenancyMigrationsFrom($from, $through) as $migration) {
            $migration->up();
        }
    }

    /** @return list<object> */
    private function tenancyMigrationsFrom(string $from, ?string $through = null): array
    {
        $index = array_search($from, self::$tenancyMigrations, true);
        $last = $through === null ? count(self::$tenancyMigrations) - 1 : array_search($through, self::$tenancyMigrations, true);

        if ($index === false || $last === false || $last < $index) {
            throw new \InvalidArgumentException("Unknown tenancy migration range {$from}..{$through}.");
        }

        return array_map(
            fn (string $name): object => require database_path("migrations/{$name}.php"),
            array_slice(self::$tenancyMigrations, $index, $last - $index + 1),
        );
    }
}
