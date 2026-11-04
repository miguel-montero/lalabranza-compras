<?php
declare(strict_types=1);

namespace KT\Catalog;

use KT\Db;
use KT\Http\HttpError;
use KT\Http\Request;
use KT\Http\Response;
use KT\Http\Router;

final class CatalogController
{
    private CatalogRepo $repo;

    public function __construct(?CatalogRepo $repo = null)
    {
        $this->repo = $repo ?? new CatalogRepo(Db::pdo());
    }

    public static function register(Router $router): void
    {
        $c = static fn() => new self();
        $router->add('GET', '/suppliers', fn(Request $r) => $c()->listSuppliers($r));
        $router->add('POST', '/suppliers', fn(Request $r) => $c()->createSupplier($r));
        $router->add('PATCH', '/suppliers/{id}', fn(Request $r) => $c()->patchSupplier($r));
        $router->add('GET', '/categories', fn(Request $r) => $c()->listCategories($r));
        $router->add('POST', '/categories', fn(Request $r) => $c()->createCategory($r));
        $router->add('PATCH', '/categories/{id}', fn(Request $r) => $c()->patchCategory($r));
        $router->add('GET', '/products', fn(Request $r) => $c()->listProducts($r));
        $router->add('POST', '/products', fn(Request $r) => $c()->createProduct($r));
        $router->add('PATCH', '/products/{id}', fn(Request $r) => $c()->patchProduct($r));
        $router->add('POST', '/products/{id}/aliases', fn(Request $r) => $c()->addAlias($r));
        $router->add('DELETE', '/aliases/{id}', fn(Request $r) => $c()->deleteAlias($r));
    }

    public function listSuppliers(Request $r): Response
    {
        $q = $r->query();
        return Response::json($this->repo->listSuppliers($this->str($q['q'] ?? null), $this->activeParam($q)));
    }

    public function createSupplier(Request $r): Response
    {
        $b = $r->json();
        $name = $this->name($b['name'] ?? null, 150);
        return Response::json($this->repo->createSupplier($name, $this->notes($b, 'notes') ?? null), 201);
    }

    public function patchSupplier(Request $r): Response
    {
        $b = $r->json();
        $f = [];
        if (array_key_exists('name', $b)) {
            $f['name'] = $this->name($b['name'], 150);
        }
        if (array_key_exists('notes', $b)) {
            $f['notes'] = $this->notes($b, 'notes');
        }
        if (array_key_exists('active', $b)) {
            $f['active'] = $this->bool($b['active']);
        }
        return $this->found($this->repo->updateSupplier((int)$r->params['id'], $f));
    }

    public function listCategories(Request $r): Response
    {
        return Response::json($this->repo->listCategories($this->str($r->query()['q'] ?? null)));
    }

    public function createCategory(Request $r): Response
    {
        return Response::json($this->repo->createCategory($this->name($r->json()['name'] ?? null, 100)), 201);
    }

    public function patchCategory(Request $r): Response
    {
        $b = $r->json();
        $name = $this->name($b['name'] ?? null, 100);
        return $this->found($this->repo->updateCategory((int)$r->params['id'], $name));
    }

    public function listProducts(Request $r): Response
    {
        $q = $r->query();
        $limit = isset($q['limit']) && ctype_digit((string)$q['limit']) ? min(200, (int)$q['limit']) : 20;
        return Response::json($this->repo->listProducts($this->str($q['q'] ?? null), $this->activeParam($q), max(1, $limit)));
    }

    public function createProduct(Request $r): Response
    {
        $b = $r->json();
        $name = $this->name($b['name'] ?? null, 150);
        $unit = $this->baseUnit($b['base_unit'] ?? null);
        $cat = $this->categoryId($b['category_id'] ?? null);
        return Response::json($this->repo->createProduct($name, $unit, $cat, $this->notes($b, 'notes')), 201);
    }

    public function patchProduct(Request $r): Response
    {
        $b = $r->json();
        $f = [];
        if (array_key_exists('name', $b)) {
            $f['name'] = $this->name($b['name'], 150);
        }
        if (array_key_exists('base_unit', $b)) {
            $f['base_unit'] = $this->baseUnit($b['base_unit']);
        }
        if (array_key_exists('category_id', $b)) {
            $f['category_id'] = $this->categoryId($b['category_id']);
        }
        if (array_key_exists('notes', $b)) {
            $f['notes'] = $this->notes($b, 'notes');
        }
        if (array_key_exists('active', $b)) {
            $f['active'] = $this->bool($b['active']);
        }
        return $this->found($this->repo->updateProduct((int)$r->params['id'], $f));
    }

    public function addAlias(Request $r): Response
    {
        $alias = $this->name($r->json()['alias'] ?? null, 150, 'invalid_alias');
        $row = $this->repo->addAlias((int)$r->params['id'], $alias);
        if ($row === null) {
            throw new HttpError(404, 'not_found');
        }
        return Response::json($row, 201);
    }

    public function deleteAlias(Request $r): Response
    {
        if (!$this->repo->deleteAlias((int)$r->params['id'])) {
            throw new HttpError(404, 'not_found');
        }
        return Response::json(['ok' => true]);
    }

    // ---- helpers ---------------------------------------------------------------

    private function found(?array $row): Response
    {
        if ($row === null) {
            throw new HttpError(404, 'not_found');
        }
        return Response::json($row);
    }

    private function str(mixed $v): ?string
    {
        return is_string($v) ? trim($v) : null;
    }

    private function activeParam(array $q): string
    {
        $a = $q['active'] ?? '1';
        return in_array($a, ['all', '0', 'false'], true) ? (string)$a : '1';
    }

    private function name(mixed $v, int $max, string $code = 'invalid_name'): string
    {
        if (!is_string($v) || trim($v) === '' || mb_strlen(trim($v)) > $max) {
            throw new HttpError(422, $code);
        }
        return trim($v);
    }

    private function notes(array $b, string $key): ?string
    {
        $v = $b[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw new HttpError(422, 'invalid_notes');
        }
        return $v === '' ? null : $v;
    }

    private function bool(mixed $v): bool
    {
        if (!is_bool($v)) {
            throw new HttpError(422, 'invalid_active');
        }
        return $v;
    }

    private function baseUnit(mixed $v): string
    {
        if (!is_string($v) || !in_array($v, CatalogRepo::BASE_UNITS, true)) {
            throw new HttpError(422, 'invalid_base_unit');
        }
        return $v;
    }

    private function categoryId(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        if (!is_int($v) || $this->repo->getCategory($v) === null) {
            throw new HttpError(422, 'invalid_category');
        }
        return $v;
    }
}
