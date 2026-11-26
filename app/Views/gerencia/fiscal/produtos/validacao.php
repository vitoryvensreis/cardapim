<?php require __DIR__.'/_base.php'; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos" data-fiscal-conferencia>
    <header class="painel-cabecalho"><div><h1>Conferência fiscal dos produtos</h1><p>Verifique classificações, versões de tabelas, unidades, perfis e composições. Esta análise cadastral não autoriza emissão.</p></div><a class="btn btn-outline" href="<?= $e($url.'/produtos') ?>">Voltar aos produtos</a></header>
    <section class="bloco">
        <div class="fiscal-resumo"><div><span>Produtos</span><strong><?= count($produtos) ?></strong></div><div><span>Sem pendências detectadas</span><strong><?= count(array_filter($verificacao,static fn($r)=>$r===[])) ?></strong></div><div><span>Com pendências</span><strong><?= count(array_filter($verificacao,static fn($r)=>$r!==[])) ?></strong></div></div>
        <p class="texto-suave">Esta conferência verifica o cadastro, não todos os cenários de impostos. A emissão de notas continua bloqueada nesta fase.</p>
    </section>
    <section class="bloco"><div class="bloco-cabecalho"><h2>Conferir produtos e composições</h2></div>
        <div class="fiscal-filtros" data-fiscal-lote-filtros>
            <label>Buscar produto<input type="search" data-fiscal-busca placeholder="Nome do produto"></label>
            <label>Mostrar<select data-fiscal-situacao><option value="1">Só com pendências</option><option value="">Todos</option><option value="0">Só sem pendências</option></select></label>
        </div>
        <?php foreach ($produtos as $id=>$p): ?><details data-fiscal-linha data-nome="<?= $e($p['nome']) ?>" data-pendente="<?= $verificacao[$id]===[]?'0':'1' ?>"><summary><?= $e($p['nome']) ?> — <?= $verificacao[$id]===[]?'Sem pendências detectadas':(count($verificacao[$id])===1?'1 pendência':count($verificacao[$id]).' pendências') ?></summary>
            <?php if ($verificacao[$id]!==[]): ?><ul class="fiscal-pendencias"><?php foreach ($verificacao[$id] as $msg): ?><li><?= $e($msg) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if (empty($estruturas[$id]['combo'])): ?><a class="btn btn-outline btn-sm" href="<?= $e($url.'/produtos/'.$id) ?>">Editar classificação</a><?php endif; ?>
            <?php if (isset($estruturas[$id])): ?><a class="btn btn-outline btn-sm" href="<?= $e($url.'/produtos/'.$id.'/composicao') ?>">Configurar composição fiscal</a><?php endif; ?>
        </details><?php endforeach; ?>
        <?php if (!$produtos): ?><p>Nenhum produto ativo cadastrado.</p><?php endif; ?>
    </section>
</div>
