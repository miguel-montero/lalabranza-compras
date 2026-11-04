<?php
declare(strict_types=1);

use KT\Auth\Auth;
use KT\Auth\Csrf;
use KT\Catalog\CatalogController;
use KT\Http\HttpError;
use KT\Http\Request;
use KT\Http\Response;
use KT\Http\Router;
use KT\Settings;

/** Registers all routes. Later tasks append here. */
return static function (Router $router): void {
    // Routes that need neither a session nor a CSRF token. Add e.g. 'GET /cron/backup' here.
    $public = [
        'GET /health',
        'POST /auth/login',
        'GET /auth/me',
    ];

    $router->before(static function (Request $r) use ($public): void {
        if (in_array($r->method . ' ' . $r->path, $public, true)) {
            return;
        }
        Auth::requireUser();
        if ($r->method !== 'GET') {
            Csrf::verify($r);
        }
    });

    $router->add('GET', '/health', fn() => Response::json(['ok' => true]));

    $router->add('POST', '/auth/login', function (Request $r) {
        $pw = $r->json()['password'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!is_string($pw) || !Auth::login($pw, $ip)) {
            throw new HttpError(401, 'invalid_credentials');
        }
        return Response::json(['csrf_token' => Csrf::token()]);
    });

    $router->add('POST', '/auth/logout', function () {
        Auth::logout();
        return Response::json(['ok' => true]);
    });

    $router->add('GET', '/auth/me', function () {
        if (!Auth::check()) {
            return Response::json(['authenticated' => false]);
        }
        return Response::json(['authenticated' => true, 'csrf_token' => Csrf::token()]);
    });

    $router->add('POST', '/auth/password', function (Request $r) {
        $b = $r->json();
        $cur = $b['current'] ?? null;
        $new = $b['new'] ?? null;
        if (!is_string($cur) || !is_string($new)) {
            throw new HttpError(422, 'invalid_password');
        }
        if (!Auth::verifyPassword($cur)) {
            throw new HttpError(403, 'wrong_password');
        }
        try {
            Auth::setPassword($new);
        } catch (\InvalidArgumentException) {
            throw new HttpError(422, 'password_too_short');
        }
        return Response::json(['ok' => true]);
    });

    $router->add('GET', '/settings', fn() => Response::json(Settings::all()));

    $router->add('PUT', '/settings', function (Request $r) {
        Settings::set($r->json());
        return Response::json(Settings::all());
    });

    CatalogController::register($router);
    \KT\Receipts\ReceiptController::register($router);
    \KT\Prices\PricesController::register($router);
};
