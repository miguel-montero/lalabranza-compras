<?php
declare(strict_types=1);

namespace KT\Tests\Receipts;

use KT\Tests\ApiTestCase;

final class EditVoidTest extends ApiTestCase
{
    private function get(int $id): array
    {
        [$st, $v] = $this->call('GET', "/receipts/$id");
        $this->assertSame(200, $st);
        return $v;
    }

    private function input(array $view, array $items, array $extra = []): array
    {
        return $extra + [
            'supplier_id' => $view['supplier']['id'], 'purchase_date' => $view['purchase_date'],
            'doc_type' => $view['doc_type'], 'items' => $items,
        ];
    }

    public function test_get_missing_404(): void
    {
        [$st] = $this->call('GET', '/receipts/99999');
        $this->assertSame(404, $st);
    }

    public function test_edit_writes_audit_and_recalculates(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)]);
        [$st, $v] = $this->call('PUT', "/receipts/{$a['id']}", $this->input($a, [$this->line($p, 1500)], ['reason' => 'precio mal']));
        $this->assertSame(200, $st, json_encode($v));
        $this->assertSame(1500, $v['items'][0]['real_cost']);
        $g = $this->get($a['id']);
        $this->assertSame(1500, $g['items'][0]['real_cost']);
        $this->assertCount(1, $g['items']);
        $upd = array_values(array_filter($g['audit'], fn($e) => $e['action'] === 'update'));
        $this->assertCount(1, $upd);
        $this->assertSame('precio mal', $upd[0]['reason']);
        $this->assertSame(1000, $upd[0]['before']['items'][0]['real_cost']);
        $this->assertSame(1500, $upd[0]['after']['items'][0]['real_cost']);
        $this->assertArrayHasKey('created_at', $upd[0]);
        $this->assertSame('create', $g['audit'][0]['action'] === 'create' ? 'create' : $g['audit'][count($g['audit']) - 1]['action']);
    }

    public function test_edit_keeps_iva_snapshot(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['doc_type' => 'factura']);
        self::$pdo->exec("UPDATE receipts SET iva_rate = 0.1500 WHERE id = {$a['id']}");
        [$st, $v] = $this->call('PUT', "/receipts/{$a['id']}", $this->input($a, [$this->line($p, 2000)]));
        $this->assertSame(200, $st);
        $this->assertSame(0.15, $v['iva_rate']);
        $this->assertSame(2300, $v['items'][0]['amount_paid']);
    }

    public function test_edit_date_reorders_baseline(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-10']);
        $b = $this->make($s, [$this->line($p, 1200)], ['purchase_date' => '2026-02-10']);
        $this->assertEquals(1000, $this->get($b['id'])['items'][0]['comparison']['ref']['normalized_price']);
        [$st] = $this->call('PUT', "/receipts/{$a['id']}", $this->input($a, [$this->line($p, 1000)], ['purchase_date' => '2026-03-10']));
        $this->assertSame(200, $st);
        $this->assertSame('none', $this->get($b['id'])['items'][0]['comparison']['status']);
        $ca = $this->get($a['id'])['items'][0]['comparison'];
        $this->assertEquals(1200, $ca['ref']['normalized_price']);
        $this->assertSame('down', $ca['status']);
    }

    public function test_edit_validates_and_rejects_future_date(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)]);
        [$st, $v] = $this->call('PUT', "/receipts/{$a['id']}", $this->input($a, [$this->line($p, 1000)], ['purchase_date' => '2999-01-01']));
        $this->assertSame(422, $st);
        $this->assertArrayHasKey('purchase_date', $v['fields']);
        $this->assertSame(1000, $this->get($a['id'])['items'][0]['real_cost']);
    }

    public function test_edit_duplicate_number_excludes_self(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['receipt_number' => '77']);
        $b = $this->make($s, [$this->line($p, 1000)], ['receipt_number' => '78']);
        [$st] = $this->call('PUT', "/receipts/{$a['id']}", $this->input($a, [$this->line($p, 1100)], ['receipt_number' => '77']));
        $this->assertSame(200, $st);
        [$st, $v] = $this->call('PUT', "/receipts/{$b['id']}", $this->input($b, [$this->line($p, 1100)], ['receipt_number' => '77']));
        $this->assertSame(409, $st);
        $this->assertSame('duplicate_receipt_number', $v['error']);
        $this->assertSame($a['id'], $v['existing_id']);
        [$st] = $this->call('PUT', "/receipts/{$b['id']}", $this->input($b, [$this->line($p, 1100)], ['receipt_number' => '77', 'confirm_duplicate' => true]));
        $this->assertSame(200, $st);
    }

    public function test_void_then_edit_409_and_audit(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)]);
        [$st, $v] = $this->call('POST', "/receipts/{$a['id']}/void", ['reason' => 'duplicada']);
        $this->assertSame(200, $st);
        $this->assertSame('void', $v['status']);
        [$st, $v] = $this->call('PUT', "/receipts/{$a['id']}", $this->input($a, [$this->line($p, 1000)]));
        $this->assertSame(409, $st);
        $this->assertSame('receipt_void', $v['error']);
        [$st, $v] = $this->call('POST', "/receipts/{$a['id']}/void", []);
        $this->assertSame(409, $st);
        $g = $this->get($a['id']);
        $voids = array_values(array_filter($g['audit'], fn($e) => $e['action'] === 'void'));
        $this->assertCount(1, $voids);
        $this->assertSame('duplicada', $voids[0]['reason']);
        $this->assertSame('active', $voids[0]['before']['status']);
        $this->assertSame('void', $voids[0]['after']['status']);
        [$st] = $this->call('POST', '/receipts/99999/void', []);
        $this->assertSame(404, $st);
    }

    public function test_list_filters_paging_and_flags(): void
    {
        $s = $this->makeSupplier('Lider');
        $s2 = $this->makeSupplier('Jumbo');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)], ['purchase_date' => '2026-01-01', 'receipt_number' => 'A1']);
        $b = $this->make($s2, [$this->line($p, 1500)], ['purchase_date' => '2026-01-02']);
        $c = $this->make($s, [$this->line($p, 900)], ['purchase_date' => '2026-01-03']);
        $this->call('POST', "/receipts/{$c['id']}/void", []);
        [$st, $l] = $this->call('GET', '/receipts');
        $this->assertSame(200, $st);
        $this->assertSame(2, $l['total']);
        $this->assertSame([$b['id'], $a['id']], array_column($l['items'], 'id'));
        $row = $l['items'][0];
        $this->assertSame('Jumbo', $row['supplier_name']);
        $this->assertSame(1500, $row['total']);
        $this->assertSame(1, $row['flagged_count']);
        $this->assertSame(0, $l['items'][1]['flagged_count']);
        $this->assertSame(['id', 'purchase_date', 'date_unknown', 'supplier_name', 'doc_type', 'receipt_number', 'total', 'status', 'flagged_count'], array_keys($row));
        [, $l] = $this->call('GET', '/receipts', null, ['status' => 'all']);
        $this->assertSame(3, $l['total']);
        $this->assertSame($c['id'], $l['items'][0]['id']);
        [, $l] = $this->call('GET', '/receipts', null, ['status' => 'void']);
        $this->assertSame(1, $l['total']);
        [, $l] = $this->call('GET', '/receipts', null, ['supplier_id' => (string) $s]);
        $this->assertSame(1, $l['total']);
        [, $l] = $this->call('GET', '/receipts', null, ['from' => '2026-01-02', 'to' => '2026-01-02']);
        $this->assertSame([$b['id']], array_column($l['items'], 'id'));
        [, $l] = $this->call('GET', '/receipts', null, ['receipt_number' => 'A1']);
        $this->assertSame([$a['id']], array_column($l['items'], 'id'));
        [, $l] = $this->call('GET', '/receipts', null, ['page' => '2']);
        $this->assertSame([], $l['items']);
        $this->assertSame(2, $l['total']);
    }

    // Carried from Task 5 review.
    public function test_unit_price_rounding_to_zero_is_422(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $line = ['product_id' => $p, 'entry_mode' => 'unit_price', 'quantity' => 0.4, 'entry_unit' => 'kg', 'unit_price' => 1];
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$line]));
        $this->assertSame(422, $st);
        $this->assertArrayHasKey('items.0.unit_price', $v['fields']);
    }

    public function test_iva_exempt_must_be_boolean(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        foreach (['false', 'yes', 2, [], null] as $bad) {
            [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($p, 1000, 1, 'kg', ['iva_exempt' => $bad])], ['doc_type' => 'factura']));
            $this->assertSame(422, $st, var_export($bad, true));
            $this->assertArrayHasKey('items.0.iva_exempt', $v['fields']);
        }
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($p, 1000, 1, 'kg', ['iva_exempt' => true])], ['doc_type' => 'factura']));
        $this->assertSame(201, $st);
        $this->assertTrue($v['items'][0]['iva_exempt']);
        $this->assertSame(1000, $v['items'][0]['amount_paid']);
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($p, 1000, 1, 'kg', ['iva_exempt' => 0])], ['doc_type' => 'factura']));
        $this->assertSame(201, $st);
        $this->assertFalse($v['items'][0]['iva_exempt']);
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($p, 1000, 1, 'kg', ['iva_exempt' => 1])], ['doc_type' => 'factura']));
        $this->assertTrue($v['items'][0]['iva_exempt']);
    }

    public function test_edit_allowed_after_supplier_and_product_deactivated(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Arroz', 'kg');
        $a = $this->make($s, [$this->line($p, 1000)]);
        self::$pdo->exec("UPDATE suppliers SET active = 0 WHERE id = $s");
        self::$pdo->exec("UPDATE products SET active = 0 WHERE id = $p");
        [$st, $v] = $this->call('PUT', "/receipts/{$a['id']}", $this->input($a, [$this->line($p, 1100)]));
        $this->assertSame(200, $st, json_encode($v));
        $this->assertSame(1100, $v['items'][0]['real_cost']);
        // a newly referenced inactive supplier/product is still rejected
        $p2 = $this->makeProduct('Azucar', 'kg');
        self::$pdo->exec("UPDATE products SET active = 0 WHERE id = $p2");
        [$st] = $this->call('PUT', "/receipts/{$a['id']}", $this->input($a, [$this->line($p2, 1100)]));
        $this->assertSame(422, $st);
        $s2 = $this->makeSupplier('Jumbo');
        self::$pdo->exec("UPDATE suppliers SET active = 0 WHERE id = $s2");
        $in = $this->input($a, [$this->line($p, 1100)]);
        $in['supplier_id'] = $s2;
        [$st] = $this->call('PUT', "/receipts/{$a['id']}", $in);
        $this->assertSame(422, $st);
    }
}
