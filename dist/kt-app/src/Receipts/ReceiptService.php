<?php
declare(strict_types=1);

namespace KT\Receipts;

use KT\Audit;
use KT\Calc\Allocation;
use KT\Calc\LineCalc;
use KT\Db;
use KT\Http\HttpError;
use KT\Prices\PriceQueries;
use KT\Settings;
use PDOException;

final class ReceiptService
{
    /** Decimal places of receipt_items.base_quantity (DECIMAL(16,6), migration 003). */
    public const BASE_QTY_DP = 6;

    /**
     * Pure calculation (no DB). Order: enteredAmount -> spread(adjustment) -> costs -> baseQuantity
     * -> normalizedPrice -> receiptTotals. Client-sent computed values are ignored.
     *
     * @param array<int,string> $baseUnitsById product id => 'kg'|'L'|'unidad'
     * @return array{items: array<int,array>, totals: array{neto:int,exento:int,iva:int,total:int}}
     */
    public static function compute(array $input, array $baseUnitsById, float $ivaRate): array
    {
        $docType = (string) ($input['doc_type'] ?? 'boleta');
        $adjustment = (int) ($input['adjustment_amount'] ?? 0);
        $delivery = (int) ($input['delivery_amount'] ?? 0);
        $lines = array_values($input['items'] ?? []);

        $entered = [];
        foreach ($lines as $l) {
            $entered[] = LineCalc::enteredAmount($l);
        }
        $shares = Allocation::spread($entered, $adjustment);

        $out = [];
        foreach ($lines as $i => $l) {
            $exempt = !empty($l['iva_exempt']);
            $costs = LineCalc::costs($entered[$i] + $shares[$i], $docType, $exempt, $ivaRate);
            $baseUnit = $baseUnitsById[(int) $l['product_id']] ?? null;
            // The price uses the unrounded quantity (as the TS preview does); only the stored value is rounded.
            $baseQty = $baseUnit === null ? null : LineCalc::baseQuantity($l, $baseUnit);
            $out[] = [
                'product_id' => (int) $l['product_id'],
                'entry_mode' => $l['entry_mode'],
                'quantity' => (float) $l['quantity'],
                'entry_unit' => $l['entry_unit'],
                'package_count' => isset($l['package_count']) ? (float) $l['package_count'] : null,
                'package_content' => isset($l['package_content']) ? (float) $l['package_content'] : null,
                'package_unit' => $l['package_unit'] ?? null,
                'unit_price' => ($l['entry_mode'] === 'unit_price') ? (int) $l['unit_price'] : null,
                'iva_exempt' => $exempt,
                'notes' => $l['notes'] ?? null,
                'entered_amount' => $entered[$i],
                'adjustment_share' => $shares[$i],
                'real_cost' => $costs['real_cost'],
                'amount_paid' => $costs['amount_paid'],
                'base_quantity' => $baseQty === null ? null : round($baseQty, self::BASE_QTY_DP),
                'normalized_price' => LineCalc::normalizedPrice($costs['real_cost'], $baseQty),
            ];
        }
        return ['items' => $out, 'totals' => LineCalc::receiptTotals($out, $delivery, $docType, $ivaRate)];
    }

    /** @return array{0: array, 1: bool} [ReceiptView, created] */
    public function create(array $input): array
    {
        $pdo = Db::pdo();
        $key = $input['idempotency_key'] ?? null;
        if (is_string($key) && $key !== '' && ($id = $this->findByKey($key)) !== null) {
            return [$this->view($id), false];
        }

        ReceiptValidator::validate($input);
        if (!is_string($key) || $key === '') {
            throw new HttpError(422, 'validation', ['fields' => ['idempotency_key' => 'es obligatorio']]);
        }

        $supplierId = (int) $input['supplier_id'];
        $number = $input['receipt_number'] ?? null;
        $number = (is_string($number) && trim($number) !== '') ? $number : null;
        $this->guardDuplicate($supplierId, $number, $input, null);

        $baseUnits = $this->baseUnits($input['items']);
        $iva = (float) Settings::get('iva_rate');
        $calc = self::compute($input, $baseUnits, $iva);

        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO receipts (supplier_id, purchase_date, date_unknown, doc_type, receipt_number, iva_rate,
                    adjustment_amount, delivery_amount, printed_total, notes, idempotency_key, source)
                 VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $supplierId, $input['purchase_date'], $input['doc_type'], $number, $iva,
                (int) ($input['adjustment_amount'] ?? 0), (int) ($input['delivery_amount'] ?? 0),
                isset($input['printed_total']) ? (int) $input['printed_total'] : null,
                $this->nullIfEmpty($input['notes'] ?? null), $key, 'manual',
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->insertItems($id, $calc['items']);
            Audit::record('receipt', $id, 'create', null, $this->view($id), null);
            if ($own) {
                $pdo->commit();
            }
        } catch (PDOException $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (($e->errorInfo[1] ?? null) === 1062 && ($existing = $this->findByKey($key)) !== null) {
                return [$this->view($existing), false];
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return [$this->view($id), true];
    }

    private function guardDuplicate(int $supplierId, ?string $number, array $input, ?int $excludeId): void
    {
        if ($number === null || !empty($input['confirm_duplicate'])) {
            return;
        }
        $st = Db::pdo()->prepare(
            "SELECT id FROM receipts WHERE supplier_id = ? AND receipt_number = ? AND status = 'active' AND id <> ?
             ORDER BY id LIMIT 1"
        );
        $st->execute([$supplierId, $number, $excludeId ?? 0]);
        $dup = $st->fetchColumn();
        if ($dup !== false) {
            throw new HttpError(409, 'duplicate_receipt_number', ['existing_id' => (int) $dup]);
        }
    }

    /** Runs $fn inside a transaction, opening one only if none is active. */
    private function atomic(callable $fn): mixed
    {
        $pdo = Db::pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $res = $fn();
            if ($own) {
                $pdo->commit();
            }
            return $res;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Locks the receipt row; throws 404 when missing. @return array receipts row */
    private function lock(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM receipts WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            throw new HttpError(404, 'not_found');
        }
        return $r;
    }

    private function reason(array $input): ?string
    {
        $reason = $input['reason'] ?? null;
        if ($reason === null || $reason === '') {
            return null;
        }
        if (!is_string($reason) || mb_strlen($reason) > 255) {
            throw new HttpError(422, 'validation', ['fields' => ['reason' => 'máximo 255 caracteres']]);
        }
        return $reason;
    }

    /** Full replace of header and items; keeps the receipt's original iva_rate snapshot. Returns the new view. */
    public function update(int $id, array $input): array
    {
        $current = $this->view($id);
        if ($current === null) {
            throw new HttpError(404, 'not_found');
        }
        ReceiptValidator::validate($input, [
            'supplier_id' => $current['supplier']['id'],
            'product_ids' => array_column($current['items'], 'product_id'),
            'date_unknown' => $current['date_unknown'],
        ]);
        $reason = $this->reason($input);
        $supplierId = (int) $input['supplier_id'];
        $number = $input['receipt_number'] ?? null;
        $number = (is_string($number) && trim($number) !== '') ? $number : null;
        $baseUnits = $this->baseUnits($input['items']);

        return $this->atomic(function () use ($id, $input, $reason, $supplierId, $number, $baseUnits) {
            $row = $this->lock($id);
            if ($row['status'] !== 'active') {
                throw new HttpError(409, 'receipt_void');
            }
            $this->guardDuplicate($supplierId, $number, $input, $id);
            $calc = self::compute($input, $baseUnits, (float) $row['iva_rate']);
            $before = $this->view($id);
            $pdo = Db::pdo();
            $pdo->prepare(
                'UPDATE receipts SET supplier_id = ?, purchase_date = ?, date_unknown = ?, doc_type = ?,
                    receipt_number = ?, adjustment_amount = ?, delivery_amount = ?, printed_total = ?, notes = ?
                 WHERE id = ?'
            )->execute([
                // The validator allows a null date only for an already-undated receipt, which stays undated.
                $supplierId, $input['purchase_date'] ?? null, ($input['purchase_date'] ?? null) === null ? 1 : 0,
                $input['doc_type'], $number,
                (int) ($input['adjustment_amount'] ?? 0), (int) ($input['delivery_amount'] ?? 0),
                isset($input['printed_total']) ? (int) $input['printed_total'] : null,
                $this->nullIfEmpty($input['notes'] ?? null), $id,
            ]);
            $pdo->prepare('DELETE FROM receipt_items WHERE receipt_id = ?')->execute([$id]);
            $this->insertItems($id, $calc['items']);
            $after = $this->view($id);
            Audit::record('receipt', $id, 'update', $before, $after, $reason);
            return $after;
        });
    }

    public function void(int $id, array $input): array
    {
        $reason = $this->reason($input);
        return $this->atomic(function () use ($id, $reason) {
            $row = $this->lock($id);
            if ($row['status'] !== 'active') {
                throw new HttpError(409, 'receipt_void');
            }
            $before = $this->view($id);
            Db::pdo()->prepare("UPDATE receipts SET status = 'void', void_reason = ? WHERE id = ?")->execute([$reason, $id]);
            $after = $this->view($id);
            Audit::record('receipt', $id, 'void', $before, $after, $reason);
            return $after;
        });
    }

    /** ReceiptView with annotated items plus audit trail (oldest first); null when missing. */
    public function detail(int $id): ?array
    {
        $v = $this->view($id);
        if ($v === null) {
            return null;
        }
        $v['items'] = $this->annotateItems($v);
        $st = Db::pdo()->prepare(
            "SELECT action, created_at, reason, before_json, after_json FROM audit_log
             WHERE entity = 'receipt' AND entity_id = ? ORDER BY id"
        );
        $st->execute([$id]);
        $v['audit'] = array_map(static fn(array $a) => [
            'action' => $a['action'],
            'created_at' => $a['created_at'],
            'reason' => $a['reason'],
            'before' => $a['before_json'] === null ? null : json_decode($a['before_json'], true),
            'after' => $a['after_json'] === null ? null : json_decode($a['after_json'], true),
        ], $st->fetchAll());
        return $v;
    }

    /** @return array<int,array> the view's items with `comparison` */
    private function annotateItems(array $v): array
    {
        $rows = [];
        foreach ($v['items'] as $i) {
            $rows[] = $i + ['purchase_date' => $v['purchase_date'], 'receipt_id' => $v['id']];
        }
        $out = [];
        foreach (PriceQueries::annotate($rows) as $n => $r) {
            $item = $v['items'][$n];
            $item['comparison'] = $r['comparison'];
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Paged list, newest first, 50 per page.
     * @param array{from?:?string,to?:?string,supplier_id?:?int,receipt_number?:?string,status?:string,page?:int} $f
     * @return array{items: array<int,array>, total:int}
     */
    public function list(array $f): array
    {
        $where = [];
        $args = [];
        $status = $f['status'] ?? 'active';
        if ($status !== 'all') {
            $where[] = 'r.status = ?';
            $args[] = $status;
        }
        if (!empty($f['from'])) {
            $where[] = 'r.purchase_date >= ?';
            $args[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'r.purchase_date <= ?';
            $args[] = $f['to'];
        }
        if (!empty($f['supplier_id'])) {
            $where[] = 'r.supplier_id = ?';
            $args[] = $f['supplier_id'];
        }
        if (isset($f['receipt_number']) && $f['receipt_number'] !== '') {
            $where[] = 'r.receipt_number LIKE ?';
            $args[] = '%' . addcslashes($f['receipt_number'], '\\%_') . '%';
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $pdo = Db::pdo();
        $st = $pdo->prepare("SELECT COUNT(*) FROM receipts r $w");
        $st->execute($args);
        $total = (int) $st->fetchColumn();

        $page = max(1, (int) ($f['page'] ?? 1));
        $st = $pdo->prepare(
            "SELECT r.id FROM receipts r $w ORDER BY r.purchase_date DESC, r.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50)
        );
        $st->execute($args);
        $views = [];
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $rid) {
            $views[] = $this->view((int) $rid);
        }
        $flat = [];
        foreach ($views as $vi => $v) {
            foreach ($v['items'] as $ii => $i) {
                $flat[] = ['product_id' => $i['product_id'], 'purchase_date' => $v['purchase_date'],
                    'receipt_id' => $v['id'], 'line_order' => $i['line_order'],
                    'normalized_price' => $i['normalized_price'], 'vi' => $vi];
            }
        }
        $flagged = [];
        foreach (PriceQueries::annotate($flat) as $r) {
            if ($r['comparison']['flagged']) {
                $flagged[$r['vi']] = ($flagged[$r['vi']] ?? 0) + 1;
            }
        }
        $items = [];
        foreach ($views as $vi => $v) {
            $items[] = [
                'id' => $v['id'],
                'purchase_date' => $v['purchase_date'],
                'date_unknown' => $v['date_unknown'],
                'supplier_name' => $v['supplier']['name'],
                'doc_type' => $v['doc_type'],
                'receipt_number' => $v['receipt_number'],
                'total' => $v['totals']['total'],
                'status' => $v['status'],
                'flagged_count' => $flagged[$vi] ?? 0,
            ];
        }
        return ['items' => $items, 'total' => $total];
    }

    public function findByKey(string $key): ?int
    {
        $st = Db::pdo()->prepare('SELECT id FROM receipts WHERE idempotency_key = ?');
        $st->execute([$key]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<int,string> */
    public function baseUnits(array $items): array
    {
        $ids = [];
        foreach ($items as $it) {
            $ids[(int) $it['product_id']] = true;
        }
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Db::pdo()->prepare("SELECT id, base_unit FROM products WHERE id IN ($ph)");
        $st->execute(array_keys($ids));
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['id']] = $r['base_unit'];
        }
        return $out;
    }

    /** @param array<int,array> $items computed items */
    public function insertItems(int $receiptId, array $items): void
    {
        $st = Db::pdo()->prepare(
            'INSERT INTO receipt_items (receipt_id, line_order, product_id, entry_mode, quantity, entry_unit,
                package_count, package_content, package_unit, unit_price, entered_amount, adjustment_share,
                iva_exempt, real_cost, amount_paid, base_quantity, normalized_price, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($items) as $n => $i) {
            $st->execute([
                $receiptId, $n, $i['product_id'], $i['entry_mode'], $i['quantity'], $i['entry_unit'],
                $i['package_count'], $i['package_content'], $i['package_unit'], $i['unit_price'],
                $i['entered_amount'], $i['adjustment_share'], $i['iva_exempt'] ? 1 : 0,
                $i['real_cost'], $i['amount_paid'], $i['base_quantity'], $i['normalized_price'],
                $this->nullIfEmpty($i['notes']),
            ]);
        }
    }

    /** Builds the ReceiptView; null when the receipt does not exist. */
    public function view(int $id): ?array
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare(
            'SELECT r.*, s.name AS supplier_name FROM receipts r JOIN suppliers s ON s.id = r.supplier_id WHERE r.id = ?'
        );
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT i.*, p.name AS product_name, p.base_unit AS product_base_unit
             FROM receipt_items i JOIN products p ON p.id = i.product_id
             WHERE i.receipt_id = ? ORDER BY i.line_order, i.id'
        );
        $st->execute([$id]);
        $items = [];
        foreach ($st->fetchAll() as $i) {
            $items[] = [
                'id' => (int) $i['id'],
                'line_order' => (int) $i['line_order'],
                'product_id' => (int) $i['product_id'],
                'product' => ['id' => (int) $i['product_id'], 'name' => $i['product_name'], 'base_unit' => $i['product_base_unit']],
                'entry_mode' => $i['entry_mode'],
                'quantity' => (float) $i['quantity'],
                'entry_unit' => $i['entry_unit'],
                'package_count' => $this->f($i['package_count']),
                'package_content' => $this->f($i['package_content']),
                'package_unit' => $i['package_unit'],
                'unit_price' => $i['unit_price'] === null ? null : (int) $i['unit_price'],
                'entered_amount' => (int) $i['entered_amount'],
                'adjustment_share' => (int) $i['adjustment_share'],
                'iva_exempt' => (int) $i['iva_exempt'] === 1,
                'real_cost' => (int) $i['real_cost'],
                'amount_paid' => (int) $i['amount_paid'],
                'base_quantity' => $this->f($i['base_quantity']),
                'normalized_price' => $this->f($i['normalized_price']),
                'notes' => $i['notes'],
            ];
        }
        $delivery = (int) $r['delivery_amount'];
        $iva = (float) $r['iva_rate'];
        $totals = LineCalc::receiptTotals($items, $delivery, $r['doc_type'], $iva);
        $printed = $r['printed_total'] === null ? null : (int) $r['printed_total'];
        return [
            'id' => (int) $r['id'],
            'supplier' => ['id' => (int) $r['supplier_id'], 'name' => $r['supplier_name']],
            'purchase_date' => $r['purchase_date'],
            'date_unknown' => (int) $r['date_unknown'] === 1,
            'doc_type' => $r['doc_type'],
            'receipt_number' => $r['receipt_number'],
            'notes' => $r['notes'],
            'status' => $r['status'],
            'adjustment_amount' => (int) $r['adjustment_amount'],
            'delivery_amount' => $delivery,
            'printed_total' => $printed,
            'iva_rate' => $iva,
            'totals' => $totals,
            'printed_total_mismatch' => $printed !== null && $printed !== $totals['total'],
            'items' => $items,
        ];
    }

    private function f(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    private function nullIfEmpty(mixed $v): ?string
    {
        return (is_string($v) && trim($v) !== '') ? $v : null;
    }
}
