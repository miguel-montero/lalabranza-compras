<?php
declare(strict_types=1);

namespace KT\Tests;

use KT\Auth\Auth;
use KT\Auth\Csrf;
use KT\Http\Request;
use KT\Http\Router;

/** DbTestCase plus a logged-in session and a call() helper that dispatches through the real router. */
abstract class ApiTestCase extends DbTestCase
{
    protected string $token = '';

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

    protected function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $router = new Router();
        (require __DIR__ . '/../src/routes.php')($router);
        $res = $router->dispatch(new Request(
            $method,
            '/api' . $path,
            $query,
            ['X-CSRF-Token' => $this->token],
            $body === null ? '' : json_encode($body)
        ));
        return [$res->status, json_decode($res->body, true)];
    }

    protected function uuid(): string
    {
        $h = bin2hex(random_bytes(16));
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    protected function line(int $product, int $amount, float $qty = 1, string $unit = 'kg', array $extra = []): array
    {
        return $extra + [
            'product_id' => $product, 'entry_mode' => 'total', 'quantity' => $qty,
            'entry_unit' => $unit, 'entered_amount' => $amount,
        ];
    }

    protected function receipt(int $supplier, array $items, array $extra = []): array
    {
        return $extra + [
            'idempotency_key' => $this->uuid(), 'supplier_id' => $supplier,
            'purchase_date' => '2026-01-15', 'doc_type' => 'boleta', 'items' => $items,
        ];
    }

    /** Creates a receipt through the API and returns its view. */
    protected function make(int $supplier, array $items, array $extra = []): array
    {
        [$st, $v] = $this->call('POST', '/receipts', $this->receipt($supplier, $items, $extra));
        $this->assertSame(201, $st, json_encode($v));
        return $v;
    }
}
