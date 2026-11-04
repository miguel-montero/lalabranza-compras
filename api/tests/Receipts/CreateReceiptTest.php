<?php
declare(strict_types=1);

namespace KT\Tests\Receipts;

use KT\Auth\Auth;
use KT\Auth\Csrf;
use KT\Http\Request;
use KT\Http\Router;
use KT\Receipts\ReceiptService;
use KT\Tests\DbTestCase;

final class CreateReceiptTest extends DbTestCase
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        @ini_set('session.use_cookies', '0');
        @ini_set('session.cache_limiter', '');
        $this->endSession();
        session_id('ktsess' . bin2hex(random_bytes(8)));
        Auth::setPassword('correct horse battery');
        Auth::login('correct horse battery', '9.9.9.9');
        $this->token = Csrf::token();
    }

    protected function tearDown(): void
    {
        $this->endSession();
        parent::tearDown();
    }

    private function endSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        $router = new Router();
        (require __DIR__ . '/../../src/routes.php')($router);
        $res = $router->dispatch(new Request(
            $method,
            '/api' . $path,
            [],
            ['X-CSRF-Token' => $this->token],
            $body === null ? '' : json_encode($body)
        ));
        return [$res->status, json_decode($res->body, true)];
    }

    private function uuid(): string
    {
        $h = bin2hex(random_bytes(16));
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    private function line(int $product, int $amount, float $qty = 1, string $unit = 'kg', array $extra = []): array
    {
        return $extra + [
            'product_id' => $product, 'entry_mode' => 'total', 'quantity' => $qty,
            'entry_unit' => $unit, 'entered_amount' => $amount,
        ];
    }

    private function receipt(int $supplier, array $items, array $extra = []): array
    {
        return $extra + [
            'idempotency_key' => $this->uuid(), 'supplier_id' => $supplier,
            'purchase_date' => '2026-01-15', 'doc_type' => 'boleta', 'items' => $items,
        ];
    }

    private function rows(string $table): int
    {
        return (int) self::$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    public function test_create_three_items_persists_once(): void
    {
        $s = $this->makeSupplier('Lider');
        $a = $this->makeProduct('Arroz', 'kg');
        $b = $this->makeProduct('Aceite', 'L');
        $c = $this->makeProduct('Huevos', 'unidad');
        $body = $this->receipt($s, [
            $this->line($a, 2000, 2),
            $this->line($b, 3000, 1500, 'mL'),
            $this->line($c, 4000, 30, 'unidad'),
        ]);
        [$st, $v] = $this->call('POST', '/receipts', $body);
        $this->assertSame(201, $st);
        $this->assertSame(3, $this->rows('receipt_items'));
        $this->assertCount(3, $v['items']);
        $this->assertSame(1.5, $v['items'][1]['base_quantity']);
        $this->assertSame(9000, $v['totals']['total']);

        [$st2, $v2] = $this->call('POST', '/receipts', $body);
        $this->assertSame(200, $st2);
        $this->assertSame($v['id'], $v2['id']);
        $this->assertSame(3, $this->rows('receipt_items'));
        $this->assertSame(1, $this->rows('receipts'));
    }

    public function test_receipt_number_leading_zeros_kept(): void
    {
        $s = $this->makeSupplier('Lider');
        $a = $this->makeProduct('Arroz', 'kg');
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['receipt_number' => '000123']));
        $this->assertSame(201, $st);
        $this->assertSame('000123', $v['receipt_number']);
    }

    public function test_factura_net_entry(): void
    {
        $s = $this->makeSupplier('Mayorista');
        $a = $this->makeProduct('Arroz', 'kg');
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['doc_type' => 'factura']));
        $this->assertSame(201, $st);
        $i = $v['items'][0];
        $this->assertSame(1000, $i['real_cost']);
        $this->assertSame(1190, $i['amount_paid']);
        $this->assertEquals(1000.0, $i['normalized_price']); // JSON has no int/float distinction
        $this->assertSame(['neto' => 1000, 'exento' => 0, 'iva' => 190, 'total' => 1190], $v['totals']);
        $this->assertSame(0.19, $v['iva_rate']);
    }

    public function test_discount_spread_and_delivery_separate(): void
    {
        $s = $this->makeSupplier('Lider');
        $a = $this->makeProduct('Arroz', 'kg');
        $b = $this->makeProduct('Azucar', 'kg');
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt(
            $s,
            [$this->line($a, 1000), $this->line($b, 3000)],
            ['adjustment_amount' => -400, 'delivery_amount' => 2000]
        ));
        $this->assertSame(201, $st);
        $this->assertSame(900, $v['items'][0]['real_cost']);
        $this->assertSame(2700, $v['items'][1]['real_cost']);
        $this->assertSame(-100, $v['items'][0]['adjustment_share']);
        $this->assertSame(5600, $v['totals']['total']);
    }

    public function test_validation_errors(): void
    {
        $s = $this->makeSupplier('Lider');
        $oil = $this->makeProduct('Aceite', 'L');
        $a = $this->makeProduct('Arroz', 'kg');
        $body = $this->receipt(999999, [
            $this->line($a, 1000, -1),
            $this->line($oil, 1000, 2, 'kg'), // mass unit on a volume product: still rejected
        ]);
        [$st, $r] = $this->call('POST', '/receipts', $body);
        $this->assertSame(422, $st);
        $this->assertSame('validation', $r['error']);
        $this->assertArrayHasKey('supplier_id', $r['fields']);
        $this->assertArrayHasKey('items.0.quantity', $r['fields']);
        $this->assertArrayHasKey('items.1.entry_unit', $r['fields']);
        $this->assertSame(0, $this->rows('receipts'));
        $this->assertSame(0, $this->rows('receipt_items'));
    }

    public function test_validation_misc(): void
    {
        $s = $this->makeSupplier('Lider');
        $a = $this->makeProduct('Arroz', 'kg');
        [$st, $r] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], [
            'purchase_date' => '2999-01-01', 'doc_type' => 'ticket', 'receipt_number' => str_repeat('9', 41),
        ]));
        $this->assertSame(422, $st);
        $this->assertArrayHasKey('purchase_date', $r['fields']);
        $this->assertArrayHasKey('doc_type', $r['fields']);
        $this->assertArrayHasKey('receipt_number', $r['fields']);

        [$st, $r] = $this->call('POST', '/receipts', $this->receipt($s, []));
        $this->assertSame(422, $st);
        $this->assertArrayHasKey('items', $r['fields']);

        [$st, $r] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 0)]));
        $this->assertSame(422, $st);
        $this->assertArrayHasKey('items.0.entered_amount', $r['fields']);
    }

    public function test_inactive_supplier_rejected(): void
    {
        $s = $this->makeSupplier('Viejo');
        self::$pdo->exec("UPDATE suppliers SET active = 0 WHERE id = $s");
        $a = $this->makeProduct('Arroz', 'kg');
        [$st, $r] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)]));
        $this->assertSame(422, $st);
        $this->assertArrayHasKey('supplier_id', $r['fields']);
    }

    public function test_package_makes_unit_compatible(): void
    {
        $s = $this->makeSupplier('Lider');
        $oil = $this->makeProduct('Aceite', 'L');
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [[
            'product_id' => $oil, 'entry_mode' => 'unit_price', 'quantity' => 6, 'entry_unit' => 'unidad',
            'package_count' => 6, 'package_content' => 900, 'package_unit' => 'mL', 'unit_price' => 1500,
        ]]));
        $this->assertSame(201, $st);
        $this->assertSame(9000, $v['items'][0]['entered_amount']);
        $this->assertSame(5.4, $v['items'][0]['base_quantity']);
        $this->assertSame(1666.6667, $v['items'][0]['normalized_price']);
    }

    public function test_adjustment_too_large(): void
    {
        $s = $this->makeSupplier('Lider');
        $a = $this->makeProduct('Arroz', 'kg');
        [$st, $r] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['adjustment_amount' => -1500]));
        $this->assertSame(422, $st);
        $this->assertSame('adjustment_too_large', $r['fields']['adjustment_amount']);
        $this->assertSame(0, $this->rows('receipts'));
    }

    public function test_duplicate_number_requires_confirm(): void
    {
        $s = $this->makeSupplier('Lider');
        $a = $this->makeProduct('Arroz', 'kg');
        [, $first] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['receipt_number' => '45']));
        [$st, $r] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['receipt_number' => '45']));
        $this->assertSame(409, $st);
        $this->assertSame('duplicate_receipt_number', $r['error']);
        $this->assertSame($first['id'], $r['existing_id']);
        $this->assertSame(1, $this->rows('receipts'));

        [$st] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['receipt_number' => '45', 'confirm_duplicate' => true]));
        $this->assertSame(201, $st);

        [$st] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['receipt_number' => '']));
        $this->assertSame(201, $st);
        [$st] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['receipt_number' => '']));
        $this->assertSame(201, $st);
    }

    public function test_duplicate_ignores_void_and_other_supplier(): void
    {
        $s = $this->makeSupplier('Lider');
        $s2 = $this->makeSupplier('Jumbo');
        $a = $this->makeProduct('Arroz', 'kg');
        [, $first] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['receipt_number' => '45']));
        [$st] = $this->call('POST', '/receipts', $this->receipt($s2, [$this->line($a, 1000)], ['receipt_number' => '45']));
        $this->assertSame(201, $st);
        self::$pdo->exec("UPDATE receipts SET status = 'void' WHERE id = " . (int) $first['id']);
        [$st] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['receipt_number' => '45']));
        $this->assertSame(201, $st);
    }

    public function test_printed_total_mismatch_flag(): void
    {
        $s = $this->makeSupplier('Lider');
        $a = $this->makeProduct('Arroz', 'kg');
        [, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['printed_total' => 1000]));
        $this->assertFalse($v['printed_total_mismatch']);
        [, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)], ['printed_total' => 1100]));
        $this->assertTrue($v['printed_total_mismatch']);
        [, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)]));
        $this->assertFalse($v['printed_total_mismatch']);
        $this->assertNull($v['printed_total']);
    }

    public function test_audit_create_written(): void
    {
        $s = $this->makeSupplier('Lider');
        $a = $this->makeProduct('Arroz', 'kg');
        [, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($a, 1000)]));
        $row = self::$pdo->query("SELECT * FROM audit_log WHERE entity = 'receipt' AND entity_id = " . (int) $v['id'])->fetch();
        $this->assertSame('create', $row['action']);
        $this->assertNull($row['before_json']);
        $this->assertSame($v['id'], json_decode($row['after_json'], true)['id']);
    }

    public function test_compute_is_pure_and_ignores_client_values(): void
    {
        $r = ReceiptService::compute([
            'doc_type' => 'boleta', 'adjustment_amount' => -400, 'delivery_amount' => 2000,
            'items' => [
                ['product_id' => 1, 'entry_mode' => 'total', 'quantity' => 1, 'entry_unit' => 'kg', 'entered_amount' => 1000, 'real_cost' => 5],
                ['product_id' => 2, 'entry_mode' => 'total', 'quantity' => 1, 'entry_unit' => 'kg', 'entered_amount' => 3000],
            ],
        ], [1 => 'kg', 2 => 'kg'], 0.19);
        $this->assertSame(900, $r['items'][0]['real_cost']);
        $this->assertSame(5600, $r['totals']['total']);
    }

    public function test_requires_auth(): void
    {
        $this->endSession();
        session_id('ktsess' . bin2hex(random_bytes(8)));
        $router = new Router();
        (require __DIR__ . '/../../src/routes.php')($router);
        $res = $router->dispatch(new Request('POST', '/api/receipts', [], [], '{}'));
        $this->assertSame(401, $res->status);
    }

    /** Spec 5.1 / AT-04: a kg/L product entered in `unidad` without net content is saved but not comparable. */
    public function test_mass_product_in_unidad_without_content_is_saved_non_comparable(): void
    {
        $s = $this->makeSupplier('Lider');
        $arroz = $this->makeProduct('Arroz', 'kg');
        $oil = $this->makeProduct('Aceite', 'L');
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [
            $this->line($arroz, 1000, 2, 'unidad'),
            $this->line($oil, 3000, 1, 'unidad'),
        ], ['purchase_date' => '2026-01-10']));
        $this->assertSame(201, $st, json_encode($v));
        foreach ($v['items'] as $it) {
            $this->assertSame('unidad', $it['entry_unit']);
            $this->assertNull($it['base_quantity']);
            $this->assertNull($it['normalized_price']);
        }
        // A later comparable purchase finds no baseline: the unidad line is never one.
        [$st, $w] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($arroz, 1200, 1)], ['purchase_date' => '2026-01-20']));
        $this->assertSame(201, $st);
        [, $d] = $this->call('GET', "/receipts/{$w['id']}");
        $this->assertSame('none', $d['items'][0]['comparison']['status']);
        [, $d0] = $this->call('GET', "/receipts/{$v['id']}");
        $this->assertSame('none', $d0['items'][0]['comparison']['status']);
        // Package content in `unidad` for a kg product is still rejected (no usable net content given).
        [$st, $r] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($arroz, 1000, 2, 'unidad', [
            'package_count' => 2, 'package_content' => 3, 'package_unit' => 'unidad',
        ])]));
        $this->assertSame(422, $st);
        $this->assertArrayHasKey('items.0.entry_unit', $r['fields']);
    }

    /** 0.125 g for $5.000 is stored without loss and priced from the unrounded quantity. */
    public function test_tiny_quantity_priced_from_unrounded_base_quantity(): void
    {
        $s = $this->makeSupplier('Lider');
        $azafran = $this->makeProduct('Azafrán', 'kg');
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($azafran, 5000, 0.125, 'g')]));
        $this->assertSame(201, $st, json_encode($v));
        $this->assertSame(0.125, $v['items'][0]['quantity']);
        $this->assertSame(0.000125, $v['items'][0]['base_quantity']);
        $this->assertEquals(40000000, $v['items'][0]['normalized_price']);
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($azafran, 5000, 0.04, 'g')]));
        $this->assertSame(201, $st, json_encode($v));
        $this->assertEquals(125000000, $v['items'][0]['normalized_price']);
    }

    public function test_price_beyond_column_range_is_422(): void
    {
        $s = $this->makeSupplier('Lider');
        $p = $this->makeProduct('Azafrán', 'kg');
        [$st, $r] = $this->call('POST', '/receipts', $this->receipt($s, [$this->line($p, 5000, 0.0001, 'g')]));
        $this->assertSame(422, $st);
        $this->assertArrayHasKey('items.0.quantity', $r['fields']);
        $this->assertSame(0, $this->rows('receipts'));
    }
}
