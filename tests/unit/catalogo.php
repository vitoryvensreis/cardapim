<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/catalogo.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\RateLimiter;
use Cardapim\Services\CatalogoCursor;
use Cardapim\Shared\RestauranteLogo;
use Cardapim\Shared\Slug;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};

// Slug
$ok(Slug::formatoValido('restaurante-modelo-cardapim'), 'slug valido');
$ok(!Slug::formatoValido('Restaurante'), 'slug com maiuscula');
$ok(!Slug::formatoValido('a--b') && !Slug::formatoValido('-a') && !Slug::formatoValido('favicon.ico'), 'slug malformado');
$ok(Slug::reservado('catalogo') && Slug::reservado('login') && Slug::reservado('api'), 'slugs reservados');
$ok(Slug::gerar('Cantina do Zé & Cia!') === 'cantina-do-ze-cia', 'gera slug sem acento');

// Cursor
$cursor = CatalogoCursor::codificar(['Pizzaria São João', 42]);
$ok(CatalogoCursor::decodificar($cursor) === ['Pizzaria São João', 42], 'cursor ida e volta');
$ok(CatalogoCursor::decodificar('nao$valido') === null, 'cursor com caractere invalido');
$ok(CatalogoCursor::decodificar(rtrim(strtr(base64_encode('["x","1"]'), '+/', '-_'), '=')) === null, 'cursor com id texto');
$ok(CatalogoCursor::decodificar(rtrim(strtr(base64_encode('["x",0]'), '+/', '-_'), '=')) === null, 'cursor com id zero');
$ok(CatalogoCursor::decodificar(null) === null && CatalogoCursor::decodificar('') === null, 'cursor vazio');

// Logo
$ok(RestauranteLogo::urlSegura('/storage/uploads/logo.png') === '/storage/uploads/logo.png', 'logo local');
$ok(RestauranteLogo::urlSegura('https://cdn.exemplo.com/logo.webp') !== null, 'logo https');
$ok(RestauranteLogo::urlSegura('javascript:alert(1)') === null, 'logo javascript');
$ok(RestauranteLogo::urlSegura('/assets/../.env') === null, 'logo com ..');
$ok(RestauranteLogo::urlSegura('http://inseguro.com/a.png') === null, 'logo http');
$ok(RestauranteLogo::urlSegura('/x.png" onerror="a') === null, 'logo com aspas');
$ok(RestauranteLogo::iniciais('Restaurante Modelo Cardapim') === 'RM', 'iniciais');
$ok(RestauranteLogo::iniciais('Casa da Pizza') === 'CP', 'iniciais ignoram preposicao');
$ok(RestauranteLogo::iniciais('ácaí da praia') === 'ÁP', 'iniciais com acento');

// Limite por IP
$dir = sys_get_temp_dir() . '/cardapim-rl-' . bin2hex(random_bytes(4));
$limiter = new RateLimiter($dir, 3, 60);
$ok($limiter->permitir('ip') && $limiter->permitir('ip') && $limiter->permitir('ip'), 'tres tentativas permitidas');
$ok(!$limiter->permitir('ip'), 'quarta tentativa bloqueada');
$ok($limiter->permitir('outro-ip'), 'outra chave independente');
array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);

if ($falhas > 0) {
    exit(1);
}
echo "Catalogo: slug, cursor, logo e limite por IP validados.\n";
