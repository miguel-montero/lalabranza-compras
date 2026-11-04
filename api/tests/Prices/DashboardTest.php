<?php
declare(strict_types=1);

namespace KT\Tests\Prices;

use KT\Tests\ApiTestCase;

final class DashboardTest extends ApiTestCase
{
    public function test_spend_includes_delivery_and_iva(): void
    {
        $s = $this->makeSupplier('Mayorista');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($s, [$this->line($p, 1000)], ['doc_type' => 'factura', 'delivery_amount' => 2000, 'purchase_date' => '2026-01-10']);
        [$st, $d] = $this->call('GET', '/dashboard', null, ['from' => '2026-01-01', 'to' => '2026-01-31']);
        $this->assertSame(200, $st, json_encode($d));
        $this->assertSame(3000, $d['spend']['total_real']);
        $this->assertSame(3570, $d['spend']['total_paid']);
        $cats = [];
        foreach ($d['spend']['by_category'] as $c) {
            $cats[$c['name']] = $c;
        }
        $this->assertSame(2000, $cats['Despacho']['total_real']);
        $this->assertSame(2380, $cats['Despacho']['total_paid']);
        $this->assertSame(1000, $cats['Sin categoría']['total_real']);
        $this->assertSame('Mayorista', $d['spend']['by_supplier'][0]['name']);
        $this->assertSame(3570, $d['spend']['by_supplier'][0]['total_paid']);
    }

    public function test_flagged_recent_range_and_void(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $this->make($s, [$this->line($p, 1300)], ['purchase_date' => '2026-01-10']);
        $this->make($s, [$this->line($p, 1500)], ['purchase_date' => '2026-02-10']);
        $v = $this->make($s, [$this->line($p, 9000)], ['purchase_date' => '2026-02-20']);
        $this->call('POST', "/receipts/{$v['id']}/void", []);

        [, $d] = $this->call('GET', '/dashboard', null, ['from' => '2026-02-01', 'to' => '2026-02-28']);
        $this->assertCount(1, $d['flagged']);
        $this->assertEquals(15.38, $d['flagged'][0]['comparison']['pct']);
        $this->assertSame(1500, $d['spend']['total_paid']);
        $this->assertCount(2, $d['recent_receipts']);

        [, $d] = $this->call('GET', '/dashboard', null, ['from' => '2026-01-01', 'to' => '2026-01-31']);
        $this->assertSame([], $d['flagged']);
    }

    public function test_default_range_is_current_month(): void
    {
        [$st, $d] = $this->call('GET', '/dashboard');
        $this->assertSame(200, $st);
        $this->assertSame(0, $d['spend']['total_paid']);
        $this->assertSame(date('Y-m') . '-01', substr($d['range']['from'], 0, 10));
    }
}
