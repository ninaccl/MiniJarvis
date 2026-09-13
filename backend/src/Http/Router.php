<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $this->normalizePath($pattern),
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }

            [$regex, $names] = $this->compile($route['pattern']);
            if (preg_match($regex, $request->path(), $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($names as $index => $name) {
                $params[$name] = rawurldecode($matches[$index + 1]);
            }
            $response = ($route['handler'])($request->withRouteParams($params));
            if (!$response instanceof Response) {
                throw new \LogicException('Route handlers must return an HTTP response.');
            }
            return $response;
        }

        throw new ApiException(404, 'NOT_FOUND', 'The requested resource was not found.');
    }

    /** @return array{string, list<string>} */
    private function compile(string $pattern): array
    {
        $names = [];
        $quoted = preg_quote($pattern, '#');
        $regex = preg_replace_callback('/\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}/', static function (array $matches) use (&$names): string {
            $names[] = $matches[1];
            return '([^/]+)';
        }, $quoted);
        return ['#^' . $regex . '$#', $names];
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . ltrim($path, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
