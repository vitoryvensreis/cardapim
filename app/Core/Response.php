<?php

declare(strict_types=1);

namespace Cardapim\Core;

final class Response
{
    public function __construct(
        private readonly string $body,
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {}

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** JSON sem cache, para APIs. */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store', ...$headers],
        );
    }

    public static function redirect(string $path, int $status = 302): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new \InvalidArgumentException('Redirecionamento deve ser local.');
        }
        return new self('', $status, ['Location' => $path]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo $this->body;
        }
    }
}
