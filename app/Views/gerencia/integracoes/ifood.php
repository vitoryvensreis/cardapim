<?php
/** iFood. Espera: $integracao, $disponivel, $base, $csrf. */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = static fn (?string $v): string => $v ? date('d/m/Y H:i', (int) strtotime($v)) : '-';
$statusIntegracao = $integracao['status'];
$d = $integracao['dados'];
$lojas = is_array($d['lojas'] ?? null) ? $d['lojas'] : [];
$expira = (int) ($d['expira'] ?? 0);
$codigoValido = $expira > time();
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Integrações</a>
            <h1>iFood</h1>
            <p>Ligue a sua loja do iFood ao <?= marca_html() ?>.</p>
        </div>
        <?php require __DIR__ . '/_status.php'; ?>
    </header>

    <?php if (!$disponivel): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('clock') ?>
                <strong>A integração com o iFood ainda não foi liberada</strong>
                <p>O <?= marca_html() ?> está concluindo o cadastro como parceiro do iFood. Quando estiver liberado, você conecta a sua loja por aqui em poucos passos.</p>
            </div>
        </section>
    <?php elseif ($statusIntegracao === 'desconectado'): ?>
        <section class="bloco" aria-labelledby="titulo-como">
            <div class="bloco-cabecalho"><h2 id="titulo-como"><?= Icon::svg('info') ?>Como funciona</h2></div>
            <ol class="passos-2fa">
                <li><strong>Gere um código de vínculo</strong> aqui no <?= marca_html() ?>.</li>
                <li><strong>Entre no Portal do Parceiro iFood</strong> com a conta da loja e digite o código para autorizar o <?= marca_html() ?>.</li>
                <li><strong>Copie o código de autorização</strong> que o iFood mostrar e cole aqui.</li>
            </ol>
            <form class="bloco-rodape" method="post" action="<?= $e($base . '/integracoes/ifood/iniciar') ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-primary" type="submit" data-submit-label="Gerando..."><?= Icon::svg('link', 'icon-sm') ?>Gerar código de vínculo</button>
            </form>
        </section>
    <?php elseif ($statusIntegracao === 'pendente' && $lojas !== []): ?>
        <section class="bloco" aria-labelledby="titulo-loja">
            <div class="bloco-cabecalho">
                <h2 id="titulo-loja"><?= Icon::svg('store') ?>Qual loja é deste restaurante?</h2>
                <p>A sua conta do iFood tem mais de uma loja. Escolha a que corresponde a este restaurante.</p>
            </div>
            <form method="post" action="<?= $e($base . '/integracoes/ifood/loja') ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <fieldset class="opcoes-modo">
                    <legend class="sr-only">Lojas do iFood</legend>
                    <?php foreach ($lojas as $i => $loja): ?>
                        <label class="opcao-modo">
                            <input type="radio" name="loja" value="<?= $e((string) $loja['id']) ?>"<?= $i === 0 ? ' checked' : '' ?>>
                            <span><strong><?= $e((string) $loja['nome']) ?></strong><small>Código no iFood: <?= $e((string) $loja['id']) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="bloco-rodape"><button class="btn btn-primary" type="submit"><?= Icon::svg('check', 'icon-sm') ?>Usar esta loja</button></div>
            </form>
        </section>
    <?php elseif ($statusIntegracao === 'pendente'): ?>
        <section class="bloco" aria-labelledby="titulo-vinculo">
            <div class="bloco-cabecalho"><h2 id="titulo-vinculo"><?= Icon::svg('link') ?>Autorize o <?= marca_html() ?> no iFood</h2></div>
            <?php if (!$codigoValido): ?>
                <div class="notice notice-warning"><?= Icon::svg('clock') ?><div><strong>O código de vínculo venceu.</strong><p>Ele vale 10 minutos. Gere um novo para continuar.</p></div></div>
                <form method="post" action="<?= $e($base . '/integracoes/ifood/iniciar') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('refresh-cw', 'icon-sm') ?>Gerar novo código</button>
                </form>
            <?php else: ?>
                <ol class="passos-2fa">
                    <li>
                        <strong>Seu código de vínculo:</strong>
                        <p><span class="codigo-grande" data-codigo-vinculo><?= $e((string) ($d['codigo'] ?? '')) ?></span>
                            <button class="link-acao" type="button" data-copiar="<?= $e((string) ($d['codigo'] ?? '')) ?>"><?= Icon::svg('copy', 'icon-sm') ?>Copiar</button>
                            <span class="texto-suave" data-copiado role="status"></span></p>
                        <p class="texto-suave" data-expira="<?= $expira ?>">Vale até <?= $e(date('H:i', $expira)) ?>.</p>
                    </li>
                    <li>
                        <strong>Abra o Portal do Parceiro iFood</strong>, entre com a conta da loja e autorize o <?= marca_html() ?> com esse código.
                        <p><a class="btn btn-wine btn-sm" href="<?= $e((string) ($d['url'] ?? 'https://portal.ifood.com.br/apps/code')) ?>" target="_blank" rel="noopener noreferrer"><?= Icon::svg('arrow-up-right', 'icon-sm') ?>Abrir Portal do Parceiro</a></p>
                    </li>
                    <li>
                        <strong>Cole aqui o código de autorização</strong> que o iFood mostrar no final (vale 5 minutos).
                        <form class="form-codigo" method="post" action="<?= $e($base . '/integracoes/ifood/concluir') ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <label class="sr-only" for="codigo-autorizacao">Código de autorização do iFood</label>
                            <input class="campo-simples campo-codigo" id="codigo-autorizacao" name="codigo" maxlength="40" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="ABCD-EFGH">
                            <button class="btn btn-primary" type="submit" data-submit-label="Conectando..."><?= Icon::svg('check', 'icon-sm') ?>Concluir conexão</button>
                        </form>
                    </li>
                </ol>
            <?php endif; ?>
            <?php if ($integracao['ultimo_erro']): ?><p class="texto-alerta"><?= $e((string) $integracao['ultimo_erro']) ?></p><?php endif; ?>
            <form class="bloco-rodape" method="post" action="<?= $e($base . '/integracoes/ifood/cancelar') ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-outline btn-sm" type="submit">Cancelar</button>
            </form>
        </section>
    <?php else: ?>
        <section class="bloco" aria-labelledby="titulo-conectado">
            <div class="bloco-cabecalho"><h2 id="titulo-conectado"><?= Icon::svg('shopping-bag') ?>Loja conectada</h2></div>
            <?php if ($integracao['ultimo_erro']): ?>
                <div class="notice notice-warning"><?= Icon::svg('circle-exclamation-point') ?><div><p><?= $e((string) $integracao['ultimo_erro']) ?></p></div></div>
            <?php endif; ?>
            <div class="resumo-assinatura">
                <div class="resumo-linha"><span>Loja no iFood</span><strong><?= $e((string) ($d['loja_nome'] ?? '-')) ?></strong></div>
                <div class="resumo-linha"><span>Conectada em</span><strong><?= $e($data($integracao['conectado_em'])) ?></strong></div>
                <div class="resumo-linha"><span>Última verificação</span><strong><?= $e($data($integracao['verificado_em'])) ?></strong></div>
            </div>
            <div class="notice notice-info"><?= Icon::svg('info') ?><div><p>Os pedidos do iFood vão aparecer no <?= marca_html() ?> quando o módulo de pedidos estiver pronto. A conexão já fica feita.</p></div></div>
            <div class="acoes-2fa">
                <form method="post" action="<?= $e($base . '/integracoes/ifood/testar') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('refresh-cw', 'icon-sm') ?>Testar conexão</button>
                </form>
                <form method="post" action="<?= $e($base . '/integracoes/ifood/desconectar') ?>"
                      data-confirm="O <?= marca_html() ?> apaga o acesso à loja. Para remover de vez, tire a autorização também no Portal do Parceiro." data-confirm-titulo="Desconectar o iFood?" data-confirm-acao="Desconectar" data-confirm-perigo="1">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline btn-sm link-perigo" type="submit"><?= Icon::svg('unplug', 'icon-sm') ?>Desconectar</button>
                </form>
            </div>
        </section>
    <?php endif; ?>
</div>
