<?php
/**
 * Layout do painel do gerente: monta o menu do restaurante e usa a estrutura comum (layouts/painel.php).
 * Espera: $content, $usuario (UsuarioLogado), $csrf, $restaurante (id, nome, slug), $ativo (chave do menu).
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Realtime\Canais;
use Cardapim\Realtime\TempoReal;
use Cardapim\Services\Painel\MenuGerencia;

$slug = (string) $restaurante['slug'];
$grupos = [];
foreach (MenuGerencia::GRUPOS as $grupo => $chaves) {
    foreach ($chaves as $chave) {
        $area = MenuGerencia::AREAS[$chave] ?? null;
        $grupos[$grupo][] = [
            MenuGerencia::url($slug, $chave),
            $area['rotulo'] ?? MenuGerencia::SECOES[$chave]['rotulo'],
            $area['icone'] ?? MenuGerencia::SECOES[$chave]['icone'],
            $ativo === ($area['area'] ?? $chave),
            $area !== null && $area['area'] === 'caixa' ? 'Tela cheia' : null,
        ];
    }
}
$painel = [
    'descricao' => 'Painel do gerente no ' . marca() . '.',
    'menu_rotulo' => 'Menu da gerência',
    'inicio' => MenuGerencia::url($slug, 'visao-geral'),
    'inicio_rotulo' => marca() . ', visão geral',
    'contexto' => ['rotulo' => 'Restaurante', 'nome' => (string) $restaurante['nome'], 'icone' => 'store'],
    'grupos' => $grupos,
    'trilha' => (string) $restaurante['nome'],
    'titulo_atual' => $ativo !== null && isset(MenuGerencia::SECOES[$ativo]) ? MenuGerencia::SECOES[$ativo]['rotulo']
        : (MenuGerencia::AREAS['@' . $ativo]['rotulo'] ?? 'Gerência'),
    'acao_topo' => [MenuGerencia::url($slug, '@caixa'), 'Abrir caixa', 'monitor'],
    'tempo_real' => TempoReal::ativo()
        ? ['ticket' => '/api/v1/tempo-real/ticket?restaurante=' . rawurlencode($slug), 'canais' => implode(',', Canais::daSecao($ativo))]
        : null,
];
$pageTitle ??= 'Gerência | ' . marca();
require __DIR__ . '/painel.php';
