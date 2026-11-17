<?php
/**
 * Operacao iFood (Merchant), incorporada na pagina unica de integracao.
 * Espera: $painel (IfoodLojaService::painel), $erros ['pausa'|'horarios' => campo => msg], $valores, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
use Cardapim\Services\Ifood\FusoLojaIfood;

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
$fusoLoja = $p['fuso'];
$vP = $valores['pausa'] ?? IfoodLojaService::novaPausa($fusoLoja);
$form = $valores['horarios'] ?? $p['sugestao'];
$proprio = (bool) ($valores['horario_proprio'] ?? $p['horario_proprio']);
$perfil = $p['perfil'];
$aviso = static function (?string $msg) use ($e): string {
    return $msg ? '<div class="notice notice-warning">' . Icon::svg('circle-exclamation-point') . '<div><p>' . $e($msg) . '</p></div></div>' : '';
};
$campoErro = static fn (array $lista, string $campo): string => isset($lista[$campo]) ? '<p class="field-msg ficha-erro">' . $e($lista[$campo]) . '</p>' : '';
?>

    <?= $aviso($p['erros']['geral'] ?? null) ?>
    <div class="ifood-painel-operacao" id="operacao-ifood">
    <section class="bloco" aria-labelledby="titulo-situacao">
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

    <section class="bloco" id="pausas" aria-labelledby="titulo-pausas" data-pausas>
        <?php $pausas = $p['pausas']; $erroLista = $p['erros']['pausas'] ?? null; $nota = null; require __DIR__ . '/_ifood-pausas.php'; ?>
    </section>


    </div>
