<?php
declare(strict_types=1);

namespace KT\Tests\Catalog;

use KT\Auth\Auth;
use KT\Auth\Csrf;
use KT\Catalog\CatalogRepo;
use KT\Http\Request;
use KT\Http\Router;
use KT\Tests\DbTestCase;

final class CatalogTest extends DbTestCase
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        @ini_set('session.use_cookies', '0');
        @ini_set('session.cache_limiter', '');
        $this->endSession();
        session_id('ktsess' . bin2hex(random_bytes(8)));
        Auth::setPassword('correct horse battery');
        Auth::login('correct horse battery', '9.9.9.9');
        $this->token = Csrf::token();
    }

    protected function tearDown(): void
    {
        $this->endSession();
        parent::tearDown();
    }

    private function endSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    private function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $router = new Router();
        (require __DIR__ . '/../../src/routes.php')($router);
        $res = $router->dispatch(new Request(
            $method,
            '/api' . $path,
            $query,
            ['X-CSRF-Token' => $this->token],
            $body === null ? '' : json_encode($body)
        ));
        return [$res->status, json_decode($res->body, true)];
    }

    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    public function test_search_matches_alias_accent_insensitive(): void
    {
        [$s, $p] = $this->call('POST', '/products', ['name' => 'Pimentón', 'base_unit' => 'kg']);
        $this->assertSame(201, $s);
        [$s, $a] = $this->call('POST', '/products/' . $p['id'] . '/aliases', ['alias' => 'aji color']);
        $this->assertSame(201, $s);

        [, $rows] = $this->call('GET', '/products', null, ['q' => 'pimenton']);
        $this->assertSame(['Pimentón'], $this->names($rows));
        $this->assertArrayNotHasKey('matched_alias', $rows[0]);
        $this->assertSame('aji color', $rows[0]['aliases'][0]['alias']);

        [, $rows] = $this->call('GET', '/products', null, ['q' => 'AJI']);
        $this->assertSame(['Pimentón'], $this->names($rows));
        $this->assertSame('aji color', $rows[0]['matched_alias']);

        [, $rows] = $this->call('GET', '/products', null, ['q' => 'PIMENTÓN']);
        $this->assertCount(1, $rows);
    }

    public function test_name_prefix_matches_first(): void
    {
        $this->makeProduct('Aceite de oliva', 'L');
        $this->makeProduct('Oliva negra', 'kg');
        $this->makeProduct('Olivas', 'kg');
        [, $rows] = $this->call('GET', '/products', null, ['q' => 'oliva']);
        $this->assertSame(['Oliva negra', 'Olivas', 'Aceite de oliva'], $this->names($rows));
    }

    public function test_duplicate_product_name_409(): void
    {
        $this->call('POST', '/products', ['name' => 'Pimentón', 'base_unit' => 'kg']);
        [$s, $b] = $this->call('POST', '/products', ['name' => 'PIMENTON', 'base_unit' => 'kg']);
        $this->assertSame(409, $s);
        $this->assertSame('duplicate_name', $b['error']);
    }

    public function test_duplicate_supplier_and_category_409(): void
    {
        $this->call('POST', '/suppliers', ['name' => 'Distribuidora Ñuñoa']);
        [$s, $b] = $this->call('POST', '/suppliers', ['name' => 'distribuidora nunoa']);
        $this->assertSame(409, $s);
        $this->assertSame('duplicate_name', $b['error']);

        $this->call('POST', '/categories', ['name' => 'Lácteos']);
        [$s, $b] = $this->call('POST', '/categories', ['name' => 'lacteos']);
        $this->assertSame(409, $s);
        $this->assertSame('duplicate_name', $b['error']);

        [, $c] = $this->call('POST', '/categories', ['name' => 'Verduras']);
        [$s, $b] = $this->call('PATCH', '/categories/' . $c['id'], ['name' => 'LACTEOS']);
        $this->assertSame(409, $s);
    }

    public function test_alias_conflicts_with_product_name_409(): void
    {
        $this->makeProduct('Pimentón', 'kg');
        $id = $this->makeProduct('Ají', 'kg');
        [$s, $b] = $this->call('POST', "/products/$id/aliases", ['alias' => 'pimenton']);
        $this->assertSame(409, $s);
        $this->assertSame('duplicate_alias', $b['error']);
    }

    public function test_alias_conflicts_with_existing_alias_409_and_delete(): void
    {
        $a = $this->makeProduct('A', 'kg');
        $b = $this->makeProduct('B', 'kg');
        [, $al] = $this->call('POST', "/products/$a/aliases", ['alias' => 'Ajó']);
        [$s, $e] = $this->call('POST', "/products/$b/aliases", ['alias' => 'AJO']);
        $this->assertSame(409, $s);
        $this->assertSame('duplicate_alias', $e['error']);
        [$s] = $this->call('DELETE', '/aliases/' . $al['id']);
        $this->assertSame(200, $s);
        [$s] = $this->call('POST', "/products/$b/aliases", ['alias' => 'AJO']);
        $this->assertSame(201, $s);
        [$s] = $this->call('DELETE', '/aliases/999999');
        $this->assertSame(404, $s);
    }

    public function test_product_name_cannot_equal_alias(): void
    {
        $a = $this->makeProduct('A', 'kg');
        $this->call('POST', "/products/$a/aliases", ['alias' => 'Ajo']);
        [$s, $b] = $this->call('POST', '/products', ['name' => 'ajo', 'base_unit' => 'kg']);
        $this->assertSame(409, $s);
        $this->assertSame('duplicate_name', $b['error']);
    }

    public function test_base_unit_locked_when_used(): void
    {
        $used = $this->makeProduct('Usado', 'kg');
        $free = $this->makeProduct('Libre', 'kg');
        $sup = $this->makeSupplier('S');
        self::$pdo->prepare('INSERT INTO receipts (supplier_id, purchase_date, iva_rate) VALUES (?, ?, 0.19)')
            ->execute([$sup, '2026-01-01']);
        $rid = (int)self::$pdo->lastInsertId();
        self::$pdo->prepare(
            "INSERT INTO receipt_items (receipt_id, product_id, entry_mode, quantity, entry_unit, entered_amount, real_cost, amount_paid)
             VALUES (?, ?, 'total', 1, 'kg', 1000, 1000, 1000)"
        )->execute([$rid, $used]);

        [$s, $b] = $this->call('PATCH', "/products/$used", ['base_unit' => 'L']);
        $this->assertSame(409, $s);
        $this->assertSame('base_unit_locked', $b['error']);

        // same value and other fields are fine
        [$s] = $this->call('PATCH', "/products/$used", ['base_unit' => 'kg', 'notes' => 'x']);
        $this->assertSame(200, $s);

        [$s, $b] = $this->call('PATCH', "/products/$free", ['base_unit' => 'L']);
        $this->assertSame(200, $s);
        $this->assertSame('L', $b['base_unit']);
    }

    public function test_inactive_hidden_by_default(): void
    {
        $id = $this->makeProduct('Viejo', 'kg');
        $this->makeProduct('Nuevo', 'kg');
        $this->call('PATCH', "/products/$id", ['active' => false]);

        [, $rows] = $this->call('GET', '/products');
        $this->assertSame(['Nuevo'], $this->names($rows));
        [, $rows] = $this->call('GET', '/products', null, ['active' => 'all']);
        $this->assertSame(['Nuevo', 'Viejo'], $this->names($rows));

        $sid = $this->makeSupplier('Prov');
        $this->call('PATCH', "/suppliers/$sid", ['active' => false]);
        [, $rows] = $this->call('GET', '/suppliers');
        $this->assertSame([], $rows);
        [, $rows] = $this->call('GET', '/suppliers', null, ['active' => 'all']);
        $this->assertSame(['Prov'], $this->names($rows));
        $this->assertFalse($rows[0]['active']);
    }

    public function test_validation_and_not_found(): void
    {
        [$s, $b] = $this->call('POST', '/products', ['name' => 'X', 'base_unit' => 'g']);
        $this->assertSame(422, $s);
        [$s] = $this->call('POST', '/products', ['name' => '  ', 'base_unit' => 'kg']);
        $this->assertSame(422, $s);
        [$s] = $this->call('POST', '/products', ['name' => 'X', 'base_unit' => 'kg', 'category_id' => 99999]);
        $this->assertSame(422, $s);
        [$s] = $this->call('PATCH', '/products/99999', ['name' => 'Y']);
        $this->assertSame(404, $s);
        [$s] = $this->call('PATCH', '/suppliers/99999', ['name' => 'Y']);
        $this->assertSame(404, $s);
    }

    public function test_product_with_category_and_supplier_crud(): void
    {
        [, $c] = $this->call('POST', '/categories', ['name' => 'Secos']);
        [, $p] = $this->call('POST', '/products', ['name' => 'Arroz', 'base_unit' => 'kg', 'category_id' => $c['id']]);
        $this->assertSame('Secos', $p['category_name']);
        $this->assertTrue($p['active']);
        $this->assertSame([], $p['aliases']);

        [, $sp] = $this->call('POST', '/suppliers', ['name' => 'Lider', 'notes' => 'n']);
        [$s, $sp] = $this->call('PATCH', '/suppliers/' . $sp['id'], ['name' => 'Líder', 'notes' => null]);
        $this->assertSame(200, $s);
        $this->assertSame('Líder', $sp['name']);
        $this->assertNull($sp['notes']);
    }

    public function test_find_product_by_name_or_alias(): void
    {
        $id = $this->makeProduct('Pimentón', 'kg');
        $this->call('POST', "/products/$id/aliases", ['alias' => 'Ají color']);
        $repo = new CatalogRepo(self::$pdo);
        $this->assertSame($id, $repo->findProductByNameOrAlias('pimenton')['id']);
        $this->assertSame($id, $repo->findProductByNameOrAlias(' AJI COLOR ')['id']);
        $this->assertNull($repo->findProductByNameOrAlias('pimentones'));
        $this->assertNull($repo->findProductByNameOrAlias(''));
    }

    public function test_requires_auth(): void
    {
        $this->endSession();
        session_id('ktsess' . bin2hex(random_bytes(8)));
        [$s] = $this->call('GET', '/products');
        $this->assertSame(401, $s);
    }
}
