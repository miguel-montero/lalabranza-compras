<?php
declare(strict_types=1);

namespace KT\Prices;

use KT\Calc\LineCalc;
use KT\Db;
use KT\Receipts\ReceiptService;

final class Dashboard
{
    /** @return array{recent_receipts:array,flagged:array,spend:array} */
    public static function build(string $from, string $to): array
    {
        $svc = new ReceiptService();
        $recent = array_slice($svc->list(['status' => 'active'])['items'], 0, 10);

        $st = Db::pdo()->prepare(
            "SELECT id FROM receipts WHERE status = 'active' AND date_unknown = 0 AND purchase_date BETWEEN ? AND ?"
        );
        $st->execute([$from, $to]);
        $totalPaid = 0;
        $totalReal = 0;
        $cats = [];
        $sups = [];
        $add = static function (array &$m, string $name, int $paid, int $real): void {
            $m[$name] ??= ['name' => $name, 'total_paid' => 0, 'total_real' => 0];
            $m[$name]['total_paid'] += $paid;
            $m[$name]['total_real'] += $real;
        };
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $v = $svc->view((int) $id);
            $paid = $v['totals']['total'];
            $real = $v['delivery_amount'];
            foreach ($v['items'] as $i) {
                $real += $i['real_cost'];
            }
            $totalPaid += $paid;
            $totalReal += $real;
            $add($sups, $v['supplier']['name'], $paid, $real);
            $catNames = self::categoryNames(array_column($v['items'], 'product_id'));
            foreach ($v['items'] as $i) {
                $add($cats, $catNames[$i['product_id']] ?? 'Sin categoría', $i['amount_paid'], $i['real_cost']);
            }
            if ($v['delivery_amount'] > 0) {
                $dp = LineCalc::costs($v['delivery_amount'], $v['doc_type'], false, $v['iva_rate'])['amount_paid'];
                $add($cats, 'Despacho', $dp, $v['delivery_amount']);
            }
        }
        $byPaid = static function (array $m): array {
            $l = array_values($m);
            usort($l, static fn($a, $b) => [$b['total_paid'], $a['name']] <=> [$a['total_paid'], $b['name']]);
            return $l;
        };
        $flagged = PriceQueries::history(['from' => $from, 'to' => $to, 'direction' => 'flagged']);

        return [
            'range' => ['from' => $from, 'to' => $to],
            'recent_receipts' => $recent,
            'flagged' => $flagged,
            'spend' => [
                'total_paid' => $totalPaid,
                'total_real' => $totalReal,
                'by_category' => $byPaid($cats),
                'by_supplier' => $byPaid($sups),
            ],
        ];
    }

    /** @return array<int,string> */
    private static function categoryNames(array $productIds): array
    {
        $ids = array_values(array_unique($productIds));
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Db::pdo()->prepare("SELECT p.id, c.name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id IN ($ph)");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
        return $out;
    }
}
