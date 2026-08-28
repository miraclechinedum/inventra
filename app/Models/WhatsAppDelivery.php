<?php

namespace App\Models;

use App\Enums\WhatsAppDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WhatsAppDelivery extends Model
{
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
            'consent_checked_at' => 'datetime',
            'consent_opt_in_at_snapshot' => 'datetime',
            'requested_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'attempt' => 'integer',
        ];
    }
}
