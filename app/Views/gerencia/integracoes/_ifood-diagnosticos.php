<?php
/** Diagnósticos da loja iFood; variáveis definidas em ifood-loja.php. */
use Cardapim\Core\Icon;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
?>
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
            <?php if (($det['test'] ?? '') === 'TEST' && ($det['status'] ?? '') === 'DISABLED'): ?>
                <div class="notice notice-warning"><?= Icon::svg('info') ?><div><p>Este é um estabelecimento de teste com cadastro desabilitado. Isso não equivale ao status operacional: confira a seção Disponibilidade agora para saber se o iFood considera a loja aberta.</p></div></div>
            <?php endif; ?>
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

    <section class="bloco" aria-labelledby="titulo-diagnostico-merchant">
        <div class="bloco-cabecalho">
            <h2 id="titulo-diagnostico-merchant"><?= Icon::svg('activity') ?>Diagnóstico da comunicação com o iFood</h2>
            <p>Respostas reais da API Merchant para pausas, disponibilidade e horários. Não inclui tokens ou senhas. Somente os dados desta loja.</p>
        </div>
        <?php if ($diagnosticoMerchant === []): ?>
            <p class="texto-suave">Ainda não há registros. Crie ou remova uma pausa e clique em Atualizar no topo para ver as respostas.</p>
        <?php else: ?>
            <p class="texto-suave">Últimas <?= count($diagnosticoMerchant) ?> chamadas, da mais recente para a mais antiga. Atualize a página para ver novas consultas.</p>
            <details class="manual">
                <summary>Ver respostas e códigos HTTP do iFood</summary>
                <pre class="ifood-json"><?= $e((string) json_encode($diagnosticoMerchant, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) ?></pre>
            </details>
        <?php endif; ?>
    </section>

