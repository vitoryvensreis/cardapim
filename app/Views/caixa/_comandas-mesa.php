<?php
/**
 * Comandas da mesa (caixa e garcom): uma aba por pessoa ("Joao", "Comanda 2"), "+ Nova comanda" e "Dar nome".
 * Funciona sem JavaScript (<details>). Espera: $c (ComandaService::detalhes), $base (caixa ou garcom), $csrf,
 * $novaComandaUrl (POST da nova comanda da mesa) e, opcional, $erros.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Shared\Numero;

$ec = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pc = $c['pedido'];
$ehMesa = $pc['tipo'] === 'mesa' && $pc['id_mesa'] !== null && $c['mesa'] !== null && $c['mesa']['excluida_em'] === null;
$irmas = $c['irmas'] ?? [];
$erroNome = $erros['nome'] ?? null;
if (!$ehMesa && !$c['aberta']) {
    return;
}
?>
<section class="comandas-mesa" aria-labelledby="comandas-mesa-titulo">
    <?php if ($ehMesa && ($c['aberta'] || $irmas !== [])): ?>
        <div class="comandas-mesa-topo">
            <h2 id="comandas-mesa-titulo"><?= Icon::svg('users', 'icon-sm') ?><?= count($irmas) > 1 ? 'Comandas da ' . $ec((string) $c['rotulo_mesa']) : 'Comanda da ' . $ec((string) $c['rotulo_mesa']) ?></h2>
            <?php if (count($irmas) > 1): ?><span class="comandas-mesa-qtd"><?= count($irmas) ?> abertas</span><?php endif; ?>
        </div>
        <ul class="comandas-mesa-lista">
            <?php foreach ($irmas as $irma): $atual = $irma['id'] === (int) $pc['id']; ?>
                <li>
                    <a class="comanda-chip<?= $atual ? ' is-atual' : '' ?><?= $irma['tem_nome'] ? '' : ' sem-nome' ?>" href="<?= $ec($base . '/comandas/' . $irma['id']) ?>"<?= $atual ? ' aria-current="page"' : '' ?>>
                        <span class="comanda-chip-icone" aria-hidden="true"><?= Icon::svg($irma['tem_nome'] ? 'user' : 'receipt', 'icon-sm') ?></span>
                        <span class="comanda-chip-texto">
                            <span class="comanda-chip-nome"><?= $ec($irma['nome']) ?></span>
                            <span class="comanda-chip-valor"><?= $ec(Numero::reais(CaixaService::dec($irma['total']))) ?><?= $irma['itens'] > 0 ? ' · ' . $irma['itens'] . ($irma['itens'] === 1 ? ' item' : ' itens') : '' ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
            <li>
                <details class="comanda-nova" name="comanda-acao"<?= $erroNome !== null && ($enviado['voltar_comanda'] ?? '') !== '' ? ' open' : '' ?>>
                    <summary class="comanda-chip comanda-chip-nova"><span class="comanda-chip-icone" aria-hidden="true"><?= Icon::svg('user-plus', 'icon-sm') ?></span><span class="comanda-chip-nome">Nova comanda</span></summary>
                    <form method="post" action="<?= $ec($novaComandaUrl) ?>" class="comanda-nome-form">
                        <input type="hidden" name="_csrf" value="<?= $ec($csrf) ?>">
                        <input type="hidden" name="voltar_comanda" value="<?= (int) $pc['id'] ?>">
                        <p class="comanda-nome-dica">Para quem chegou depois: a conta dessa pessoa fica separada na mesma mesa.</p>
                        <label class="campo-caixa"><span>Nome da pessoa (opcional)</span>
                            <input name="nome" maxlength="<?= ComandaService::MAX_NOME ?>" placeholder="Ex.: João" autocomplete="off" autocapitalize="words" enterkeyhint="done"></label>
                        <button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('plus', 'icon-sm') ?>Abrir comanda</button>
                    </form>
                </details>
            </li>
        </ul>
    <?php endif; ?>

    <?php if ($c['aberta']): $temNome = trim((string) $c['nome']) !== ''; ?>
        <details class="comanda-renomear" name="comanda-acao"<?= $erroNome !== null && ($enviado['voltar_comanda'] ?? '') === '' ? ' open' : '' ?>>
            <summary><?= Icon::svg('pencil', 'icon-sm') ?><?= $temNome ? 'Mudar o nome desta comanda' : ($ehMesa ? 'Dar o nome da pessoa a esta comanda' : 'Dar nome a esta venda') ?></summary>
            <form method="post" action="<?= $ec($base . '/comandas/' . (int) $pc['id'] . '/nome') ?>" class="comanda-nome-form">
                <input type="hidden" name="_csrf" value="<?= $ec($csrf) ?>">
                <label class="campo-caixa"><span>Nome</span>
                    <input name="nome" maxlength="<?= ComandaService::MAX_NOME ?>" value="<?= $ec((string) $c['nome']) ?>" placeholder="Ex.: Maria" autocomplete="off" autocapitalize="words" enterkeyhint="done"<?= $erroNome !== null ? ' aria-invalid="true"' : '' ?>></label>
                <?php if ($erroNome !== null): ?><p class="campo-erro"><?= $ec((string) $erroNome) ?></p><?php endif; ?>
                <div class="comanda-nome-acoes">
                    <button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('check', 'icon-sm') ?>Salvar nome</button>
                    <?php if ($temNome): ?><button class="btn btn-outline btn-sm" type="submit" name="nome" value="">Tirar o nome</button><?php endif; ?>
                </div>
            </form>
        </details>
    <?php endif; ?>
</section>
