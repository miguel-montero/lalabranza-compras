<?php
declare(strict_types=1);

namespace KT\Tests;

final class SchemaTest extends DbTestCase
{
    public function test_helpers_and_alias_unique(): void
    {
        $s = $this->makeSupplier('Vega');
        $p = $this->makeProduct('Tomate', 'kg');
        $this->assertGreaterThan(0, $s);
        $st = self::$pdo->prepare('INSERT INTO product_aliases (product_id, alias) VALUES (?, ?)');
        $st->execute([$p, 'tomates']);
        $this->expectException(\PDOException::class);
        $st->execute([$p, 'tomates']);
    }

    public function test_receipt_idempotency_key_unique(): void
    {
        $s = $this->makeSupplier('Vega');
        $st = self::$pdo->prepare(
            "INSERT INTO receipts (supplier_id, purchase_date, doc_type, iva_rate, idempotency_key) VALUES (?, '2026-01-01', 'boleta', 0.19, ?)"
        );
        $key = '11111111-1111-4111-8111-111111111111';
        $st->execute([$s, $key]);
        $this->expectException(\PDOException::class);
        $st->execute([$s, $key]);
    }

    public function test_invalid_base_unit_rejected(): void
    {
        $this->expectException(\PDOException::class);
        $this->makeProduct('X', 'g');
    }
}
