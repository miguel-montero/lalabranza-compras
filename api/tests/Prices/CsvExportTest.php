<?php
declare(strict_types=1);

namespace KT\Tests\Prices;

use KT\Http\Request;
use KT\Http\Router;
use KT\Tests\ApiTestCase;

final class CsvExportTest extends ApiTestCase
{
    public function test_matches_filter_and_columns(): void
    {
        $s = $this->makeSupplier('Lider');
        $cat = (int) (function () {
            self::$pdo->exec("INSERT INTO categories (name) VALUES ('Despensa')");
            return self::$pdo->lastInsertId();
        })();
        $p = $this->makeProduct('Arroz', 'kg', $cat);
        $this->make($s, [$this->line($p, 1300, 1000, 'g')], ['purchase_date' => '2026-01-10', 'receipt_number' => 'A;1']);
        $this->make($s, [$this->line($p, 1500)], ['purchase_date' => '2026-02-10']);
        $this->make($s, [$this->line($p, 1700)], ['purchase_date' => '2026-03-10']);

        $q = ['from' => '2026-01-01', 'to' => '2026-02-28'];
        [, $json] = $this->call('GET', '/history', null, $q);
        $router = new Router();
        (require __DIR__ . '/../../src/routes.php')($router);
        $res = $router->dispatch(new Request('GET', '/api/history', $q + ['format' => 'csv'], ['X-CSRF-Token' => $this->token]));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('text/csv', $res->headers['Content-Type']);
        $this->assertMatchesRegularExpression('/filename="historial-\d{8}\.csv"/', $res->headers['Content-Disposition']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $res->body);
        $lines = explode("\r\n", rtrim(substr($res->body, 3), "\r\n"));
        $this->assertSame(
            'Fecha;Proveedor;Producto;Categoría;Tipo doc;N° documento;Cantidad;Unidad;Envases;Contenido;Unidad contenido;Cantidad base;Unidad base;Monto ingresado;Monto pagado;Costo real;Costo real por unidad base;Cambio %;Alerta',
            $lines[0]
        );
        $this->assertCount($json['total'] + 1, $lines);
        $this->assertSame(2, $json['total']);
        // newest first: row 1 = 2026-02-10 (1500, +15,38%), row 2 = 2026-01-10 (1000 g / 1300)
        $first = str_getcsv($lines[1], ';', '"', '');
        $this->assertSame('10-02-2026', $first[0]);
        $this->assertSame('1500,0000', $first[16]);
        $this->assertSame('15,38', $first[17]);
        $this->assertSame('Sí', $first[18]);
        $second = str_getcsv($lines[2], ';', '"', '');
        $this->assertSame('Despensa', $second[3]);
        $this->assertSame('A;1', $second[5]);
        $this->assertSame('1000', $second[6]);
        $this->assertSame('g', $second[7]);
        $this->assertSame('1', $second[11]);
        $this->assertSame('kg', $second[12]);
        $this->assertSame('1300,0000', $second[16]);
        $this->assertSame('', $second[17]);
        $this->assertSame('', $second[18]);
    }
}
