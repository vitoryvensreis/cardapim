<?php
require __DIR__.'/_base.php';
$revisados=count(array_filter($produtos,static fn($p)=>$p['pendencias']===[]));
?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Cadastro fiscal dos produtos</h1><p>Configure em massa e revise as informações individuais, sem alterar o cardápio.</p></div><a class="btn btn-outline" href="<?= $e($url.'/perfis') ?>"><?= Icon::svg('layers','icon-sm') ?> Perfis tributários</a></header>
    <p><a href="<?= $e($url) ?>">Voltar à Central Fiscal</a></p>
    <section class="bloco">
        <div class="fiscal-resumo">
            <div><span>Produtos</span><strong><?= count($produtos) ?></strong></div>
            <div><span>Cadastro revisado</span><strong><?= $revisados ?></strong></div>
            <div><span>Pendências</span><strong><?= count($produtos)-$revisados ?></strong></div>
        </div>
        <p class="texto-suave">Revisão cadastral não significa autorização fiscal. A emissão real permanece bloqueada.</p>
    </section>
    <section class="bloco">
        <div class="bloco-cabecalho"><h2>Aplicar perfil em massa</h2><p>Selecione até 200 produtos e confira a prévia. Os dados individuais serão preservados.</p></div>
        <?php if ($perfis===[]): ?><p>Crie e revise seu primeiro perfil tributário para começar.</p><a class="btn btn-primary" href="<?= $e($url.'/perfis') ?>">Configurar perfis</a><?php endif; ?>
        <form method="post" action="<?= $e($url.'/produtos/previa') ?>" data-fiscal-lote><?php $token(); ?>
            <div class="fiscal-filtros">
                <label>Buscar produto<input type="search" data-fiscal-busca placeholder="Nome do produto"></label>
                <label>Categoria<select data-fiscal-filtro><option value="">Todas</option><?php foreach ($categorias as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['nome']) ?></option><?php endforeach; ?></select></label>
            </div>
            <label class="fiscal-check"><input type="checkbox" data-fiscal-todos> Selecionar produtos visíveis</label>
            <div class="fiscal-lista">
                <?php foreach ($produtos as $p): $perfil=$perfis[(int)$p['id_perfil']] ?? null; ?>
                    <div class="fiscal-linha" data-fiscal-linha data-nome="<?= $e($p['nome']) ?>" data-categoria="<?= $e($p['id_categoria']) ?>">
                        <label class="fiscal-check"><input type="checkbox" name="produtos[]" value="<?= $e($p['id']) ?>"><span><strong><?= $e($p['nome']) ?></strong><small><?= $e($p['categoria'] ?? 'Sem categoria') ?> · <?= $e($perfil['nome'] ?? 'Sem perfil') ?><?php if (!$perfil && isset($perfis[(int)$p['sugestao']])): ?> · Sugestão: <?= $e($perfis[(int)$p['sugestao']]['nome']) ?><?php endif; ?></small></span></label>
                        <span class="fiscal-status"><?= $p['pendencias']===[]?'Revisado':count($p['pendencias']).' pendências' ?></span>
                        <a class="btn btn-outline btn-sm" href="<?= $e($url.'/produtos/'.$p['id']) ?>">Editar dados fiscais</a>
                    </div>
                <?php endforeach; ?>
                <?php if ($produtos===[]): ?><p>Nenhum produto cadastrado. Cadastre no estoque normalmente.</p><?php endif; ?>
            </div>
            <div class="fiscal-filtros">
                <label>Perfil tributário<select name="id_perfil" required><option value="">Selecione um perfil revisado</option><?php foreach ($perfis as $p): if (!Validar::perfilVigente($p,$empresa)) { continue; } ?><option value="<?= $e($p['id']) ?>"><?= $e($p['nome']) ?> · v<?= $e($p['versao']) ?></option><?php endforeach; ?></select></label>
                <p data-fiscal-contagem role="status">Nenhum produto selecionado</p>
                <button class="btn btn-primary" type="submit" <?= $produtos===[]?'disabled':'' ?>>Revisar aplicação</button>
            </div>
        </form>
    </section>
</div>
