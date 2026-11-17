<?php
/**
 * Conteudo do card "Pausas" da Loja no iFood. Usado na tela e devolvido pronto quando a pausa e criada
 * ou removida sem recarregar a pagina (o aviso aparece aqui dentro do card).
 * Espera: $pausas (lista do iFood), $erroLista (?string), $eP (erros do formulario), $vP (valores), $nota (?['tipo' => sucesso|erro, 'mensagem']),
 *         $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
use Cardapim\Services\Ifood\FusoLojaIfood;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fusoLoja = FusoLojaIfood::valido((string) ($fusoLoja ?? '')) ? $fusoLoja : 'America/Sao_Paulo';
$quandoPausa = static fn (string $iso): string => IfoodLojaService::dataIfood($iso)?->setTimezone(FusoLojaIfood::timezone($fusoLoja))->format('d/m/Y \à\s H:i') ?? $iso;
$erroPausa = static fn (string $campo): string => isset($eP[$campo]) ? '<p class="field-msg ficha-erro" id="erro-pausa-' . $e($campo) . '">' . $e($eP[$campo]) . '</p>' : '';
$ativas = array_column(IfoodLojaService::pausasAtivas($pausas), 'id');
$geral = $nota === null && isset($eP['_geral']) ? ['tipo' => 'erro', 'mensagem' => $eP['_geral']] : $nota;
?>
<div class="bloco-cabecalho">
    <h2 id="titulo-pausas"><?= Icon::svg('circle-pause') ?>Pausas</h2>
    <p>Cadastre uma interrupção temporária de 1 minuto a 7 dias. A pausa só fechará a loja no iFood após a confirmação da disponibilidade no painel acima.</p>
</div>
<?php if ($geral !== null): ?>
    <div class="notice <?= $geral['tipo'] === 'sucesso' ? 'notice-success' : 'notice-warning' ?> ifood-pausa-aviso" role="<?= $geral['tipo'] === 'erro' ? 'alert' : 'status' ?>" tabindex="-1" data-pausas-aviso>
        <?= Icon::svg($geral['tipo'] === 'sucesso' ? 'circle-check' : ($geral['tipo'] === 'pendente' ? 'clock' : 'circle-exclamation-point')) ?><div><p><?= $e($geral['mensagem']) ?></p></div>
    </div>
<?php endif; ?>
<?php if ($erroLista !== null): ?>
    <div class="notice notice-warning"><?= Icon::svg('circle-exclamation-point') ?><div><p><?= $e($erroLista) ?></p></div></div>
<?php endif; ?>
<?php if ($pausas === []): ?>
    <p class="texto-suave"><?= $erroLista !== null ? 'Não foi possível confirmar as pausas agora.' : 'Nenhuma pausa ativa.' ?></p>
<?php else: ?>
    <ul class="ifood-lista">
        <?php foreach ($pausas as $pausa): $agora = in_array($pausa['id'], $ativas, true); $fimPausa = IfoodLojaService::dataIfood($pausa['fim']); $encerrada = $fimPausa !== null && $fimPausa->getTimestamp() <= time(); ?>
            <li class="ifood-lista-item">
                <span><strong><?= $e($pausa['descricao'] !== '' ? $pausa['descricao'] : 'Pausa') ?></strong>
                    <small>De <?= $e($quandoPausa($pausa['inicio'])) ?> até <?= $e($quandoPausa($pausa['fim'])) ?> (horário da loja)</small></span>
                <span class="etiqueta <?= $agora ? 'etiqueta-atencao' : 'etiqueta-neutra' ?>"><?= Icon::svg($agora ? 'circle-pause' : 'clock') ?><?= $agora ? 'Horário vigente' : ($encerrada ? 'Encerrada' : 'Agendada') ?></span>
                <form method="post" action="<?= $e($base . '/integracoes/ifood/pausas/remover') ?>" data-pausa-form
                      data-confirm="Solicitar remoção da pausa ao iFood? Confira o status da loja depois da atualização." data-confirm-titulo="Remover esta pausa?" data-confirm-acao="Remover">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="pausa" value="<?= $e($pausa['id']) ?>">
                    <button class="btn btn-outline btn-sm" type="submit" data-submit-label="Removendo..."><?= Icon::svg('circle-play', 'icon-sm') ?><span>Remover pausa</span></button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<form class="ifood-form-pausa" method="post" action="<?= $e($base . '/integracoes/ifood/pausas') ?>" novalidate data-pausa-form>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <h3>Nova pausa</h3>
    <p class="texto-suave">Horário de referência do iFood: <?= $e(FusoLojaIfood::OPCOES[$fusoLoja]) ?>. Se estiver diferente do Portal, ajuste em Dados e diagnósticos.</p>
    <label class="campo-rotulo" for="pausa-descricao">Motivo</label>
    <input class="campo-simples" id="pausa-descricao" name="descricao" maxlength="255" required value="<?= $e((string) $vP['descricao']) ?>" placeholder="Ex.: Cozinha cheia"<?= isset($eP['descricao']) ? ' aria-invalid="true" aria-describedby="erro-pausa-descricao"' : '' ?>>
    <?= $erroPausa('descricao') ?>
    <div class="ifood-datas">
        <div>
            <label class="campo-rotulo" for="pausa-inicio">Começa (horário da loja)</label>
            <input class="campo-simples" id="pausa-inicio" type="datetime-local" name="inicio" required value="<?= $e((string) $vP['inicio']) ?>"<?= isset($eP['inicio']) ? ' aria-invalid="true" aria-describedby="erro-pausa-inicio"' : '' ?>>
            <?= $erroPausa('inicio') ?>
        </div>
        <div>
            <label class="campo-rotulo" for="pausa-fim">Termina (horário da loja)</label>
            <input class="campo-simples" id="pausa-fim" type="datetime-local" name="fim" required value="<?= $e((string) $vP['fim']) ?>"<?= isset($eP['fim']) ? ' aria-invalid="true" aria-describedby="erro-pausa-fim"' : '' ?>>
            <?= $erroPausa('fim') ?>
        </div>
    </div>
    <div class="bloco-rodape"><button class="btn btn-primary" type="submit" data-submit-label="Criando..."><?= Icon::svg('circle-pause', 'icon-sm') ?><span>Criar pausa</span></button></div>
</form>
