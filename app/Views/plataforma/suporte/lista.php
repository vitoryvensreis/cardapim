<?php
/** Suporte da plataforma. Espera: $chamados, $total, $pagina, $paginas, $filtros, $contagem. */
use Cardapim\Core\Icon;
use Cardapim\Services\Suporte\SuporteService;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$link = static fn (array $mudar): string => '/painel/suporte?' . http_build_query(array_filter([...$filtros, 'pagina' => null, ...$mudar], static fn ($v) => $v !== null && $v !== ''));
$rotulos = ['aguardando' => 'Aguardando resposta', 'abertos' => 'Em andamento', 'resolvidos' => 'Resolvidos', 'todos' => 'Todos'];
$quando = static function (string $d): string {
    $t = (int) strtotime($d);
    return date('Y-m-d', $t) === date('Y-m-d') ? 'hoje, ' . date('H:i', $t) : date('d/m/Y H:i', $t);
};
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Suporte</h1>
            <p>Chamados abertos pelos restaurantes. Os que esperam a sua resposta aparecem primeiro.</p>
        </div>
    </header>

    <nav class="filtros gestao-situacoes" aria-label="Situação dos chamados">
        <?php foreach ($rotulos as $chave => $rotulo): ?>
            <a href="<?= $e($link(['filtro' => $chave === 'abertos' ? null : $chave])) ?>"<?= $filtros['filtro'] === $chave ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?> <span class="contador"><?= (int) $contagem[$chave] ?></span></a>
        <?php endforeach; ?>
    </nav>

    <form class="ferramentas gestao-busca" method="get" action="/painel/suporte" role="search">
        <?php if ($filtros['filtro'] !== 'abertos'): ?><input type="hidden" name="filtro" value="<?= $e($filtros['filtro']) ?>"><?php endif; ?>
        <label class="busca">
            <?= Icon::svg('search', 'icon-sm') ?>
            <span class="sr-only">Buscar chamado</span>
            <input type="search" name="busca" value="<?= $e($filtros['busca']) ?>" placeholder="Número, assunto ou restaurante" autocomplete="off">
        </label>
        <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('search', 'icon-sm') ?>Buscar</button>
        <?php if ($filtros['busca'] !== ''): ?><a class="btn-icone" href="<?= $e($link(['busca' => null])) ?>"><?= Icon::svg('x', 'icon-sm') ?><span>Limpar</span></a><?php endif; ?>
    </form>

    <?php if ($chamados === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('life-buoy') ?>
                <?php if ($contagem['todos'] === 0): ?>
                    <strong>Nenhum chamado ainda</strong>
                    <p>Os chamados que os gerentes abrirem em Suporte aparecem aqui. Você recebe um aviso por e-mail a cada chamado novo.</p>
                <?php elseif ($filtros['filtro'] === 'aguardando'): ?>
                    <strong>Tudo respondido</strong>
                    <p>Nenhum chamado esperando a sua resposta.</p>
                <?php else: ?>
                    <strong>Nenhum chamado encontrado</strong>
                    <p><a href="/painel/suporte?filtro=todos">Ver todos os chamados</a></p>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <p class="texto-suave gestao-total"><?= $total === 1 ? '1 chamado' : (int) $total . ' chamados' ?><?= $paginas > 1 ? ' · página ' . (int) $pagina . ' de ' . (int) $paginas : '' ?></p>
        <ul class="suporte-lista">
            <?php foreach ($chamados as $c):
                $aguardando = $c['status'] !== 'resolvido' && $c['ultima_de'] === 'restaurante'; ?>
                <li>
                    <a class="suporte-item<?= $aguardando ? ' is-novo' : '' ?>" href="/painel/suporte/<?= (int) $c['id'] ?>">
                        <span class="suporte-item-topo">
                            <strong>#<?= (int) $c['id'] ?> <?= $e((string) $c['assunto']) ?></strong>
                            <?php $chamadoSelo = $c; $ladoSelo = 'suporte'; require __DIR__ . '/../../suporte/_selo.php'; ?>
                        </span>
                        <span class="suporte-item-sub">
                            <span><?= Icon::svg('store', 'icon-sm') ?><?= $e((string) $c['restaurante_nome']) ?><?= $c['autor'] ? ' · ' . $e((string) $c['autor']) : '' ?></span>
                            <span><?= $e(SuporteService::CATEGORIAS[$c['categoria']] ?? '') ?> · <?= (int) $c['mensagens'] ?> <?= (int) $c['mensagens'] === 1 ? 'mensagem' : 'mensagens' ?> · <?= $e($quando((string) $c['ultima_mensagem_em'])) ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php require __DIR__ . '/../_paginacao.php'; ?>
    <?php endif; ?>
</div>
