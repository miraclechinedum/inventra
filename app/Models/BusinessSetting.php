<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Business's profile: exactly one per Business, enforced by the UNIQUE `business_id`. Writes go
 * through App\Actions\Settings\UpdateBusinessSettings; this model is fully guarded so no request
 * payload can reach a column — `business_id` included — by mass assignment.
 */
class BusinessSetting extends Model
{
    use BelongsToBusiness;

    /**
     * Fields the Business profile screen may change. Everything else is out of reach by
     * construction.
     *
     * `business_email`, `city` and `state` are deliberately ABSENT. They remain real columns
     * holding real data, and nothing here deletes or blanks them — they were removed from the
     * screen, not from the domain. Leaving them on this list would mean every save submitted them
     * as absent and silently cleared values the business had entered, which is exactly the outcome
     * this list now prevents. Any future screen that edits them must name them explicitly.
     */
    public const EDITABLE = [
        'business_name', 'business_phone', 'business_address', 'receipt_footer',
        'business_type', 'currency', 'tax_number', 'manager_alert_number',
    ];

    /**
     * Columns that exist and hold data but no longer appear on any screen.
     *
     * Named so the intent is documented and testable: these are preserved, not retired.
     *
     * @var list<string>
     */
    public const PRESERVED_NOT_EDITABLE = ['business_email', 'city', 'state'];

    /**
     * Business types offered by the profile screen. Profile metadata only — nothing in the domain
     * branches on this, and `Other` means the list never has to be exhaustive.
     *
     * @var list<string>
     */
    public const TYPES = [
        'Auto parts & spares', 'Building materials', 'Electronics & phones',
        'Fashion & clothing', 'Food & groceries', 'Health & pharmacy',
        'Home & furniture', 'Wholesale & distribution', 'Other',
    ];

    /**
     * Currencies the profile may be set to.
     *
     * Only NGN for now, and deliberately so: every money view renders a hard-coded naira symbol,
     * so offering USD here would imply a multi-currency system that does not exist. The value is
     * stored as an ISO-4217 code, so adding to this list is all a future currency needs.
     *
     * @var array<string, string>
     */
    public const CURRENCIES = [
        'NGN' => 'Nigerian Naira (NGN)',
    ];

    protected $guarded = ['*'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
