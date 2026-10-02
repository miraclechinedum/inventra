<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Concerns\ScopedToCurrentBusiness;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use LogicException;

class Sale extends Model
{
    /** Identity snapshots used when a Sale has no registered customer. */
    public const WALK_IN_NAME = 'Walk-in customer';

    public const WALK_IN_CODE = 'WALK-IN';

    /** @use HasFactory<SaleFactory> */
    use HasFactory, ScopedToCurrentBusiness;

    private bool $paymentAggregateTransition = false;

    private bool $discountApprovalTransition = false;

    private bool $correctionTransition = false;

    private bool $pickupTransition = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // The public identifier is the server's to assign, never the client's. `$guarded = ['*']`
        // already blocks mass assignment; this only guarantees every Sale has one however it was
        // created.
        static::creating(function (Sale $sale): void {
            if (blank($sale->public_id)) {
                $sale->public_id = (string) Str::ulid();
            }
        });

        static::updating(function (Sale $sale): void {
            // Once a receipt has been printed or a link shared, that URL must keep resolving. The
            // identifier is fixed at creation, and is deliberately absent from every `$allowed`
            // list below so no transition can carry it.
            if ($sale->isDirty('public_id')) {
                throw new LogicException('A sale public identifier is immutable once assigned.');
            }

            $allowed = ['status', 'voided_by', 'voided_at', 'void_reason', 'updated_at'];
            if ($sale->paymentAggregateTransition) {
                $allowed = [...$allowed, 'amount_paid', 'balance_due', 'payment_status', 'returned_amount', 'refunded_amount', 'refundable_credit'];
            }
            // An approved discount is the one sanctioned way the price of a completed Sale changes.
            // `subtotal` is deliberately absent: the goods sold and their prices are evidence, so a
            // discount reduces what is owed without rewriting what was sold.
            if ($sale->discountApprovalTransition) {
                $allowed = [...$allowed, 'discount_amount', 'total_amount', 'balance_due', 'payment_status', 'refundable_credit'];
            }
            // Correcting a recording mistake. `discount_amount` is absent because discounts have
            // their own approval workflow, and `amount_paid` is absent because what the customer
            // actually handed over is not a mistake a correction gets to rewrite — it is derived
            // from the immutable payment ledger.
            if ($sale->correctionTransition) {
                $allowed = [...$allowed,
                    'customer_id', 'customer_code_snapshot', 'customer_name_snapshot', 'customer_phone_snapshot',
                    'notes', 'subtotal', 'total_amount', 'balance_due', 'payment_status', 'refundable_credit',
                ];
            }
            // Fulfilment. Marking an order ready for collection records something that becomes
            // true AFTER the sale is complete, so it is not a rewrite of what was sold: no money,
            // no goods and no identity moves. It has its own flag rather than joining the base
            // list, so only markPickupReady() can set these columns.
            if ($sale->pickupTransition) {
                $allowed = [...$allowed, 'pickup_ready_at', 'pickup_ready_by'];
            }
            // `sale_number` is absent from every `$allowed` list above and has no exception here.
            // It is assigned once at creation and then fixed: it is printed on receipts, quoted in
            // support and recorded in the audit trail, so changing it would orphan all three. The
            // previous placeholder-then-rename escape hatch is gone with the sequential scheme that
            // needed it.
            if (array_diff(array_keys($sale->getDirty()), $allowed) !== []) {
                throw new LogicException('Completed sales are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Sales cannot be deleted.'));
    }

    /**
     * Sales are addressed publicly by their ULID, so `/sales/1` becomes `/sales/01K5H2…`. A URL no
     * longer says how many sales the business has made, nor lets one be guessed from another.
     *
     * Internal relationships are untouched: `sale_items.sale_id`, payments, returns, refunds,
     * corrections and every report still join on the numeric primary key. Only route binding and
     * URL generation change. The number a person reads on a receipt remains `sale_number`.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * A walk-in Sale has no Customer row to reach, so anything asking about the buyer must ask the
     * Sale itself and fall back to its identity snapshots.
     */
    public function isWalkIn(): bool
    {
        return (bool) $this->is_walk_in;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * The lines the Sale currently asserts. Rows retired by a correction are excluded, so every
     * existing consumer — the receipt, the detail page, the return form, void's stock restoration —
     * sees the corrected Sale without needing to know that corrections exist.
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->whereNull('superseded_by_correction_id');
    }

    /** Every line the Sale has ever carried, superseded ones included, oldest first. */
    public function allItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(SaleCorrection::class);
    }

    /**
     * Historical receipt deliveries from the WhatsApp module this replaced. The table is preserved
     * read-only so past records are never destroyed; nothing writes to it any more. New messages
     * live in `whatsappMessages()` below.
     */
    public function whatsappDeliveries(): HasMany
    {
        return $this->hasMany(WhatsAppDelivery::class);
    }

    /** WhatsApp automation messages caused by this sale (post-purchase, pickup reminder). */
    public function whatsappMessages(): MorphMany
    {
        return $this->morphMany(WhatsAppMessage::class, 'subject');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(SaleRefund::class);
    }

    public function discountRequests(): HasMany
    {
        return $this->hasMany(SaleDiscountRequest::class);
    }

    /**
     * Records that this order is ready for collection.
     *
     * The only way `pickup_ready_at` is ever written, which is what keeps the timestamp and the
     * actor coherent — MySQL would not accept a CHECK constraint pairing them alongside the actor's
     * ON DELETE SET NULL foreign key, so this method is the guarantee instead.
     */
    public function markPickupReady(\DateTimeInterface $readyAt, int $actorId): void
    {
        $this->pickupTransition = true;

        try {
            $this->pickup_ready_at = $readyAt;
            $this->pickup_ready_by = $actorId;
            $this->save();
        } finally {
            $this->pickupTransition = false;
        }
    }

    public function synchronizePaymentAggregates(string $amountPaid, string $balanceDue, PaymentStatus $status): void
    {
        $this->paymentAggregateTransition = true;

        try {
            $this->amount_paid = $amountPaid;
            $this->balance_due = $balanceDue;
            $this->payment_status = $status;
            $this->save();
        } finally {
            $this->paymentAggregateTransition = false;
        }
    }

    /**
     * Applies an Admin-approved discount and re-derives the money that follows from it.
     *
     * `amount_paid` is never touched and no `sale_payments` row is altered: what the customer
     * actually handed over is historical fact. Only the obligation changes, and the balance,
     * payment status and refundable credit are recomputed from it — so a discount that takes the
     * total below what was already paid produces refundable credit rather than a negative balance.
     *
     * @param  array<string, mixed>  $state  from SaleFinancials::lockedState()
     */
    public function applyApprovedDiscount(string $discountAmount, string $totalAmount, array $state): void
    {
        $this->discountApprovalTransition = true;

        try {
            $this->discount_amount = $discountAmount;
            $this->total_amount = $totalAmount;
            $this->balance_due = $state['balance'];
            $this->refundable_credit = $state['credit'];
            $this->payment_status = $state['status'];
            $this->save();
        } finally {
            $this->discountApprovalTransition = false;
        }
    }

    /**
     * Applies a correction's new authoritative figures. `amount_paid` is untouched and no payment
     * row is altered; balance, status and refundable credit come from SaleFinancials, so a
     * correction that lowers the total below what was already paid produces refundable credit
     * exactly as an approved discount does.
     *
     * @param  array<string, mixed>  $attributes  corrected columns
     * @param  array<string, mixed>  $state  from SaleFinancials::lockedState()
     */
    public function applyCorrection(array $attributes, array $state): void
    {
        $this->correctionTransition = true;

        try {
            foreach ($attributes as $key => $value) {
                $this->$key = $value;
            }

            $this->balance_due = $state['balance'];
            $this->refundable_credit = $state['credit'];
            $this->payment_status = $state['status'];
            $this->save();
        } finally {
            $this->correctionTransition = false;
        }
    }

    public function synchronizeReturnFinancials(array $state): void
    {
        $this->paymentAggregateTransition = true;
        try {
            $this->amount_paid = $state['payments'];
            $this->returned_amount = $state['returns'];
            $this->refunded_amount = $state['refunds'];
            $this->balance_due = $state['balance'];
            $this->refundable_credit = $state['credit'];
            $this->payment_status = $state['status'];
            $this->save();
        } finally {
            $this->paymentAggregateTransition = false;
        }
    }

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'is_walk_in' => 'boolean',
            'sale_date' => 'date',
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'refundable_credit' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }
}
