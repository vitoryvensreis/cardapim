<?php require __DIR__.'/_base.php'; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Perfis tributários</h1><p>Configure uma vez, reutilize nos produtos compatíveis.</p></div><a class="btn btn-primary" href="<?= $e($url.'/perfis/novo') ?>"><?= Icon::svg('plus','icon-sm') ?> Novo perfil</a></header>
    <p><a href="<?= $e($url.'/produtos') ?>">Voltar aos produtos fiscais</a></p>
    <section class="bloco"><div class="bloco-cabecalho"><h2>Modelos para começar</h2><p>São apenas sugestões de nomes. Nenhum código ou imposto é presumido.</p></div>
        <div class="fiscal-modelos"><?php foreach (Validar::MODELOS as $m): ?><a class="btn btn-outline" href="<?= $e($url.'/perfis/novo?modelo='.rawurlencode($m)) ?>"><?= Icon::svg('plus','icon-sm') ?><?= $e($m) ?></a><?php endforeach; ?></div>
    </section>
    <section class="bloco"><h2>Perfis cadastrados</h2>
        <?php if ($perfis===[]): ?><p>Crie um perfil usando um modelo ou comece do zero.</p><?php endif; ?>
        <?php foreach ($perfis as $p): ?>
            <div class="fiscal-linha"><span><strong><?= $e($p['nome']) ?></strong><small>Versão <?= $e($p['versao']) ?> · <?= $e($p['vinculados']) ?> produtos vinculados · <?= Validar::perfilVigente($p,$empresa)?'Revisão registrada':'Pendente de revisão ou vigência' ?></small></span>
                <a class="btn btn-outline btn-sm" href="<?= $e($url.'/perfis/'.$p['id']) ?>">Editar</a><a class="btn btn-outline btn-sm" href="<?= $e($url.'/perfis/novo?copiar='.$p['id']) ?>">Duplicar</a>
            </div>
        <?php endforeach; ?>
    </section>
    <section class="bloco"><div class="bloco-cabecalho"><h2>Sugestões por categoria</h2><p>Somente sugere o perfil para novos cadastros fiscais. Não altera produtos existentes.</p></div>
        <?php foreach ($categorias as $c): ?>
            <form class="fiscal-linha" method="post" action="<?= $e($url.'/perfis/categoria') ?>"><?php $token(); ?><input type="hidden" name="id_categoria" value="<?= $e($c['id']) ?>">
                <label><?= $e($c['nome']) ?><select name="id_perfil"><option value="">Sem sugestão</option><?php foreach ($perfis as $p): if (!Validar::perfilVigente($p,$empresa) && (int)$c['id_perfil']!==(int)$p['id']) { continue; } ?><option value="<?= $e($p['id']) ?>" <?= (int)$p['id']===(int)$c['id_perfil']?'selected':'' ?>><?= $e($p['nome']) ?><?= Validar::perfilVigente($p,$empresa)?'':' (revisar)' ?></option><?php endforeach; ?></select></label><button class="btn btn-outline btn-sm" type="submit">Salvar sugestão</button>
            </form>
        <?php endforeach; ?>
    </section>
</div>
