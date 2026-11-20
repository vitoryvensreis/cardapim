<?php
/**
 * Importar cardapio por planilha. Espera: $base, $csrf, $xlsxDisponivel.
 * Depois do envio: $plano (PlanilhaCardapio::interpretar), $conferencia (ImportarCardapioService::conferir), $arquivo, $codigo.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$aceita = $xlsxDisponivel ? '.xlsx,.csv' : '.csv';
$formatos = $xlsxDisponivel ? 'Excel (.xlsx) ou CSV' : 'CSV';
$c = $conferencia;
$plural = static fn (int $n, string $um, string $varios): string => $n . ' ' . ($n === 1 ? $um : $varios);
$textoGrupo = static function (array $g) use ($e): string {
    $regra = $g['minimo'] > 0 ? 'obrigatório' : 'opcional';
    $regra .= $g['maximo'] > 1 ? ', até ' . $g['maximo'] : '';
    $opcoes = array_map(static fn (array $o): string => $o['nome'] . ((float) $o['preco'] > 0 ? ' +' . Numero::reais($o['preco']) : ''), $g['opcoes']);
    return '<strong>' . $e($g['nome']) . '</strong> <span class="texto-suave">(' . $e($regra) . ')</span>: ' . $e(implode(', ', $opcoes));
};

$formulario = static function (bool $deNovo) use ($e, $base, $csrf, $aceita, $formatos): void {
    ?>
    <form class="importar-envio" method="post" action="<?= $e($base . '/integracoes/importar-cardapio/conferir') ?>" enctype="multipart/form-data" data-validate-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= 2 * 1024 * 1024 ?>">
        <label class="importar-arquivo" for="planilha">
            <?= Icon::svg('file-spreadsheet') ?>
            <span><strong><?= $deNovo ? 'Escolher a planilha corrigida' : 'Escolher a planilha' ?></strong><small><?= $e($formatos) ?>, até 2 MB</small></span>
        </label>
        <input class="importar-input" id="planilha" type="file" name="planilha" accept="<?= $e($aceita) ?>" required>
        <div class="bloco-rodape">
            <button class="btn btn-primary" type="submit" data-submit-label="Lendo a planilha..."><?= Icon::svg('upload', 'icon-sm') ?>Enviar para conferir</button>
        </div>
        <p class="texto-suave">Nada é gravado agora. Primeiro você confere o que vai entrar.</p>
    </form>
    <?php
};
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Integrações</a>
            <h1>Importar cardápio</h1>
            <p>Cadastre muitos produtos de uma vez a partir de uma planilha.</p>
        </div>
    </header>

    <?php if ($c === null): ?>
        <section class="bloco" aria-labelledby="titulo-passos">
            <div class="bloco-cabecalho"><h2 id="titulo-passos"><?= Icon::svg('list-checks') ?>Como funciona</h2></div>
            <ol class="passos-2fa">
                <li><strong>Baixe o modelo</strong> e abra no Excel ou no Google Planilhas.</li>
                <li><strong>Preencha uma linha por produto</strong> com categoria, nome e preço. Apague os exemplos.</li>
                <li><strong>Envie a planilha.</strong> Você confere tudo antes de importar.</li>
            </ol>
            <p><a class="btn btn-outline" href="<?= $e($base . '/integracoes/importar-cardapio/modelo') ?>" download><?= Icon::svg('download', 'icon-sm') ?>Baixar o modelo</a></p>
        </section>

        <section class="bloco" aria-labelledby="titulo-enviar">
            <div class="bloco-cabecalho"><h2 id="titulo-enviar"><?= Icon::svg('upload') ?>Enviar a planilha</h2></div>
            <?php $formulario(false); ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-dicas">
            <div class="bloco-cabecalho"><h2 id="titulo-dicas"><?= Icon::svg('info') ?>Dicas para preencher</h2></div>
            <ul class="importar-dicas">
                <li><strong>Categoria vazia</strong> usa a mesma categoria da linha de cima.</li>
                <li><strong>Preço</strong> pode ser 24,90 ou 24.90.</li>
                <li><strong>Complementos</strong> (tamanho, sabor, adicionais): escreva o nome em "Complemento" e cada escolha em "Opção", uma por linha, logo abaixo do produto.</li>
                <li><strong>Preço da opção</strong> é o valor somado ao preço do produto. Deixe vazio se for grátis.</li>
                <li><strong>Obrigatório</strong>: Sim quando o cliente precisa escolher (ex.: tamanho). <strong>Máximo</strong>: quantas opções ele pode marcar.</li>
                <li>Produtos que já existem com o mesmo nome <strong>não são alterados</strong>.</li>
            </ul>
        </section>
    <?php else:
        $temErros = $c['erros'] !== [];
        $novos = count($c['novos']);
    ?>
        <section class="bloco" id="conferencia" aria-labelledby="titulo-conferencia">
            <div class="bloco-cabecalho">
                <h2 id="titulo-conferencia"><?= Icon::svg('file-spreadsheet') ?>Conferência: <?= $e((string) $arquivo) ?></h2>
            </div>
            <div class="indicadores">
                <div class="indicador <?= $novos > 0 ? 'is-ok' : '' ?>">
                    <span class="indicador-topo"><?= Icon::svg('plus') ?>Produtos novos</span>
                    <strong><?= $novos ?></strong>
                    <small>entram à venda</small>
                </div>
                <div class="indicador">
                    <span class="indicador-topo"><?= Icon::svg('tags') ?>Categorias novas</span>
                    <strong><?= count($c['categorias_novas']) ?></strong>
                    <small><?= $c['categorias_novas'] === [] ? 'usa as que já existem' : $e(implode(', ', array_slice($c['categorias_novas'], 0, 4)) . (count($c['categorias_novas']) > 4 ? '...' : '')) ?></small>
                </div>
                <div class="indicador">
                    <span class="indicador-topo"><?= Icon::svg('square-plus') ?>Complementos</span>
                    <strong><?= (int) $c['grupos_novos'] ?></strong>
                    <small><?= $e($plural((int) $c['opcoes'], 'opção', 'opções')) ?><?= $c['grupos_reaproveitados'] > 0 ? $e(' · ' . $c['grupos_reaproveitados'] . ' já existiam') : '' ?></small>
                </div>
                <div class="indicador <?= $temErros ? 'is-alerta' : 'is-ok' ?>">
                    <span class="indicador-topo"><?= Icon::svg($temErros ? 'circle-x' : 'circle-check') ?>Para corrigir</span>
                    <strong><?= count($c['erros']) ?></strong>
                    <small><?= $temErros ? 'linhas com problema' : 'tudo certo' ?></small>
                </div>
            </div>

            <?php if ($temErros): ?>
                <div class="notice notice-warning" role="alert">
                    <?= Icon::svg('triangle-exclamation-point') ?>
                    <div>
                        <strong>Corrija a planilha e envie de novo</strong>
                        <p>Para não cadastrar nada pela metade, a importação só acontece quando todas as linhas estão certas.</p>
                    </div>
                </div>
                <ul class="importar-erros">
                    <?php foreach (array_slice($c['erros'], 0, 200) as $erro): ?>
                        <li><?php if ($erro['linha'] > 0): ?><span class="etiqueta etiqueta-alerta">Linha <?= (int) $erro['linha'] ?></span><?php endif; ?><span><?= $e($erro['mensagem']) ?></span></li>
                    <?php endforeach; ?>
                    <?php if (count($c['erros']) > 200): ?><li><span>E mais <?= count($c['erros']) - 200 ?> linhas. Corrija as de cima e envie de novo.</span></li><?php endif; ?>
                </ul>
                <?php $formulario(true); ?>
            <?php else: ?>
                <div class="importar-confirmar">
                    <form method="post" action="<?= $e($base . '/integracoes/importar-cardapio/importar') ?>" data-validate-form>
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="codigo" value="<?= $e((string) $codigo) ?>">
                        <button class="btn btn-primary" type="submit" data-submit-label="Importando..."><?= Icon::svg('circle-check', 'icon-sm') ?>Importar <?= $e($plural($novos, 'produto', 'produtos')) ?></button>
                    </form>
                    <form method="post" action="<?= $e($base . '/integracoes/importar-cardapio/descartar') ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-outline" type="submit"><?= Icon::svg('x', 'icon-sm') ?>Cancelar</button>
                    </form>
                </div>
                <p class="texto-suave">Os produtos entram à venda, sem controle de estoque e sem foto. Depois você ajusta cada um em Estoque &gt; Produtos.</p>
            <?php endif; ?>
        </section>

        <?php if ($c['existentes'] !== []): ?>
            <section class="bloco" aria-labelledby="titulo-existentes">
                <div class="bloco-cabecalho"><h2 id="titulo-existentes"><?= Icon::svg('info') ?>Já cadastrados (não mudam)</h2></div>
                <p class="texto-suave"><?= $e(implode(', ', array_map(static fn (array $p): string => $p['nome'], $c['existentes']))) ?></p>
            </section>
        <?php endif; ?>

        <?php if ($c['novos'] !== []):
            $porCategoria = [];
            foreach ($c['novos'] as $p) {
                $porCategoria[$p['categoria'] ?? 'Sem categoria'][] = $p;
            }
        ?>
            <section class="bloco" aria-labelledby="titulo-previa">
                <div class="bloco-cabecalho"><h2 id="titulo-previa"><?= Icon::svg('book-open') ?>Como vai ficar</h2></div>
                <?php foreach ($porCategoria as $categoria => $itens): ?>
                    <h3 class="importar-categoria"><?= $e((string) $categoria) ?> <span class="texto-suave">(<?= count($itens) ?>)</span></h3>
                    <ul class="importar-previa">
                        <?php foreach ($itens as $p): ?>
                            <li>
                                <div class="importar-previa-linha">
                                    <span><strong><?= $e((string) $p['nome']) ?></strong><?php if ($p['descricao']): ?><small><?= $e((string) $p['descricao']) ?></small><?php endif; ?></span>
                                    <span class="importar-preco"><?= $e(Numero::reais((string) $p['preco'])) ?></span>
                                </div>
                                <?php foreach ($p['grupos'] as $g): if ($g['opcoes'] === []) { continue; } ?>
                                    <p class="importar-grupo"><?= $textoGrupo($g) ?></p>
                                <?php endforeach; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
