<?php
/**
 * Publicar o cardapio no iFood a partir do Deliveryzim. Espera: $pronto, $categorias (IfoodPublicacao::painel), $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\MidiaUrl;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$situacoes = [
    'publicado' => ['etiqueta-ok', 'circle-check', 'No iFood'],
    'pendente' => ['etiqueta-atencao', 'clock', 'Enviando'],
    'erro' => ['etiqueta-alerta', 'circle-exclamation-point', 'Com erro'],
    'nao' => ['etiqueta-neutra', 'minus', 'Não publicado'],
];
$total = array_sum(array_map(static fn (array $c): int => count($c['produtos']), $categorias));
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes/ifood') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>iFood</a>
            <h1>Publicar cardápio no iFood</h1>
            <p>O cardápio do <?= marca_html() ?> vai para o iFood com categoria, foto, preço e complementos. Você cadastra uma vez aqui.</p>
        </div>
    </header>

    <?php if (!$pronto): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('clock') ?>
                <strong>A publicação está sendo liberada</strong>
                <p>Falta uma atualização do sistema no servidor. Assim que for feita, você publica o cardápio por aqui.</p>
            </div>
        </section>
    <?php else: ?>
        <section class="bloco" aria-labelledby="titulo-como-publicar">
            <div class="bloco-cabecalho"><h2 id="titulo-como-publicar"><?= Icon::svg('info') ?>Como funciona</h2></div>
            <ol class="passos-2fa">
                <li><strong>Cadastre em Estoque</strong> a categoria, o produto (com foto) e os complementos.</li>
                <li><strong>Marque aqui</strong> os produtos e toque em "Publicar no iFood".</li>
                <li><strong>Depois é automático:</strong> mudou preço, nome, foto ou pausou um complemento aqui, o iFood é atualizado em até 1 minuto. Para ver na hora, toque em "Enviar mudanças agora".</li>
            </ol>
        </section>

        <?php if ($total === 0): ?>
            <section class="bloco">
                <div class="lista-vazia">
                    <?= Icon::svg('package') ?>
                    <strong>Nenhum produto cadastrado</strong>
                    <p>Cadastre os produtos em Estoque e volte aqui.</p>
                    <a class="btn btn-primary btn-sm" href="<?= $e($base . '/estoque/produtos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Cadastrar produto</a>
                </div>
            </section>
        <?php else: ?>
            <form method="post" action="<?= $e($base . '/integracoes/ifood/publicar') ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <?php foreach ($categorias as $cat): ?>
                    <section class="bloco ifood-cardapio" aria-label="<?= $e($cat['nome']) ?>">
                        <h2 class="ifood-publicar-categoria"><?= $e($cat['nome']) ?></h2>
                        <ul class="ifood-itens">
                            <?php foreach ($cat['produtos'] as $p): [$classe, $icone, $rotulo] = $situacoes[$p['situacao']] ?? $situacoes['nao']; ?>
                                <li class="ifood-publicar-item">
                                    <input type="checkbox" id="pub-<?= (int) $p['id'] ?>" name="produtos[]" value="<?= (int) $p['id'] ?>"<?= $p['por_unidade'] ? '' : ' disabled' ?>>
                                    <?php if ($p['imagem']): ?>
                                        <img src="<?= $e(MidiaUrl::publica((string) $p['imagem'])) ?>" alt="" width="56" height="56" loading="lazy">
                                    <?php else: ?>
                                        <span class="ifood-sem-foto" aria-hidden="true"><?= Icon::svg('image', 'icon-sm') ?></span>
                                    <?php endif; ?>
                                    <label for="pub-<?= (int) $p['id'] ?>">
                                        <strong><?= $e($p['nome']) ?></strong>
                                        <small><?= $e($p['preco']) ?><?= $p['grupos'] !== [] ? ' · Complementos: ' . $e(implode(', ', $p['grupos'])) : '' ?></small>
                                        <?php if (!$p['por_unidade']): ?><small>Vendido por quilo ou litro: publique pelo Portal do Parceiro.</small><?php endif; ?>
                                        <?php if (!$p['imagem'] && $p['por_unidade']): ?><small>Sem foto: o iFood recomenda foto em todos os itens.</small><?php endif; ?>
                                        <?php if ($p['situacao'] === 'erro' && $p['erro']): ?><small class="texto-alerta"><?= $e((string) $p['erro']) ?></small><?php endif; ?>
                                    </label>
                                    <span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $rotulo ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
                <div class="form-acoes ifood-publicar-acoes">
                    <button class="btn btn-primary" type="submit" data-submit-label="Publicando..."><?= Icon::svg('send', 'icon-sm') ?>Publicar no iFood</button>
                </div>
            </form>
            <form class="bloco-rodape" method="post" action="<?= $e($base . '/integracoes/ifood/publicar/sincronizar') ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-outline" type="submit" data-submit-label="Enviando..."><?= Icon::svg('refresh-cw', 'icon-sm') ?>Enviar mudanças agora</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
