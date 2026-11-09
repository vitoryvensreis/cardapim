<?php

declare(strict_types=1);

namespace Cardapim\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable|array $handler): self
    {
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('A rota deve comecar com /.');
        }
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $path, 'handler' => $handler];
        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($this->compile($route['pattern']), $request->path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $request->method && !($request->method === 'HEAD' && $route['method'] === 'GET')) {
                $allowed[] = $route['method'];
                continue;
            }
            $parameters = [];
            foreach ($matches as $name => $value) {
                if (is_string($name)) {
                    $parameters[$name] = rawurldecode($value);
                }
            }
            $handler = $route['handler'];
            if (is_array($handler) && is_string($handler[0])) {
                $handler = [new $handler[0](), $handler[1]];
            }
            $response = $handler(...array_values($parameters));
            if (!$response instanceof Response) {
                throw new \LogicException('O controller deve retornar Response.');
            }
            return $response;
        }
        if ($allowed !== []) {
            return new Response('Metodo nao permitido.', 405, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Allow' => implode(', ', array_unique($allowed)),
            ]);
        }
        return Response::html('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>404 | Cardapim</title><body style="font:16px system-ui;padding:3rem"><h1>Essa pagina nao existe.</h1><p><a href="/">Voltar ao Cardapim</a></p></body></html>', 404);
    }

    private function compile(string $path): string
    {
        if ($path === '/') {
            return '~^/$~D';
        }
        $segments = explode('/', trim($path, '/'));
        $segments = array_map(static function (string $segment): string {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches)) {
                return '(?P<' . $matches[1] . '>[^/]+)';
            }
            return preg_quote($segment, '~');
        }, $segments);
        return '~^/' . implode('/', $segments) . '/?$~D';
    }
}
