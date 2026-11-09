<?php

declare(strict_types=1);

// php -S localhost:8000 -t public dev/router.php
$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$staticFile = realpath(dirname(__DIR__) . '/public' . $requestedPath);
$publicRoot = realpath(dirname(__DIR__) . '/public');

if (
    $requestedPath !== '/'
    && is_string($staticFile)
    && is_string($publicRoot)
    && str_starts_with($staticFile, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($staticFile)
) {
    return false;
}

require dirname(__DIR__) . '/public/index.php';
