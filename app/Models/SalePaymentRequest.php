<?php

namespace App\Models;

use App\Models\Concerns\OwnedByIssuingOperator;
use Illuminate\Database\Eloquent\Model;

class SalePaymentRequest extends Model
{
    use OwnedByIssuingOperator;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
