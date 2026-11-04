<?php
declare(strict_types=1);

namespace KT\Import;

use KT\Catalog\CatalogRepo;
use KT\Db;
use KT\Http\HttpError;
use KT\Http\Request;
use KT\Http\Response;
use KT\Http\Router;

final class ImportController
{
    private const MAX_ROWS = 5000;

    public static function register(Router $router): void
    {
        $router->add('POST', '/import/validate', fn(Request $r) => (new self())->validate($r));
        $router->add('POST', '/import/commit', fn(Request $r) => (new self())->commit($r));
    }

    private function rows(Request $r): array
    {
        $rows = $r->json()['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new HttpError(422, 'validation', ['fields' => ['rows' => 'debe ser una lista']]);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new HttpError(422, 'validation', ['fields' => ['rows' => 'demasiadas filas']]);
        }
        return $rows;
    }

    public function validate(Request $r): Response
    {
        $v = new ImportValidator(new CatalogRepo(Db::pdo()));
        return Response::json(['rows' => $v->validate($this->rows($r))]);
    }

    public function commit(Request $r): Response
    {
        return Response::json((new ImportService())->commit($this->rows($r)));
    }
}
