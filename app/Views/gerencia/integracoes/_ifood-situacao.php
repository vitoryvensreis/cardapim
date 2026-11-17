<?php
use Cardapim\Core\Icon;
/** Status operacional do iFood; $p normalizado em IfoodLojaService. */
?>
    <section class="bloco" aria-labelledby="titulo-situacao" data-ifood-status>
        <div class="bloco-cabecalho">
            <h2 id="titulo-situacao"><?= Icon::svg('activity') ?>Disponibilidade agora</h2>
            <p>Se a loja está recebendo pedidos no iFood em cada tipo de operação.</p>
        </div>
        <?= $aviso($p['erros']['situacao'] ?? null) ?>
        <?php if (($p['divergencia'] ?? false) === true): ?>
            <div class="notice notice-warning" role="status"><?= Icon::svg('circle-exclamation-point') ?><div><p><strong>Pausa vigente, mas loja aberta no iFood.</strong> O iFood cadastrou uma interrupção, porém confirma que a operação continua disponível para pedidos. Verifique as condições da loja de teste e acompanhe a confirmação no Portal do Parceiro. O Deliveryzim pode manter um bloqueio preventivo de novos pedidos feitos diretamente por seu cardápio.</p></div></div>
        <?php endif; ?>
        <?php if ($p['situacao'] === [] && !isset($p['erros']['situacao'])): ?>
            <p class="texto-suave">O iFood não informou a situação da loja.</p>
        <?php endif; ?>
        <ul class="saude-lista">
            <?php foreach ($p['situacao'] as $s): ?>
                <li class="saude-item <?= $s['disponivel'] ? 'is-ok' : 'is-aviso' ?>"><?= Icon::svg($s['disponivel'] ? 'circle-check' : 'circle-pause') ?>
                    <span><strong><?= $e(ucfirst(strtolower($s['operacao']))) ?><?= $s['canal'] !== '' ? ' · ' . $e($s['canal']) : '' ?>: <?= $s['disponivel'] ? 'aberta' : 'fechada' ?></strong>
                        <small><?= $e($s['mensagem'] !== '' ? $s['mensagem'] : 'Situação: ' . $s['estado']) ?></small>
                        <?php foreach ($s['validacoes'] as $v): ?><small><?= $e($v) ?></small><?php endforeach; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

