<?php
/** Avisos para as lojas. Espera: $avisos (AvisosLojaService::listar), $indisponivel, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Services\Plataforma\AvisosLojaService;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = static fn (string $v): string => date('d/m/Y H:i', (int) strtotime($v));
$agora = time();
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Avisos para as lojas</h1>
            <p>Pop-up para o gerente de todas as lojas, em qualquer tela da gerência. Você escolhe o período e quantas vezes por dia ele aparece.</p>
        </div>
        <?php if (!$indisponivel && $avisos !== []): ?>
            <div class="painel-acoes">
                <a class="btn btn-primary btn-sm" href="<?= $e($base . '/avisos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Novo aviso</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($indisponivel): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('clock') ?>
                <strong>Os avisos ainda não foram liberados neste servidor</strong>
                <p>A atualização do banco (migration 20261207100000_avisos_loja) ainda não foi aplicada.</p>
            </div>
        </section>
    <?php elseif ($avisos === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('bell-ring') ?>
                <strong>Nenhum aviso ainda</strong>
                <p>Crie um aviso para falar com todos os gerentes de uma vez. Ex.: pedir para reconectar o WhatsApp.</p>
                <p class="lista-vazia-acao"><a class="btn btn-primary btn-sm" href="<?= $e($base . '/avisos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Criar aviso</a></p>
            </div>
        </section>
    <?php else: ?>
        <div class="avisos-admin">
            <?php foreach ($avisos as $a):
                $ativo = (int) $a['ativo'] === 1;
                $inicio = (int) strtotime((string) $a['inicio']);
                $fim = (int) strtotime((string) $a['fim']);
                [$classe, $icone, $situacao] = match (true) {
                    !$ativo => ['etiqueta-neutra', 'pause', 'Pausado'],
                    $fim <= $agora => ['etiqueta-neutra', 'circle-check', 'Terminou'],
                    $inicio > $agora => ['etiqueta-atencao', 'clock', 'Agendado'],
                    default => ['etiqueta-ok', 'bell-ring', 'No ar'],
                };
                $vezes = (int) $a['vezes_por_dia'];
                $destino = (string) ($a['botao_destino'] ?? '');
                $botao = $destino === '' ? 'Sem botão'
                    : ($destino === AvisosLojaService::EXTERNO ? 'Link externo' : (AvisosLojaService::DESTINOS[$destino][0] ?? $destino));
            ?>
                <article class="aviso-admin<?= $ativo ? '' : ' is-pausado' ?>" id="aviso-<?= (int) $a['id'] ?>">
                    <div class="aviso-admin-topo">
                        <h2><?= $e((string) $a['titulo']) ?></h2>
                        <span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $situacao ?></span>
                    </div>
                    <?php if (!empty($a['texto'])): ?><p class="aviso-admin-texto"><?= $e((string) $a['texto']) ?></p><?php endif; ?>
                    <ul class="aviso-admin-info">
                        <li><?= Icon::svg('calendar-range', 'icon-sm') ?><?= $e($data((string) $a['inicio'])) ?> até <?= $e($data((string) $a['fim'])) ?></li>
                        <li><?= Icon::svg('repeat', 'icon-sm') ?><?= $vezes === 1 ? '1 vez por dia' : $vezes . ' vezes por dia' ?></li>
                        <li><?= Icon::svg('link', 'icon-sm') ?>Botão: <?= $e($botao) ?><?= !empty($a['botao_texto']) ? ' ("' . $e((string) $a['botao_texto']) . '")' : '' ?></li>
                        <?php if (!empty($a['imagem_path'])): ?><li><?= Icon::svg('image', 'icon-sm') ?>Com imagem</li><?php endif; ?>
                        <li><?= Icon::svg('store', 'icon-sm') ?><?= (int) $a['lojas_viram'] === 1 ? '1 loja viu' : (int) $a['lojas_viram'] . ' lojas viram' ?>
                            · <?= (int) $a['lojas_clicaram'] === 1 ? '1 clicou no botão' : (int) $a['lojas_clicaram'] . ' clicaram no botão' ?></li>
                    </ul>
                    <div class="aviso-admin-acoes">
                        <a class="btn btn-outline btn-sm" href="<?= $e($base . '/avisos/' . (int) $a['id']) ?>"><?= Icon::svg('pencil', 'icon-sm') ?>Editar</a>
                        <form method="post" action="<?= $e($base . '/avisos/' . (int) $a['id'] . '/ativo') ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn <?= $ativo ? 'btn-outline' : 'btn-primary' ?> btn-sm" type="submit"><?= Icon::svg($ativo ? 'pause' : 'play', 'icon-sm') ?><?= $ativo ? 'Pausar' : 'Ativar' ?></button>
                        </form>
                        <form method="post" action="<?= $e($base . '/avisos/' . (int) $a['id'] . '/excluir') ?>"
                              data-confirm="O aviso some para todas as lojas e o histórico de quem viu é apagado." data-confirm-titulo="Excluir este aviso?" data-confirm-acao="Excluir" data-confirm-perigo="1">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn btn-ghost btn-sm link-perigo" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Excluir</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
