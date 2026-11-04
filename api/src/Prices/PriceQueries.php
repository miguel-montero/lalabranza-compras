<?php
declare(strict_types=1);

namespace KT\Prices;

use KT\Calc\Compare;
use KT\Db;
use KT\Settings;

/**
 * Comparison engine. Ordering key of a line is (purchase_date, receipt_id, line_order).
 * A line's baseline is the comparable line of the same product with the greatest key below its own.
 * Comparable = receipt active AND date_unknown = 0 AND normalized_price NOT NULL.
 */
final class PriceQueries
{
    private const COMPARABLE =
        "r.status = 'active' AND r.date_unknown = 0 AND r.purchase_date IS NOT NULL AND i.normalized_price IS NOT NULL";

    /**
     * Baseline for a (possibly unsaved) line. $receiptId = null means a new unsaved receipt: every comparable
     * line dated on or before $purchaseDate counts as earlier.
     *
     * @return array{normalized_price:float, supplier_name:string, purchase_date:string, receipt_id:int}|null
     */
    public static function baseline(int $productId, string $purchaseDate, ?int $receiptId, ?int $lineOrder): ?array
    {
        $sql = 'SELECT i.normalized_price, s.name AS supplier_name, r.purchase_date, r.id AS receipt_id
                FROM receipt_items i
                JOIN receipts r ON r.id = i.receipt_id
                JOIN suppliers s ON s.id = r.supplier_id
                WHERE i.product_id = ? AND ' . self::COMPARABLE . ' AND (';
        $args = [$productId];
        if ($receiptId === null) {
            $sql .= 'r.purchase_date <= ?';
            $args[] = $purchaseDate;
        } else {
            $sql .= 'r.purchase_date < ? OR (r.purchase_date = ? AND (r.id < ?';
            array_push($args, $purchaseDate, $purchaseDate, $receiptId);
            if ($lineOrder !== null) {
                $sql .= ' OR (r.id = ? AND i.line_order < ?)';
                array_push($args, $receiptId, $lineOrder);
            }
            $sql .= '))';
        }
        $sql .= ') ORDER BY r.purchase_date DESC, r.id DESC, i.line_order DESC, i.id DESC LIMIT 1';
        $st = Db::pdo()->prepare($sql);
        $st->execute($args);
        $r = $st->fetch();
        return $r ? self::ref($r) : null;
    }

    /**
     * Adds `comparison` to each line. One query for all products involved, then one in-memory pass.
     * Input rows need: product_id, purchase_date (null/absent = undated: comparison none), receipt_id,
     * line_order, normalized_price (null = none). Status/date_unknown of the row's OWN receipt do not matter:
     * voided or undated receipts' own lines are annotated but, being excluded in SQL, never serve as baseline.
     * Extra keys are preserved; input order is preserved.
     *
     * @param array<int,array> $lines
     * @return array<int,array>
     */
    public static function annotate(array $lines): array
    {
        $pids = [];
        foreach ($lines as $l) {
            $pids[(int) $l['product_id']] = true;
        }
        $byProduct = [];
        if ($pids) {
            $ph = implode(',', array_fill(0, count($pids), '?'));
            $st = Db::pdo()->prepare(
                "SELECT i.product_id, i.normalized_price, i.line_order, s.name AS supplier_name,
                        r.purchase_date, r.id AS receipt_id
                 FROM receipt_items i
                 JOIN receipts r ON r.id = i.receipt_id
                 JOIN suppliers s ON s.id = r.supplier_id
                 WHERE i.product_id IN ($ph) AND " . self::COMPARABLE . '
                 ORDER BY i.product_id, r.purchase_date, r.id, i.line_order, i.id'
            );
            $st->execute(array_keys($pids));
            foreach ($st->fetchAll() as $row) {
                $byProduct[(int) $row['product_id']][] = $row;
            }
        }

        $threshold = (float) Settings::get('alert_threshold_pct');
        foreach ($lines as $n => $l) {
            $date = $l['purchase_date'] ?? null;
            $price = $l['normalized_price'] ?? null;
            $ref = null;
            if ($date !== null && $price !== null) {
                $ref = self::findBelow(
                    $byProduct[(int) $l['product_id']] ?? [],
                    (string) $date,
                    (int) $l['receipt_id'],
                    (int) $l['line_order']
                );
            }
            $cmp = Compare::change(
                $price === null ? null : (float) $price,
                $ref === null ? null : (float) $ref['normalized_price'],
                $threshold
            );
            $cmp['ref'] = $ref === null ? null : [
                'normalized_price' => (float) $ref['normalized_price'],
                'supplier_name' => $ref['supplier_name'],
                'purchase_date' => $ref['purchase_date'],
            ];
            $lines[$n]['comparison'] = $cmp;
        }
        return $lines;
    }

    /** Binary search: last row whose key is strictly below the given key. Rows sorted ascending by key. */
    private static function findBelow(array $rows, string $date, int $rid, int $order): ?array
    {
        $lo = 0;
        $hi = count($rows);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $r = $rows[$mid];
            $cmp = [$r['purchase_date'], (int) $r['receipt_id'], (int) $r['line_order']] <=> [$date, $rid, $order];
            if ($cmp < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo > 0 ? $rows[$lo - 1] : null;
    }

    private static function ref(array $r): array
    {
        return [
            'normalized_price' => (float) $r['normalized_price'],
            'supplier_name' => $r['supplier_name'],
            'purchase_date' => $r['purchase_date'],
            'receipt_id' => (int) $r['receipt_id'],
        ];
    }
}
