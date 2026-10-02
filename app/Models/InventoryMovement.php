<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use App\Models\Concerns\ScopedToCurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

class InventoryMovement extends Model
{
    use ScopedToCurrentBusiness;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Inventory movements are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Inventory movements are append-only.'));

        // The product side is enforced by a composite foreign key. The document side is polymorphic,
        // which no foreign key can express, so a movement recorded against a sale, return or purchase
        // is refused here unless that document belongs to the movement's own Business.
        static::creating(function (self $movement): void {
            if ($movement->reference_type === null || $movement->reference_id === null || ! class_exists($movement->reference_type)) {
                return;
            }

            $document = new $movement->reference_type;

            if (! $document instanceof Model || ! array_key_exists(ScopedToCurrentBusiness::class, class_uses_recursive($document))) {
                return;
            }

            $owner = DB::table($document->getTable())->where('id', $movement->reference_id)->value('business_id');

            if ((int) $owner !== (int) $movement->business_id) {
                throw new LogicException('A stock movement must belong to the same business as the document it records.');
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity_change' => 'decimal:3',
            'quantity_before' => 'decimal:3',
            'quantity_after' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }
}
