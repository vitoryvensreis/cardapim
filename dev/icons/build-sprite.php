<?php

declare(strict_types=1);

// Gera public/assets/icons/lucide.svg a partir dos SVGs oficiais do Lucide (ISC).
// Uso: php dev/icons/build-sprite.php /caminho/para/lucide/icons
if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_dir($argv[1])) {
    fwrite(STDERR, "Uso: php dev/icons/build-sprite.php <pasta lucide/icons>\n");
    exit(2);
}

$source = rtrim($argv[1], '/');
$names = array_filter(array_map('trim', file(__DIR__ . '/icons.txt') ?: []), static fn (string $line): bool => $line !== '' && $line[0] !== '#');
$symbols = [];
foreach ($names as $name) {
    if (preg_match('/\A[a-z0-9-]+\z/', $name) !== 1) {
        throw new RuntimeException("Nome invalido: $name");
    }
    $svg = file_get_contents("$source/$name.svg");
    if ($svg === false || preg_match('~<svg[^>]*>(.*)</svg>~s', $svg, $match) !== 1) {
        throw new RuntimeException("Icone nao encontrado: $name");
    }
    $inner = preg_replace('/\s+/', ' ', trim($match[1]));
    $inner = str_replace(' />', '/>', (string) $inner);
    $symbols[] = sprintf('<symbol id="%s" viewBox="0 0 24 24">%s</symbol>', $name, str_replace('> <', '><', $inner));
}

$sprite = "<svg xmlns=\"http://www.w3.org/2000/svg\">\n<!-- Lucide Icons (ISC License) https://lucide.dev - gerado por dev/icons/build-sprite.php -->\n"
    . implode("\n", $symbols) . "\n</svg>\n";
file_put_contents(dirname(__DIR__, 2) . '/public/assets/icons/lucide.svg', $sprite);
echo count($symbols) . " icones gravados.\n";
