<?php
/**
 * Entradas (reforco de troco) e saidas (sangria, despesas) de dinheiro da gaveta.
 * Espera: $caixa, $tipo ('entrada'|'saida'), $resumo, $movimentos, $base, $csrf, $erros, $enviado.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lista = $tipo === 'entrada' ? CaixaService::ENTRADAS : CaixaService::SAIDAS;
$escolhida = (string) ($enviado['categoria'] ?? array_key_first($lista));
$todas = CaixaService::ENTRADAS + CaixaService::SAIDAS;
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Entradas e saídas</h1>
            <p>Registre todo dinheiro que entra ou sai da gaveta sem ser venda. Assim o caixa fecha certinho.</p>
        </div>
    </header>

    <?php if ($caixa === null): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('lock') ?><strong>O caixa está fechado</strong>
                <p>Abra o caixa para registrar entradas e saídas.</p>
                <p class="lista-vazia-acao"><a class="btn btn-primary btn-sm" href="<?= $e($base) ?>"><?= Icon::svg('lock-open', 'icon-sm') ?>Abrir caixa</a></p>
            </div>
        </section>
    <?php else: ?>
        <div class="caixa-colunas">
            <section class="bloco" aria-labelledby="titulo-registrar">
                <nav class="segmento" aria-label="Tipo">
                    <a href="<?= $e($base . '/movimentos?tipo=entrada') ?>"<?= $tipo === 'entrada' ? ' aria-current="true"' : '' ?>><?= Icon::svg('arrow-down-to-line', 'icon-sm') ?>Entrada</a>
                    <a href="<?= $e($base . '/movimentos?tipo=saida') ?>"<?= $tipo === 'saida' ? ' aria-current="true"' : '' ?>><?= Icon::svg('arrow-up-from-line', 'icon-sm') ?>Saída</a>
                </nav>
                <h2 class="sr-only" id="titulo-registrar">Registrar <?= $tipo === 'entrada' ? 'entrada' : 'saída' ?></h2>
                <form method="post" action="<?= $e($base . '/movimentos') ?>" novalidate data-validate-form>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
                    <fieldset class="escolhas">
                        <legend><?= $tipo === 'entrada' ? 'O que está entrando?' : 'Para que está saindo?' ?></legend>
                        <?php foreach ($lista as $chave => $rotulo): ?>
                            <label class="escolha"><input type="radio" name="categoria" value="<?= $e($chave) ?>"<?= $escolhida === $chave ? ' checked' : '' ?>><span><?= $e($rotulo) ?></span></label>
                        <?php endforeach; ?>
                        <?php if (isset($erros['categoria'])): ?><p class="field-msg is-error"><?= $e($erros['categoria']) ?></p><?php endif; ?>
                    </fieldset>
                    <?= Campo::texto(['nome' => 'valor', 'id' => 'mov-valor', 'rotulo' => 'Valor', 'valor' => $enviado['valor'] ?? '', 'icone' => 'banknote',
                        'erro' => $erros['valor'] ?? null, 'dica' => $tipo === 'saida' ? 'Na gaveta agora: ' . Numero::reais(CaixaService::dec($resumo['esperado'])) . '.' : null,
                        'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'placeholder' => '0,00']]) ?>
                    <?= Campo::texto(['nome' => 'descricao', 'id' => 'mov-descricao', 'rotulo' => 'Descrição', 'valor' => $enviado['descricao'] ?? '', 'icone' => 'pencil',
                        'opcional' => true, 'erro' => $erros['descricao'] ?? null, 'attrs' => ['maxlength' => 200, 'placeholder' => $tipo === 'entrada' ? 'Ex.: troco trazido pelo gerente' : 'Ex.: gelo, gás, Distribuidora Silva']]) ?>
                    <button class="btn btn-primary btn-block" type="submit"><?= Icon::svg($tipo === 'entrada' ? 'arrow-down-to-line' : 'arrow-up-from-line', 'icon-sm') ?>Registrar <?= $tipo === 'entrada' ? 'entrada' : 'saída' ?></button>
                </form>
            </section>

            <section class="bloco" aria-labelledby="titulo-lista-mov">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-lista-mov"><?= Icon::svg('arrow-left-right') ?>Neste caixa</h2>
                    <p><span class="valor-mais">Entradas +<?= $e(Numero::reais(CaixaService::dec($resumo['entradas']))) ?></span> · <span class="valor-menos">Saídas −<?= $e(Numero::reais(CaixaService::dec($resumo['saidas']))) ?></span></p>
                </div>
                <?php if ($movimentos === []): ?>
                    <p class="texto-suave">Nenhuma entrada ou saída registrada ainda.</p>
                <?php else: ?>
                    <ul class="caixa-linhas">
                        <?php foreach ($movimentos as $m): $entrada = $m['tipo'] === 'entrada'; ?>
                            <li>
                                <span><strong><?= $e($todas[$m['categoria']] ?? (string) $m['categoria']) ?></strong>
                                    <small><?= $m['descricao'] ? $e((string) $m['descricao']) . ' · ' : '' ?><?= $e(date('H:i', (int) strtotime((string) $m['criado_em']))) ?> · <?= $e((string) $m['autor']) ?></small></span>
                                <span class="caixa-valor <?= $entrada ? 'valor-mais' : 'valor-menos' ?>"><?= $entrada ? '+' : '−' ?><?= $e(Numero::reais((string) $m['valor'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</div>
