<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composerAutoload = $root . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    // Landing funciona sem dependencias externas no primeiro commit.
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Cardapim\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = $root . '/app/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
require_once $root . '/app/Core/helpers.php';
date_default_timezone_set('America/Fortaleza');
