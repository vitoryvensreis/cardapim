<?php
/**
 * Contas da plataforma. Espera: $usuarios, $total, $pagina, $paginas, $filtros, $situacoes, $contagem, $restaurantes.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Gerencia\EquipeService;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$link = static fn (array $mudar): string => '/painel/usuarios?' . http_build_query(array_filter([...$filtros, 'situacao' => $filtros['situacao'] === 'todos' ? null : $filtros['situacao'], 'pagina' => null, ...$mudar], static fn ($v) => $v !== null && $v !== ''));
$quando = static fn (?string $d): string => $d ? date('d/m/Y H:i', (int) strtotime($d)) : 'Nunca entrou';
$todas = array_sum($contagem);
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Usuários</h1>
            <p>Administradores da plataforma e as contas de todos os restaurantes (gerentes, caixas, garçons e entregadores).</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-primary btn-sm" href="/painel/usuarios/novo"><?= Icon::svg('user-plus', 'icon-sm') ?>Nova conta</a>
        </div>
    </header>

    <nav class="filtros gestao-situacoes" aria-label="Função">
        <a href="<?= $e($link(['cargo' => null])) ?>"<?= $filtros['cargo'] === null ? ' aria-current="true"' : '' ?>>Todas <span class="contador"><?= (int) $todas ?></span></a>
        <?php foreach ($contagem as $cargoChave => $n): ?>
            <a href="<?= $e($link(['cargo' => $cargoChave])) ?>"<?= $filtros['cargo'] === $cargoChave ? ' aria-current="true"' : '' ?>><?= $e($cargoChave === 'adm_master' ? 'Administradores' : Autorizacao::rotuloCargo($cargoChave)) ?> <span class="contador"><?= (int) $n ?></span></a>
        <?php endforeach; ?>
    </nav>

    <form class="ferramentas gestao-busca" method="get" action="/painel/usuarios" role="search">
        <?php if ($filtros['cargo'] !== null): ?><input type="hidden" name="cargo" value="<?= $e($filtros['cargo']) ?>"><?php endif; ?>
        <label class="busca">
            <?= Icon::svg('search', 'icon-sm') ?>
            <span class="sr-only">Buscar conta</span>
            <input type="search" name="busca" value="<?= $e($filtros['busca']) ?>" placeholder="Nome, usuário ou e-mail" autocomplete="off">
        </label>
        <label class="gestao-select">
            <span class="sr-only">Restaurante</span>
            <select name="restaurante">
                <option value="">Todos os restaurantes</option>
                <?php foreach ($restaurantes as $r): ?>
                    <option value="<?= (int) $r['id'] ?>"<?= $filtros['restaurante'] === (int) $r['id'] ? ' selected' : '' ?>><?= $e($r['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="gestao-select">
            <span class="sr-only">Acesso</span>
            <select name="situacao">
                <?php foreach ($situacoes as $sitChave => $sitRotulo): ?>
                    <option value="<?= $e($sitChave) ?>"<?= $filtros['situacao'] === $sitChave ? ' selected' : '' ?>><?= $e($sitRotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('search', 'icon-sm') ?>Buscar</button>
        <?php if ($filtros['busca'] !== '' || $filtros['restaurante'] !== null || $filtros['situacao'] !== 'todos'): ?><a class="btn-icone" href="<?= $e($link(['busca' => null, 'restaurante' => null, 'situacao' => null])) ?>"><?= Icon::svg('x', 'icon-sm') ?><span>Limpar</span></a><?php endif; ?>
    </form>

    <?php if ($usuarios === []): ?>
        <section class="bloco"><div class="lista-vazia"><?= Icon::svg('users') ?><strong>Nenhuma conta encontrada</strong><p><a href="/painel/usuarios">Ver todas as contas</a></p></div></section>
    <?php else: ?>
        <p class="texto-suave gestao-total"><?= $total === 1 ? '1 conta' : (int) $total . ' contas' ?><?= $paginas > 1 ? ' · página ' . (int) $pagina . ' de ' . (int) $paginas : '' ?></p>
        <ul class="lista lista-usuarios-adm">
            <li class="lista-cabecalho" aria-hidden="true"><span>Pessoa</span><span>Função</span><span>Restaurante</span><span>Último acesso</span><span class="lista-cab-acoes">Abrir</span></li>
            <?php foreach ($usuarios as $u): $iniciais = mb_strtoupper(mb_substr((string) $u['nome'], 0, 1) . mb_substr((string) $u['sobrenome'], 0, 1)); ?>
                <li class="lista-item">
                    <div class="lista-principal">
                        <span class="lista-icone" aria-hidden="true"><?= $e($iniciais) ?></span>
                        <span class="lista-titulo">
                            <a href="/painel/usuarios/<?= (int) $u['id'] ?>"><strong><?= $e($u['nome'] . ' ' . $u['sobrenome']) ?></strong></a>
                            <small><?= $e((string) $u['usuario']) ?><?= EquipeService::semEmail((string) $u['email']) ? '' : ' · ' . $e((string) $u['email']) ?></small>
                        </span>
                    </div>
                    <span class="lista-dado" data-rotulo="Função">
                        <?= $e(Autorizacao::rotuloCargo((string) $u['cargo'])) ?>
                        <?php if ((int) $u['ativo'] !== 1): ?><span class="etiqueta etiqueta-neutra"><?= Icon::svg('lock') ?>Bloqueada</span><?php endif; ?>
                        <?php if ($u['dois_fatores'] !== 'desativado'): ?><span class="etiqueta etiqueta-ok" title="Verificação em duas etapas ligada"><?= Icon::svg('shield-check') ?>2 etapas</span><?php endif; ?>
                    </span>
                    <span class="lista-dado" data-rotulo="Restaurante"><?php if ($u['restaurante_nome'] !== null): ?><a href="/painel/restaurantes/<?= (int) $u['id_restaurante'] ?>"><?= $e((string) $u['restaurante_nome']) ?></a><?php else: ?><span class="texto-suave">Plataforma</span><?php endif; ?></span>
                    <span class="lista-dado" data-rotulo="Último acesso"><?= $e($quando($u['ultimo_acesso'])) ?></span>
                    <div class="lista-acoes"><a class="btn-icone" href="/painel/usuarios/<?= (int) $u['id'] ?>"><?= Icon::svg('pencil', 'icon-sm') ?><span>Abrir</span></a></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php require __DIR__ . '/../_paginacao.php'; ?>
    <?php endif; ?>
</div>
