<?php

namespace App\Models;

use App\Alerts\AlertSubject;
use App\Enums\OperationalAlertSeverity;
use App\Enums\OperationalAlertStatus;
use App\Enums\OperationalAlertType;
use App\Models\Concerns\ScopedToCurrentBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One lifecycle occurrence of one operational condition. Written only by
 * App\Alerts\OperationalAlertProjector; fully guarded so no request payload can reach a column.
 */
class OperationalAlert extends Model
{
    use ScopedToCurrentBusiness;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // The subject is polymorphic, which no foreign key can express. Only the known subject types
        // are resolved — never an arbitrary class — and the alert must share its subject's Business.
        static::creating(function (self $alert): void {
            $class = AlertSubject::TYPES[$alert->subject_type] ?? null;
            $owner = $class === null ? null : DB::table((new $class)->getTable())->where('id', $alert->subject_id)->value('business_id');

            if ($owner === null || (int) $owner !== (int) $alert->business_id) {
                throw new LogicException('An alert must belong to the same business as its subject.');
            }
        });
    }

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
