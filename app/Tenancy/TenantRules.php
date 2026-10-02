<?php

namespace App\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules confined to the CurrentBusiness.
 *
 * `Rule::exists()` and `Rule::unique()` run through the query builder, which Eloquent global scopes
 * never reach, so a tenant-owned column must name its business explicitly: an id is only valid if it
 * is this business's, and a value is only taken if this business already uses it.
 */
final class TenantRules
{
    /** @param  class-string  $model */
    public static function exists(string $model, string $column = 'id'): Exists
    {
        return Rule::exists($model, $column)->where('business_id', app(CurrentBusiness::class)->id());
    }

    /** @param  class-string  $model */
    public static function unique(string $model, string $column): Unique
    {
        return Rule::unique($model, $column)->where('business_id', app(CurrentBusiness::class)->id());
    }
}
