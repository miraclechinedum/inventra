<?php

namespace App\Models;

use App\Enums\DiscountRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SaleDiscountRequest extends Model
{
    protected $guarded = ['*'];

    /** Set only by DecideSaleDiscount while it records a decision. */
    private bool $decisionTransition = false;

    protected static function booted(): void
    {
        static::updating(function (SaleDiscountRequest $request): void {
            if (! $request->decisionTransition) {
                throw new LogicException('A discount request may only change through a recorded decision.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Discount request history cannot be deleted.'));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Writes the one permitted state change: pending to approved or declined. Wrapped in a flag so
     * the `updating` guard above blocks every other route to mutating a request.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordDecision(array $attributes): void
    {
        $this->decisionTransition = true;

        try {
            foreach ($attributes as $key => $value) {
                $this->$key = $value;
            }

            $this->save();
        } finally {
            $this->decisionTransition = false;
        }
    }

    protected function casts(): array
    {
        return [
            'status' => DiscountRequestStatus::class,
            'requested_amount' => 'decimal:2',
            'discount_before' => 'decimal:2',
            'discount_after' => 'decimal:2',
            'total_before' => 'decimal:2',
            'total_after' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'refundable_credit_after' => 'decimal:2',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
