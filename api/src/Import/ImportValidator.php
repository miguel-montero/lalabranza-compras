<?php
declare(strict_types=1);

namespace KT\Import;

use KT\Catalog\CatalogRepo;
use KT\Receipts\ReceiptValidator;

/** Computes the per-row review flags for spreadsheet rows (spec section 8). */
final class ImportValidator
{
    public function __construct(private CatalogRepo $catalog)
    {
    }

    /**
     * @param array<int,array> $rows raw ImportRow objects
     * @return array<int,array> rows plus `index`, `flags`, `suggestions`
     */
    public function validate(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $row = is_array($row) ? $row : [];
            [$flags, $sugg] = $this->flagRow($row);
            $out[] = array_merge($row, ['index' => $i, 'flags' => $flags,
                'suggestions' => $sugg === [] ? new \stdClass() : $sugg]);
        }
        return $out;
    }

    /** @return array{0: string[], 1: array} */
    private function flagRow(array $r): array
    {
        $flags = [];
        $sugg = [];
        $supplierText = self::text($r['supplier'] ?? null);
        $productText = self::text($r['product'] ?? null);

        if (self::text($r['date'] ?? null) === '') {
            $flags[] = 'missing_date';
        }
        if (self::unit($r['unit'] ?? null) === null) {
            $flags[] = 'missing_unit';
        }
        $total = self::amount($r['total'] ?? null);
        $unitPrice = self::amount($r['unit_price'] ?? null);
        if ($total === null && $unitPrice === null) {
            $flags[] = 'missing_total';
        }
        if (self::text($r['receipt_number'] ?? null) === '') {
            $flags[] = 'missing_receipt_number';
        }

        $supplier = $supplierText === '' ? null : $this->catalog->findSupplierByName($supplierText);
        $product = $productText === '' ? null : $this->catalog->findProductByNameOrAlias($productText);
        if ($supplier === null) {
            $flags[] = 'unknown_supplier';
        } else {
            $sugg['supplier_id'] = (int) $supplier['id'];
            $sugg['supplier_name'] = $supplier['name'];
        }
        if ($product === null) {
            $flags[] = 'unknown_product';
        } else {
            $sugg['product_id'] = (int) $product['id'];
            $sugg['product_name'] = $product['name'];
            $sugg['product_base_unit'] = $product['base_unit'];
        }

        $swapProduct = $supplierText === '' ? null : $this->catalog->findProductByNameOrAlias($supplierText);
        $swapSupplier = $productText === '' ? null : $this->catalog->findSupplierByName($productText);
        if ($swapProduct !== null || $swapSupplier !== null) {
            $flags[] = 'possible_swap';
            $sugg['swapped'] = true;
        }

        $qty = ReceiptValidator::num($r['quantity'] ?? null);
        if ($qty === null || $qty <= 0) {
            $flags[] = 'invalid_quantity';
        }
        if ($unitPrice !== null && $total === null && $qty !== 1.0) {
            $flags[] = 'ambiguous_price';
        }
        return [$flags, $sugg];
    }

    private static function text(mixed $v): string
    {
        return is_string($v) || is_int($v) || is_float($v) ? trim((string) $v) : '';
    }

    private static function amount(mixed $v): ?float
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        return ReceiptValidator::num($v);
    }

    private static function unit(mixed $v): ?string
    {
        return is_string($v) && in_array($v, ReceiptValidator::UNITS, true) ? $v : null;
    }
}
