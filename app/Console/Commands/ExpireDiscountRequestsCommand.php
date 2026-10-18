<?php

namespace App\Console\Commands;

use App\Actions\Sale\ExpireSaleDiscountRequest;
use App\Enums\DiscountRequestStatus;
use App\Models\Business;
use App\Models\SaleDiscountRequest;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Records the expiry of discount requests that have outlived their time, for every Business, each
 * inside its own context.
 *
 * Bookkeeping, not the gate: a stale request is already refused wherever it would be used. This
 * clears it from the approval queue and releases the guard on its Sale or cart. Each candidate is
 * re-checked under its own row lock by ExpireSaleDiscountRequest, so a request decided or spent in
 * the meantime is left alone.
 */
class ExpireDiscountRequestsCommand extends Command
{
    protected $signature = 'inventra:expire-discount-requests';

    protected $description = 'Record the expiry of discount requests and unspent pre-sale approvals that are past their time';

    public function handle(ExpireSaleDiscountRequest $expire, CurrentBusiness $tenancy): int
    {
        $expired = 0;
        $failed = 0;

        foreach (Business::query()->orderBy('id')->cursor() as $business) {
            try {
                $expired += $tenancy->run($business, function () use ($expire): int {
                    $count = 0;

                    foreach ($this->candidates()->cursor() as $request) {
                        $count += $expire->execute($request) ? 1 : 0;
                    }

                    return $count;
                });
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        // A count only: no amounts, names or reasons reach scheduler output or cron mail.
        $this->line('Discount requests expired: '.$expired);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function candidates()
    {
        // An approval lasts until the end of the business day it was granted, so any approval
        // granted before today began, in the business timezone, has expired.
        $todayBegan = CarbonImmutable::now(config('business.timezone'))->startOfDay()->utc();

        return SaleDiscountRequest::query()
            ->where(fn ($query) => $query
                ->where(fn ($pending) => $pending
                    ->where('status', DiscountRequestStatus::Pending->value)
                    ->where('requested_at', '<=', now()->subHours(SaleDiscountRequest::PENDING_LIFETIME_HOURS)))
                ->orWhere(fn ($approved) => $approved
                    ->where('status', DiscountRequestStatus::Approved->value)
                    ->whereNotNull('sale_draft_id')
                    ->where('decided_at', '<', $todayBegan)
                    ->whereHas('draft', fn ($draft) => $draft->whereNull('consumed_by_sale_id'))))
            ->orderBy('id');
    }
}
