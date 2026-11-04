<?php
declare(strict_types=1);

namespace KT\Calc;

final class LineCalc
{
    public static function baseQuantity(array $line, string $baseUnit): ?float
    {
        $count = $line['package_count'] ?? null;
        $content = $line['package_content'] ?? null;
        $pUnit = $line['package_unit'] ?? null;
        if ($count !== null && $content !== null && $pUnit !== null) {
            $per = Units::toBase((float) $content, (string) $pUnit, $baseUnit);
            return $per === null ? null : (float) $count * $per;
        }
        return Units::toBase((float) $line['quantity'], (string) $line['entry_unit'], $baseUnit);
    }

    public static function enteredAmount(array $line): int
    {
        if (($line['entry_mode'] ?? null) === 'total') {
            return (int) $line['entered_amount'];
        }
        $count = $line['package_count'] ?? null;
        $multiplier = $count ?? $line['quantity'];
        return (int) round((float) $line['unit_price'] * (float) $multiplier);
    }

    /** @return array{real_cost:int, amount_paid:int} */
    public static function costs(int $netOrPrinted, string $docType, bool $exempt, float $ivaRate): array
    {
        if ($docType === 'factura' && !$exempt) {
            return ['real_cost' => $netOrPrinted, 'amount_paid' => (int) round($netOrPrinted * (1 + $ivaRate))];
        }
        return ['real_cost' => $netOrPrinted, 'amount_paid' => $netOrPrinted];
    }

    public static function normalizedPrice(int $realCost, ?float $baseQty): ?float
    {
        if ($baseQty === null || $baseQty <= 0) {
            return null;
        }
        return round($realCost / $baseQty, 4);
    }

    /** @return array{neto:int, exento:int, iva:int, total:int} */
    public static function receiptTotals(array $lines, int $deliveryAmount, string $docType, float $ivaRate): array
    {
        if ($docType !== 'factura') {
            $sum = 0;
            foreach ($lines as $l) {
                $sum += (int) $l['real_cost'];
            }
            return ['neto' => 0, 'exento' => 0, 'iva' => 0, 'total' => $sum + $deliveryAmount];
        }
        $neto = $deliveryAmount;
        $exento = 0;
        foreach ($lines as $l) {
            if (!empty($l['iva_exempt'])) {
                $exento += (int) $l['real_cost'];
            } else {
                $neto += (int) $l['real_cost'];
            }
        }
        $iva = (int) round($neto * $ivaRate);
        return ['neto' => $neto, 'exento' => $exento, 'iva' => $iva, 'total' => $neto + $exento + $iva];
    }
}
