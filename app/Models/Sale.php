<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    private bool $paymentAggregateTransition = false;

    private bool $discountApprovalTransition = false;

    private bool $correctionTransition = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (Sale $sale): void {
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
            $dirty = array_keys($sale->getDirty());
            $initialNumberAssignment = str_starts_with((string) $sale->getOriginal('sale_number'), 'PENDING-')
                && in_array('sale_number', $dirty, true)
                && array_diff($dirty, ['sale_number', 'updated_at']) === [];

            if (! $initialNumberAssignment && array_diff(array_keys($sale->getDirty()), $allowed) !== []) {
                throw new LogicException('Completed sales are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Sales cannot be deleted.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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

    public function whatsappDeliveries(): HasMany
    {
        return $this->hasMany(WhatsAppDelivery::class);
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
