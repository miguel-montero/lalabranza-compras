<?php
declare(strict_types=1);

use KT\Http\Response;
use KT\Http\Router;

/** Registers all routes. Later tasks append here. */
return static function (Router $router): void {
    $router->add('GET', '/health', fn() => Response::json(['ok' => true]));
};
