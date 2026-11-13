<?php
/** Clientes > Cupons: lista com desconto, regras, usos e situacao. Espera: $cupons, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Services\Descontos\Desconto;
use Cardapim\Services\Gerencia\CupomService;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$situacoes = [
    'ativo' => ['Ativo', 'etiqueta-ok', 'circle-check'],
    'pausado' => ['Pausado', 'etiqueta-neutra', 'circle-pause'],
    'vencido' => ['Vencido', 'etiqueta-alerta', 'calendar'],
    'esgotado' => ['Esgotado', 'etiqueta-alerta', 'ban'],
];
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Cupons</h1>
            <p>O cliente digita o código ao finalizar o pedido no cardápio online e o desconto entra na hora.</p>
        </div>
        <?php if ($cupons !== []): ?>
            <div class="painel-acoes"><a class="btn btn-primary btn-sm" href="<?= $e($base . '/cupons/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Novo cupom</a></div>
        <?php endif; ?>
    </header>

    <?php if ($cupons === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('ticket-percent') ?>
                <strong>Nenhum cupom ainda</strong>
                <p>Crie um código como <strong>BEMVINDO10</strong> para dar 10% de desconto, ou <strong>FRETE5</strong> para R$ 5,00. Você decide se ele vale uma vez ou várias.</p>
                <p class="lista-vazia-acao"><a class="btn btn-primary btn-sm" href="<?= $e($base . '/cupons/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Criar o primeiro cupom</a></p>
            </div>
        </section>
    <?php else: ?>
        <div class="ferramentas">
            <label class="busca">
                <?= Icon::svg('search', 'icon-sm') ?>
                <span class="sr-only">Buscar cupom</span>
                <input type="search" placeholder="Buscar pelo código" data-filtro="#lista-cupons" data-filtro-vazio="#cupons-nenhum" autocomplete="off">
            </label>
        </div>
        <ul class="lista lista-cupons" id="lista-cupons">
            <li class="lista-cabecalho" aria-hidden="true"><span>Cupom</span><span>Regras</span><span>Usos</span><span>Situação</span><span class="lista-cab-acoes">Ações</span></li>
            <?php foreach ($cupons as $c):
                $id = (int) $c['id'];
                [$rotulo, $classe, $icone] = $situacoes[$c['situacao']];
                $ativo = (int) $c['ativo'] === 1;
                $usos = (int) $c['usos'];
            ?>
                <li class="lista-item<?= $c['situacao'] === 'ativo' ? '' : ' is-inativo' ?>" data-filtro-item="<?= $e((string) $c['codigo']) ?>">
                    <div class="lista-principal">
                        <span class="lista-icone" aria-hidden="true"><?= Icon::svg('ticket-percent', 'icon-sm') ?></span>
                        <span class="lista-titulo">
                            <a href="<?= $e($base . '/cupons/' . $id) ?>"><strong class="cl-codigo"><?= $e((string) $c['codigo']) ?></strong></a>
                            <small><?= $e(Desconto::rotulo((string) $c['tipo'], (string) $c['valor'])) ?> de desconto</small>
                        </span>
                    </div>
                    <span class="lista-dado" data-rotulo="Regras"><?php $regras = CupomService::resumo($c, false); ?><?= $e(mb_strtoupper(mb_substr($regras, 0, 1)) . mb_substr($regras, 1)) ?></span>
                    <span class="lista-dado" data-rotulo="Usos">
                        <?= $c['limite_usos'] !== null ? $usos . ' de ' . (int) $c['limite_usos'] : $usos . ($usos === 1 ? ' uso' : ' usos') ?>
                        <?php if (Numero::centavos((string) $c['descontado']) > 0): ?><small class="texto-suave">· <?= $e(Numero::reais((string) $c['descontado'])) ?> em descontos</small><?php endif; ?>
                    </span>
                    <span class="lista-dado" data-rotulo="Situação"><span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $rotulo ?></span></span>
                    <div class="lista-acoes">
                        <a class="btn-icone" href="<?= $e($base . '/cupons/' . $id) ?>"><?= Icon::svg('pencil', 'icon-sm') ?><span>Editar</span></a>
                        <form method="post" action="<?= $e($base . '/cupons/' . $id . '/pausar') ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn-icone" type="submit"><?= Icon::svg($ativo ? 'circle-pause' : 'circle-play', 'icon-sm') ?><span><?= $ativo ? 'Pausar' : 'Ativar' ?></span></button>
                        </form>
                        <form method="post" action="<?= $e($base . '/cupons/' . $id . '/excluir') ?>"
                              data-confirm="<?= $e($usos > 0 ? 'Este cupom já foi usado em pedidos e não pode ser excluído. Use Pausar.' : 'O código ' . $c['codigo'] . ' deixa de funcionar na hora.') ?>"
                              data-confirm-titulo="Excluir o cupom <?= $e((string) $c['codigo']) ?>?" data-confirm-acao="Excluir cupom" data-confirm-perigo="1">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn-icone is-perigo" type="submit" aria-label="Excluir o cupom <?= $e((string) $c['codigo']) ?>"><?= Icon::svg('trash', 'icon-sm') ?></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="lista-vazia" id="cupons-nenhum" hidden>Nenhum cupom com esse código.</p>
    <?php endif; ?>
</div>
