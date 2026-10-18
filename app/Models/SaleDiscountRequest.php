<?php

namespace App\Models;

use App\Enums\DiscountRequestStatus;
use App\Models\Concerns\ScopedToCurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SaleDiscountRequest extends Model
{
    use ScopedToCurrentBusiness;

    protected $guarded = ['*'];

    /** How long a request may wait for a decision before it expires. */
    public const PENDING_LIFETIME_HOURS = 24;

    /** Set only while a decision, cancellation or expiry is being recorded. */
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

    /** Set on a pre-sale request; null on a post-hoc one, where `sale` is set instead. */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(SaleDraft::class, 'sale_draft_id');
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
     * Pending requests still open to a decision: not yet decided and not past their lifetime.
     *
     * An expired request may still read `pending` until the scheduler records its expiry, so every
     * "is something awaiting a decision" question asks this rather than reading the status alone.
     */
    public function scopeAwaitingDecision(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), DiscountRequestStatus::Pending->value)
            ->where($this->qualifyColumn('requested_at'), '>', now()->subHours(self::PENDING_LIFETIME_HOURS));
    }

    /** When a pending request stops being decidable. */
    public function pendingExpiresAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->requested_at)->addHours(self::PENDING_LIFETIME_HOURS);
    }

    /** A pending request no one decided in time. Derived, so it holds before the scheduler runs. */
    public function isPendingExpired(): bool
    {
        return $this->status === DiscountRequestStatus::Pending && ! $this->pendingExpiresAt()->isFuture();
    }

    /**
     * When a pre-sale approval stops being spendable: the end of the business day it was granted,
     * in the business timezone. Nothing about the Sale being recorded moves it, so backdating a
     * Sale cannot revive an approval.
     */
    public function approvalExpiresAt(): ?CarbonImmutable
    {
        if ($this->sale_draft_id === null || $this->decided_at === null) {
            return null;
        }

        $timezone = config('business.timezone');

        return CarbonImmutable::parse($this->decided_at)->timezone($timezone)->endOfDay();
    }

    /** A pre-sale approval no Sale spent before its business day ended. */
    public function isApprovalExpired(): bool
    {
        $expiresAt = $this->approvalExpiresAt();

        return $this->status === DiscountRequestStatus::Approved && $expiresAt !== null && ! $expiresAt->isFuture();
    }

    /**
     * Writes one permitted state change out of pending (decided, cancelled or expired), or the
     * expiry of an unspent approval. Wrapped in a flag so the `updating` guard above blocks every
     * other route to mutating a request.
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
            'expired_at' => 'datetime',
            'self_approved' => 'boolean',
        ];
    }
}
