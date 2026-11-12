<?php
/** Suporte do gerente: chamados do restaurante. Espera: $chamados, $contagem, $filtro, $base. */
use Cardapim\Core\Icon;
use Cardapim\Services\Suporte\SuporteService;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filtros = ['abertos' => 'Em andamento', 'resolvidos' => 'Resolvidos', 'todos' => 'Todos'];
$quando = static function (string $d): string {
    $t = (int) strtotime($d);
    return date('Y-m-d', $t) === date('Y-m-d') ? 'hoje, ' . date('H:i', $t) : date('d/m/Y H:i', $t);
};
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Suporte</h1>
            <p>Precisa de ajuda com o <?= marca_html() ?>? Abra um chamado: a nossa equipe responde aqui e você recebe um aviso por e-mail.</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-primary btn-sm" href="<?= $e($base . '/suporte/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Abrir chamado</a>
        </div>
    </header>

    <?php if ($contagem['todos'] > 0): ?>
        <nav class="filtros suporte-filtros" aria-label="Filtrar chamados">
            <?php foreach ($filtros as $chave => $rotulo): ?>
                <a href="<?= $e($base . '/suporte' . ($chave === 'abertos' ? '' : '?filtro=' . $chave)) ?>"<?= $filtro === $chave ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?> (<?= (int) $contagem[$chave] ?>)</a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($chamados === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('life-buoy') ?>
                <?php if ($contagem['todos'] === 0): ?>
                    <strong>Nenhum chamado ainda</strong>
                    <p>Quando tiver uma dúvida, um problema ou uma sugestão, abra um chamado. Conte com detalhes o que aconteceu.</p>
                    <p class="lista-vazia-acao"><a class="btn btn-primary btn-sm" href="<?= $e($base . '/suporte/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Abrir chamado</a></p>
                <?php else: ?>
                    <strong><?= $filtro === 'abertos' ? 'Nenhum chamado em andamento' : 'Nenhum chamado aqui' ?></strong>
                    <p><a href="<?= $e($base . '/suporte?filtro=todos') ?>">Ver todos os chamados</a></p>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <ul class="suporte-lista">
            <?php foreach ($chamados as $c):
                $novo = $c['ultima_de'] === 'suporte' && ($c['visto_restaurante_em'] === null || strtotime((string) $c['visto_restaurante_em']) < strtotime((string) $c['ultima_mensagem_em'])); ?>
                <li>
                    <a class="suporte-item<?= $novo ? ' is-novo' : '' ?>" href="<?= $e($base . '/suporte/' . (int) $c['id']) ?>">
                        <span class="suporte-item-topo">
                            <strong>#<?= (int) $c['id'] ?> <?= $e((string) $c['assunto']) ?></strong>
                            <?php $chamadoSelo = $c; $ladoSelo = 'restaurante'; require __DIR__ . '/../../suporte/_selo.php'; ?>
                        </span>
                        <span class="suporte-item-sub">
                            <span><?= $e(SuporteService::CATEGORIAS[$c['categoria']] ?? '') ?> · <?= (int) $c['mensagens'] ?> <?= (int) $c['mensagens'] === 1 ? 'mensagem' : 'mensagens' ?></span>
                            <span><?= $novo ? '<strong class="suporte-novo">' . Icon::svg('bell', 'icon-sm') . 'Resposta nova</strong> · ' : '' ?>Atualizado <?= $e($quando((string) $c['ultima_mensagem_em'])) ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
