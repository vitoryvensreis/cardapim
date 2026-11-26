<?php
/**
 * Detalhe de uma NFC-e. Espera: $d (GestaoNotas::detalhe), $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Fiscal\FotoFiscal;
use Cardapim\Modules\Fiscal\RateioFiscal;
use Cardapim\Services\Fiscal\FiscalEmissao;

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = $base . '/fiscal/notas';
$doc = $d['doc'];
$foto = $d['foto'];
$id = (int) $doc['id'];
$st = (string) $doc['status'];
$r = static fn (int $c): string => 'R$ ' . RateioFiscal::dinheiro($c);
$data = static fn (?string $v): string => $v ? date('d/m/Y H:i', (int) strtotime($v)) : '-';
$classe = match ($st) { 'autorizada' => 'etiqueta-ok', 'rejeitada', 'bloqueada' => 'etiqueta-alerta', 'cancelada' => 'etiqueta-neutra', default => 'etiqueta-atencao' };
$autorizadaHa = $doc['autorizada_em'] ? (time() - (int) strtotime((string) $doc['autorizada_em'])) / 60 : null;
$token = static function () use ($e, $csrf): void { ?><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><?php };
?>
<div class="painel-page notas-fiscais">
    <header class="painel-cabecalho">
        <div><a class="voltar-link" href="<?= $e($url) ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Notas emitidas</a>
            <h1><?= $doc['numero'] !== null ? 'Nota ' . $e($doc['numero']) . ' · série ' . $e($doc['serie']) : 'Nota ainda sem número' ?></h1>
            <p>Pedido #<?= $e($doc['pedido_numero']) ?> · <?= $e(FiscalEmissao::ORIGENS[$doc['origem']] ?? $doc['origem']) ?> · <?= $e($r(RateioFiscal::centavos((string) $doc['valor']))) ?><?= (int) $doc['ambiente'] !== 1 ? ' · teste, sem valor fiscal' : '' ?></p></div>
        <span class="etiqueta <?= $e($classe) ?>"><?= $e(FiscalEmissao::ROTULOS[$st] ?? $st) ?></span>
    </header>

    <?php if (in_array($st, ['rejeitada', 'bloqueada'], true)): ?>
        <div class="notice notice-warning" role="alert"><?= Icon::svg('triangle-exclamation-point') ?><div>
            <strong><?= $st === 'rejeitada' ? 'A SEFAZ recusou esta nota' : 'Falta acertar o cadastro fiscal' ?></strong>
            <p><?= $e((string) ($doc['motivo'] ?? 'Motivo não informado.')) ?></p>
            <p class="texto-suave"><?= $st === 'bloqueada' ? 'Corrija em Produtos fiscais ou nos dados da empresa. O sistema confere de novo sozinho a cada 30 minutos, ou clique em "Tentar de novo".' : 'Corrija o que a SEFAZ apontou e clique em "Tentar de novo". Se não for reenviar, inutilize o número na lista de notas.' ?></p>
            <form method="post" action="<?= $e($url . '/' . $id . '/reenviar') ?>"><?php $token(); ?><button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('refresh-cw', 'icon-sm') ?>Tentar de novo</button></form>
        </div></div>
    <?php elseif (in_array($st, ['pendente', 'enviando', 'incerta'], true)): ?>
        <div class="notice notice-info" role="status"><?= Icon::svg('clock') ?><div><strong>Nota em processamento</strong><p><?= $st === 'incerta' ? 'Aguardando a confirmação da SEFAZ. O sistema confere sozinho antes de reenviar, sem risco de nota duplicada.' : 'A nota está na fila e sai em instantes.' ?></p></div></div>
    <?php endif; ?>

    <?php if ($st === 'autorizada'): ?>
    <section class="bloco notas-acoes" aria-label="Ações da nota">
        <form method="post" action="<?= $e($url . '/' . $id . '/imprimir') ?>"><?php $token(); ?><button class="btn btn-primary" type="submit"><?= Icon::svg('printer', 'icon-sm') ?>Imprimir</button></form>
        <a class="btn btn-outline" href="<?= $e($url . '/' . $id . '/pdf') ?>" target="_blank" rel="noopener"><?= Icon::svg('file-text', 'icon-sm') ?>Ver PDF</a>
        <a class="btn btn-outline" href="<?= $e($url . '/' . $id . '/xml') ?>"><?= Icon::svg('download', 'icon-sm') ?>Baixar XML</a>
    </section>
    <?php elseif ($st === 'cancelada'): ?>
    <section class="bloco notas-acoes"><a class="btn btn-outline" href="<?= $e($url . '/' . $id . '/xml') ?>"><?= Icon::svg('download', 'icon-sm') ?>Baixar XML</a></section>
    <?php endif; ?>

    <section class="bloco">
        <div class="bloco-cabecalho"><h2><?= Icon::svg('receipt') ?>Itens da nota</h2></div>
        <?php if (($foto['itens'] ?? []) === []): ?>
            <p class="texto-suave">Os itens aparecem aqui depois que a nota é montada.</p>
        <?php else: ?>
            <ul class="notas-itens">
                <?php foreach ($foto['itens'] as $i): ?>
                    <li><span><strong><?= $e($i['nome']) ?></strong><small><?= $e(RateioFiscal::quantidade((int) $i['qtd'])) ?> <?= $e($i['ucom'] ?? 'UN') ?> · NCM <?= $e($i['ncm'] ?? '') ?> · CFOP <?= $e($i['cfop'] ?? '') ?></small></span><span><?= $e($r((int) $i['vprod'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <ul class="notas-totais">
                <?php foreach ($foto['pagamentos'] ?? [] as $p): ?>
                    <li><span><?= $e(FotoFiscal::TPAG_ROTULO[$p['tpag']] ?? 'Outros') ?></span><span><?= $e($r((int) $p['vpag'])) ?><?= (int) $p['troco'] > 0 ? ' (troco ' . $e($r((int) $p['troco'])) . ')' : '' ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="bloco">
        <div class="bloco-cabecalho"><h2><?= Icon::svg('info') ?>Dados da nota</h2></div>
        <dl class="notas-dados">
            <dt>Consumidor</dt><dd><?= $doc['cpf_cnpj'] ? $e($doc['cpf_cnpj']) : 'Não identificado' ?></dd>
            <dt>Chave de acesso</dt><dd class="notas-chave"><?= $doc['chave'] ? $e(trim(chunk_split((string) $doc['chave'], 4, ' '))) : '-' ?></dd>
            <dt>Protocolo</dt><dd><?= $e($doc['protocolo'] ?? '-') ?></dd>
            <dt>Criada</dt><dd><?= $e($data($doc['criado_em'])) ?></dd>
            <dt>Autorizada</dt><dd><?= $e($data($doc['autorizada_em'])) ?></dd>
            <?php if ($doc['cancelada_em']): ?><dt>Cancelada</dt><dd><?= $e($data($doc['cancelada_em'])) ?></dd><?php endif; ?>
            <dt>Tentativas de envio</dt><dd><?= $e($doc['tentativas']) ?></dd>
        </dl>
        <?php if ($doc['url_consulta']): ?><p class="texto-suave">Consulta pública: <?= $e($doc['url_consulta']) ?></p><?php endif; ?>
    </section>

    <?php if ($st === 'autorizada'): ?>
    <section class="bloco">
        <div class="bloco-cabecalho"><h2><?= Icon::svg('mail') ?>Enviar por e-mail</h2><p>O cliente recebe o PDF e o XML da nota.</p></div>
        <form method="post" action="<?= $e($url . '/' . $id . '/email') ?>" class="notas-email"><?php $token(); ?>
            <label for="nota-email">E-mail do cliente</label>
            <div class="notas-email-linha"><input id="nota-email" name="email" type="email" maxlength="160" autocomplete="off" required><button class="btn btn-outline" type="submit">Enviar</button></div>
        </form>
    </section>

    <details class="bloco notas-cancelar">
        <summary><?= Icon::svg('circle-x', 'icon-sm') ?>Cancelar esta nota</summary>
        <p class="texto-suave">Cancele só se a venda foi desfeita. Na NFC-e a SEFAZ costuma aceitar o cancelamento até 30 minutos depois da autorização<?= $autorizadaHa !== null ? ' (esta foi autorizada há ' . $e((int) $autorizadaHa) . ' min)' : '' ?>. O pedido e o caixa não mudam.</p>
        <form method="post" action="<?= $e($url . '/' . $id . '/cancelar') ?>" class="fiscal-form" data-confirm="A nota será cancelada na SEFAZ. Isso não pode ser desfeito." data-confirm-titulo="Cancelar a nota?" data-confirm-acao="Cancelar nota" data-confirm-perigo="1"><?php $token(); ?>
            <label for="nota-just">Motivo do cancelamento (15 a 255 letras)</label>
            <textarea id="nota-just" name="justificativa" minlength="15" maxlength="255" rows="2" required placeholder="Ex.: Cliente desistiu da compra antes de receber"></textarea>
            <button class="btn btn-outline" type="submit">Cancelar nota</button>
        </form>
    </details>
    <?php endif; ?>

    <?php if ($d['eventos'] !== []): ?>
    <section class="bloco">
        <div class="bloco-cabecalho"><h2><?= Icon::svg('clock') ?>Histórico</h2></div>
        <ul class="notas-itens">
            <?php foreach ($d['eventos'] as $ev): ?>
                <li><span><strong><?= $ev['tipo'] === 'cancelamento' ? 'Pedido de cancelamento' : 'Inutilização' ?></strong><small><?= $e($ev['justificativa']) ?></small></span><span><?= $e($data($ev['criado_em'])) ?><small><?= $e(trim(($ev['cod_status'] ? $ev['cod_status'] . ' - ' : '') . ($ev['motivo'] ?? ''))) ?></small></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</div>
