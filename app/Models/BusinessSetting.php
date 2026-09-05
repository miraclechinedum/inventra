<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single business identity record. Writes go through App\Actions\Settings\UpdateBusinessSettings;
 * this model is fully guarded so no request payload can reach a column by mass assignment.
 */
class BusinessSetting extends Model
{
    public const SINGLETON_KEY = 'business';

    /** Fields an Administrator may change. Everything else is out of reach by construction. */
    public const EDITABLE = [
        'business_name', 'legal_name', 'business_phone', 'business_email',
        'business_address', 'city', 'state', 'receipt_footer',
    ];

    protected $guarded = ['*'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
