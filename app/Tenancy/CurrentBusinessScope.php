<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Confines a tenant-owned model to the CurrentBusiness.
 *
 * Fails closed: with no Business in context the query throws rather than widening to every tenant.
 * It never reads a Business from the request, a default, or "the first" row. Work that genuinely
 * needs another tenant's rows either runs inside CurrentBusiness::run() or opts out by name through
 * acrossBusinesses(), which is how such a path stays visible in review.
 */
class CurrentBusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('business_id'), app(CurrentBusiness::class)->id());
    }
}
