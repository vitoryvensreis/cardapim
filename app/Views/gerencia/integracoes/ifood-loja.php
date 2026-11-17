<?php
/**
 * Loja no iFood (Merchant): lojas autorizadas, dados, disponibilidade, pausas e horarios.
 * Espera: $painel (IfoodLojaService::painel), $erros ['pausa'|'horarios' => campo => msg], $valores, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$quando = static function (string $iso): string {
    $t = strtotime($iso);
    return $t !== false ? date('d/m/Y H:i', $t) : $iso;
};
$p = $painel;
$det = is_array($p['detalhes']) ? $p['detalhes'] : [];
$end = is_array($det['address'] ?? null) ? $det['address'] : [];
$eP = $erros['pausa'] ?? [];
$eH = $erros['horarios'] ?? [];
$vP = $valores['pausa'] ?? IfoodLojaService::novaPausa();
$form = $valores['horarios'] ?? $p['sugestao'];
$proprio = (bool) ($valores['horario_proprio'] ?? $p['horario_proprio']);
$perfil = $p['perfil'];
$aviso = static function (?string $msg) use ($e): string {
    return $msg ? '<div class="notice notice-warning">' . Icon::svg('circle-exclamation-point') . '<div><p>' . $e($msg) . '</p></div></div>' : '';
};
$campoErro = static fn (array $lista, string $campo): string => isset($lista[$campo]) ? '<p class="field-msg ficha-erro">' . $e($lista[$campo]) . '</p>' : '';
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes/ifood') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>iFood</a>
            <h1>Loja no iFood</h1>
            <p>Dados, disponibilidade, pausas e horário de funcionamento da sua loja no iFood. Tudo que você muda aqui aparece no Portal do Parceiro.</p>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= $e($base . '/integracoes/ifood/minha-loja') ?>"><?= Icon::svg('refresh-cw', 'icon-sm') ?>Atualizar</a>
    </header>

    <?= $aviso($p['erros']['geral'] ?? null) ?>

    <section class="bloco" aria-labelledby="titulo-lojas">
        <div class="bloco-cabecalho">
            <h2 id="titulo-lojas"><?= Icon::svg('store') ?>Lojas vinculadas</h2>
            <p>Lojas do iFood que autorizaram o <?= marca_html() ?>.</p>
        </div>
        <?= $aviso($p['erros']['lojas'] ?? null) ?>
        <?php if ($p['lojas'] === []): ?>
            <p class="texto-suave">Nenhuma loja encontrada.</p>
        <?php else: ?>
            <ul class="ifood-lista">
                <?php foreach ($p['lojas'] as $l): ?>
                    <li class="ifood-lista-item">
                        <span><strong><?= $e((string) $l['nome']) ?></strong><small>Código no iFood: <?= $e((string) $l['id']) ?></small></span>
                        <?php if ($l['id'] === $p['loja']): ?><span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>Ligada a este restaurante</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="bloco" aria-labelledby="titulo-dados">
        <div class="bloco-cabecalho"><h2 id="titulo-dados"><?= Icon::svg('info') ?>Dados da loja</h2></div>
        <?= $aviso($p['erros']['detalhes'] ?? null) ?>
        <?php if ($det !== []): ?>
            <div class="resumo-assinatura">
                <div class="resumo-linha"><span>Nome</span><strong><?= $e((string) ($det['name'] ?? '-')) ?></strong></div>
                <div class="resumo-linha"><span>Razão social</span><strong><?= $e((string) ($det['corporateName'] ?? '-')) ?></strong></div>
                <div class="resumo-linha"><span>Código no iFood</span><strong class="chave-curta"><?= $e((string) ($det['id'] ?? $p['loja'])) ?></strong></div>
                <?php if (isset($det['type'])): ?><div class="resumo-linha"><span>Tipo</span><strong><?= $e(is_scalar($det['type']) ? (string) $det['type'] : '-') ?></strong></div><?php endif; ?>
                <?php if (isset($det['status'])): ?><div class="resumo-linha"><span>Situação do cadastro</span><strong><?= $e(is_scalar($det['status']) ? (string) $det['status'] : '-') ?></strong></div><?php endif; ?>
                <?php if (isset($det['averageTicket']) && is_numeric($det['averageTicket'])): ?><div class="resumo-linha"><span>Ticket médio</span><strong>R$ <?= $e(number_format((float) $det['averageTicket'], 2, ',', '.')) ?></strong></div><?php endif; ?>
                <?php if ($end !== []): ?>
                    <div class="resumo-linha"><span>Endereço</span><strong><?= $e(trim(implode(', ', array_filter([
                        (string) ($end['street'] ?? ''), (string) ($end['number'] ?? ''), (string) ($end['district'] ?? ''),
                        trim(($end['city'] ?? '') . (isset($end['state']) ? ' - ' . $end['state'] : '')), (string) ($end['postalCode'] ?? ''),
                    ], static fn (string $v): bool => trim($v) !== '')))) ?></strong></div>
                <?php endif; ?>
                <?php foreach (is_array($det['operations'] ?? null) ? $det['operations'] : [] as $op): if (!is_array($op)) { continue; } ?>
                    <div class="resumo-linha"><span>Operação <?= $e((string) ($op['name'] ?? '')) ?></span><strong><?= $e(implode(', ', array_map(
                        static fn ($c): string => is_array($c) ? (string) ($c['name'] ?? '') . (($c['enabled'] ?? true) ? '' : ' (desligado)') : '',
                        is_array($op['salesChannels'] ?? null) ? $op['salesChannels'] : []))) ?: '-' ?></strong></div>
                <?php endforeach; ?>
            </div>
            <details class="manual">
                <summary>Ver todos os dados que o iFood enviou</summary>
                <pre class="ifood-json"><?= $e((string) json_encode($det, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </details>
        <?php endif; ?>
    </section>

    <section class="bloco" aria-labelledby="titulo-situacao">
        <div class="bloco-cabecalho">
            <h2 id="titulo-situacao"><?= Icon::svg('activity') ?>Disponibilidade agora</h2>
            <p>Se a loja está recebendo pedidos no iFood em cada tipo de operação.</p>
        </div>
        <?= $aviso($p['erros']['situacao'] ?? null) ?>
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

    <section class="bloco" id="pausas" aria-labelledby="titulo-pausas" data-pausas>
        <?php $pausas = $p['pausas']; $erroLista = $p['erros']['pausas'] ?? null; $nota = null; require __DIR__ . '/_ifood-pausas.php'; ?>
    </section>

    <section class="bloco" id="horarios" aria-labelledby="titulo-horarios">
        <div class="bloco-cabecalho">
            <h2 id="titulo-horarios"><?= Icon::svg('clock') ?>Horário de funcionamento no iFood</h2>
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
</div>
