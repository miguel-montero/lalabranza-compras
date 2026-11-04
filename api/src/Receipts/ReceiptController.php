<?php
declare(strict_types=1);

namespace KT\Receipts;

use KT\Http\HttpError;
use KT\Http\Request;
use KT\Http\Response;
use KT\Http\Router;

final class ReceiptController
{
    public static function register(Router $router): void
    {
        $router->add('POST', '/receipts', fn(Request $r) => (new self())->create($r));
        $router->add('GET', '/receipts', fn(Request $r) => (new self())->list($r));
        $router->add('GET', '/receipts/{id}', fn(Request $r) => (new self())->show($r));
        $router->add('PUT', '/receipts/{id}', fn(Request $r) => (new self())->update($r));
        $router->add('POST', '/receipts/{id}/void', fn(Request $r) => (new self())->void($r));
    }

    public function create(Request $r): Response
    {
        [$view, $created] = (new ReceiptService())->create($r->json());
        return Response::json($view, $created ? 201 : 200);
    }

    public function show(Request $r): Response
    {
        $v = (new ReceiptService())->detail((int) $r->params['id']);
        if ($v === null) {
            throw new HttpError(404, 'not_found');
        }
        return Response::json($v);
    }

    public function update(Request $r): Response
    {
        return Response::json((new ReceiptService())->update((int) $r->params['id'], $r->json()));
    }

    public function void(Request $r): Response
    {
        $body = trim($r->rawBody()) === '' ? [] : $r->json();
        return Response::json((new ReceiptService())->void((int) $r->params['id'], $body));
    }

    public function list(Request $r): Response
    {
        $q = $r->query();
        $f = [];
        $fields = [];
        foreach (['from', 'to'] as $k) {
            $v = $q[$k] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $fields[$k] = 'fecha inválida';
            } else {
                $f[$k] = $v;
            }
        }
        $status = $q['status'] ?? 'active';
        if (!is_string($status) || !in_array($status, ['active', 'void', 'all'], true)) {
            $fields['status'] = 'estado inválido';
        } else {
            $f['status'] = $status;
        }
        if (isset($q['supplier_id']) && $q['supplier_id'] !== '') {
            $sid = ReceiptValidator::int($q['supplier_id']);
            if ($sid === null || $sid <= 0) {
                $fields['supplier_id'] = 'inválido';
            } else {
                $f['supplier_id'] = $sid;
            }
        }
        if (isset($q['receipt_number']) && is_string($q['receipt_number'])) {
            $f['receipt_number'] = trim($q['receipt_number']);
        }
        if (isset($q['page']) && $q['page'] !== '') {
            $pg = ReceiptValidator::int($q['page']);
            if ($pg === null || $pg < 1) {
                $fields['page'] = 'inválido';
            } else {
                $f['page'] = $pg;
            }
        }
        if ($fields) {
            throw new HttpError(422, 'validation', ['fields' => $fields]);
        }
        return Response::json((new ReceiptService())->list($f));
    }
}
