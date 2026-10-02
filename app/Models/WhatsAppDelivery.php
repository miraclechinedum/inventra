<?php

namespace App\Models;

use App\Enums\WhatsAppDeliveryOrigin;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\Concerns\ScopedToCurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Historical receipt deliveries from before the automation messages replaced them. Read-only, and
 * owned by their sale's Business: tenant-scoped like any other record, so the customer page that
 * still lists them shows only its own Business's history.
 */
class WhatsAppDelivery extends Model
{
    use ScopedToCurrentBusiness;

    protected $table = 'whatsapp_deliveries';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('WhatsApp deliveries require controlled state transitions.'));
        static::deleting(fn (): never => throw new LogicException('WhatsApp delivery history cannot be deleted.'));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    protected function casts(): array
    {
        return [
            'status' => WhatsAppDeliveryStatus::class,
            'origin' => WhatsAppDeliveryOrigin::class,
            'consent_checked_at' => 'datetime',
            'consent_opt_in_at_snapshot' => 'datetime',
            'requested_at' => 'datetime',
            'dispatch_claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'attempt' => 'integer',
        ];
    }
}
