<?php
declare(strict_types=1);

namespace KT\Receipts;

use KT\Calc\LineCalc;
use KT\Db;
use KT\Http\HttpError;
use KT\Settings;

final class ReceiptValidator
{
    public const UNITS = ['kg', 'g', 'L', 'mL', 'unidad'];
    public const DOC_TYPES = ['boleta', 'factura'];
    public const MODES = ['total', 'unit_price'];

    /** Throws HttpError(422, 'validation', ['fields' => [...]]) when the input is not acceptable. */
    public static function validate(array $input): void
    {
        $f = [];
        $pdo = Db::pdo();

        // Header
        $sid = self::int($input['supplier_id'] ?? null);
        if ($sid === null || $sid <= 0) {
            $f['supplier_id'] = 'es obligatorio';
        } else {
            $st = $pdo->prepare('SELECT active FROM suppliers WHERE id = ?');
            $st->execute([$sid]);
            $active = $st->fetchColumn();
            if ($active === false) {
                $f['supplier_id'] = 'proveedor no existe';
            } elseif ((int) $active !== 1) {
                $f['supplier_id'] = 'proveedor inactivo';
            }
        }

        $date = $input['purchase_date'] ?? null;
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $f['purchase_date'] = 'fecha inválida';
        } elseif ($date > self::today()) {
            $f['purchase_date'] = 'no puede ser futura';
        }

        if (!is_string($input['doc_type'] ?? null) || !in_array($input['doc_type'], self::DOC_TYPES, true)) {
            $f['doc_type'] = 'tipo de documento inválido';
        }

        $num = $input['receipt_number'] ?? null;
        if ($num !== null && (!is_string($num) || mb_strlen($num) > 40)) {
            $f['receipt_number'] = 'máximo 40 caracteres';
        }
        if (isset($input['notes']) && !is_string($input['notes'])) {
            $f['notes'] = 'inválido';
        }
        if (isset($input['idempotency_key'])
            && (!is_string($input['idempotency_key']) || $input['idempotency_key'] === '' || strlen($input['idempotency_key']) > 36)) {
            $f['idempotency_key'] = 'inválido';
        }

        foreach (['adjustment_amount', 'delivery_amount', 'printed_total'] as $k) {
            if (isset($input[$k])) {
                $v = self::int($input[$k]);
                if ($v === null) {
                    $f[$k] = 'debe ser un entero';
                } elseif ($k !== 'adjustment_amount' && $v < 0) {
                    $f[$k] = 'no puede ser negativo';
                }
            }
        }

        // Items
        $items = $input['items'] ?? null;
        $baseUnits = [];
        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            $f['items'] = 'debe haber al menos una línea';
            $items = [];
        }
        $ids = [];
        foreach ($items as $it) {
            $pid = is_array($it) ? self::int($it['product_id'] ?? null) : null;
            if ($pid !== null && $pid > 0) {
                $ids[$pid] = true;
            }
        }
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, base_unit, active FROM products WHERE id IN ($ph)");
            $st->execute(array_keys($ids));
            foreach ($st->fetchAll() as $row) {
                $baseUnits[(int) $row['id']] = ['unit' => $row['base_unit'], 'active' => (int) $row['active'] === 1];
            }
        }
        $lineOk = true;
        foreach ($items as $n => $it) {
            $before = count($f);
            self::validateItem($f, "items.$n", is_array($it) ? $it : [], $baseUnits);
            if (count($f) !== $before) {
                $lineOk = false;
            }
        }

        if ($f) {
            throw new HttpError(422, 'validation', ['fields' => $f]);
        }

        // Allocation must not push any line below zero.
        if ($lineOk && $items) {
            $units = [];
            foreach ($baseUnits as $id => $b) {
                $units[$id] = $b['unit'];
            }
            $calc = ReceiptService::compute($input, $units, 0.19);
            foreach ($calc['items'] as $it) {
                if ($it['entered_amount'] + $it['adjustment_share'] < 0) {
                    throw new HttpError(422, 'validation', ['fields' => ['adjustment_amount' => 'adjustment_too_large']]);
                }
            }
        }
    }

    private static function validateItem(array &$f, string $p, array $it, array $baseUnits): void
    {
        $pid = self::int($it['product_id'] ?? null);
        $base = null;
        if ($pid === null || $pid <= 0 || !isset($baseUnits[$pid])) {
            $f["$p.product_id"] = 'producto no existe';
        } elseif (!$baseUnits[$pid]['active']) {
            $f["$p.product_id"] = 'producto inactivo';
        } else {
            $base = $baseUnits[$pid]['unit'];
        }

        $mode = $it['entry_mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            $f["$p.entry_mode"] = 'modo inválido';
        }

        $qty = self::num($it['quantity'] ?? null);
        if ($qty === null || $qty <= 0) {
            $f["$p.quantity"] = 'debe ser mayor que 0';
        } elseif ($qty > 9999999999) {
            $f["$p.quantity"] = 'demasiado grande';
        }

        $unit = $it['entry_unit'] ?? null;
        $unitOk = is_string($unit) && in_array($unit, self::UNITS, true);
        if (!$unitOk) {
            $f["$p.entry_unit"] = 'unidad inválida';
        }

        if ($mode === 'total') {
            $a = self::int($it['entered_amount'] ?? null);
            if ($a === null || $a <= 0) {
                $f["$p.entered_amount"] = 'debe ser mayor que 0';
            }
        } elseif ($mode === 'unit_price') {
            $a = self::int($it['unit_price'] ?? null);
            if ($a === null || $a <= 0) {
                $f["$p.unit_price"] = 'debe ser mayor que 0';
            }
        }

        if (array_key_exists('iva_exempt', $it)
            && !(is_bool($it['iva_exempt']) || $it['iva_exempt'] === 0 || $it['iva_exempt'] === 1)) {
            $f["$p.iva_exempt"] = 'debe ser verdadero o falso';
        }

        $pc = $it['package_count'] ?? null;
        $pk = $it['package_content'] ?? null;
        $pu = $it['package_unit'] ?? null;
        $hasPkg = false;
        if ($pc !== null || $pk !== null || $pu !== null) {
            $okPkg = true;
            if (($c = self::num($pc)) === null || $c <= 0) {
                $f["$p.package_count"] = 'debe ser mayor que 0';
                $okPkg = false;
            }
            if (($c = self::num($pk)) === null || $c <= 0) {
                $f["$p.package_content"] = 'debe ser mayor que 0';
                $okPkg = false;
            }
            if (!is_string($pu) || !in_array($pu, self::UNITS, true)) {
                $f["$p.package_unit"] = 'unidad inválida';
                $okPkg = false;
            }
            $hasPkg = $okPkg;
            if (!$okPkg) {
                return;
            }
        }

        if ($mode === 'unit_price' && $qty !== null && $qty > 0 && !isset($f["$p.unit_price"]) && !isset($f["$p.quantity"])) {
            $probe = ['entry_mode' => 'unit_price', 'unit_price' => self::int($it['unit_price'] ?? null),
                'quantity' => $qty, 'package_count' => $hasPkg ? $pc : null];
            if (LineCalc::enteredAmount($probe) <= 0) {
                $f["$p.unit_price"] = 'el monto de la línea debe ser mayor que 0';
            }
        }

        if ($base !== null && $unitOk && $qty !== null) {
            $probe = ['quantity' => 1, 'entry_unit' => $unit,
                'package_count' => $hasPkg ? $pc : null, 'package_content' => $hasPkg ? $pk : null,
                'package_unit' => $hasPkg ? $pu : null];
            if (LineCalc::baseQuantity($probe, $base) === null) {
                $f["$p.entry_unit"] = "unidad incompatible con $base";
            }
        }
        if (isset($it['notes']) && !is_string($it['notes'])) {
            $f["$p.notes"] = 'inválido';
        }
    }

    /** Integer from an int, integral float or digit string; null otherwise. */
    public static function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 1e15) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/^-?\d{1,15}$/', trim($v))) {
            return (int) trim($v);
        }
        return null;
    }

    public static function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? (float) $v : null;
        }
        if (is_string($v) && is_numeric(trim($v))) {
            $n = (float) trim($v);
            return is_finite($n) ? $n : null;
        }
        return null;
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(Settings::get('timezone'))))->format('Y-m-d');
    }
}
