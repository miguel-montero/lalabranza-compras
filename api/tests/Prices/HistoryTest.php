<?php
declare(strict_types=1);

namespace KT\Tests\Prices;

use KT\Prices\PriceQueries;
use KT\Tests\ApiTestCase;

final class HistoryTest extends ApiTestCase
{
    private function hist(array $q = []): array
    {
        [$st, $v] = $this->call('GET', '/history', null, $q);
        $this->assertSame(200, $st, json_encode($v));
        return $v;
    }

    public function test_filters_and_direction(): void
    {
        $s1 = $this->makeSupplier('Lider');
        $s2 = $this->makeSupplier('Jumbo');
        $p = $this->makeProduct('Arroz', 'kg');
        $q = $this->makeProduct('Azucar', 'kg');
        $this->make($s1, [$this->line($p, 1300), $this->line($q, 1000)], ['purchase_date' => '2026-01-10']);
        $this->make($s1, [$this->line($p, 1500), $this->line($q, 1050)], ['purchase_date' => '2026-02-10']);
        $this->make($s2, [$this->line($p, 1400)], ['purchase_date' => '2026-03-10']);

        $all = $this->hist();
        $this->assertSame(5, $all['total']);
        $this->assertSame('2026-03-10', $all['items'][0]['purchase_date']);

        $this->assertSame(1, $this->hist(['supplier_id' => (string) $s2])['total']);
        $this->assertSame(2, $this->hist(['from' => '2026-02-01', 'to' => '2026-02-28'])['total']);
        $this->assertSame(3, $this->hist(['product_id' => (string) $p])['total']);

        $fl = $this->hist(['direction' => 'flagged']);
        $this->assertSame(1, $fl['total']);
        $row = $fl['items'][0];
        $this->assertSame('Arroz', $row['product_name']);
        $this->assertEquals(15.38, $row['comparison']['pct']);
        $this->assertTrue($row['comparison']['flagged']);

        $up = $this->hist(['direction' => 'up']);
        $this->assertSame(2, $up['total']); // 1500 vs 1300, 1050 vs 1000
        $down = $this->hist(['direction' => 'down']);
        $this->assertSame(1, $down['total']); // 1400 vs 1500
        $this->assertSame('Jumbo', $down['items'][0]['supplier_name']);
    }

    public function test_row_shape(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($s, [$this->line($p, 1300, 1000, 'g')], ['receipt_number' => 'F-1']);
        $r = $this->hist()['items'][0];
        foreach (['receipt_id', 'purchase_date', 'date_unknown', 'supplier_name', 'product_name', 'category_name', 'doc_type',
            'receipt_number', 'quantity', 'entry_unit', 'package_count', 'package_content', 'package_unit', 'base_quantity',
            'base_unit', 'entered_amount', 'amount_paid', 'real_cost', 'normalized_price', 'comparison'] as $k) {
            $this->assertArrayHasKey($k, $r, $k);
        }
        $this->assertSame('kg', $r['base_unit']);
        $this->assertSame('F-1', $r['receipt_number']);
    }

    public function test_void_excluded(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-10']);
        $this->make($s, [$this->line($p, 1200)], ['purchase_date' => '2026-02-10']);
        $this->call('POST', "/receipts/{$a['id']}/void", []);
        $h = $this->hist();
        $this->assertSame(1, $h['total']);
        $this->assertSame('none', $h['items'][0]['comparison']['status']);
    }

    public function test_include_undated(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-10']);
        $u = $this->make($s, [$this->line($p, 5000)], ['purchase_date' => '2026-01-11']);
        self::$pdo->exec("UPDATE receipts SET purchase_date = NULL, date_unknown = 1 WHERE id = {$u['id']}");

        $this->assertSame(1, $this->hist()['total']);
        $h = $this->hist(['include_undated' => '1']);
        $this->assertSame(2, $h['total']);
        $this->assertNull($h['items'][1]['purchase_date']);
        $this->assertTrue($h['items'][1]['date_unknown']);
        $this->assertSame('none', $h['items'][1]['comparison']['status']);
        $this->assertSame('none', $h['items'][0]['comparison']['status']);
    }

    public function test_annotate_null_receipt_ranks_after_saved_lines(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-10']);
        foreach ([['product_id' => $p, 'purchase_date' => '2026-01-10', 'receipt_id' => null, 'line_order' => 0, 'normalized_price' => 1200],
                  ['product_id' => $p, 'purchase_date' => '2026-01-10', 'line_order' => 0, 'normalized_price' => 1200]] as $row) {
            $out = PriceQueries::annotate([$row]);
            $this->assertSame('up', $out[0]['comparison']['status']);
            $this->assertEquals(1000, $out[0]['comparison']['ref']['normalized_price']);
        }
    }

    public function test_invalid_params_422(): void
    {
        [$st] = $this->call('GET', '/history', null, ['direction' => 'sideways']);
        $this->assertSame(422, $st);
        [$st] = $this->call('GET', '/history', null, ['from' => 'x']);
        $this->assertSame(422, $st);
    }
}
