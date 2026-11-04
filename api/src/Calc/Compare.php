<?php
declare(strict_types=1);

namespace KT\Calc;

final class Compare
{
    /** @return array{status:string, diff:?float, pct:?float, flagged:bool} */
    public static function change(?float $new, ?float $ref, float $thresholdPct): array
    {
        if ($new === null || $ref === null || $ref <= 0) {
            return ['status' => 'none', 'diff' => null, 'pct' => null, 'flagged' => false];
        }
        $pct = round(($new - $ref) / $ref * 100, 2) + 0.0;
        if ($pct == 0) {
            return ['status' => 'same', 'diff' => 0.0, 'pct' => 0.0, 'flagged' => false];
        }
        return [
            'status' => $pct > 0 ? 'up' : 'down',
            'diff' => round($new - $ref, 2),
            'pct' => $pct,
            'flagged' => abs($pct) >= $thresholdPct,
        ];
    }
}
