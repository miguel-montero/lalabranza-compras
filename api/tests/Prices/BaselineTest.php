<?php
declare(strict_types=1);

namespace KT\Tests\Prices;

use KT\Prices\PriceQueries;
use KT\Tests\ApiTestCase;

final class BaselineTest extends ApiTestCase
{
    private function get(int $id): array
    {
        [$st, $v] = $this->call('GET', "/receipts/$id");
        $this->assertSame(200, $st);
        return $v;
    }

    public function test_at02_comparison(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($s, [$this->line($p, 1300, 1000, 'g')], ['purchase_date' => '2026-01-10']);
        $b = $this->make($s, [$this->line($p, 1500, 1, 'kg')], ['purchase_date' => '2026-02-10']);
        $c = $this->get($b['id'])['items'][0]['comparison'];
        $this->assertSame('up', $c['status']);
        $this->assertEquals(200, $c['diff']);
        $this->assertEquals(15.38, $c['pct']);
        $this->assertTrue($c['flagged']);
        $this->assertSame('Lider', $c['ref']['supplier_name']);
        $this->assertSame('2026-01-10', $c['ref']['purchase_date']);
        $this->assertEquals(1300, $c['ref']['normalized_price']);
    }

    public function test_at02b_factura_vs_boleta(): void
    {
        $s = $this->makeSupplier('Mayorista');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($s, [$this->line($p, 1000)], ['doc_type' => 'factura', 'purchase_date' => '2026-01-10']);
        $b = $this->make($s, [$this->line($p, 1100)], ['purchase_date' => '2026-02-10']);
        $c = $this->get($b['id'])['items'][0]['comparison'];
        $this->assertEquals(10, $c['pct']);
        $this->assertTrue($c['flagged']);
    }

    public function test_at03_first_purchase_none(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)]);
        $c = $this->get($a['id'])['items'][0]['comparison'];
        $this->assertSame('none', $c['status']);
        $this->assertFalse($c['flagged']);
        $this->assertNull($c['ref']);
    }

    public function test_at04_noncomparable_excluded(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Leche', 'L');
        $a = $this->make($s, [$this->line($p, 1000, 1, 'unidad', ['package_count' => 1, 'package_content' => 1, 'package_unit' => 'L'])], ['purchase_date' => '2026-01-01']);
        $this->assertEquals(1000, $a['items'][0]['normalized_price']);
        $b = $this->make($s, [$this->line($p, 900, 1, 'L')], ['purchase_date' => '2026-01-05']);
        self::$pdo->exec('UPDATE receipt_items SET normalized_price = NULL WHERE receipt_id = ' . $b['id']);
        $c = $this->make($s, [$this->line($p, 1100, 1, 'L')], ['purchase_date' => '2026-01-09']);
        $cc = $this->get($c['id'])['items'][0]['comparison'];
        $this->assertEquals(1000, $cc['ref']['normalized_price']);
        $this->assertSame('up', $cc['status']);
        // the null line itself shows none
        $this->assertSame('none', $this->get($b['id'])['items'][0]['comparison']['status']);
        // and when only a null line exists before, status none
        self::$pdo->exec('UPDATE receipt_items SET normalized_price = NULL WHERE receipt_id = ' . $a['id']);
        $this->assertSame('none', $this->get($c['id'])['items'][0]['comparison']['status']);
    }

    public function test_same_date_uses_entry_order(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-10']);
        $b = $this->make($s, [$this->line($p, 1200)], ['purchase_date' => '2026-01-10']);
        $this->assertSame('none', $this->get($a['id'])['items'][0]['comparison']['status']);
        $cb = $this->get($b['id'])['items'][0]['comparison'];
        $this->assertSame('up', $cb['status']);
        $this->assertEquals(1000, $cb['ref']['normalized_price']);
    }

    public function test_same_receipt_lines_use_line_order(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000), $this->line($p, 1500)]);
        $items = $this->get($a['id'])['items'];
        $this->assertSame('none', $items[0]['comparison']['status']);
        $this->assertEquals(1000, $items[1]['comparison']['ref']['normalized_price']);
    }

    public function test_void_removed_from_baseline(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-01']);
        $b = $this->make($s, [$this->line($p, 1200)], ['purchase_date' => '2026-01-02']);
        $c = $this->make($s, [$this->line($p, 1300)], ['purchase_date' => '2026-01-03']);
        $this->assertEquals(1200, $this->get($c['id'])['items'][0]['comparison']['ref']['normalized_price']);
        [$st] = $this->call('POST', "/receipts/{$b['id']}/void", ['reason' => 'error']);
        $this->assertSame(200, $st);
        $this->assertEquals(1000, $this->get($c['id'])['items'][0]['comparison']['ref']['normalized_price']);
        $vb = $this->get($b['id']);
        $this->assertSame('void', $vb['status']);
        // void receipt's own line still annotates against earlier comparable lines
        $this->assertSame('up', $vb['items'][0]['comparison']['status']);
        $this->assertEquals(1000, $vb['items'][0]['comparison']['ref']['normalized_price']);
    }

    public function test_undated_excluded(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-01']);
        $u = $this->make($s, [$this->line($p, 5000)], ['purchase_date' => '2026-01-02']);
        self::$pdo->exec("UPDATE receipts SET date_unknown = 1, purchase_date = NULL WHERE id = {$u['id']}");
        $c = $this->make($s, [$this->line($p, 1100)], ['purchase_date' => '2026-01-03']);
        $cc = $this->get($c['id'])['items'][0]['comparison'];
        $this->assertEquals(1000, $cc['ref']['normalized_price']);
        $this->assertSame('none', $this->get($u['id'])['items'][0]['comparison']['status']);
        $this->assertTrue($this->get($u['id'])['date_unknown']);
    }

    public function test_baseline_function_and_endpoint(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-10']);
        $b = $this->make($s, [$this->line($p, 1200)], ['purchase_date' => '2026-02-10']);
        // new unsaved receipt on same date as A counts A as earlier
        $r = PriceQueries::baseline($p, '2026-01-10', null, null);
        $this->assertSame($a['id'], $r['receipt_id']);
        $this->assertSame('Lider', $r['supplier_name']);
        $this->assertSame(1000.0, $r['normalized_price']);
        $this->assertNull(PriceQueries::baseline($p, '2026-01-09', null, null));
        $this->assertSame($a['id'], PriceQueries::baseline($p, '2026-02-10', $b['id'], 0)['receipt_id']);
        $this->assertNull(PriceQueries::baseline($p, '2026-01-10', $a['id'], 0));
        [$st, $body] = $this->call('GET', "/products/$p/baseline", null, ['date' => '2026-03-01']);
        $this->assertSame(200, $st);
        $this->assertSame($b['id'], $body['receipt_id']);
        [$st, $body] = $this->call('GET', "/products/$p/baseline", null, ['date' => '2025-01-01']);
        $this->assertSame(200, $st);
        $this->assertNull($body);
        [$st] = $this->call('GET', "/products/$p/baseline", null, ['date' => 'nope']);
        $this->assertSame(422, $st);
    }

    public function test_annotate_accepts_arbitrary_rows(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-10']);
        $rows = [
            ['product_id' => $p, 'purchase_date' => '2026-02-01', 'receipt_id' => 9999, 'line_order' => 0, 'normalized_price' => 1500.0],
            ['product_id' => $p, 'purchase_date' => '2026-01-10', 'receipt_id' => $a['id'], 'line_order' => 0, 'normalized_price' => 1000.0],
        ];
        $out = PriceQueries::annotate($rows);
        $this->assertSame('up', $out[0]['comparison']['status']);
        $this->assertEquals(50, $out[0]['comparison']['pct']);
        $this->assertSame('none', $out[1]['comparison']['status']);
    }
}
