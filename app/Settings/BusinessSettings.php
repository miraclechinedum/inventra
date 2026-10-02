<?php

namespace App\Settings;

use App\Models\Business;
use App\Models\BusinessSetting;
use App\Tenancy\CurrentBusiness;
use RuntimeException;

/**
 * The one place the application reads business identity from.
 *
 * Every read names its Business: `current()` for the CurrentBusiness of a tenant request, `for()`
 * for a Business a caller resolved explicitly. There is no "the settings row" lookup any more. Reads
 * are memoised per Business for the life of the scoped instance and never create a row: a Business
 * without settings is an incomplete tenant, not something a GET request should silently repair.
 */
class BusinessSettings
{
    /** @var array<int, BusinessSetting> */
    private array $cached = [];

    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    /** Settings for the Business the current request acts for. Fails closed without one. */
    public function current(): BusinessSetting
    {
        return $this->for($this->currentBusiness->get());
    }

    public function for(Business|int $business): BusinessSetting
    {
        $id = (int) ($business instanceof Business ? $business->getKey() : $business);

        return $this->cached[$id] ??= BusinessSetting::query()
            ->where('business_id', $id)
            ->first() ?? throw new RuntimeException(
                "Business {$id} has no business settings record. The installation or tenant provisioning is incomplete."
            );
    }

    /** Drops the memo so a write is visible to later reads in the same request. */
    public function forget(): void
    {
        $this->cached = [];
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
