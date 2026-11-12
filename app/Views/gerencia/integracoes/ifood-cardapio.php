<?php
/**
 * Cardapio do iFood ligado aos produtos (estoque e disponibilidade).
 * Espera: $itens (IfoodCatalogo::itens), $filtro (todos|soltos|erros), $produtos, $sincronizar, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$soltos = array_values(array_filter($itens, static fn (array $i): bool => $i['id_produto'] === null));
$comErro = array_values(array_filter($itens, static fn (array $i): bool => $i['ultimo_erro'] !== null));
$lista = match ($filtro) {
    'soltos' => $soltos,
    'erros' => $comErro,
    default => $itens,
};
$filtros = ['todos' => ['Todos', count($itens)], 'soltos' => ['Sem ligação', count($soltos)], 'erros' => ['Com erro', count($comErro)]];
$statusIfood = static fn (?string $s): array => match ($s) {
    'AVAILABLE' => ['Vendendo no iFood', 'etiqueta-ok', 'circle-check'],
    'UNAVAILABLE' => ['Indisponível no iFood', 'etiqueta-neutra', 'circle-pause'],
    default => ['Veio em um pedido', 'etiqueta-neutra', 'receipt'],
};
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes/ifood') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>iFood</a>
            <h1>Cardápio do iFood</h1>
            <p>Escolha qual produto daqui corresponde a cada item do iFood. A venda no iFood baixa o estoque desse produto e a disponibilidade dele vai para o iFood.</p>
        </div>
        <div class="painel-acoes">
            <form method="post" action="<?= $e($base . '/integracoes/ifood/cardapio/buscar') ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-outline btn-sm" type="submit" data-submit-label="Buscando..."><?= Icon::svg('refresh-cw', 'icon-sm') ?>Buscar cardápio do iFood</button>
            </form>
            <?php if ($itens !== []): ?>
                <form method="post" action="<?= $e($base . '/integracoes/ifood/cardapio/sincronizar') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline btn-sm" type="submit" data-submit-label="Enviando..."><?= Icon::svg('send', 'icon-sm') ?>Enviar disponibilidade agora</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$sincronizar): ?>
        <div class="notice notice-info"><?= Icon::svg('info') ?><div><p>O envio automático da disponibilidade está desligado nas <a href="<?= $e($base . '/integracoes/ifood') ?>#titulo-opcoes-ifood">opções do iFood</a>. As vendas do iFood continuam baixando o estoque.</p></div></div>
    <?php endif; ?>

    <?php if ($itens === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('list-checks') ?>
                <strong>Nenhum item do iFood ainda</strong>
                <p>Toque em "Buscar cardápio do iFood". Os itens com o mesmo código PDV ou o mesmo nome de um produto daqui são ligados sozinhos.</p>
            </div>
        </section>
    <?php else: ?>
        <nav class="filtros ifood-filtros" aria-label="Filtrar itens">
            <?php foreach ($filtros as $chave => [$rotulo, $qtd]): ?>
                <a href="<?= $e($base . '/integracoes/ifood/cardapio' . ($chave === 'todos' ? '' : '?filtro=' . $chave)) ?>"<?= $filtro === $chave ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?> (<?= (int) $qtd ?>)</a>
            <?php endforeach; ?>
        </nav>

        <?php if ($lista === []): ?>
            <section class="bloco"><div class="lista-vazia"><?= Icon::svg('circle-check') ?><strong><?= $filtro === 'soltos' ? 'Todos os itens estão ligados' : 'Nenhum item com erro' ?></strong></div></section>
        <?php else: ?>
            <form method="post" action="<?= $e($base . '/integracoes/ifood/cardapio/ligar') ?>" class="bloco ifood-cardapio">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="filtro" value="<?= $e($filtro) ?>">
                <ul class="ifood-itens">
                    <?php foreach ($lista as $item): [$rotuloIfood, $classeIfood, $iconeIfood] = $statusIfood($item['status_ifood'] !== null ? (string) $item['status_ifood'] : null); ?>
                        <li class="ifood-item">
                            <div class="ifood-item-info">
                                <strong><?= $e((string) $item['nome']) ?></strong>
                                <small><?= $e(implode(' · ', array_filter([
                                    (string) ($item['categoria'] ?? ''),
                                    $item['preco'] !== null ? Numero::reais((string) $item['preco']) : '',
                                    $item['codigo_externo'] !== null ? 'Código PDV ' . $item['codigo_externo'] : '',
                                ]))) ?></small>
                                <span class="etiqueta <?= $e($classeIfood) ?>"><?= Icon::svg($iconeIfood) ?><?= $e($rotuloIfood) ?></span>
                            </div>
                            <div class="ifood-item-produto">
                                <label for="produto-<?= (int) $item['id'] ?>">Produto daqui</label>
                                <select id="produto-<?= (int) $item['id'] ?>" name="produto[<?= (int) $item['id'] ?>]">
                                    <option value="">Sem ligação</option>
                                    <?php foreach ($produtos as $prod): ?>
                                        <option value="<?= (int) $prod['id'] ?>"<?= (int) $item['id_produto'] === $prod['id'] ? ' selected' : '' ?>><?= $e($prod['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($item['id_produto'] !== null): ?>
                                    <small class="texto-suave">
                                        <?= $item['vinculo'] === 'automatico' ? 'Ligado sozinho. ' : '' ?>
                                        <?php if ($item['desejado'] === 'AVAILABLE'): ?>Tem estoque<?= $item['porcoes'] !== null ? ' para ' . (int) $item['porcoes'] . ' un.' : '' ?>.<?php else: ?>Sem estoque ou pausado aqui.<?php endif; ?>
                                        <?= $item['sincronizado_em'] ? 'Enviado às ' . $e(date('H:i', (int) strtotime((string) $item['sincronizado_em']))) . '.' : ($sincronizar ? 'Será enviado em até 1 minuto.' : '') ?>
                                    </small>
                                <?php endif; ?>
                                <?php if ($item['ultimo_erro'] !== null): ?><small class="texto-alerta"><?= $e((string) $item['ultimo_erro']) ?></small><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="bloco-rodape ifood-salvar"><button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('check', 'icon-sm') ?>Salvar ligações</button></div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
