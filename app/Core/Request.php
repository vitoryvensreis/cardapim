<?php

declare(strict_types=1);

namespace Cardapim\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
    ) {}

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return new self($method, is_string($path) && $path !== '' ? $path : '/');
    }
}
