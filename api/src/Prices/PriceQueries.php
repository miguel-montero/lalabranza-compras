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
     * line dated on or before $purchaseDate counts as earlier. $excludeReceiptId additionally drops every line of
     * that receipt (use with $receiptId = null for the live baseline of an edited draft).
     *
     * @return array{normalized_price:float, supplier_name:string, purchase_date:string, receipt_id:int}|null
     */
    public static function baseline(int $productId, string $purchaseDate, ?int $receiptId, ?int $lineOrder, ?int $excludeReceiptId = null): ?array
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
        $sql .= ')';
        if ($excludeReceiptId !== null) {
            // Edited draft of a saved receipt: new-receipt semantics (date <= D) minus that receipt entirely.
            $sql .= ' AND r.id <> ?';
            $args[] = $excludeReceiptId;
        }
        $sql .= ' ORDER BY r.purchase_date DESC, r.id DESC, i.line_order DESC, i.id DESC LIMIT 1';
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
                    isset($l['receipt_id']) ? (int) $l['receipt_id'] : PHP_INT_MAX, // unsaved line ranks after saved ones
                    (int) ($l['line_order'] ?? 0)
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

    /**
     * History rows (annotated), unpaged, newest first, undated last. Voided receipts are always excluded.
     * Filters: from, to, product_id, supplier_id, category_id, receipt_number (substring), doc_type,
     * direction (up|down|flagged, applied after annotation), include_undated (bool; undated rows ignore from/to).
     *
     * @return array<int,array>
     */
    public static function history(array $f): array
    {
        $where = ["r.status = 'active'"];
        $args = [];
        foreach (['product_id' => 'i.product_id', 'supplier_id' => 'r.supplier_id', 'category_id' => 'p.category_id', 'doc_type' => 'r.doc_type'] as $k => $col) {
            if (isset($f[$k]) && $f[$k] !== '') {
                $where[] = "$col = ?";
                $args[] = $f[$k];
            }
        }
        if (isset($f['receipt_number']) && $f['receipt_number'] !== '') {
            $where[] = 'r.receipt_number LIKE ?';
            $args[] = '%' . addcslashes((string) $f['receipt_number'], '\\%_') . '%';
        }
        $dated = ['r.date_unknown = 0', 'r.purchase_date IS NOT NULL'];
        if (!empty($f['from'])) {
            $dated[] = 'r.purchase_date >= ?';
            $args[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $dated[] = 'r.purchase_date <= ?';
            $args[] = $f['to'];
        }
        $datedSql = '(' . implode(' AND ', $dated) . ')';
        $where[] = !empty($f['include_undated']) ? "($datedSql OR r.date_unknown = 1)" : $datedSql;

        $st = Db::pdo()->prepare(
            'SELECT r.id AS receipt_id, r.purchase_date, r.date_unknown, s.name AS supplier_name, i.product_id,
                    p.name AS product_name, c.name AS category_name, p.base_unit, r.doc_type, r.receipt_number,
                    i.line_order, i.quantity, i.entry_unit, i.package_count, i.package_content, i.package_unit,
                    i.base_quantity, i.entered_amount, i.amount_paid, i.real_cost, i.normalized_price
             FROM receipt_items i
             JOIN receipts r ON r.id = i.receipt_id
             JOIN suppliers s ON s.id = r.supplier_id
             JOIN products p ON p.id = i.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY (r.purchase_date IS NULL), r.purchase_date DESC, r.id DESC, i.line_order DESC, i.id DESC'
        );
        $st->execute($args);
        $nf = static fn($v) => $v === null ? null : (float) $v;
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'receipt_id' => (int) $r['receipt_id'],
                'purchase_date' => $r['purchase_date'],
                'date_unknown' => (int) $r['date_unknown'] === 1,
                'supplier_name' => $r['supplier_name'],
                'product_id' => (int) $r['product_id'],
                'product_name' => $r['product_name'],
                'category_name' => $r['category_name'],
                'doc_type' => $r['doc_type'],
                'receipt_number' => $r['receipt_number'],
                'line_order' => (int) $r['line_order'],
                'quantity' => (float) $r['quantity'],
                'entry_unit' => $r['entry_unit'],
                'package_count' => $nf($r['package_count']),
                'package_content' => $nf($r['package_content']),
                'package_unit' => $r['package_unit'],
                'base_quantity' => $nf($r['base_quantity']),
                'base_unit' => $r['base_unit'],
                'entered_amount' => (int) $r['entered_amount'],
                'amount_paid' => (int) $r['amount_paid'],
                'real_cost' => (int) $r['real_cost'],
                'normalized_price' => $nf($r['normalized_price']),
            ];
        }
        $rows = self::annotate($rows);
        $dir = $f['direction'] ?? null;
        if ($dir !== null && $dir !== '') {
            $rows = array_values(array_filter($rows, static function (array $r) use ($dir): bool {
                $c = $r['comparison'];
                return match ($dir) {
                    'flagged' => $c['flagged'],
                    default => $c['status'] === $dir,
                };
            }));
        }
        return $rows;
    }

    /**
     * Per-product price stats. "Recent" = purchase_date >= today - recent_days. Only active receipts count;
     * undated lines appear only in `series` (purchase_date null) and in purchase_count.
     *
     * @param array<int,int> $productIds
     * @return array<int,array{stats:array,by_supplier:array,series:array}>
     */
    public static function productStats(array $productIds): array
    {
        $out = [];
        if (!$productIds) {
            return $out;
        }
        $recentDays = (int) Settings::get('recent_days');
        $cutoff = (new \DateTimeImmutable(\KT\Receipts\ReceiptValidator::today()))->modify("-$recentDays days")->format('Y-m-d');
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $st = Db::pdo()->prepare(
            "SELECT i.product_id, i.normalized_price, r.purchase_date, r.date_unknown, r.doc_type, s.name AS supplier_name
             FROM receipt_items i
             JOIN receipts r ON r.id = i.receipt_id
             JOIN suppliers s ON s.id = r.supplier_id
             WHERE i.product_id IN ($ph) AND r.status = 'active'
             ORDER BY (r.purchase_date IS NULL), r.purchase_date, r.id, i.line_order, i.id"
        );
        $st->execute(array_values($productIds));
        $lines = [];
        foreach ($st->fetchAll() as $r) {
            $lines[(int) $r['product_id']][] = $r;
        }
        foreach ($productIds as $pid) {
            $latest = null;
            $lowest = null;
            $bySup = [];
            $series = [];
            $rows = $lines[$pid] ?? [];
            foreach ($rows as $r) {
                if ($r['normalized_price'] === null) {
                    continue;
                }
                $price = (float) $r['normalized_price'];
                $undated = $r['purchase_date'] === null || (int) $r['date_unknown'] === 1;
                $series[] = [
                    'purchase_date' => $undated ? null : $r['purchase_date'],
                    'normalized_price' => $price,
                    'supplier_name' => $r['supplier_name'],
                    'doc_type' => $r['doc_type'],
                ];
                if ($undated) {
                    continue;
                }
                $pt = ['normalized_price' => $price, 'supplier_name' => $r['supplier_name'], 'purchase_date' => $r['purchase_date']];
                $latest = $pt;
                $inWindow = $r['purchase_date'] >= $cutoff;
                if ($inWindow && ($lowest === null || $price <= $lowest['normalized_price'])) {
                    $lowest = $pt;
                }
                $sn = $r['supplier_name'];
                $bySup[$sn] ??= ['supplier_name' => $sn, 'latest_normalized_price' => $price, 'purchase_date' => $r['purchase_date'], 'count_in_window' => 0];
                $bySup[$sn]['latest_normalized_price'] = $price;
                $bySup[$sn]['purchase_date'] = $r['purchase_date'];
                if ($inWindow) {
                    $bySup[$sn]['count_in_window']++;
                }
            }
            $bs = array_values($bySup);
            usort($bs, static fn($a, $b) => [$b['purchase_date'], $a['supplier_name']] <=> [$a['purchase_date'], $b['supplier_name']]);
            $out[$pid] = [
                'stats' => [
                    'latest' => $latest,
                    'lowest_recent' => $lowest,
                    'purchase_count' => count($rows),
                    'recent_days' => $recentDays,
                ],
                'by_supplier' => $bs,
                'series' => $series,
            ];
        }
        return $out;
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
