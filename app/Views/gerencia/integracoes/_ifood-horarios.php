<?php
/** Horários da loja iFood; variáveis definidas em ifood-loja.php. */
use Cardapim\Core\Icon;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
?>
    <section class="bloco ifood-horarios-painel" id="horarios" aria-labelledby="titulo-horarios">
        <div class="bloco-cabecalho">
            <h2 id="titulo-horarios"><?= Icon::svg('clock') ?>Horários de funcionamento</h2>
            <p>Normalmente o iFood usa o mesmo horário do seu Perfil. Se no iFood a loja abre em outro horário, ligue a opção abaixo.</p>
        </div>
        <?= $aviso($p['erros']['horarios'] ?? null) ?>
        <?php if (isset($eH['_geral'])): ?><?= $aviso($eH['_horarios'] ?? $eH['_geral']) ?><?php endif; ?>
        <form method="post" action="<?= $e($base . '/integracoes/ifood/horarios') ?>" novalidate data-ifood-horario>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div class="ifood-horario-perfil" data-ifood-horario-perfil<?= $proprio ? ' hidden' : '' ?>>
                <p class="ifood-horario-perfil-rotulo"><?= Icon::svg('store', 'icon-sm') ?>Horário do Perfil</p>
                <?php if ($perfil['turnos'] === []): ?>
                    <p class="texto-suave">O Perfil ainda não tem horário de funcionamento.</p>
                    <a class="btn btn-outline btn-sm" href="<?= $e($base . '/perfil#horarios') ?>"><?= Icon::svg('pencil', 'icon-sm') ?>Cadastrar horário no Perfil</a>
                <?php else: ?>
                    <ul class="ifood-horario-resumo">
                        <?php foreach (IfoodApi::DIAS as $dia): $turnos = $perfil['dias'][$dia] ?? []; ?>
                            <li><strong><?= $e(IfoodLojaService::NOMES_DIAS[$dia]) ?></strong>
                                <span><?= $turnos === [] ? 'Fechado' : $e(implode(' · ', $turnos)) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="texto-suave">Para mudar, edite o horário em <a href="<?= $e($base . '/perfil#horarios') ?>">Perfil do restaurante</a>. O iFood muda junto.</p>
                <?php endif; ?>
            </div>
            <?= \Cardapim\Shared\Html\Campo::alternador('horario_proprio', 'Horário diferente para o iFood', 'Ligue para usar no iFood um horário só dele. O horário do Perfil continua valendo no seu cardápio.', $proprio, ['data-ifood-horario-proprio' => '']) ?>
            <div class="ifood-horario-proprio" data-ifood-horario-form<?= $proprio ? '' : ' hidden' ?>>
                <p class="texto-suave">Até <?= IfoodLojaService::MAX_TURNOS_DIA ?> horários por dia. Deixe o dia em branco para ficar fechado. Fechamento 00:00 = meia-noite.</p>
                <div class="ifood-horarios">
                    <?php foreach (IfoodApi::DIAS as $dia): $linhas = is_array($form[$dia] ?? null) ? array_values($form[$dia]) : []; ?>
                        <fieldset class="ifood-dia">
                            <legend><?= $e(IfoodLojaService::NOMES_DIAS[$dia]) ?></legend>
                            <?php for ($i = 0; $i < IfoodLojaService::MAX_TURNOS_DIA; $i++): $l = is_array($linhas[$i] ?? null) ? $linhas[$i] : []; ?>
                                <div class="ifood-turno<?= isset($eH[$dia . '.' . $i]) ? ' tem-erro' : '' ?>">
                                    <label class="sr-only" for="h-<?= $e($dia) ?>-<?= $i ?>-a"><?= $e(IfoodLojaService::NOMES_DIAS[$dia]) ?>, horário <?= $i + 1 ?>: abre</label>
                                    <input class="campo-simples" type="time" id="h-<?= $e($dia) ?>-<?= $i ?>-a" name="h[<?= $e($dia) ?>][<?= $i ?>][abre]" value="<?= $e(is_string($l['abre'] ?? null) ? $l['abre'] : '') ?>">
                                    <span aria-hidden="true">às</span>
                                    <label class="sr-only" for="h-<?= $e($dia) ?>-<?= $i ?>-f"><?= $e(IfoodLojaService::NOMES_DIAS[$dia]) ?>, horário <?= $i + 1 ?>: fecha</label>
                                    <input class="campo-simples" type="time" id="h-<?= $e($dia) ?>-<?= $i ?>-f" name="h[<?= $e($dia) ?>][<?= $i ?>][fecha]" value="<?= $e(is_string($l['fecha'] ?? null) ? $l['fecha'] : '') ?>">
                                </div>
                                <?= $campoErro($eH, $dia . '.' . $i) ?>
                            <?php endfor; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="bloco-rodape"><button class="btn btn-primary" type="submit" data-submit-label="Enviando..."><?= Icon::svg('send', 'icon-sm') ?>Salvar e enviar ao iFood</button></div>
        </form>
        <h3 class="ifood-subtitulo">Como está no iFood agora</h3>
        <?php if ($p['horarios'] === []): ?>
            <p class="texto-suave">Nenhum horário cadastrado no iFood.</p>
        <?php else: ?>
            <ul class="ifood-lista">
                <?php foreach (IfoodLojaService::paraFormulario($p['horarios']) as $dia => $turnos): ?>
                    <li class="ifood-lista-item"><span><strong><?= $e(IfoodLojaService::NOMES_DIAS[$dia]) ?></strong>
                        <small><?= $e(implode(' · ', array_map(static fn (array $t): string => $t['abre'] . ' às ' . $t['fecha'], $turnos))) ?></small></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
