<?php

namespace App\Models\Concerns;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared shape for a model owned by a Business through its own `business_id` column.
 *
 * Explicit by design: there is no global scope and no automatic stamping from request context.
 * Callers name the business they mean — `Product::query()->forBusiness($business)` — so a queued
 * job or console command can never silently inherit whichever tenant a previous request left behind.
 * A global scope belongs here only once every table that adopts the trait carries a populated
 * `business_id`, and it must fail closed when no business is in context rather than fall back.
 */
trait BelongsToBusiness
{
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeForBusiness(Builder $query, Business|int $business): void
    {
        $query->where($this->qualifyColumn('business_id'), $business instanceof Business ? $business->getKey() : $business);
    }

    public function belongsToBusiness(Business|int $business): bool
    {
        $id = $business instanceof Business ? $business->getKey() : $business;

        return $this->business_id !== null && (int) $this->business_id === (int) $id;
    }
}
