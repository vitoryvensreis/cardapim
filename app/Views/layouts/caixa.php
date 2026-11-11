<?php
/**
 * Layout do modo caixa: menu lateral proprio + situacao do caixa + fila de delivery colada no menu.
 * Usa a estrutura comum dos paineis (layouts/painel.php).
 * Espera: $content, $usuario, $csrf, $restaurante, $ativo, $tituloTela, $caixaAtual, $filaDelivery.
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Realtime\TempoReal;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Painel\MenuCaixa;
use Cardapim\Services\Painel\MenuGerencia;

$slug = (string) $restaurante['slug'];
$ativosFila = array_values(array_filter($filaDelivery, static fn (array $p): bool => $p['status'] !== 'aguardando_pagamento'));
$novos = count(array_filter($ativosFila, static fn (array $p): bool => $p['status'] === 'recebido'));
$grupos = [];
foreach (MenuCaixa::GRUPOS as $grupo => $chaves) {
    foreach ($chaves as $chave) {
        [$rotulo, $icone] = MenuCaixa::ITENS[$chave];
        $tag = match ($chave) {
            'caixa' => $caixaAtual !== null ? 'Aberto' : 'Fechado',
            'delivery' => $novos > 0 ? (string) $novos . ' novo' . ($novos > 1 ? 's' : '') : null,
            default => null,
        };
        $grupos[$grupo][] = [MenuCaixa::url($slug, $chave), $rotulo, $icone, $ativo === $chave, $tag];
    }
}
if (in_array($usuario->cargo, ['gerente', Autorizacao::CARGO_MASTER], true)) {
    $grupos['Gerência'][] = [MenuGerencia::url($slug, 'visao-geral'), 'Voltar à gerência', 'arrow-left', false, null];
}
ob_start();
require __DIR__ . '/../caixa/_fila.php';
$lateral = (string) ob_get_clean();

$painel = [
    'descricao' => 'Modo caixa do ' . marca() . '.',
    'menu_rotulo' => 'Menu do caixa',
    'inicio' => MenuCaixa::url($slug, 'caixa'),
    'inicio_rotulo' => marca() . ', caixa',
    'contexto' => ['rotulo' => 'Modo caixa', 'nome' => (string) $restaurante['nome'], 'icone' => 'monitor'],
    'grupos' => $grupos,
    'lateral_extra' => $lateral,
    'trilha' => 'Caixa',
    'titulo_atual' => (string) ($tituloTela ?? 'Caixa'),
    'acao_topo' => $caixaAtual === null ? [MenuCaixa::url($slug, 'caixa'), 'Abrir caixa', 'lock-open'] : null,
    'tempo_real' => TempoReal::ativo()
        ? ['ticket' => '/api/v1/tempo-real/ticket?restaurante=' . rawurlencode($slug), 'canais' => 'pedidos,mesas,caixa']
        : null,
];
$pageStyles = ['css/caixa.css', ...($pageStyles ?? [])];
$pageScripts = ['js/forms.js', 'js/caixa.js', ...($pageScripts ?? [])];
$pageTitle ??= 'Caixa | ' . marca();
require __DIR__ . '/painel.php';
