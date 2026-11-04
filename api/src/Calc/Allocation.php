<?php
declare(strict_types=1);

namespace KT\Calc;

use InvalidArgumentException;

final class Allocation
{
    /**
     * Spread an integer adjustment proportionally over amounts. The rounding
     * remainder goes to the largest amount (lowest index on ties).
     *
     * @param int[] $amounts
     * @return int[]
     */
    public static function spread(array $amounts, int $adjustment): array
    {
        $amounts = array_values($amounts);
        if ($amounts === []) {
            return [];
        }
        $sum = array_sum($amounts);
        if ($sum == 0) {
            if ($adjustment !== 0) {
                throw new InvalidArgumentException('Cannot spread a non-zero adjustment over zero amounts');
            }
            return array_fill(0, count($amounts), 0);
        }
        $shares = [];
        $largest = 0;
        foreach ($amounts as $i => $a) {
            $shares[$i] = (int) round($adjustment * $a / $sum);
            if ($a > $amounts[$largest]) {
                $largest = $i;
            }
        }
        $shares[$largest] += $adjustment - array_sum($shares);
        return $shares;
    }
}
