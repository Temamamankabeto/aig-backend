<?php

namespace App\Services;

use App\Models\InventoryItem;
use RuntimeException;

class InventoryUnitConversionService
{
    public function convertForInventoryItem(InventoryItem $inventoryItem, float $quantity, ?string $fromUnit): float
    {
        $fromUnit = $this->normalizeUnit($fromUnit ?: $inventoryItem->unit);
        $baseUnit = $this->normalizeUnit($inventoryItem->unit);

        if ($fromUnit === $baseUnit) {
            return round($quantity, 3);
        }

        $conversion = $inventoryItem->conversions
            ->first(fn ($row) => $this->normalizeUnit($row->from_unit) === $fromUnit && $this->normalizeUnit($row->to_unit) === $baseUnit);

        if ($conversion) {
            return round($quantity * (float) $conversion->multiplier, 3);
        }

        $reverse = $inventoryItem->conversions
            ->first(fn ($row) => $this->normalizeUnit($row->from_unit) === $baseUnit && $this->normalizeUnit($row->to_unit) === $fromUnit);

        if ($reverse && (float) $reverse->multiplier > 0) {
            return round($quantity / (float) $reverse->multiplier, 3);
        }

        throw new RuntimeException("Unit conversion is not configured for {$inventoryItem->name}: {$fromUnit} -> {$baseUnit}.");
    }

    public function normalizeUnit(?string $unit): string
    {
        return strtolower(trim((string) $unit));
    }
}
