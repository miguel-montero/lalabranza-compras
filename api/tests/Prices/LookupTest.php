<?php
declare(strict_types=1);

namespace KT\Tests\Prices;

use KT\Tests\ApiTestCase;

final class LookupTest extends ApiTestCase
{
    private function ago(int $days): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->modify("-$days days")->format('Y-m-d');
    }

    public function test_lowest_recent_respects_window(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($s, [$this->line($p, 900)], ['purchase_date' => $this->ago(100)]);
        $this->make($s, [$this->line($p, 1100)], ['purchase_date' => $this->ago(10)]);
        [$st, $v] = $this->call('GET', "/products/$p");
        $this->assertSame(200, $st);
        $this->assertEquals(1100, $v['stats']['lowest_recent']['normalized_price']);
        $this->assertEquals(1100, $v['stats']['latest']['normalized_price']);
        $this->assertSame(2, $v['stats']['purchase_count']);
        $this->assertSame(90, $v['stats']['recent_days']);
        $this->assertCount(2, $v['series']);
        $this->assertSame('Arroz', $v['product']['name']);
    }

    public function test_nothing_in_window(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($s, [$this->line($p, 900)], ['purchase_date' => $this->ago(100)]);
        [, $v] = $this->call('GET', "/products/$p");
        $this->assertNull($v['stats']['lowest_recent']);
        $this->assertEquals(900, $v['stats']['latest']['normalized_price']);
    }

    public function test_by_supplier_latest(): void
    {
        $a = $this->makeSupplier('Lider');
        $b = $this->makeSupplier('Jumbo');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($a, [$this->line($p, 1000)], ['purchase_date' => $this->ago(30)]);
        $this->make($a, [$this->line($p, 1200)], ['purchase_date' => $this->ago(5)]);
        $this->make($b, [$this->line($p, 800)], ['purchase_date' => $this->ago(120)]);
        [, $v] = $this->call('GET', '/lookup', null, ['q' => 'arroz']);
        $this->assertCount(1, $v['items']);
        $bs = [];
        foreach ($v['items'][0]['by_supplier'] as $r) {
            $bs[$r['supplier_name']] = $r;
        }
        $this->assertEquals(1200, $bs['Lider']['latest_normalized_price']);
        $this->assertSame(2, $bs['Lider']['count_in_window']);
        $this->assertEquals(800, $bs['Jumbo']['latest_normalized_price']);
        $this->assertSame(0, $bs['Jumbo']['count_in_window']);
        $this->assertEquals(1000, $v['items'][0]['stats']['lowest_recent']['normalized_price']);
        $this->assertEquals(1200, $v['items'][0]['stats']['latest']['normalized_price']);
    }

    public function test_undated_only_in_series_and_void_ignored(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 900)], ['purchase_date' => $this->ago(3)]);
        $u = $this->make($s, [$this->line($p, 5000)], ['purchase_date' => $this->ago(2)]);
        self::$pdo->exec("UPDATE receipts SET purchase_date = NULL, date_unknown = 1 WHERE id = {$u['id']}");
        $w = $this->make($s, [$this->line($p, 100)], ['purchase_date' => $this->ago(1)]);
        $this->call('POST', "/receipts/{$w['id']}/void", []);
        [, $v] = $this->call('GET', "/products/$p");
        $this->assertEquals(900, $v['stats']['latest']['normalized_price']);
        $this->assertCount(2, $v['series']);
        $this->assertNull($v['series'][1]['purchase_date']);
        $this->assertCount(1, $v['by_supplier']);
    }

    public function test_lookup_empty_and_unknown(): void
    {
        [, $v] = $this->call('GET', '/lookup', null, ['q' => '']);
        $this->assertSame([], $v['items']);
        [$st] = $this->call('GET', '/products/99999');
        $this->assertSame(404, $st);
    }

    public function test_baseline_excluding_receipt_for_edit_mode(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-10']);
        $b = $this->make($s, [$this->line($p, 1200)], ['purchase_date' => '2026-01-10']);
        [, $v] = $this->call('GET', "/products/$p/baseline", null, ['date' => '2026-01-10']);
        $this->assertEquals(1200, $v['normalized_price']);
        [, $v] = $this->call('GET', "/products/$p/baseline", null, ['date' => '2026-01-10', 'receipt_id' => (string) $b['id']]);
        $this->assertEquals(1000, $v['normalized_price']);
        [, $v] = $this->call('GET', "/products/$p/baseline", null, ['date' => '2026-01-10', 'receipt_id' => (string) $a['id']]);
        $this->assertNull($v);
    }
}
