<?php
declare(strict_types=1);

namespace KT\Calc;

final class Units
{
    /** Convert a quantity to the product's base unit; null when units are incompatible. */
    public static function toBase(float $qty, string $unit, string $baseUnit): ?float
    {
        if ($unit === $baseUnit && in_array($unit, ['kg', 'L', 'unidad'], true)) {
            return $qty;
        }
        if ($unit === 'g' && $baseUnit === 'kg') {
            return $qty / 1000;
        }
        if ($unit === 'mL' && $baseUnit === 'L') {
            return $qty / 1000;
        }
        return null;
    }
}
