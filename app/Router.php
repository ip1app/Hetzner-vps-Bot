<?php
declare(strict_types=1);

namespace App;

final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: callable|array}> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable|array $handler): void
    {
        // {path} catches nested admin/account routes e.g. /admin/telegram/connect
        $regex = preg_replace('#\{path\}#', '(?P<path>.+)', $pattern);
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $regex);
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(): void
    {
        $req = Request::capture();
        foreach ($this->routes as $route) {
            if ($route['method'] !== $req->method) {
                continue;
            }
            if (!preg_match($route['regex'], $req->path, $m)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $method] = $handler;
                (new $class())->$method($req, $params);
            } else {
                $handler($req, $params);
            }
            return;
        }
        if (str_starts_with($req->path, '/api/')) {
            \App\Api\ApiResponse::err('NOT_FOUND', 'Endpoint not found.', 404);
        }
        \App\Services\NotFound::store($req);
    }

    /** @return array{handler: callable|array, params: array<string, string>}|null */
    public function resolve(Request $req): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $req->method) {
                continue;
            }
            if (!preg_match($route['regex'], $req->path, $m)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['handler' => $route['handler'], 'params' => $params];
        }
        return null;
    }
}
