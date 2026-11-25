<?php require __DIR__.'/_base.php'; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Conferência fiscal dos produtos</h1><p>Verifique classificações, versões de tabelas, unidades, perfis e composições. Esta análise cadastral não autoriza emissão.</p></div><a class="btn btn-outline" href="<?= $e($url.'/produtos') ?>">Voltar aos produtos</a></header>
    <section class="bloco">
        <div class="fiscal-resumo"><div><span>Produtos</span><strong><?= count($produtos) ?></strong></div><div><span>Sem pendências detectadas</span><strong><?= count(array_filter($verificacao,static fn($r)=>$r===[])) ?></strong></div><div><span>Com pendências</span><strong><?= count(array_filter($verificacao,static fn($r)=>$r!==[])) ?></strong></div></div>
        <p>Não valida todos os cenários de ICMS, PIS/COFINS, IBS/CBS, benefícios ou leiaute da NeXT. A emissão real continua bloqueada.</p>
    </section>
    <section class="bloco"><h2>Conferir produtos e composições</h2>
        <?php foreach ($produtos as $id=>$p): ?><details <?= $verificacao[$id]===[]?'':'open' ?>><summary><?= $e($p['nome']) ?> — <?= $verificacao[$id]===[]?'Sem pendências detectadas':count($verificacao[$id]).' pendências' ?></summary>
            <?php foreach ($verificacao[$id] as $msg): ?><p><?= $e($msg) ?></p><?php endforeach; ?>
            <?php if (empty($estruturas[$id]['combo'])): ?><a class="btn btn-outline btn-sm" href="<?= $e($url.'/produtos/'.$id) ?>">Editar classificação</a><?php endif; ?>
            <?php if (isset($estruturas[$id])): ?><a class="btn btn-outline btn-sm" href="<?= $e($url.'/produtos/'.$id.'/composicao') ?>">Configurar composição fiscal</a><?php endif; ?>
        </details><?php endforeach; ?>
        <?php if (!$produtos): ?><p>Nenhum produto ativo cadastrado.</p><?php endif; ?>
    </section>
</div>
