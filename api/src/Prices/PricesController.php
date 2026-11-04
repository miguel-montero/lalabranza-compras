<?php
declare(strict_types=1);

namespace KT\Prices;

use KT\Catalog\CatalogRepo;
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
        $router->add('GET', '/products/{id}', fn(Request $r) => (new self())->product($r));
        $router->add('GET', '/lookup', fn(Request $r) => (new self())->lookup($r));
        $router->add('GET', '/history', fn(Request $r) => (new self())->history($r));
        $router->add('GET', '/dashboard', fn(Request $r) => (new self())->dashboard($r));
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
        // receipt_id (edit mode): compare against what was entered before that receipt, never against itself.
        $rid = null;
        if (($r->query()['receipt_id'] ?? '') !== '') {
            $rid = ReceiptValidator::int($r->query()['receipt_id']);
            if ($rid === null || $rid <= 0) {
                throw new HttpError(422, 'validation', ['fields' => ['receipt_id' => 'inválido']]);
            }
        }
        return Response::json(PriceQueries::baseline($id, $date, $rid, null));
    }

    private const PAGE_SIZE = 50;

    public function product(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $p = (new CatalogRepo(Db::pdo()))->getProduct($id);
        if ($p === null) {
            throw new HttpError(404, 'not_found');
        }
        $s = PriceQueries::productStats([$id])[$id];
        return Response::json([
            'product' => $p,
            'aliases' => $p['aliases'],
            'stats' => $s['stats'],
            'by_supplier' => $s['by_supplier'],
            'series' => $s['series'],
        ]);
    }

    public function lookup(Request $r): Response
    {
        $q = $r->query()['q'] ?? '';
        $q = is_string($q) ? trim($q) : '';
        if ($q === '') {
            return Response::json(['items' => []]);
        }
        $products = (new CatalogRepo(Db::pdo()))->listProducts($q, 'active', 10);
        $stats = PriceQueries::productStats(array_column($products, 'id'));
        $items = [];
        foreach ($products as $p) {
            $s = $stats[$p['id']];
            $items[] = $p + ['stats' => $s['stats'], 'by_supplier' => $s['by_supplier']];
        }
        return Response::json(['items' => $items]);
    }

    public function history(Request $r): Response
    {
        $q = $r->query();
        $f = [];
        $fields = [];
        foreach (['from', 'to'] as $k) {
            $v = $q[$k] ?? '';
            if ($v === '') {
                continue;
            }
            if (!self::validDate($v)) {
                $fields[$k] = 'fecha inválida';
            } else {
                $f[$k] = $v;
            }
        }
        foreach (['product_id', 'supplier_id', 'category_id'] as $k) {
            $v = $q[$k] ?? '';
            if ($v === '') {
                continue;
            }
            $n = ReceiptValidator::int($v);
            if ($n === null || $n <= 0) {
                $fields[$k] = 'inválido';
            } else {
                $f[$k] = $n;
            }
        }
        $dt = $q['doc_type'] ?? '';
        if ($dt !== '') {
            if (!is_string($dt) || !in_array($dt, ReceiptValidator::DOC_TYPES, true)) {
                $fields['doc_type'] = 'tipo de documento inválido';
            } else {
                $f['doc_type'] = $dt;
            }
        }
        $dir = $q['direction'] ?? '';
        if ($dir !== '') {
            if (!is_string($dir) || !in_array($dir, ['up', 'down', 'flagged'], true)) {
                $fields['direction'] = 'inválido';
            } else {
                $f['direction'] = $dir;
            }
        }
        if (isset($q['receipt_number']) && is_string($q['receipt_number']) && trim($q['receipt_number']) !== '') {
            $f['receipt_number'] = trim($q['receipt_number']);
        }
        $f['include_undated'] = in_array($q['include_undated'] ?? '', ['1', 1, 'true', true], true);
        $page = 1;
        if (($q['page'] ?? '') !== '') {
            $pg = ReceiptValidator::int($q['page']);
            if ($pg === null || $pg < 1) {
                $fields['page'] = 'inválido';
            } else {
                $page = $pg;
            }
        }
        if ($fields) {
            throw new HttpError(422, 'validation', ['fields' => $fields]);
        }
        $rows = PriceQueries::history($f);
        if (($q['format'] ?? '') === 'csv') {
            return CsvExport::response($rows, ReceiptValidator::today());
        }
        return Response::json([
            'items' => array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'total' => count($rows),
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
        ]);
    }

    public function dashboard(Request $r): Response
    {
        $q = $r->query();
        $today = ReceiptValidator::today();
        $from = ($q['from'] ?? '') === '' ? substr($today, 0, 8) . '01' : $q['from'];
        $to = ($q['to'] ?? '') === '' ? (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d') : $q['to'];
        $fields = [];
        if (!self::validDate($from)) {
            $fields['from'] = 'fecha inválida';
        }
        if (!self::validDate($to)) {
            $fields['to'] = 'fecha inválida';
        }
        if ($fields) {
            throw new HttpError(422, 'validation', ['fields' => $fields]);
        }
        return Response::json(Dashboard::build($from, $to));
    }

    private static function validDate(mixed $d): bool
    {
        return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
