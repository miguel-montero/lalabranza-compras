<?php
declare(strict_types=1);

namespace KT\Receipts;

use KT\Http\Request;
use KT\Http\Response;
use KT\Http\Router;

final class ReceiptController
{
    public static function register(Router $router): void
    {
        $router->add('POST', '/receipts', fn(Request $r) => (new self())->create($r));
    }

    public function create(Request $r): Response
    {
        [$view, $created] = (new ReceiptService())->create($r->json());
        return Response::json($view, $created ? 201 : 200);
    }
}
