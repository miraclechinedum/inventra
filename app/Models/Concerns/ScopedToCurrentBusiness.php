<?php

namespace App\Models\Concerns;

use App\Tenancy\CurrentBusinessScope;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * A tenant-owned model whose every Eloquent query is confined to the CurrentBusiness.
 *
 * Adopted only by tables whose `business_id` is NOT NULL and fully backfilled. Implicit route model
 * binding goes through the same scope, so another tenant's id or public_id resolves to a 404 rather
 * than to a record a policy must then reject. Ownership is immutable: a row can never be moved to
 * another Business.
 *
 * The scope does not reach the query builder. `DB::table()`, joins onto these tables and validation
 * rules (`Rule::exists`, `Rule::unique`) must name the business explicitly.
 */
trait ScopedToCurrentBusiness
{
    use BelongsToBusiness;

    public static function bootScopedToCurrentBusiness(): void
    {
        static::addGlobalScope(new CurrentBusinessScope);

        static::updating(function (self $model): void {
            if ($model->isDirty('business_id')) {
                throw new LogicException('A record cannot be moved to another business.');
            }
        });
    }

    /**
     * The deliberate, privileged opt-out for work that must see every tenant — maintenance sweeps
     * that reconcile derived state. Never use it to answer a tenant request.
     */
    public static function acrossBusinesses(): Builder
    {
        return static::query()->withoutGlobalScope(CurrentBusinessScope::class);
    }
}
