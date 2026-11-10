<?php
/** Lista da equipe. Espera: $pessoas, $filtro, $contagem (por cargo), $total, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Shared\Validation\Documento;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$funcoes = require __DIR__ . '/_funcoes.php';
$quando = static function (?string $data): string {
    if ($data === null || $data === '') {
        return 'Nunca entrou';
    }
    $t = strtotime($data);
    if ($t === false) {
        return '-';
    }
    if (date('Y-m-d', $t) === date('Y-m-d')) {
        return 'Hoje, ' . date('H:i', $t);
    }
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) {
        return 'Ontem, ' . date('H:i', $t);
    }
    return date('d/m/Y', $t);
};
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Equipe</h1>
            <p>Cada pessoa entra com o próprio usuário e senha e só vê o que a função dela permite.</p>
        </div>
        <?php if ($total > 0): ?>
            <div class="painel-acoes">
                <a class="btn btn-primary btn-sm" href="<?= $e($base . '/equipe/nova' . ($filtro !== 'todos' ? '?funcao=' . $filtro : '')) ?>"><?= Icon::svg('user-plus', 'icon-sm') ?>Adicionar pessoa</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($total === 0): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('users') ?>
                <strong>Sua equipe ainda está vazia</strong>
                <p>Crie uma conta para cada pessoa que trabalha com você. Escolha a função:</p>
            </div>
            <div class="funcoes-grade">
                <?php foreach ($funcoes as $cargo => [$rotulo, , $icone, $descricao]): ?>
                    <a class="funcao-cartao" href="<?= $e($base . '/equipe/nova?funcao=' . $cargo) ?>">
                        <span class="lista-icone" aria-hidden="true"><?= Icon::svg($icone, 'icon-sm') ?></span>
                        <strong>Adicionar <?= $e(mb_strtolower($rotulo)) ?></strong>
                        <small><?= $e($descricao) ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <div class="ferramentas">
            <label class="busca">
                <?= Icon::svg('search', 'icon-sm') ?>
                <span class="sr-only">Buscar pessoa</span>
                <input type="search" placeholder="Buscar pelo nome ou usuário" data-filtro="#lista-equipe" data-filtro-vazio="#equipe-nenhum" autocomplete="off">
            </label>
            <nav class="filtros" aria-label="Filtrar por função">
                <a href="<?= $e($base . '/equipe') ?>"<?= $filtro === 'todos' ? ' aria-current="true"' : '' ?>>Todos (<?= (int) $total ?>)</a>
                <?php foreach ($funcoes as $cargo => [, $plural]): ?>
                    <a href="<?= $e($base . '/equipe?funcao=' . $cargo) ?>"<?= $filtro === $cargo ? ' aria-current="true"' : '' ?>><?= $e($plural) ?> (<?= (int) $contagem[$cargo] ?>)</a>
                <?php endforeach; ?>
            </nav>
        </div>

        <?php if ($pessoas === []): ?>
            <div class="bloco">
                <div class="lista-vazia">
                    <?= Icon::svg('users') ?><strong>Ninguém com esta função</strong>
                    <p class="lista-vazia-acao"><a class="btn btn-primary btn-sm" href="<?= $e($base . '/equipe/nova?funcao=' . $filtro) ?>"><?= Icon::svg('user-plus', 'icon-sm') ?>Adicionar <?= $e(mb_strtolower($funcoes[$filtro][0])) ?></a></p>
                </div>
            </div>
        <?php else: ?>
            <ul class="lista lista-equipe" id="lista-equipe">
                <li class="lista-cabecalho" aria-hidden="true"><span>Pessoa</span><span>Função</span><span>WhatsApp</span><span>Último acesso</span><span class="lista-cab-acoes">Ações</span></li>
                <?php foreach ($pessoas as $p):
                    $id = (int) $p['id'];
                    [$rotulo, , $icone] = $funcoes[$p['cargo']];
                    $estaAtivo = (int) $p['ativo'] === 1;
                    $nome = $p['nome'] . ' ' . $p['sobrenome'];
                ?>
                    <li class="lista-item<?= $estaAtivo ? '' : ' is-inativo' ?>" data-filtro-item="<?= $e($nome . ' ' . $p['usuario']) ?>">
                        <div class="lista-principal">
                            <span class="lista-icone" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) $p['nome'], 0, 1) . mb_substr((string) $p['sobrenome'], 0, 1))) ?></span>
                            <span class="lista-titulo">
                                <a href="<?= $e($base . '/equipe/' . $id) ?>"><strong><?= $e($nome) ?></strong></a>
                                <small>Usuário: <?= $e((string) $p['usuario']) ?></small>
                            </span>
                        </div>
                        <span class="lista-dado" data-rotulo="Função">
                            <span class="etiqueta etiqueta-marca"><?= Icon::svg($icone) ?><?= $e($rotulo) ?></span>
                            <?php if (!$estaAtivo): ?><span class="etiqueta etiqueta-neutra"><?= Icon::svg('lock') ?>Bloqueado</span><?php endif; ?>
                        </span>
                        <span class="lista-dado" data-rotulo="WhatsApp"><?= $p['whatsapp'] ? $e(Documento::formatarTelefone((string) $p['whatsapp'])) : '<span class="texto-suave">Não informado</span>' ?></span>
                        <span class="lista-dado" data-rotulo="Último acesso"><?= $e($quando($p['ultimo_acesso'] ?? null)) ?></span>
                        <div class="lista-acoes">
                            <a class="btn-icone" href="<?= $e($base . '/equipe/' . $id) ?>"><?= Icon::svg('pencil', 'icon-sm') ?><span>Editar</span></a>
                            <form method="post" action="<?= $e($base . '/equipe/' . $id . '/acesso') ?>"
                                  <?= $estaAtivo ? 'data-confirm="' . $e($p['nome'] . ' não vai conseguir entrar até você liberar de novo. Se estiver usando o sistema agora, será desconectada.') . '" data-confirm-titulo="Bloquear o acesso?" data-confirm-acao="Bloquear acesso"' : '' ?>>
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="btn-icone" type="submit"><?= Icon::svg($estaAtivo ? 'lock' : 'rotate-ccw', 'icon-sm') ?><span><?= $estaAtivo ? 'Bloquear' : 'Liberar' ?></span></button>
                            </form>
                            <form method="post" action="<?= $e($base . '/equipe/' . $id . '/excluir') ?>"
                                  data-confirm="<?= $e($p['nome'] . ' perde o acesso para sempre e some da lista. O histórico do que ela fez continua guardado.') ?>"
                                  data-confirm-titulo="Remover <?= $e((string) $p['nome']) ?> da equipe?" data-confirm-acao="Remover da equipe" data-confirm-perigo="1">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="btn-icone is-perigo" type="submit" aria-label="Remover <?= $e($nome) ?> da equipe"><?= Icon::svg('trash', 'icon-sm') ?></button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="lista-vazia" id="equipe-nenhum" hidden>Ninguém encontrado com essa busca.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
