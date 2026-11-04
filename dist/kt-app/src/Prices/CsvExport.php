<?php
declare(strict_types=1);

namespace KT\Prices;

use KT\Http\Response;

/** History CSV for Excel es-CL: UTF-8 BOM, ';' separator, comma decimals, no thousands separator. */
final class CsvExport
{
    public const HEADER = ['Fecha', 'Proveedor', 'Producto', 'Categoría', 'Tipo doc', 'N° documento', 'Cantidad', 'Unidad',
        'Envases', 'Contenido', 'Unidad contenido', 'Cantidad base', 'Unidad base', 'Monto ingresado', 'Monto pagado',
        'Costo real', 'Costo real por unidad base', 'Cambio %', 'Alerta'];

    /** @param array<int,array> $rows annotated history rows */
    public static function response(array $rows, string $today): Response
    {
        $data = [self::HEADER];
        foreach ($rows as $r) {
            $c = $r['comparison'];
            $data[] = [
                $r['purchase_date'] === null ? 'sin fecha' : self::date($r['purchase_date']),
                $r['supplier_name'], $r['product_name'], $r['category_name'] ?? '',
                $r['doc_type'] === 'factura' ? 'Factura' : 'Boleta',
                $r['receipt_number'] ?? '',
                self::num($r['quantity']), $r['entry_unit'],
                self::num($r['package_count']), self::num($r['package_content']), $r['package_unit'] ?? '',
                self::num($r['base_quantity']), $r['base_unit'],
                (string) $r['entered_amount'], (string) $r['amount_paid'], (string) $r['real_cost'],
                $r['normalized_price'] === null ? '' : number_format($r['normalized_price'], 4, ',', ''),
                $c['pct'] === null ? '' : number_format((float) $c['pct'], 2, ',', ''),
                $c['flagged'] ? 'Sí' : '',
            ];
        }
        return Response::csv('historial-' . str_replace('-', '', $today) . '.csv', $data, ';');
    }

    private static function date(string $d): string
    {
        return substr($d, 8, 2) . '-' . substr($d, 5, 2) . '-' . substr($d, 0, 4);
    }

    /** Up to 4 decimals, trailing zeros trimmed, comma decimal. */
    private static function num(?float $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = rtrim(rtrim(number_format($v, 4, ',', ''), '0'), ',');
        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
