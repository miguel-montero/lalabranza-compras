<?php
declare(strict_types=1);

namespace KT\Tests\Import;

use KT\Prices\PriceQueries;
use KT\Tests\ApiTestCase;

final class ImportTest extends ApiTestCase
{
    private function resolved(int $s, int $p, array $extra = []): array
    {
        return $extra + [
            'supplier_id' => $s, 'product_id' => $p, 'purchase_date' => '2026-01-15', 'date_unknown' => false,
            'doc_type' => 'boleta', 'receipt_number' => 'B1', 'quantity' => 1, 'entry_unit' => 'kg',
            'entered_amount' => 1000, 'accepted_flags' => [],
        ];
    }

    public function test_flags_sample_rows(): void
    {
        $this->makeSupplier('Vega Central');
        $this->makeProduct('Pimentón', 'kg');
        [$st, $v] = $this->call('POST', '/import/validate', ['rows' => [
            ['supplier' => 'Pimenton', 'product' => 'Vega central', 'quantity' => 1],
            ['product' => 'Tomate', 'quantity' => 3, 'unit_price' => 920],
            ['supplier' => 'Vega Central', 'product' => 'Ajo', 'unit' => null, 'total' => null],
        ]]);
        $this->assertSame(200, $st);
        $r = $v['rows'];
        $this->assertSame([0, 1, 2], array_column($r, 'index'));
        foreach (['possible_swap', 'missing_date', 'missing_total', 'missing_receipt_number'] as $f) {
            $this->assertContains($f, $r[0]['flags'], $f);
        }
        $this->assertTrue($r[0]['suggestions']['swapped']);
        $this->assertContains('ambiguous_price', $r[1]['flags']);
        $this->assertNotContains('missing_total', $r[1]['flags']);
        $this->assertContains('missing_unit', $r[2]['flags']);
        $this->assertContains('missing_total', $r[2]['flags']);
        $this->assertNotContains('unknown_supplier', $r[2]['flags']);
        $this->assertContains('unknown_product', $r[2]['flags']);
    }

    public function test_known_row_has_suggestions_and_no_flags(): void
    {
        $s = $this->makeSupplier('Vega Central');
        $p = $this->makeProduct('Pimentón', 'kg');
        [, $v] = $this->call('POST', '/import/validate', ['rows' => [[
            'date' => '2026-01-10', 'supplier' => 'vega central', 'product' => 'pimenton', 'quantity' => 2,
            'unit' => 'kg', 'total' => 2000, 'receipt_number' => '55',
        ]]]);
        $this->assertSame([], $v['rows'][0]['flags']);
        $this->assertSame($s, $v['rows'][0]['suggestions']['supplier_id']);
        $this->assertSame($p, $v['rows'][0]['suggestions']['product_id']);
    }

    public function test_commit_rejects_unaccepted_flags(): void
    {
        $s = $this->makeSupplier('V');
        $p = $this->makeProduct('P', 'kg');
        [$st, $v] = $this->call('POST', '/import/commit', ['rows' => [
            $this->resolved($s, $p, ['receipt_number' => null]),
        ]]);
        $this->assertSame(422, $st);
        $this->assertSame('unresolved_flags', $v['error']);
        $this->assertSame(['missing_receipt_number'], $v['rows'][0]['flags']);
        $count = self::$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn();
        $this->assertSame(0, (int) $count);

        [$st, $v] = $this->call('POST', '/import/commit', ['rows' => [
            $this->resolved($s, $p, ['receipt_number' => null, 'accepted_flags' => ['missing_receipt_number']]),
        ]]);
        $this->assertSame(200, $st, json_encode($v));
        $this->assertSame(['receipts_created' => 1, 'lines_created' => 1], $v);
    }

    public function test_factura_row_converted_to_net(): void
    {
        $s = $this->makeSupplier('V');
        $p = $this->makeProduct('P', 'kg');
        [$st] = $this->call('POST', '/import/commit', ['rows' => [
            $this->resolved($s, $p, ['doc_type' => 'factura', 'entered_amount' => 1190]),
        ]]);
        $this->assertSame(200, $st);
        $row = self::$pdo->query('SELECT i.entered_amount, i.real_cost, r.source, r.doc_type FROM receipt_items i JOIN receipts r ON r.id = i.receipt_id')->fetch();
        $this->assertSame(1000, (int) $row['entered_amount']);
        $this->assertSame(1000, (int) $row['real_cost']);
        $this->assertSame('import', $row['source']);
        $this->assertSame('factura', $row['doc_type']);
        $reason = self::$pdo->query("SELECT reason FROM audit_log WHERE action = 'create'")->fetchColumn();
        $this->assertSame('importación', $reason);
    }

    public function test_boleta_row_stored_as_given(): void
    {
        $s = $this->makeSupplier('V');
        $p = $this->makeProduct('P', 'kg');
        $this->call('POST', '/import/commit', ['rows' => [$this->resolved($s, $p, ['entered_amount' => 1190])]]);
        $row = self::$pdo->query('SELECT entered_amount, real_cost FROM receipt_items')->fetch();
        $this->assertSame(1190, (int) $row['entered_amount']);
        $this->assertSame(1190, (int) $row['real_cost']);
    }

    public function test_commit_groups_by_receipt_and_marks_undated(): void
    {
        $s = $this->makeSupplier('V');
        $p = $this->makeProduct('P', 'kg');
        $p2 = $this->makeProduct('Q', 'kg');
        [$st, $v] = $this->call('POST', '/import/commit', ['rows' => [
            $this->resolved($s, $p),
            $this->resolved($s, $p2, ['entered_amount' => 500]),
            $this->resolved($s, $p, ['purchase_date' => null, 'date_unknown' => true, 'receipt_number' => 'B9', 'entered_amount' => 99999]),
            $this->resolved($s, $p, ['receipt_number' => null, 'purchase_date' => '2026-02-01', 'accepted_flags' => ['missing_receipt_number']]),
            $this->resolved($s, $p, ['receipt_number' => null, 'purchase_date' => '2026-02-01', 'accepted_flags' => ['missing_receipt_number']]),
        ]]);
        $this->assertSame(200, $st, json_encode($v));
        $this->assertSame(['receipts_created' => 4, 'lines_created' => 5], $v);
        $n = self::$pdo->query("SELECT COUNT(*) FROM receipt_items i JOIN receipts r ON r.id = i.receipt_id WHERE r.receipt_number = 'B1'")->fetchColumn();
        $this->assertSame(2, (int) $n);
        $u = self::$pdo->query("SELECT date_unknown, purchase_date FROM receipts WHERE receipt_number = 'B9'")->fetch();
        $this->assertSame(1, (int) $u['date_unknown']);
        $this->assertNull($u['purchase_date']);
        $b = PriceQueries::baseline($p, '2030-01-01', null, null);
        $this->assertEquals(1000, $b['normalized_price']); // not 99999
    }

    public function test_commit_rejects_future_date_and_unknown_ids(): void
    {
        $s = $this->makeSupplier('V');
        $p = $this->makeProduct('P', 'kg');
        [$st] = $this->call('POST', '/import/commit', ['rows' => [$this->resolved($s, $p, ['purchase_date' => '2999-01-01'])]]);
        $this->assertSame(422, $st);
        [$st, $v] = $this->call('POST', '/import/commit', ['rows' => [$this->resolved($s, 999999)]]);
        $this->assertSame(422, $st);
        $this->assertContains('unknown_product', $v['rows'][0]['flags']);
        [$st] = $this->call('POST', '/import/commit', ['rows' => []]);
        $this->assertSame(422, $st);
    }

    public function test_commit_rejects_duplicate_receipt_number(): void
    {
        $s = $this->makeSupplier('V');
        $p = $this->makeProduct('P', 'kg');
        $this->call('POST', '/import/commit', ['rows' => [$this->resolved($s, $p)]]);
        [$st, $v] = $this->call('POST', '/import/commit', ['rows' => [$this->resolved($s, $p)]]);
        $this->assertSame(409, $st);
        $this->assertSame('duplicate_receipt_number', $v['error']);
    }
}
