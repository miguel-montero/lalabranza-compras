<?php
declare(strict_types=1);

use KT\Http\Request;
use KT\Http\Router;

$root = getenv('KT_APP_ROOT');
if (!$root) {
    $root = is_file(__DIR__ . '/app-root.php') ? require __DIR__ . '/app-root.php' : __DIR__ . '/..';
}

try {
    require $root . '/bootstrap.php';
    $router = new Router();
    (require $root . '/src/routes.php')($router);
    $router->dispatch(Request::fromGlobals())->send();
} catch (Throwable $e) {
    error_log('[kt] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"server_error"}';
    }
}
