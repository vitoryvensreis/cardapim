<?php
/** Planos da plataforma. Espera: $planos (com 'restaurantes' e 'link'), $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Services\Plataforma\PlanoService;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Planos</h1>
            <p>O que os restaurantes podem assinar. Planos públicos aparecem no site; privados só com o link de convite.</p>
        </div>
        <?php if ($planos !== []): ?>
            <div class="painel-acoes">
                <a class="btn btn-primary btn-sm" href="<?= $e($base . '/planos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Novo plano</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($planos === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('tags') ?>
                <strong>Nenhum plano ainda</strong>
                <p>Crie o primeiro plano para os restaurantes poderem se cadastrar.</p>
                <p class="lista-vazia-acao"><a class="btn btn-primary btn-sm" href="<?= $e($base . '/planos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Criar plano</a></p>
            </div>
        </section>
    <?php else: ?>
        <div class="planos-admin">
            <?php foreach ($planos as $p):
                $publico = (int) $p['visibilidade'] === 1;
                $estaAtivo = (int) $p['ativo'] === 1;
                $gratis = (float) $p['valor'] <= 0;
                $qtd = (int) $p['restaurantes'];
            ?>
                <article class="plano-admin<?= $estaAtivo ? '' : ' is-pausado' ?>" id="plano-<?= (int) $p['id'] ?>">
                    <div class="plano-admin-topo">
                        <div>
                            <h2><?= $e((string) $p['nome']) ?></h2>
                            <p class="preco"><?php if ($gratis): ?>Grátis<?php else: ?><?= $e(Numero::reais((string) $p['valor'])) ?><?php endif; ?>
                                <small><?= $e($gratis && $p['periodo'] === 'vitalicio' ? 'para sempre' : (AssinaturaService::ROTULO_PERIODO[$p['periodo']] ?? '')) ?></small></p>
                        </div>
                        <div class="plano-admin-selos">
                            <?php if ($publico): ?>
                                <span class="etiqueta etiqueta-ok"><?= Icon::svg('globe') ?>Público</span>
                            <?php else: ?>
                                <span class="etiqueta etiqueta-marca"><?= Icon::svg('lock') ?>Só com convite</span>
                            <?php endif; ?>
                            <?php if (!$estaAtivo): ?><span class="etiqueta etiqueta-neutra"><?= Icon::svg('pause') ?>Pausado</span><?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($p['descricao'])): ?><p class="plano-admin-descricao"><?= $e((string) $p['descricao']) ?></p><?php endif; ?>
                    <p class="plano-admin-info"><?= Icon::svg('store', 'icon-sm') ?><?= $qtd === 0 ? 'Nenhum restaurante neste plano' : ($qtd === 1 ? '1 restaurante neste plano' : $qtd . ' restaurantes neste plano') ?>
                        · <?= $e(PlanoService::PERIODOS[$p['periodo']][0] ?? '') ?></p>

                    <?php if (!$publico && $p['link'] !== null): ?>
                        <div class="convite-caixa">
                            <span class="convite-rotulo"><?= Icon::svg('link', 'icon-sm') ?>Link de convite</span>
                            <code class="convite-link"><?= $e((string) $p['link']) ?></code>
                            <div class="convite-acoes">
                                <button class="btn btn-wine btn-sm" type="button" data-copiar="<?= $e((string) $p['link']) ?>"><?= Icon::svg('copy', 'icon-sm') ?>Copiar link</button>
                                <form method="post" action="<?= $e($base . '/planos/' . (int) $p['id'] . '/convite') ?>"
                                      data-confirm="Quem ainda não usou o link antigo vai precisar do novo." data-confirm-titulo="Criar um link novo?" data-confirm-acao="Criar link novo">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <button class="link-acao" type="submit"><?= Icon::svg('refresh-cw', 'icon-sm') ?>Trocar link</button>
                                </form>
                            </div>
                            <span class="texto-suave" data-copiado role="status"></span>
                        </div>
                    <?php endif; ?>

                    <div class="plano-admin-acoes">
                        <a class="btn btn-outline btn-sm" href="<?= $e($base . '/planos/' . (int) $p['id']) ?>"><?= Icon::svg('pencil', 'icon-sm') ?>Editar</a>
                        <form method="post" action="<?= $e($base . '/planos/' . (int) $p['id'] . '/ativo') ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg($estaAtivo ? 'pause' : 'play', 'icon-sm') ?><?= $estaAtivo ? 'Pausar' : 'Liberar' ?></button>
                        </form>
                        <?php if ($qtd === 0): ?>
                            <form method="post" action="<?= $e($base . '/planos/' . (int) $p['id'] . '/excluir') ?>"
                                  data-confirm="Se o plano já foi assinado alguma vez, ele não pode ser excluído (só pausado)." data-confirm-titulo="Excluir o plano <?= $e((string) $p['nome']) ?>?" data-confirm-acao="Excluir" data-confirm-perigo="1">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="link-acao link-perigo" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Excluir</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="texto-suave planos-nota"><?= Icon::svg('info', 'icon-sm') ?>Mudar o preço de um plano vale para quem assinar depois. Quem já assinou continua com o valor contratado.</p>
    <?php endif; ?>
</div>
