<?php
declare(strict_types=1);

namespace KT\Http;

final class Request
{
    public string $method;
    public string $path;
    /** @var array<string,string> route params, filled by the Router */
    public array $params = [];
    /** @var array<string,string> lower-cased header name => value */
    private array $headers = [];

    public function __construct(
        string $method,
        string $uri,
        private array $query = [],
        array $headers = [],
        private string $body = ''
    ) {
        $this->method = strtoupper($method);
        $path = (string)parse_url($uri, PHP_URL_PATH);
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            $path = substr($path, strlen('/api'));
        }
        $path = rtrim($path, '/');
        $this->path = $path === '' ? '/' : '/' . ltrim($path, '/');
        foreach ($headers as $k => $v) {
            $this->headers[strtolower((string)$k)] = (string)$v;
        }
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($k, 5))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_SERVER['REQUEST_URI'] ?? '/',
            $_GET,
            $headers,
            (string)file_get_contents('php://input')
        );
    }

    public function query(): array
    {
        return $this->query;
    }

    public function rawBody(): string
    {
        return $this->body;
    }

    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpError(400, 'invalid_json');
        }
        return $data;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
