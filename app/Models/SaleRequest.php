<?php

namespace App\Models;

use App\Models\Concerns\OwnedByIssuingOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One Record Sale submission: the hashed token a Record Sale page was issued, and, once spent, the
 * Sale it produced. A used row is evidence and is never pruned.
 */
class SaleRequest extends Model
{
    use MassPrunable, OwnedByIssuingOperator;

    /** How long an unused Record Sale page stays submittable. A counter page is often left open. */
    public const LIFETIME_HOURS = 12;

    protected $guarded = ['*'];

    public function prunable(): Builder
    {
        return self::query()->whereNull('used_at')->whereNull('sale_id')->where('expires_at', '<', now());
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
