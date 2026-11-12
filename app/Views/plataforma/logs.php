<?php
/**
 * Logs de auditoria da plataforma. Espera: $logs, $total, $pagina, $paginas, $filtros, $restaurantes, $cargos.
 */
use Cardapim\Core\Icon;
use Cardapim\Repositories\Plataforma\LogAdminRepository;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$valores = [...$filtros, 'restaurante' => $filtros['restaurante'] === 0 ? 'plataforma' : $filtros['restaurante']];
$link = static fn (array $mudar): string => '/painel/logs?' . http_build_query(array_filter([...$valores, 'pagina' => null, ...$mudar], static fn ($v) => $v !== null && $v !== ''));
$planilha = '/painel/logs/planilha?' . http_build_query(array_filter([...$valores], static fn ($v) => $v !== null && $v !== ''));
$temFiltro = $filtros['restaurante'] !== null || $filtros['pessoa'] !== '' || $filtros['busca'] !== '' || $filtros['de'] !== null || $filtros['ate'] !== null;
$tipoDe = static function (string $titulo): array {
    return match (true) {
        in_array($titulo, ['Login', 'Login automático'], true) => ['log-in', 'is-acesso'],
        $titulo === 'Logout' => ['log-out', 'is-acesso'],
        in_array($titulo, ['Login recusado', 'Verificação recusada', 'Alerta de segurança'], true), str_starts_with($titulo, 'Cliente: alerta') => ['shield-exclamation-point', 'is-alerta'],
        str_contains($titulo, 'senha') || str_contains($titulo, 'duas etapas') || str_contains($titulo, 'recuperação') || str_starts_with($titulo, 'Aparelho') => ['key-round', 'is-seguranca'],
        str_starts_with($titulo, 'Cliente:') => ['user', 'is-cliente'],
        str_contains($titulo, 'excluíd') || str_contains($titulo, 'cancelad') || str_contains($titulo, 'removid') => ['trash', 'is-alteracao'],
        default => ['pencil', 'is-alteracao'],
    };
};
$diaRotulo = static function (string $dia): string {
    if ($dia === date('Y-m-d')) {
        return 'Hoje';
    }
    if ($dia === date('Y-m-d', strtotime('-1 day'))) {
        return 'Ontem';
    }
    return date('d/m/Y', (int) strtotime($dia));
};
$diaAtual = null;
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Logs de auditoria</h1>
            <p>Tudo o que foi feito na plataforma, por quem e quando: entradas, alertas de segurança, cadastros, alterações e exclusões. Nada aqui pode ser editado ou apagado.</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-outline btn-sm" href="<?= $e($planilha) ?>"><?= Icon::svg('download', 'icon-sm') ?>Baixar planilha</a>
        </div>
    </header>

    <nav class="filtros gestao-situacoes" aria-label="Tipo de registro">
        <a href="<?= $e($link(['tipo' => null])) ?>"<?= $filtros['tipo'] === null ? ' aria-current="true"' : '' ?>>Todos</a>
        <?php foreach (LogAdminRepository::TIPOS as $chave => $rotulo): ?>
            <a href="<?= $e($link(['tipo' => $chave])) ?>"<?= $filtros['tipo'] === $chave ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?></a>
        <?php endforeach; ?>
    </nav>

    <form class="logs-filtros" method="get" action="/painel/logs" role="search">
        <?php if ($filtros['tipo'] !== null): ?><input type="hidden" name="tipo" value="<?= $e($filtros['tipo']) ?>"><?php endif; ?>
        <label class="logs-campo logs-campo-largo">
            <span>Procurar</span>
            <input type="search" name="busca" value="<?= $e($filtros['busca']) ?>" placeholder="Palavra, nome de produto, IP..." autocomplete="off" maxlength="80">
        </label>
        <label class="logs-campo">
            <span>Pessoa</span>
            <input type="search" name="pessoa" value="<?= $e($filtros['pessoa']) ?>" placeholder="Nome, usuário ou e-mail" autocomplete="off" maxlength="80">
        </label>
        <label class="logs-campo">
            <span>Restaurante</span>
            <select name="restaurante">
                <option value="">Todos</option>
                <option value="plataforma"<?= $filtros['restaurante'] === 0 ? ' selected' : '' ?>>Administração da plataforma</option>
                <?php foreach ($restaurantes as $r): ?>
                    <option value="<?= (int) $r['id'] ?>"<?= $filtros['restaurante'] === $r['id'] ? ' selected' : '' ?>><?= $e($r['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="logs-campo logs-campo-data">
            <span>De</span>
            <input type="date" name="de" value="<?= $e((string) $filtros['de']) ?>" max="<?= $e(date('Y-m-d')) ?>">
        </label>
        <label class="logs-campo logs-campo-data">
            <span>Até</span>
            <input type="date" name="ate" value="<?= $e((string) $filtros['ate']) ?>" max="<?= $e(date('Y-m-d')) ?>">
        </label>
        <div class="logs-acoes">
            <button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('search', 'icon-sm') ?>Filtrar</button>
            <?php if ($temFiltro): ?><a class="btn-icone" href="<?= $e('/painel/logs' . ($filtros['tipo'] !== null ? '?tipo=' . rawurlencode($filtros['tipo']) : '')) ?>"><?= Icon::svg('x', 'icon-sm') ?><span>Limpar</span></a><?php endif; ?>
        </div>
    </form>

    <?php if ($logs === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('file-text') ?>
                <strong><?= $temFiltro || $filtros['tipo'] !== null ? 'Nenhum registro com esses filtros' : 'Nenhum registro ainda' ?></strong>
                <p><?= $temFiltro || $filtros['tipo'] !== null ? 'Tente outro período ou limpe os filtros.' : 'Cada entrada, cadastro, alteração e exclusão aparece aqui.' ?></p>
            </div>
        </section>
    <?php else: ?>
        <p class="texto-suave gestao-total"><?= $total === 1 ? '1 registro' : number_format($total, 0, ',', '.') . ' registros' ?><?= $paginas > 1 ? ' · página ' . (int) $pagina . ' de ' . (int) $paginas : '' ?> · mais recentes primeiro</p>
        <div class="logs-lista">
            <?php foreach ($logs as $l):
                $t = (int) strtotime((string) $l['data_hora']);
                $dia = date('Y-m-d', $t);
                [$icone, $classe] = $tipoDe((string) $l['titulo']);
                if ($dia !== $diaAtual):
                    if ($diaAtual !== null): ?></ol><?php endif;
                    $diaAtual = $dia; ?>
                    <h2 class="logs-dia"><?= $e($diaRotulo($dia)) ?></h2>
                    <ol class="logs-itens">
                <?php endif; ?>
                <li class="logs-item <?= $classe ?>">
                    <span class="logs-icone" aria-hidden="true"><?= Icon::svg($icone) ?></span>
                    <div class="logs-corpo">
                        <p class="logs-titulo"><strong><?= $e((string) $l['titulo']) ?></strong><time datetime="<?= $e(date('c', $t)) ?>"><?= $e(date('H:i', $t)) ?></time></p>
                        <p class="logs-descricao"><?= $e((string) $l['descricao']) ?></p>
                        <p class="logs-meta">
                            <?php if ($l['id_user'] !== null): ?>
                                <span><?= Icon::svg('user', 'icon-sm') ?><?= $e(trim($l['nome'] . ' ' . $l['sobrenome'])) ?> (<?= $e((string) $l['usuario']) ?>)<?= isset($cargos[$l['cargo']]) ? ' · ' . $e($cargos[$l['cargo']]) : '' ?></span>
                                <?php if ($l['restaurante_nome'] !== null): ?><span><?= Icon::svg('store', 'icon-sm') ?><?= $e((string) $l['restaurante_nome']) ?></span><?php endif; ?>
                            <?php else: ?>
                                <span><?= Icon::svg('cpu', 'icon-sm') ?>Sistema ou cliente do cardápio</span>
                            <?php endif; ?>
                            <?php if (!empty($l['ip'])): ?><span><?= Icon::svg('globe', 'icon-sm') ?>IP <?= $e((string) $l['ip']) ?></span><?php endif; ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
            </ol>
        </div>
        <?php require __DIR__ . '/_paginacao.php'; ?>
    <?php endif; ?>
</div>
