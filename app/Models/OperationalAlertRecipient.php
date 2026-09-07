<?php

namespace App\Models;

use App\Enums\OperationalAlertType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's delivery of one alert, and the only place read/acknowledge state lives. Guarded so a
 * request can never set user_id, timestamps or the alert it points at; the read and acknowledge
 * actions write the two timestamps explicitly.
 */
class OperationalAlertRecipient extends Model
{
    protected $guarded = ['*'];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(OperationalAlert::class, 'operational_alert_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * The type of the alert this delivery points at, without tripping strict lazy-loading: the
     * eager-loaded relation is reused when present, otherwise it is fetched explicitly.
     */
    public function alertType(): ?OperationalAlertType
    {
        $alert = $this->relationLoaded('alert') ? $this->getRelation('alert') : $this->alert()->first();

        return $alert?->type;
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
