<?php
/** Rodape publico compartilhado. Espera: $logoBranca, $textoRodape (sem escape). */
use Cardapim\Core\Icon;

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$footerColumns = [
    'Produto' => [
        ['/#recursos', 'Recursos', 'layout-dashboard'],
        ['/#como-funciona', 'Como funciona', 'zap'],
        ['/#planos', 'Planos', 'wallet'],
        ['/#para-quem', 'Para quem é', 'chef-hat'],
        ['/catalogo', 'Restaurantes', 'store'],
    ],
    'Acesso' => [
        ['/login', 'Entrar', 'log-in'],
        ['/cadastro', 'Criar conta', 'user-plus'],
        ['/status', 'Status do sistema', 'activity'],
    ],
    'Legal' => [
        ['/privacidade', 'Privacidade', 'shield-check'],
        ['/termos-de-uso', 'Termos de Uso', 'file-text'],
        ['/cookies', 'Cookies', 'cookie'],
        ['/exclusao-de-dados', 'Exclusão de Dados', 'trash'],
        ['/tratamento-de-dados', 'Tratamento de Dados', 'scale'],
    ],
];
?>
<footer class="site-footer" aria-labelledby="footer-title">
    <h2 id="footer-title" class="sr-only">Rodapé do Cardapim</h2>
    <div class="shell">
        <div class="footer-grid">
            <div class="footer-brand">
                <a class="brand" href="/" aria-label="Cardapim, página inicial">
                    <img src="<?= $e($logoBranca) ?>" width="152" height="49" alt="Cardapim">
                </a>
                <p class="footer-tagline"><?= $e($textoRodape) ?> Pedidos, mesas, caixa, cozinha e delivery no mesmo ritmo.</p>
                <a class="btn btn-primary btn-sm footer-cta" href="/cadastro">Criar minha conta <?= Icon::svg('arrow-up-right', 'icon-move') ?></a>
            </div>
            <?php foreach ($footerColumns as $heading => $links): ?>
                <nav class="footer-col" aria-label="<?= $e($heading) ?>">
                    <h3><?= $e($heading) ?></h3>
                    <ul>
                        <?php foreach ($links as [$href, $label, $icon]): ?>
                            <li>
                                <a class="footer-link" href="<?= $e($href) ?>"<?= $currentPath === $href ? ' aria-current="page"' : '' ?>>
                                    <?= Icon::svg($icon) ?><span><?= $e($label) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endforeach; ?>
        </div>
        <div class="footer-bottom">
            <div class="footer-company">
                <p>© <?= date('Y') ?> Cardapim. Todos os direitos reservados.</p>
                <p>Um produto da Escotilha Produtora · CNPJ 64.069.224/0001-32</p>
            </div>
            <button class="back-to-top" type="button" data-back-to-top>
                <?= Icon::svg('arrow-up') ?>Voltar ao topo
            </button>
        </div>
    </div>
</footer>
