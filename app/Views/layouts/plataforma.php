<?php
/**
 * Layout do painel da plataforma (adm_master): monta o menu da administracao e usa layouts/painel.php.
 * Espera: $content, $usuario (UsuarioLogado), $csrf, $ativo (chave do menu).
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Services\Painel\MenuPlataforma;

$grupos = [];
foreach (MenuPlataforma::GRUPOS as $grupo => $chaves) {
    foreach ($chaves as $chave) {
        $secaoMenu = MenuPlataforma::SECOES[$chave];
        $grupos[$grupo][] = [MenuPlataforma::url($chave), $secaoMenu['rotulo'], $secaoMenu['icone'], $ativo === $chave, null];
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
