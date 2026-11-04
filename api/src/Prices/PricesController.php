<?php
declare(strict_types=1);

namespace KT\Prices;

use KT\Db;
use KT\Http\HttpError;
use KT\Http\Request;
use KT\Http\Response;
use KT\Http\Router;
use KT\Receipts\ReceiptValidator;

final class PricesController
{
    public static function register(Router $router): void
    {
        $router->add('GET', '/products/{id}/baseline', fn(Request $r) => (new self())->baseline($r));
    }

    public function baseline(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $st = Db::pdo()->prepare('SELECT 1 FROM products WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() === false) {
            throw new HttpError(404, 'not_found');
        }
        $date = $r->query()['date'] ?? ReceiptValidator::today();
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new HttpError(422, 'validation', ['fields' => ['date' => 'fecha inválida']]);
        }
        return Response::json(PriceQueries::baseline($id, $date, null, null));
    }
}
