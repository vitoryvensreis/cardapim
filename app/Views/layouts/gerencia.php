<?php
/**
 * Layout do painel do gerente: monta o menu do restaurante e usa a estrutura comum (layouts/painel.php).
 * Espera: $content, $usuario (UsuarioLogado), $csrf, $restaurante (id, nome, slug), $ativo (chave do menu).
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Realtime\Canais;
use Cardapim\Realtime\TempoReal;
use Cardapim\Core\Database;
use Cardapim\Repositories\SuporteRepository;
use Cardapim\Services\Painel\MenuGerencia;

$slug = (string) $restaurante['slug'];
// Resposta nova do suporte vira um selo no menu (nunca derruba a tela).
try {
    $suporteNovidades = (new SuporteRepository(Database::connect()))->novidadesRestaurante((int) ($restaurante['id'] ?? 0));
} catch (\Throwable) {
    $suporteNovidades = 0;
}
$grupos = [];
foreach (MenuGerencia::GRUPOS as $grupo => $chaves) {
    foreach ($chaves as $chave) {
        $area = MenuGerencia::AREAS[$chave] ?? null;
        $grupos[$grupo][] = [
            MenuGerencia::url($slug, $chave),
            $area['rotulo'] ?? MenuGerencia::SECOES[$chave]['rotulo'],
            $area['icone'] ?? MenuGerencia::SECOES[$chave]['icone'],
            $ativo === ($area['area'] ?? $chave),
            $area !== null && $area['area'] === 'caixa' ? 'Tela cheia' : ($chave === 'suporte' && $suporteNovidades > 0 ? 'Resposta nova' : null),
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
    'alerta_pedido' => '/' . rawurlencode($slug) . '/caixa/delivery',
];
$pageTitle ??= 'Gerência | ' . marca();
require __DIR__ . '/painel.php';
