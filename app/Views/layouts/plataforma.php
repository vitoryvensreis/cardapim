<?php
/**
 * Layout do painel da plataforma (adm_master): monta o menu da administracao e usa layouts/painel.php.
 * Espera: $content, $usuario (UsuarioLogado), $csrf, $ativo (chave do menu).
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Core\Database;
use Cardapim\Repositories\SuporteRepository;
use Cardapim\Services\Painel\MenuPlataforma;

// Chamados esperando resposta viram um selo no menu (nunca derruba a tela).
try {
    $suporteAguardando = (new SuporteRepository(Database::connect()))->aguardandoPlataforma();
} catch (\Throwable) {
    $suporteAguardando = 0;
}
$grupos = [];
foreach (MenuPlataforma::GRUPOS as $grupo => $chaves) {
    foreach ($chaves as $chave) {
        $secaoMenu = MenuPlataforma::SECOES[$chave];
        $grupos[$grupo][] = [MenuPlataforma::url($chave), $secaoMenu['rotulo'], $secaoMenu['icone'], $ativo === $chave,
            $chave === 'suporte' && $suporteAguardando > 0 ? ($suporteAguardando === 1 ? '1 novo' : $suporteAguardando . ' novos') : null];
    }
}
$painel = [
    'descricao' => 'Administração da plataforma ' . marca() . '.',
    'menu_rotulo' => 'Menu da plataforma',
    'inicio' => MenuPlataforma::url('dashboard'),
    'inicio_rotulo' => marca() . ', dashboard da plataforma',
    'contexto' => ['rotulo' => 'Administração', 'nome' => 'Plataforma ' . marca(), 'icone' => 'shield-check'],
    'grupos' => $grupos,
    'trilha' => 'Plataforma',
    'titulo_atual' => MenuPlataforma::SECOES[$ativo]['rotulo'] ?? 'Plataforma',
    'acao_topo' => null,
    'tempo_real' => null,
];
$pageTitle ??= 'Plataforma | ' . marca();
require __DIR__ . '/painel.php';
