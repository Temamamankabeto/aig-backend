<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use HasFactory, SoftDeletes;

    public const BASE_UNITS = ['kg', 'L', 'pcs'];

    protected $fillable = [
        'name',
        'sku',
        'base_unit',
        'minimum_quantity',
        'current_stock',
        'average_purchase_price',
        'is_active',
    ];

    protected $casts = [
        'minimum_quantity' => 'decimal:3',
        'current_stock' => 'decimal:3',
        'average_purchase_price' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'stock_status',
        'available_stock',
        'expired_stock',
    ];

    public static function allowedBaseUnits(): array
    {
        return self::BASE_UNITS;
    }

    public static function baseUnitValidationRule(): string
    {
        return 'in:' . implode(',', self::BASE_UNITS);
    }

    public function hasSupportedBaseUnit(): bool
    {
        return in_array((string) $this->base_unit, self::BASE_UNITS, true);
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class, 'inventory_item_id');
    }

    public function recipeItems()
    {
        return $this->hasMany(RecipeItem::class, 'inventory_item_id');
    }

    public function batches()
    {
        return $this->hasMany(InventoryItemBatch::class, 'inventory_item_id');
    }

    public function purchaseOrderItems()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'inventory_item_id');
    }

    public function getAvailableStockAttribute(): string
    {
        if (! $this->relationLoaded('batches')) {
            return number_format((float) ($this->current_stock ?? 0), 3, '.', '');
        }

        $usable = $this->batches
            ->filter(fn ($batch) => ! $batch->isExpired() && (float) $batch->remaining_qty > 0)
            ->sum(fn ($batch) => (float) $batch->remaining_qty);

        return number_format((float) $usable, 3, '.', '');
    }

    public function getExpiredStockAttribute(): string
    {
        if (! $this->relationLoaded('batches')) {
            return number_format(0, 3, '.', '');
        }

        $expired = $this->batches
            ->filter(fn ($batch) => $batch->isExpired() && (float) $batch->remaining_qty > 0)
            ->sum(fn ($batch) => (float) $batch->remaining_qty);

        return number_format((float) $expired, 3, '.', '');
    }

    public function getStockStatusAttribute(): string
    {
        $available = (float) $this->available_stock;
        $expired = (float) $this->expired_stock;
        $minimum = (float) ($this->minimum_quantity ?? 0);

        if ($available <= 0 && $expired > 0) {
            return 'expired';
        }

        if ($available <= 0) {
            return 'out_of_stock';
        }

        if ($available <= $minimum) {
            return 'low_stock';
        }

        return 'in_stock';
    }
}
