<?php
declare(strict_types=1);

namespace KT\Http;

final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable}> method, regex, handler */
    private array $routes = [];
    /** @var array<int,callable> */
    private array $before = [];

    /** Pattern uses {name} segments, which match digits only. */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('#\{([A-Za-z_][A-Za-z0-9_]*)\}#', '(?P<$1>[0-9]+)', $pattern);
        $this->routes[] = [strtoupper($method), '#^' . $regex . '$#', $handler];
    }

    /**
     * Register a hook run after a route matched, before its handler.
     * It receives the Request; it may throw HttpError or return a Response to short-circuit.
     */
    public function before(callable $hook): void
    {
        $this->before[] = $hook;
    }

    public function dispatch(Request $r): Response
    {
        try {
            foreach ($this->routes as [$method, $regex, $handler]) {
                if ($method !== $r->method || !preg_match($regex, $r->path, $m)) {
                    continue;
                }
                $r->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                foreach ($this->before as $hook) {
                    $res = $hook($r);
                    if ($res instanceof Response) {
                        return $res;
                    }
                }
                return $handler($r);
            }
            throw new HttpError(404, 'not_found');
        } catch (HttpError $e) {
            return $e->toResponse();
        }
    }
}
