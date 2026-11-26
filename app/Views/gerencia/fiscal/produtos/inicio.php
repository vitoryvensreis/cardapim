<?php
use Cardapim\Core\Icon;
use Cardapim\Modules\Fiscal\CadastroProdutoFiscal as Validar;

require __DIR__.'/_base.php';
$revisados=count(array_filter($produtos,static fn($p)=>$p['pendencias']===[]));
$ncmPendentes=count(array_filter($produtos,static fn($p)=>($p['dados']['ncm'] ?? '')!=='' && !\Cardapim\Modules\Fiscal\CatalogoNcm::fonteValida((string)$p['dados']['ncm'],$p['dados']['ncm_fonte'] ?? null)));
?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><a class="voltar-link" href="<?= $e($url) ?>"><?= \Cardapim\Core\Icon::svg('arrow-left','icon-sm') ?>Central fiscal</a><h1>Cadastro fiscal dos produtos</h1><p>Configure em massa e revise as informações individuais, sem alterar o cardápio.</p></div><a class="btn btn-outline" href="<?= $e($url.'/perfis') ?>"><?= Icon::svg('list','icon-sm') ?> Perfis tributários</a></header>
    <section class="bloco"><div class="bloco-cabecalho"><h2>Reaproveitar dados fiscais</h2><p>Importe a classificação de mercadorias já cadastradas a partir de XML ou CSV.</p></div><a class="btn btn-outline" href="<?= $e($url.'/produtos/importar') ?>"><?= Icon::svg('upload','icon-sm') ?> Importar XML ou CSV</a> <a class="btn btn-outline" href="<?= $e($url.'/produtos/catalogos') ?>">Pesquisar tabelas fiscais</a> <a class="btn btn-outline" href="<?= $e($url.'/produtos/validacao') ?>">Conferência fiscal</a></section>
    <section class="bloco">
        <div class="fiscal-resumo">
            <div><span>Produtos</span><strong><?= count($produtos) ?></strong></div>
            <div><span>Cadastro revisado</span><strong><?= $revisados ?></strong></div>
            <div><span>Pendências</span><strong><?= count($produtos)-$revisados ?></strong></div>
        </div>
        <p class="texto-suave">Produto revisado significa cadastro completo; a emissão de notas continua bloqueada nesta fase.</p>
        <?php if ($ncmPendentes>0): ?>
            <form method="post" action="<?= $e($url.'/produtos/revalidar-ncm') ?>" class="fiscal-aviso"><?php $token(); ?>
                <p><strong><?= $e($ncmPendentes) ?> produtos com NCM ainda não conferido na BrasilAPI.</strong> A conferência é feita uma vez por código e vale enquanto o NCM estiver em vigor.</p>
                <button class="btn btn-outline" type="submit"><?= Icon::svg('refresh-cw','icon-sm') ?> Revalidar NCMs</button>
            </form>
        <?php endif; ?>
    </section>
    <section class="bloco">
        <div class="bloco-cabecalho"><h2>Aplicar perfil em massa</h2><p>Selecione até 200 produtos, escolha o que deseja alterar e confira a prévia.</p></div>
        <?php if ($perfis===[]): ?><p>Crie e revise seu primeiro perfil tributário para começar.</p><a class="btn btn-primary" href="<?= $e($url.'/perfis') ?>">Configurar perfis</a><?php endif; ?>
        <form method="post" action="<?= $e($url.'/produtos/previa') ?>" data-fiscal-lote data-fiscal-classificacao data-ncm-url="<?= $e($url.'/classificacoes/ncm') ?>"><?php $token(); ?>
            <div class="fiscal-filtros">
                <label>Buscar produto<input type="search" data-fiscal-busca placeholder="Nome do produto"></label>
                <label>Categoria<select data-fiscal-filtro><option value="">Todas</option><?php foreach ($categorias as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['nome']) ?></option><?php endforeach; ?></select></label>
                <label>Mostrar<select data-fiscal-situacao><option value="">Todos</option><option value="1">Só com pendências</option><option value="0">Só revisados</option></select></label>
            </div>
            <label class="fiscal-check"><input type="checkbox" data-fiscal-todos> Selecionar produtos visíveis</label>
            <div class="fiscal-lista">
                <?php foreach ($produtos as $p): $perfil=$perfis[(int)$p['id_perfil']] ?? null; ?>
                    <div class="fiscal-linha" data-fiscal-linha data-nome="<?= $e($p['nome']) ?>" data-categoria="<?= $e($p['id_categoria']) ?>" data-pendente="<?= $p['pendencias']===[]?'0':'1' ?>">
                        <label class="fiscal-check"><input type="checkbox" name="produtos[]" value="<?= $e($p['id']) ?>"><span><strong><?= $e($p['nome']) ?></strong><small><?= $e($p['categoria'] ?? 'Sem categoria') ?> · <?= $e($perfil['nome'] ?? 'Sem perfil') ?><?php if (!$perfil && isset($perfis[(int)$p['sugestao']])): ?> · Sugestão: <?= $e($perfis[(int)$p['sugestao']]['nome']) ?><?php endif; ?></small></span></label>
                        <span class="fiscal-status"><?php if ($p['pendencias']===[]): ?><?= Icon::svg('circle-check','icon-sm') ?> Revisado<?php else: ?><?= count($p['pendencias'])===1?'1 pendência':count($p['pendencias']).' pendências' ?><small class="fiscal-pendencia-principal"><?= $e($p['pendencias'][0]) ?></small><?php endif; ?></span>
                        <a class="btn btn-outline btn-sm" href="<?= $e($url.'/produtos/'.$p['id']) ?>">Editar dados fiscais</a>
                    </div>
                <?php endforeach; ?>
                <?php if ($produtos===[]): ?><p>Nenhum produto cadastrado. Cadastre no estoque normalmente.</p><?php endif; ?>
            </div>
            <div class="fiscal-filtros">
                <label>Perfil tributário<select name="id_perfil"><option value="">Manter perfil atual</option><?php foreach ($perfis as $p): if (!Validar::perfilVigente($p,$empresa)) { continue; } ?><option value="<?= $e($p['id']) ?>"><?= $e($p['nome']) ?> · v<?= $e($p['versao']) ?></option><?php endforeach; ?></select></label>
            </div>
            <details><summary>Classificação individual em massa (opcional)</summary>
                <p>Preencha só o que é igual para todos os produtos marcados. O código de barras nunca é copiado em massa, e cada produto alterado volta a pedir conferência.</p>
                <div class="fiscal-form">
                    <label for="lote-ncm-busca">Pesquisar NCM pela BrasilAPI (nome ou código)</label><input id="lote-ncm-busca" data-ncm-busca type="search" maxlength="100" placeholder="Nome ou código">
                    <button class="btn btn-outline" type="button" data-ncm-pesquisar><?= Icon::svg('search','icon-sm') ?> Pesquisar classificação</button>
                    <p role="status" aria-live="polite" data-ncm-status></p><div data-ncm-resultados class="fiscal-lista"></div>
                    <?php foreach (['ncm'=>'NCM (8 dígitos)','cest'=>'CEST (7 dígitos, quando aplicável)','origem'=>'Origem (0 a 8)','unidade_comercial'=>'Unidade comercial','unidade_tributavel'=>'Unidade tributável','fator'=>'Fator de conversão'] as $k=>$rot): ?>
                        <label for="lote-<?= $e($k) ?>"><?= $e($rot) ?></label><input id="lote-<?= $e($k) ?>" name="<?= $e($k) ?>" maxlength="18" data-fiscal-lote-campo="<?= $e($k) ?>">
                        <label class="fiscal-check"><input type="checkbox" name="campos[]" value="<?= $e($k) ?>"> Aplicar este campo aos selecionados</label>
                    <?php endforeach; ?>
                    <label class="fiscal-check"><input type="checkbox" name="sobrescrever" value="1"> Substituir dados individuais já preenchidos (por padrão, serão preservados).</label>
                </div>
            </details>
            <div class="fiscal-filtros">
                <p data-fiscal-contagem role="status">Nenhum produto selecionado</p>
                <button class="btn btn-primary" type="submit" <?= $produtos===[]?'disabled':'' ?>>Revisar aplicação</button>
            </div>
        </form>
    </section>
</div>
