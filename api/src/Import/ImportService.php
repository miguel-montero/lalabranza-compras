<?php
declare(strict_types=1);

namespace KT\Import;

use KT\Audit;
use KT\Db;
use KT\Http\HttpError;
use KT\Receipts\ReceiptService;
use KT\Receipts\ReceiptValidator;
use KT\Settings;

final class ImportService
{
    /** Flags a user may knowingly accept; every other flag must be fixed. */
    private const ACCEPTABLE = ['missing_receipt_number', 'duplicate_receipt_number'];

    /** @return array{receipts_created:int, lines_created:int} */
    public function commit(array $rows): array
    {
        if (!array_is_list($rows) || $rows === []) {
            throw new HttpError(422, 'validation', ['fields' => ['rows' => 'no hay filas para importar']]);
        }
        $pdo = Db::pdo();
        $iva = (float) Settings::get('iva_rate');

        // 1. Re-validate every row.
        $bad = [];
        $clean = [];
        foreach ($rows as $i => $row) {
            $row = is_array($row) ? $row : [];
            $flags = $this->unresolved($row);
            if ($flags) {
                $bad[] = ['index' => $i, 'flags' => $flags];
            }
            $clean[$i] = $row;
        }
        if ($bad) {
            throw new HttpError(422, 'unresolved_flags', ['rows' => $bad]);
        }

        // 2. Group into receipts.
        $groups = [];
        foreach ($clean as $i => $row) {
            $unknown = !empty($row['date_unknown']);
            $date = $unknown ? null : (string) $row['purchase_date'];
            $number = trim((string) ($row['receipt_number'] ?? ''));
            $number = $number === '' ? null : $number;
            $key = ($number !== null && $date !== null)
                ? ((int) $row['supplier_id']) . "\0" . $number . "\0" . $date . "\0" . $row['doc_type']
                : 'row' . $i;
            $groups[$key] ??= ['supplier_id' => (int) $row['supplier_id'], 'date' => $date, 'number' => $number,
                'doc_type' => (string) $row['doc_type'], 'rows' => []];
            $groups[$key]['rows'][$i] = $row;
        }

        $svc = new ReceiptService();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $receipts = 0;
            $lines = 0;
            foreach ($groups as $g) {
                $items = [];
                foreach ($g['rows'] as $row) {
                    $amount = (int) ReceiptValidator::int($row['entered_amount']);
                    if ($g['doc_type'] === 'factura') {
                        $amount = (int) round($amount / (1 + $iva)); // spreadsheet amounts are IVA-included
                    }
                    $items[] = [
                        'product_id' => (int) $row['product_id'], 'entry_mode' => 'total',
                        'quantity' => $row['quantity'], 'entry_unit' => $row['entry_unit'],
                        'package_count' => $row['package_count'] ?? null,
                        'package_content' => $row['package_content'] ?? null,
                        'package_unit' => $row['package_unit'] ?? null,
                        'entered_amount' => $amount,
                    ];
                }
                $input = ['supplier_id' => $g['supplier_id'], 'purchase_date' => $g['date'] ?? ReceiptValidator::today(),
                    'doc_type' => $g['doc_type'], 'receipt_number' => $g['number'], 'items' => $items];
                try {
                    ReceiptValidator::validate($input);
                } catch (HttpError $e) {
                    throw new HttpError($e->status, $e->errorCode, $e->extra + ['rows' => array_keys($g['rows'])]);
                }
                $calc = ReceiptService::compute($input, $svc->baseUnits($items), $iva);
                $pdo->prepare(
                    "INSERT INTO receipts (supplier_id, purchase_date, date_unknown, doc_type, receipt_number, iva_rate,
                        adjustment_amount, delivery_amount, source) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 'import')"
                )->execute([$g['supplier_id'], $g['date'], $g['date'] === null ? 1 : 0, $g['doc_type'], $g['number'], $iva]);
                $id = (int) $pdo->lastInsertId();
                $svc->insertItems($id, $calc['items']);
                Audit::record('receipt', $id, 'create', null, $svc->view($id), 'importación');
                $receipts++;
                $lines += count($items);
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['receipts_created' => $receipts, 'lines_created' => $lines];
    }

    /** @return string[] flags that are neither resolved by the row's content nor acceptable-and-accepted */
    private function unresolved(array $row): array
    {
        $f = [];
        $pdo = Db::pdo();
        $exists = static function (string $table, mixed $id) use ($pdo): bool {
            $id = ReceiptValidator::int($id);
            if ($id === null || $id <= 0) {
                return false;
            }
            $st = $pdo->prepare("SELECT 1 FROM $table WHERE id = ?");
            $st->execute([$id]);
            return $st->fetchColumn() !== false;
        };
        if (!$exists('suppliers', $row['supplier_id'] ?? null)) {
            $f[] = 'unknown_supplier';
        }
        if (!$exists('products', $row['product_id'] ?? null)) {
            $f[] = 'unknown_product';
        }
        if (empty($row['date_unknown']) && (!isset($row['purchase_date']) || !is_string($row['purchase_date']) || $row['purchase_date'] === '')) {
            $f[] = 'missing_date';
        }
        if (!is_string($row['entry_unit'] ?? null) || !in_array($row['entry_unit'], ReceiptValidator::UNITS, true)) {
            $f[] = 'missing_unit';
        }
        $amt = ReceiptValidator::int($row['entered_amount'] ?? null);
        if ($amt === null || $amt <= 0) {
            $f[] = 'missing_total';
        }
        $qty = ReceiptValidator::num($row['quantity'] ?? null);
        if ($qty === null || $qty <= 0) {
            $f[] = 'invalid_quantity';
        }
        if (!is_string($row['doc_type'] ?? null) || !in_array($row['doc_type'], ReceiptValidator::DOC_TYPES, true)) {
            $f[] = 'invalid_doc_type';
        }
        $accepted = is_array($row['accepted_flags'] ?? null) ? $row['accepted_flags'] : [];
        $ok = static fn(string $flag): bool => in_array($flag, $accepted, true) && in_array($flag, self::ACCEPTABLE, true);
        $num = $row['receipt_number'] ?? null;
        if (!(is_string($num) && trim($num) !== '')) {
            if (!$ok('missing_receipt_number')) {
                $f[] = 'missing_receipt_number';
            }
        } elseif (!in_array('unknown_supplier', $f, true)
            && ImportValidator::receiptExists((int) $row['supplier_id'], trim($num)) && !$ok('duplicate_receipt_number')) {
            $f[] = 'duplicate_receipt_number';
        }
        return $f;
    }
}
