<?php

declare(strict_types=1);

namespace CRM\Infrastructure\Http;

use Closure;

final class ApiRouter
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:Closure,metadata:array<string,mixed>}> */
    private array $routes = [];

    /** @param callable $handler @param array<string,mixed> $metadata */
    public function add(string $method, string $pattern, callable $handler, array $metadata = []): void
    {
        $method = strtoupper(trim($method));
        $pattern = '/' . trim($pattern, '/');
        $pattern = $pattern === '/' ? '/' : rtrim($pattern, '/');
        $regex = '';
        $offset = 0;
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $pattern, $placeholders, PREG_OFFSET_CAPTURE);
        foreach ($placeholders[0] ?? [] as $index => $placeholder) {
            $literal = (string) $placeholder[0];
            $position = (int) $placeholder[1];
            $name = (string) ($placeholders[1][$index][0] ?? 'param');
            $regex .= preg_quote(substr($pattern, $offset, $position - $offset), '#');
            $regex .= '(?P<' . $name . '>[^/]+)';
            $offset = $position + strlen($literal);
        }
        $regex .= preg_quote(substr($pattern, $offset), '#');
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => Closure::fromCallable($handler),
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array{handler:Closure,params:array<string,string>,metadata:array<string,mixed>,pattern:string}
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method === 'HEAD' ? 'GET' : $method);
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode((string) $value);
                }
            }
            return [
                'handler' => $route['handler'],
                'params' => $params,
                'metadata' => $route['metadata'],
                'pattern' => $route['pattern'],
            ];
        }

        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Método HTTP não permitido para esta rota.', [], [
                'Allow' => implode(', ', $allowed),
            ]);
        }
        throw new ApiException(404, 'NOT_FOUND', 'Endpoint não encontrado.');
    }
}
