<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class ExpenseRequest extends Model
{
    use MassPrunable;

    protected $guarded = ['*'];

    public function prunable(): Builder
    {
        return self::query()->whereNull('used_at')->whereNull('expense_id')->where('expires_at', '<', now());
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
