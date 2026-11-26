<?php
use Cardapim\Core\Icon;
?>
<section class="bloco" id="liberacao-fiscal">
    <div class="bloco-cabecalho"><h2><?= Icon::svg('lock') ?> Liberação do módulo fiscal</h2><p>Você controla quais restaurantes podem visualizar e configurar o Fiscal. Nenhum dado é apagado ao ocultar o módulo.</p></div>
    <?php if ($liberacoes===null): ?>
        <p>Controle administrativo aguardando atualização do banco. A gerência permanece sem acesso fiscal.</p>
    <?php else: ?>
        <form method="post" action="<?= $e($url.'/visibilidade') ?>" data-confirm="<?= $liberacoes['global']?'Ocultar a aba Fiscal de todos os restaurantes? Os dados e as autorizações serão preservados.':'Restaurar acesso somente aos restaurantes autorizados?' ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="visivel" value="<?= $liberacoes['global']?'0':'1' ?>">
            <p><strong><?= $liberacoes['global']?'Disponível para restaurantes autorizados':'Oculto para todos os restaurantes' ?></strong></p>
            <button type="submit" class="btn btn-outline"><?= Icon::svg('power','icon-sm') ?><?= $liberacoes['global']?'Ocultar de todos':'Restaurar acesso dos autorizados' ?></button>
        </form>
        <form class="fiscal-form" method="get" action="<?= $e($url) ?>">
            <label for="fiscal-busca-restaurante">Buscar restaurante</label>
            <input id="fiscal-busca-restaurante" name="busca_restaurante" maxlength="120" value="<?= $e($liberacoes['busca']) ?>" placeholder="Nome ou endereço do cardápio">
            <button type="submit" class="btn btn-outline">Buscar</button>
        </form>
        <?php if ($liberacoes['mais']): ?><p>Mostrando os primeiros 100 resultados. Refine a busca para encontrar outro restaurante.</p><?php endif; ?>
        <?php foreach ($liberacoes['restaurantes'] as $r): ?>
            <article class="bloco">
                <strong><?= $e($r['nome']) ?></strong><p><?= $e($r['slug']) ?> · <?= $r['permitido']?'Autorizado':'Não autorizado' ?><?= $r['permitido']&&!$liberacoes['global']?' (oculto globalmente)':'' ?><?= $r['permitido']?' · '.((int)$r['producao']===1?'Produção liberada (notas com valor fiscal)':'Somente testes'):'' ?></p>
                <form method="post" action="<?= $e($url.'/liberar-restaurante') ?>" data-confirm="<?= $r['permitido']?'Ocultar a aba Fiscal deste restaurante? Os dados serão preservados.':'Liberar a aba Fiscal para este restaurante? A emissão real permanece bloqueada.' ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="id_restaurante" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="permitido" value="<?= $r['permitido']?'0':'1' ?>">
                    <button class="btn <?= $r['permitido']?'btn-outline':'btn-primary' ?> btn-sm" type="submit"><?= $r['permitido']?'Ocultar Fiscal':'Liberar Fiscal' ?></button>
                </form>
                <?php if ($r['permitido']): $prod=(int)$r['producao']===1; ?>
                    <form method="post" action="<?= $e($url.'/producao') ?>" data-confirm="<?= $prod?'Bloquear a produção? O restaurante volta para homologação e nenhuma nota com valor fiscal será emitida.':'Liberar a produção? O restaurante poderá emitir NFC-e com valor fiscal depois de configurar série, numeração, CSC e certificado de produção.' ?>" data-confirm-titulo="<?= $prod?'Bloquear produção?':'Liberar produção?' ?>"<?= $prod?' data-confirm-perigo="1"':'' ?>>
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="id_restaurante" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="producao" value="<?= $prod?'0':'1' ?>">
                        <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg($prod?'lock':'circle-check','icon-sm') ?><?= $prod?'Bloquear produção':'Liberar produção' ?></button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if ($liberacoes['restaurantes']===[]): ?><p>Nenhum restaurante encontrado.</p><?php endif; ?>
    <?php endif; ?>
</section>
