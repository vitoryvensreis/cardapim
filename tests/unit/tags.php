<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Plataforma\TagsService;
use Cardapim\Shared\Consentimento;

function conferir(bool $ok, string $mensagem): void {
    if (!$ok) {
        fwrite(STDERR, $mensagem . PHP_EOL);
        exit(1);
    }
}
$ga = ['id' => 'G-ABC123', 'ativo' => '1', 'escopos' => ['inicio', 'inicio']];
[$v, $erros] = TagsService::validar(['ga4' => $ga]);
conferir($erros === [] && $v['ga4']['escopos'] === ['inicio'] && $v['ga4']['ativo'], 'GA4 valido');
conferir($v['google_advanced'] === false, 'Configuracao anterior continua no modo basico');
[$v, $erros] = TagsService::validar(['ga4' => $ga, 'google_advanced' => '1']);
conferir($erros === [] && $v['google_advanced'] === true, 'Modo avancado explicito');
[, $erros] = TagsService::validar(['google_advanced' => ['1']]);
conferir($erros !== [], 'Modo avancado invalido rejeitado');
foreach ([
    ['ga4' => [...$ga, 'id' => '<script>alert(1)</script>']],
    ['ga4' => [...$ga, 'escopos' => ['checkout']]],
    ['ga4' => [...$ga, 'escopos' => []]],
    ['ga4' => $ga, 'gtm' => ['id' => 'GTM-ABC123', 'ativo' => '1', 'escopos' => ['inicio']]],
    ['meta' => ['id' => 'abc', 'ativo' => '1', 'escopos' => ['catalogo']]],
] as $invalido) {
    [, $erros] = TagsService::validar($invalido);
    conferir($erros !== [], 'Configuracao invalida aceita');
}
[, $erros] = TagsService::validar(['ga4' => $ga, 'gtm' => ['id' => 'GTM-ABC123', 'ativo' => '1', 'escopos' => ['catalogo']]]);
conferir($erros === [], 'Escopos distintos permitidos');
conferir(TagsService::publicas(null) === [] && TagsService::publicas('checkout') === [], 'Escopo privado bloqueado sem banco');
foreach (['todos.20261112' => false, 'todos.v2.20261112' => true, 'necessarios.v2.20261112' => false, '<script>' => false] as $cookie => $esperado) {
    $_COOKIE[Consentimento::COOKIE] = $cookie;
    conferir(Consentimento::aceitouTodos() === $esperado, 'Consentimento incorreto');
}
echo "OK: tags e consentimento\n";
