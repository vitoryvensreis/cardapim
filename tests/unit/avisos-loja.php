<?php

declare(strict_types=1);

// Avisos da plataforma para as lojas: validacao do formulario e link do botao (sem banco).
// php tests/unit/avisos-loja.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Plataforma\AvisosLojaService as A;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};
$base = ['titulo' => 'Reconecte o WhatsApp', 'texto' => 'Toque no botão.', 'botao_destino' => 'whatsapp', 'botao_texto' => 'Reconectar agora',
    'botao_url' => '', 'inicio' => '2026-11-28T09:00', 'fim' => '2026-11-30T23:59', 'vezes_por_dia' => '2'];

[$v, $erros] = A::validar($base);
$ok($erros === [], 'aviso completo valido');
$ok($v['inicio_sql'] === '2026-11-28 09:00:00' && $v['fim_sql'] === '2026-11-30 23:59:00' && $v['vezes_por_dia'] === 2, 'datas e vezes normalizadas');
$ok(isset(A::validar(['titulo' => 'x'] + $base)[1]['titulo']), 'titulo curto recusado');
$ok(isset(A::validar(['fim' => '2026-11-28T08:00'] + $base)[1]['fim']), 'fim antes do inicio recusado');
$ok(isset(A::validar(['inicio' => 'ontem'] + $base)[1]['inicio']), 'data invalida recusada');
$ok(isset(A::validar(['vezes_por_dia' => '0'] + $base)[1]['vezes_por_dia']) && isset(A::validar(['vezes_por_dia' => '11'] + $base)[1]['vezes_por_dia']), 'vezes por dia de 1 a 10');
$ok(isset(A::validar(['botao_texto' => ''] + $base)[1]['botao_texto']), 'botao sem texto recusado');
$ok(isset(A::validar(['botao_destino' => 'qualquer'] + $base)[1]['botao_destino']), 'destino desconhecido recusado');
[$v, $erros] = A::validar(['botao_destino' => '', 'botao_texto' => 'x'] + $base);
$ok($erros === [] && $v['botao_texto'] === '', 'sem botao: ignora o texto do botao');
$ok(isset(A::validar(['botao_destino' => 'externo', 'botao_url' => 'http://site.com'] + $base)[1]['botao_url']), 'link externo sem https recusado');
$ok(isset(A::validar(['botao_destino' => 'externo', 'botao_url' => 'javascript:alert(1)'] + $base)[1]['botao_url']), 'link javascript recusado');
$ok(A::validar(['botao_destino' => 'externo', 'botao_url' => 'https://deliveryzim.com.br/ajuda'] + $base)[1] === [], 'link externo https aceito');

$ok(A::link(['botao_destino' => 'whatsapp'], 'mana-lanches') === '/mana-lanches/gerencia/integracoes/whatsapp', 'botao WhatsApp leva para a tela da propria loja');
$ok(A::link(['botao_destino' => 'visao-geral'], 'espetinho') === '/espetinho/gerencia', 'visao geral da loja');
$ok(A::link(['botao_destino' => 'externo', 'botao_url' => 'https://exemplo.com/x'], 'loja') === 'https://exemplo.com/x', 'link externo como cadastrado');
$ok(A::link(['botao_destino' => 'externo', 'botao_url' => 'javascript:x'], 'loja') === null, 'link externo inseguro nunca sai');
$ok(A::link(['botao_destino' => null], 'loja') === null, 'sem botao, sem link');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s) nos avisos para as lojas.\n");
    exit(1);
}
echo "OK: avisos para as lojas (validacao e links).\n";
