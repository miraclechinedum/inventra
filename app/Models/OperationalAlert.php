<?php

namespace App\Models;

use App\Enums\OperationalAlertSeverity;
use App\Enums\OperationalAlertStatus;
use App\Enums\OperationalAlertType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One lifecycle occurrence of one operational condition. Written only by
 * App\Alerts\OperationalAlertProjector; fully guarded so no request payload can reach a column.
 */
class OperationalAlert extends Model
{
    protected $guarded = ['*'];

    public function recipients(): HasMany
    {
        return $this->hasMany(OperationalAlertRecipient::class);
    }

    /** The dedupe identity: one active alert per condition per subject. */
    public static function activeKeyFor(OperationalAlertType $type, string $subjectType, int $subjectId): string
    {
        return $type->value.':'.$subjectType.':'.$subjectId;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OperationalAlertStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === OperationalAlertStatus::Active;
    }

    protected function casts(): array
    {
        return [
            'type' => OperationalAlertType::class,
            'severity' => OperationalAlertSeverity::class,
            'status' => OperationalAlertStatus::class,
            'first_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
