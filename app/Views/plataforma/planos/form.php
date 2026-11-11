<?php
/** Criar/editar plano. Espera: $plano (null = novo), $valores, $erros, $link, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Services\Plataforma\PlanoService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $c): string => is_string($valores[$c] ?? null) ? (string) $valores[$c] : '';
$novo = $plano === null;
$periodo = $v('periodo') ?: 'mensal';
$privado = $v('visibilidade') === 'privado';
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/planos') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Planos</a>
            <h1><?= $novo ? 'Novo plano' : $e((string) $plano['nome']) ?></h1>
            <p><?= $novo ? 'Defina nome, preço, de quanto em quanto tempo cobra e quem pode assinar.' : 'Mudanças valem para quem assinar depois.' ?></p>
        </div>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="<?= $e($base . '/planos' . ($novo ? '' : '/' . (int) $plano['id'])) ?>" novalidate data-validate-form data-plano-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-plano">
            <div class="bloco-cabecalho"><h2 id="titulo-plano"><?= Icon::svg('tags') ?>O plano</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome do plano', 'valor' => $v('nome'), 'icone' => 'tag', 'erro' => $erros['nome'] ?? null,
                    'dica' => 'Aparece para o restaurante. Ex.: Profissional, Parceiro.', 'classe' => 'col-inteira',
                    'attrs' => ['maxlength' => 120, 'autocomplete' => 'off']]) ?>
                <?= Campo::areaTexto(['nome' => 'descricao', 'rotulo' => 'O que inclui', 'valor' => $v('descricao'), 'icone' => 'list', 'erro' => $erros['descricao'] ?? null,
                    'dica' => 'Uma frase curta. Ex.: Pedidos, mesas, caixa e delivery.', 'opcional' => true, 'classe' => 'col-inteira',
                    'attrs' => ['maxlength' => 500, 'rows' => 3]]) ?>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-cobranca">
            <div class="bloco-cabecalho">
                <h2 id="titulo-cobranca"><?= Icon::svg('repeat') ?>Cobrança</h2>
                <p>O Asaas cobra automaticamente no período escolhido (Pix, boleto ou cartão, conforme as regras de cobrança).</p>
            </div>
            <fieldset class="opcoes-modo opcoes-periodo" id="campo-periodo">
                <legend class="sr-only">De quanto em quanto tempo cobra</legend>
                <?php foreach (PlanoService::PERIODOS as $chave => [$rotulo, $explica]): ?>
                    <label class="opcao-modo">
                        <input type="radio" name="periodo" value="<?= $e($chave) ?>"<?= $periodo === $chave ? ' checked' : '' ?> required data-periodo>
                        <span><strong><?= Icon::svg($chave === 'vitalicio' ? 'infinity' : 'calendar', 'icon-sm') ?> <?= $e($rotulo) ?></strong><small><?= $e($explica) ?></small></span>
                    </label>
                <?php endforeach; ?>
                <?php if (isset($erros['periodo'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['periodo']) ?></p><?php endif; ?>
            </fieldset>
            <div class="form-grade" data-campo-valor<?= $periodo === 'vitalicio' ? ' hidden' : '' ?>>
                <?= Campo::texto(['nome' => 'valor', 'rotulo' => 'Preço por período', 'valor' => $v('valor'), 'icone' => 'banknote', 'erro' => $erros['valor'] ?? null,
                    'dica' => 'Total cobrado a cada período. Use 0 para um plano grátis.', 'opcional' => true,
                    'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'data-mask' => 'money', 'placeholder' => '0,00']]) ?>
            </div>
            <p class="texto-suave" data-aviso-vitalicio<?= $periodo === 'vitalicio' ? '' : ' hidden' ?>><?= Icon::svg('gift', 'icon-sm') ?>Plano vitalício é sempre grátis: o restaurante nunca recebe cobrança.</p>
        </section>

        <section class="bloco" aria-labelledby="titulo-quem">
            <div class="bloco-cabecalho"><h2 id="titulo-quem"><?= Icon::svg('users') ?>Quem pode assinar</h2></div>
            <fieldset class="opcoes-modo" id="campo-visibilidade">
                <legend class="sr-only">Quem pode assinar</legend>
                <label class="opcao-modo">
                    <input type="radio" name="visibilidade" value="publico"<?= $privado ? '' : ' checked' ?>>
                    <span><strong><?= Icon::svg('globe', 'icon-sm') ?> Todos</strong><small>Aparece na página inicial e no cadastro.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="visibilidade" value="privado"<?= $privado ? ' checked' : '' ?>>
                    <span><strong><?= Icon::svg('lock', 'icon-sm') ?> Só com link de convite</strong><small>Não aparece no site. Você envia o link para o restaurante.</small></span>
                </label>
            </fieldset>
            <?php if ($link !== null && $privado): ?>
                <div class="convite-caixa">
                    <span class="convite-rotulo"><?= Icon::svg('link', 'icon-sm') ?>Link de convite</span>
                    <code class="convite-link"><?= $e($link) ?></code>
                    <div class="convite-acoes"><button class="btn btn-wine btn-sm" type="button" data-copiar="<?= $e($link) ?>"><?= Icon::svg('copy', 'icon-sm') ?>Copiar link</button></div>
                    <span class="texto-suave" data-copiado role="status"></span>
                </div>
            <?php elseif ($novo): ?>
                <p class="texto-suave"><?= Icon::svg('info', 'icon-sm') ?>Ao salvar um plano "só com convite", o link aparece pronto para copiar.</p>
            <?php endif; ?>
            <?= Campo::alternador('ativo', 'Aceitando novas assinaturas', 'Desligado: ninguém novo assina (nem pelo link). Quem já assinou continua normalmente.', $v('ativo') !== '') ?>
        </section>

        <div class="form-acoes">
            <a class="btn btn-outline" href="<?= $e($base . '/planos') ?>">Cancelar</a>
            <button class="btn btn-primary" type="submit"><?= Icon::svg('check', 'icon-sm') ?><span><?= $novo ? 'Criar plano' : 'Salvar plano' ?></span></button>
        </div>
    </form>
</div>
