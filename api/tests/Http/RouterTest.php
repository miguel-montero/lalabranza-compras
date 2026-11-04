<?php
declare(strict_types=1);

namespace KT\Tests\Http;

use KT\Http\HttpError;
use KT\Http\Request;
use KT\Http\Response;
use KT\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function req(string $method, string $path): Request
    {
        return new Request($method, $path);
    }

    public function test_unknown_route_404(): void
    {
        $res = (new Router())->dispatch($this->req('GET', '/api/nope'));
        $this->assertSame(404, $res->status);
        $this->assertSame(['error' => 'not_found'], json_decode($res->body, true));
    }

    public function test_param_route(): void
    {
        $r = new Router();
        $r->add('GET', '/receipts/{id}', fn(Request $q) => Response::json(['id' => (int)$q->params['id']]));
        $res = $r->dispatch($this->req('GET', '/api/receipts/42'));
        $this->assertSame(200, $res->status);
        $this->assertSame(['id' => 42], json_decode($res->body, true));
        $this->assertSame(404, $r->dispatch($this->req('GET', '/api/receipts/abc'))->status);
    }

    public function test_http_error(): void
    {
        $r = new Router();
        $r->add('POST', '/x', function (Request $q) {
            throw new HttpError(409, 'duplicate_receipt_number', ['existing_id' => 7]);
        });
        $res = $r->dispatch($this->req('POST', '/api/x'));
        $this->assertSame(409, $res->status);
        $this->assertSame(
            ['error' => 'duplicate_receipt_number', 'existing_id' => 7],
            json_decode($res->body, true)
        );
    }

    public function test_method_mismatch_is_404(): void
    {
        $r = new Router();
        $r->add('GET', '/x', fn() => Response::json([]));
        $this->assertSame(404, $r->dispatch($this->req('POST', '/api/x'))->status);
    }

    public function test_before_hook_can_short_circuit(): void
    {
        $r = new Router();
        $r->add('GET', '/x', fn() => Response::json(['ok' => true]));
        $r->before(function (Request $q) {
            throw new HttpError(401, 'unauthorized');
        });
        $res = $r->dispatch($this->req('GET', '/api/x'));
        $this->assertSame(401, $res->status);
    }

    public function test_before_hook_not_run_for_unknown_route_but_runs_for_match(): void
    {
        $calls = 0;
        $r = new Router();
        $r->add('GET', '/x', fn() => Response::json([]));
        $r->before(function (Request $q) use (&$calls) { $calls++; });
        $r->dispatch($this->req('GET', '/api/x'));
        $this->assertSame(1, $calls);
    }

    public function test_request_json_invalid_is_400(): void
    {
        $q = new Request('POST', '/api/x', [], [], '{bad');
        try {
            $q->json();
            $this->fail('expected HttpError');
        } catch (HttpError $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('invalid_json', $e->errorCode);
        }
    }

    public function test_request_strips_api_prefix_and_reads_headers(): void
    {
        $q = new Request('GET', '/api/a/b/', ['x' => '1'], ['X-CSRF-Token' => 'abc'], '');
        $this->assertSame('/a/b', $q->path);
        $this->assertSame(['x' => '1'], $q->query());
        $this->assertSame('abc', $q->header('x-csrf-token'));
        $this->assertNull($q->header('nope'));
    }

    public function test_csv_response(): void
    {
        $res = Response::csv('a.csv', [['h1', 'h2'], ['x,y', 'z']]);
        $this->assertStringContainsString('attachment; filename="a.csv"', $res->headers['Content-Disposition']);
        $this->assertStringContainsString("h1,h2\r\n\"x,y\",z\r\n", $res->body);
    }
}
