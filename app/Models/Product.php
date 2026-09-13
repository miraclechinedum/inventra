<?php

namespace App\Models;

use App\Enums\ProductUnit;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use LogicException;

#[Fillable(['category_id', 'name', 'sku', 'description', 'cost_price', 'selling_price', 'reorder_level', 'unit'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        // The public identifier is the server's to assign, never the client's. It is not in the
        // Fillable attribute either, so no request payload can reach it; this only guarantees a
        // value exists for every Product however it was created.
        static::creating(function (self $product): void {
            if (blank($product->public_id)) {
                $product->public_id = (string) Str::ulid();
            }
        });

        // Once published, a URL should keep resolving. Changing the identifier would break every
        // link already shared, so it is fixed at creation.
        static::updating(function (self $product): void {
            if ($product->isDirty('public_id')) {
                throw new LogicException('A product public identifier is immutable once assigned.');
            }
        });
    }

    /**
     * Products are addressed publicly by their ULID, so `/inventory/products/1` becomes
     * `/inventory/products/01K5G9…`. Internal relationships continue to use the numeric primary
     * key; only route binding and URL generation change.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isLowStock(): bool
    {
        return bccomp($this->current_stock, $this->reorder_level, 3) <= 0;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('current_stock', '<=', 'reorder_level');
    }

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'current_stock' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'unit' => ProductUnit::class,
            'is_active' => 'boolean',
        ];
    }
}
