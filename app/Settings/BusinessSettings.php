<?php

namespace App\Settings;

use App\Models\BusinessSetting;
use RuntimeException;

/**
 * The one place the application reads business identity from. It resolves the authoritative
 * singleton, memoises it for the current request so repeated reads on one page cost one query,
 * and never creates a row: a missing record is an incomplete installation, not something a GET
 * request should silently repair.
 */
class BusinessSettings
{
    private ?BusinessSetting $cached = null;

    public function current(): BusinessSetting
    {
        return $this->cached ??= BusinessSetting::query()
            ->where('singleton_key', BusinessSetting::SINGLETON_KEY)
            ->first() ?? throw new RuntimeException(
                'Business settings are not installed. Run `php artisan migrate` to create the business_settings record.'
            );
    }

    /** Drops the request-scoped memo so a write is visible to later reads in the same request. */
    public function forget(): void
    {
        $this->cached = null;
    }

    /** Currency is fixed: no table snapshots a currency, so history cannot be reinterpreted. */
    public function currencyCode(): string
    {
        return 'NGN';
    }

    public function currencySymbol(): string
    {
        return '₦';
    }

    /** Timezone is fixed at deploy time; making it editable would move historical business dates. */
    public function timezone(): string
    {
        return (string) config('business.timezone');
    }
}
