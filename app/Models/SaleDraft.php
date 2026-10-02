<?php

namespace App\Models;

use App\Enums\DiscountRequestStatus;
use App\Models\Concerns\ScopedToCurrentBusiness;
use App\Support\CartSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * A cart awaiting a discount decision. Explicitly not a Sale: it holds no stock, writes no movement
 * and takes no payment. It exists only so an Admin has something concrete to approve before the
 * money is taken, and is consumed once the real Sale is recorded.
 */
class SaleDraft extends Model
{
    use ScopedToCurrentBusiness;

    protected $guarded = ['*'];

    /** Set only while CreateSale marks a draft spent. */
    private bool $consumptionTransition = false;

    protected static function booted(): void
    {
        static::creating(function (self $draft): void {
            if (blank($draft->public_id)) {
                $draft->public_id = (string) Str::ulid();
            }
        });

        // The whole point of the snapshot is that it does not move under an approval. The only
        // permitted change is recording which Sale finally spent it.
        static::updating(function (self $draft): void {
            if ($draft->consumptionTransition) {
                return;
            }

            throw new LogicException('A sale draft is immutable once created; only consumption may be recorded.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Null on a walk-in draft, exactly as on the Sale it will become. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function discountRequests(): HasMany
    {
        return $this->hasMany(SaleDiscountRequest::class);
    }

    /** The one decision still outstanding on this cart, if any. */
    public function pendingDiscountRequest(): ?SaleDiscountRequest
    {
        return $this->discountRequests()
            ->where('status', DiscountRequestStatus::Pending->value)
            ->latest('id')
            ->first();
    }

    /** The most recent decision made on this cart, whatever it was. */
    public function latestDecidedRequest(): ?SaleDiscountRequest
    {
        return $this->discountRequests()
            ->whereIn('status', [DiscountRequestStatus::Approved->value, DiscountRequestStatus::Declined->value])
            ->latest('decided_at')
            ->latest('id')
            ->first();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_by_sale_id !== null;
    }

    /**
     * Whether the sale being recorded is still the sale that was approved — the same goods, for the
     * same buyer, on the same trading day. Any of the three differing means the approval does not
     * apply and a fresh request is required.
     */
    public function matches(array $lines, bool $isWalkIn, int|string|null $customerId, string $saleDate): bool
    {
        return hash_equals(
            (string) $this->cart_hash,
            CartSnapshot::hash($lines, $isWalkIn, $customerId, $saleDate),
        );
    }

    public function markConsumed(Sale $sale): void
    {
        $this->consumptionTransition = true;

        try {
            $this->consumed_by_sale_id = $sale->id;
            $this->consumed_at = now();
            $this->save();
        } finally {
            $this->consumptionTransition = false;
        }
    }

    protected function casts(): array
    {
        return [
            'cart_snapshot' => 'array',
            'is_walk_in' => 'boolean',
            'sale_date' => 'date',
            'subtotal_snapshot' => 'decimal:2',
            'consumed_at' => 'datetime',
        ];
    }
}
