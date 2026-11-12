<?php
/**
 * Restaurantes da plataforma. Espera: $restaurantes, $total, $pagina, $paginas, $filtros, $contagem, $planos, $base.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Plataforma\RestauranteAdminService;
use Cardapim\Shared\RestauranteLogo;
use Cardapim\Shared\Validation\Documento;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$link = static fn (array $mudar): string => '/painel/restaurantes?' . http_build_query(array_filter([...$filtros, 'pagina' => null, ...$mudar], static fn ($v) => $v !== null && $v !== ''));
$selos = [
    'ativo' => ['etiqueta-ok', 'circle-check'],
    'pendente' => ['etiqueta-atencao', 'clock'],
    'inadimplente' => ['etiqueta-alerta', 'triangle-exclamation-point'],
    'suspenso' => ['etiqueta-alerta', 'pause'],
    'inativo' => ['etiqueta-neutra', 'power'],
];
$todos = array_sum($contagem);
$quando = static fn (?string $d): string => $d ? date('d/m/Y', (int) strtotime($d)) : '—';
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Restaurantes</h1>
            <p>Todos os restaurantes da plataforma. Toque em um para editar, mudar a situação, trocar o plano ou excluir.</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-primary btn-sm" href="/painel/restaurantes/novo"><?= Icon::svg('plus', 'icon-sm') ?>Novo restaurante</a>
        </div>
    </header>

    <nav class="filtros gestao-situacoes" aria-label="Situação dos restaurantes">
        <a href="<?= $e($link(['status' => null])) ?>"<?= $filtros['status'] === null ? ' aria-current="true"' : '' ?>>Todos <span class="contador"><?= (int) $todos ?></span></a>
        <?php foreach ($contagem as $st => $n): if ($n === 0 && $filtros['status'] !== $st) { continue; } ?>
            <a href="<?= $e($link(['status' => $st])) ?>"<?= $filtros['status'] === $st ? ' aria-current="true"' : '' ?>><?= $e(RestauranteAdminService::rotuloStatus($st)) ?> <span class="contador"><?= (int) $n ?></span></a>
        <?php endforeach; ?>
    </nav>

    <form class="ferramentas gestao-busca" method="get" action="/painel/restaurantes" role="search">
        <?php if ($filtros['status'] !== null): ?><input type="hidden" name="status" value="<?= $e($filtros['status']) ?>"><?php endif; ?>
        <label class="busca">
            <?= Icon::svg('search', 'icon-sm') ?>
            <span class="sr-only">Buscar restaurante</span>
            <input type="search" name="busca" value="<?= $e($filtros['busca']) ?>" placeholder="Nome, endereço, cidade, CNPJ ou telefone" autocomplete="off">
        </label>
        <label class="gestao-select">
            <span class="sr-only">Plano</span>
            <select name="plano">
                <option value="">Todos os planos</option>
                <?php foreach ($planos as $pl): ?>
                    <option value="<?= (int) $pl['id'] ?>"<?= $filtros['plano'] === (int) $pl['id'] ? ' selected' : '' ?>><?= $e((string) $pl['nome']) ?><?= (int) $pl['ativo'] === 1 ? '' : ' (pausado)' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('search', 'icon-sm') ?>Buscar</button>
        <?php if ($filtros['busca'] !== '' || $filtros['plano'] !== null): ?><a class="btn-icone" href="<?= $e($link(['busca' => null, 'plano' => null])) ?>"><?= Icon::svg('x', 'icon-sm') ?><span>Limpar</span></a><?php endif; ?>
    </form>

    <?php if ($restaurantes === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('store') ?>
                <?php if ($todos === 0): ?>
                    <strong>Nenhum restaurante ainda</strong>
                    <p>Os restaurantes aparecem aqui quando se cadastram pelo site ou quando você cadastra um.</p>
                    <p class="lista-vazia-acao"><a class="btn btn-primary btn-sm" href="/painel/restaurantes/novo"><?= Icon::svg('plus', 'icon-sm') ?>Cadastrar restaurante</a></p>
                <?php else: ?>
                    <strong>Nenhum restaurante encontrado</strong>
                    <p><a href="/painel/restaurantes">Ver todos os restaurantes</a></p>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <p class="texto-suave gestao-total"><?= $total === 1 ? '1 restaurante' : (int) $total . ' restaurantes' ?><?= $paginas > 1 ? ' · página ' . (int) $pagina . ' de ' . (int) $paginas : '' ?></p>
        <ul class="lista lista-restaurantes-adm">
            <li class="lista-cabecalho" aria-hidden="true"><span>Restaurante</span><span>Situação</span><span>Plano</span><span>Gerente</span><span>Desde</span><span class="lista-cab-acoes">Abrir</span></li>
            <?php foreach ($restaurantes as $r):
                [$classe, $icone] = $selos[$r['status_rest']] ?? ['etiqueta-neutra', 'circle'];
                $logo = RestauranteLogo::urlSegura($r['logo_path'] !== null ? (string) $r['logo_path'] : null); ?>
                <li class="lista-item">
                    <div class="lista-principal">
                        <span class="lista-icone gestao-logo" aria-hidden="true">
                            <?php if ($logo !== null): ?><img src="<?= $e($logo) ?>" alt="" width="40" height="40" loading="lazy"><?php else: ?><?= $e(RestauranteLogo::iniciais((string) $r['nome'])) ?><?php endif; ?>
                        </span>
                        <span class="lista-titulo">
                            <a href="/painel/restaurantes/<?= (int) $r['id'] ?>"><strong><?= $e((string) $r['nome']) ?></strong></a>
                            <small>/<?= $e((string) $r['slug']) ?><?= $r['cidade'] ? ' · ' . $e((string) $r['cidade']) . '/' . $e((string) $r['estado']) : '' ?><?= $r['cnpj'] ? ' · ' . $e(Documento::formatarCnpj((string) $r['cnpj'])) : '' ?></small>
                        </span>
                    </div>
                    <span class="lista-dado" data-rotulo="Situação"><span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $e(RestauranteAdminService::rotuloStatus((string) $r['status_rest'])) ?></span></span>
                    <span class="lista-dado" data-rotulo="Plano"><?= $r['plano_nome'] !== null ? $e((string) $r['plano_nome']) : '<span class="texto-suave">Sem plano</span>' ?></span>
                    <span class="lista-dado" data-rotulo="Gerente"><?= $r['gerente'] !== null ? $e((string) $r['gerente']) : '<span class="texto-suave">Sem gerente</span>' ?></span>
                    <span class="lista-dado" data-rotulo="Desde"><?= $e($quando($r['created_at'] ?? null)) ?></span>
                    <div class="lista-acoes">
                        <a class="btn-icone" href="/painel/restaurantes/<?= (int) $r['id'] ?>"><?= Icon::svg('pencil', 'icon-sm') ?><span>Abrir</span></a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php require __DIR__ . '/../_paginacao.php'; ?>
    <?php endif; ?>
</div>
