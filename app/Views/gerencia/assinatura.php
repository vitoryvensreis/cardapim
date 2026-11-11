<?php
/**
 * Assinatura. Espera: $vigente (com 'situacao' e 'fatura_aberta') ou null, $faturas, $planos, $historico,
 * $bloqueado (restaurante inadimplente), $pendente (aguardando 1o pagamento), $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = static fn (?string $v): string => $v ? AssinaturaService::data($v) : '-';
$situacoes = [
    'aguardando' => ['etiqueta-atencao', 'hourglass', 'Aguardando pagamento'],
    'ativa' => ['etiqueta-ok', 'circle-check', 'Ativa'],
    'gratis' => ['etiqueta-ok', 'gift', 'Sem cobrança'],
    'atrasada' => ['etiqueta-alerta', 'triangle-exclamation-point', 'Pagamento atrasado'],
    'vencida' => ['etiqueta-atencao', 'triangle-exclamation-point', 'Vencida'],
    'cancelada' => ['etiqueta-neutra', 'ban', 'Cancelada'],
];
$statusFatura = [
    'pendente' => ['etiqueta-atencao', 'clock', 'Em aberto'],
    'vencida' => ['etiqueta-alerta', 'triangle-exclamation-point', 'Vencida'],
    'paga' => ['etiqueta-ok', 'circle-check', 'Paga'],
    'estornada' => ['etiqueta-neutra', 'rotate-ccw', 'Estornada'],
];
$statusHistorico = ['aguardando' => 'Aguardando pagamento', 'ativa' => 'Ativa', 'cancelada' => 'Cancelada', 'encerrada' => 'Encerrada'];
$aberta = $vigente['fatura_aberta'] ?? null;
$pago = $vigente !== null && (float) $vigente['valor'] > 0;
$semCobranca = $pago && $vigente['asaas_assinatura_id'] === null && $vigente['status'] !== 'cancelada';
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Assinatura</h1>
            <p>Seu plano do <?= marca_html() ?>, as faturas e o pagamento.</p>
        </div>
    </header>

    <?php if ($bloqueado): ?>
        <div class="notice notice-warning bloco-aviso" role="alert">
            <?= Icon::svg('lock') ?>
            <div>
                <strong>Acesso pausado por pagamento pendente.</strong>
                <p><?= $aberta !== null ? 'Pague a fatura em aberto. Assim que o pagamento for confirmado, tudo volta a funcionar sozinho.' : 'Escolha um plano abaixo para voltar a usar o sistema.' ?></p>
            </div>
        </div>
    <?php elseif ($pendente && $vigente !== null && $vigente['status'] === 'aguardando'): ?>
        <div class="notice notice-info bloco-aviso" role="note">
            <?= Icon::svg('info') ?>
            <div>
                <strong>Falta só o primeiro pagamento.</strong>
                <p>Já dá para montar o cardápio, a equipe e as mesas. O cardápio aparece para os clientes assim que o pagamento for confirmado.</p>
            </div>
        </div>
    <?php endif; ?>

    <section class="bloco" aria-labelledby="titulo-plano">
        <div class="bloco-cabecalho"><h2 id="titulo-plano"><?= Icon::svg('crown') ?>Seu plano</h2></div>
        <?php if ($vigente === null): ?>
            <div class="lista-vazia">
                <?= Icon::svg('credit-card') ?>
                <strong>Você ainda não tem um plano ativo</strong>
                <p>Escolha um dos planos abaixo para continuar usando o <?= marca_html() ?>.</p>
            </div>
        <?php else:
            [$classe, $icone, $rotulo] = $situacoes[$vigente['situacao']];
        ?>
            <div class="plano-atual">
                <div class="plano-atual-topo">
                    <div>
                        <h3><?= $e((string) $vigente['plano_nome']) ?></h3>
                        <p class="preco"><?php if ($pago): ?><?= $e(Numero::reais((string) $vigente['valor'])) ?> <small><?= $e(AssinaturaService::ROTULO_PERIODO[$vigente['periodo']] ?? '') ?></small><?php else: ?>Grátis <small><?= $vigente['periodo'] === 'vitalicio' ? 'para sempre' : '' ?></small><?php endif; ?></p>
                    </div>
                    <span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $e($rotulo) ?></span>
                </div>

                <?php if ($aberta !== null && $vigente['status'] !== 'cancelada'): ?>
                    <div class="fatura-destaque<?= $aberta['vencimento'] < date('Y-m-d') ? ' is-atrasada' : '' ?>">
                        <div>
                            <span><?= $aberta['vencimento'] < date('Y-m-d') ? 'Fatura vencida em' : 'Próxima fatura vence em' ?> <?= $e($data((string) $aberta['vencimento'])) ?></span>
                            <strong><?= $e(Numero::reais((string) $aberta['valor'])) ?></strong>
                        </div>
                        <?php if (!empty($aberta['link_fatura'])): ?>
                            <a class="btn btn-primary" href="<?= $e((string) $aberta['link_fatura']) ?>" target="_blank" rel="noopener"><?= Icon::svg('credit-card', 'icon-sm') ?>Pagar agora</a>
                        <?php endif; ?>
                    </div>
                    <p class="texto-suave fatura-ajuda"><?= Icon::svg('shield-check', 'icon-sm') ?>Abre a página segura do Asaas para pagar por Pix, boleto ou cartão. A confirmação chega aqui sozinha.</p>
                <?php endif; ?>

                <div class="resumo-assinatura">
                    <div class="resumo-linha"><span>Começou em</span><strong><?= $e($data((string) $vigente['inicio_em'])) ?></strong></div>
                    <?php if ($vigente['situacao'] === 'cancelada'): ?>
                        <div class="resumo-linha"><span>Você pode usar até</span><strong><?= $e($data($vigente['vence_em'] !== null ? (string) $vigente['vence_em'] : null)) ?></strong></div>
                        <div class="resumo-linha"><span>Cancelada em</span><strong><?= $e($data((string) $vigente['cancelada_em'])) ?></strong></div>
                    <?php elseif ($pago && $vigente['vence_em'] !== null): ?>
                        <div class="resumo-linha"><span><?= $vigente['situacao'] === 'vencida' ? 'Venceu em' : 'Pago até' ?></span><strong><?= $e($data((string) $vigente['vence_em'])) ?></strong></div>
                    <?php elseif (!$pago): ?>
                        <div class="resumo-linha"><span>Cobrança</span><strong>Nenhuma</strong></div>
                    <?php endif; ?>
                </div>

                <?php if ($semCobranca): ?>
                    <div class="notice notice-warning plano-aviso" role="status">
                        <?= Icon::svg('info') ?>
                        <div>
                            <strong>A fatura ainda não foi gerada.</strong>
                            <p><?= $e((string) ($vigente['cobranca_erro'] ?? 'Toque em "Gerar cobrança" para criar a fatura.')) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($vigente['situacao'] === 'vencida'): ?>
                    <p class="texto-alerta plano-aviso">O período pago terminou. Fale com o suporte para acertar o pagamento e manter o acesso.</p>
                <?php endif; ?>

                <div class="bloco-rodape">
                    <?php if ($vigente['status'] === 'cancelada'): ?>
                        <form method="post" action="<?= $e($base . '/assinatura/reativar') ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('rotate-ccw', 'icon-sm') ?>Desfazer cancelamento</button>
                        </form>
                    <?php else: ?>
                        <?php if ($pago): ?>
                            <form method="post" action="<?= $e($base . '/assinatura/cobranca') ?>">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('refresh-cw', 'icon-sm') ?><?= $semCobranca ? 'Gerar cobrança' : 'Atualizar faturas' ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($pago && $vigente['status'] === 'ativa'): ?>
                            <form method="post" action="<?= $e($base . '/assinatura/cancelar') ?>"
                                  data-confirm="<?= $e('Não haverá novas cobranças. Você continua usando tudo até ' . ($vigente['vence_em'] !== null ? $data((string) $vigente['vence_em']) : 'hoje') . '; depois, o acesso fica pausado até escolher um plano. Dá para desfazer até lá.') ?>"
                                  data-confirm-titulo="Cancelar a assinatura?" data-confirm-acao="Sim, cancelar" data-confirm-perigo="1">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="btn btn-outline btn-sm link-perigo" type="submit"><?= Icon::svg('ban', 'icon-sm') ?>Cancelar assinatura</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($faturas !== []): ?>
        <section class="bloco" aria-labelledby="titulo-faturas">
            <div class="bloco-cabecalho"><h2 id="titulo-faturas"><?= Icon::svg('receipt') ?>Faturas</h2></div>
            <ul class="lista lista-faturas">
                <?php foreach ($faturas as $f):
                    [$cf, $if, $rf] = $statusFatura[$f['status']] ?? $statusFatura['pendente'];
                    $emAberto = in_array($f['status'], ['pendente', 'vencida'], true); ?>
                    <li class="lista-item fatura-item">
                        <div class="lista-titulo">
                            <strong><?= $e(Numero::reais((string) $f['valor'])) ?></strong>
                            <small>Vencimento <?= $e($data((string) $f['vencimento'])) ?><?= $f['status'] === 'paga' && $f['pago_em'] ? ' · paga em ' . $e($data((string) $f['pago_em'])) : '' ?></small>
                        </div>
                        <span class="etiqueta <?= $cf ?>"><?= Icon::svg($if) ?><?= $e($rf) ?></span>
                        <?php if (!empty($f['link_fatura'])): ?>
                            <a class="btn <?= $emAberto ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="<?= $e((string) $f['link_fatura']) ?>" target="_blank" rel="noopener">
                                <?= Icon::svg($emAberto ? 'credit-card' : 'external-link', 'icon-sm') ?><?= $emAberto ? 'Pagar' : 'Ver' ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="bloco" aria-labelledby="titulo-planos">
        <div class="bloco-cabecalho">
            <h2 id="titulo-planos"><?= Icon::svg('sparkles') ?><?= $vigente === null ? 'Escolha um plano' : 'Trocar de plano' ?></h2>
            <?php if ($vigente !== null): ?><p>O período já pago continua valendo; o novo valor entra na próxima fatura.</p><?php endif; ?>
        </div>
        <?php if ($planos === []): ?>
            <div class="lista-vazia"><?= Icon::svg('life-buoy') ?><strong>Nenhum plano disponível agora</strong><p>Fale com o suporte do <?= marca_html() ?>.</p></div>
        <?php else: ?>
            <div class="planos-grade">
                <?php foreach ($planos as $plano):
                    $atual = $vigente !== null && (int) $vigente['plano_id'] === (int) $plano['id'];
                    $preco = AssinaturaService::preco((string) $plano['valor'], (string) $plano['periodo']);
                ?>
                    <article class="plano-opcao<?= $atual ? ' is-atual' : '' ?>">
                        <h3><?= $e((string) $plano['nome']) ?></h3>
                        <p class="preco"><?php if ((float) $plano['valor'] > 0): ?><?= $e(Numero::reais((string) $plano['valor'])) ?> <small><?= $e(AssinaturaService::ROTULO_PERIODO[$plano['periodo']] ?? '') ?></small><?php else: ?>Grátis<?php endif; ?></p>
                        <?php if (!empty($plano['descricao'])): ?><p><?= $e((string) $plano['descricao']) ?></p><?php endif; ?>
                        <?php if ($atual && $vigente['status'] !== 'cancelada'): ?>
                            <p class="plano-selo"><span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>Seu plano atual</span></p>
                        <?php else: ?>
                            <form method="post" action="<?= $e($base . '/assinatura/assinar') ?>"
                                  data-confirm="<?= $e($atual ? 'O cancelamento é desfeito e o plano continua ativo.' : ($vigente === null ? 'Plano ' . $plano['nome'] . ': ' . $preco . '.' : 'Seu plano muda para ' . $plano['nome'] . ' (' . $preco . ').')) ?>"
                                  data-confirm-titulo="<?= $e($atual ? 'Manter o plano ' . $plano['nome'] . '?' : ($vigente === null ? 'Assinar este plano?' : 'Trocar de plano?')) ?>"
                                  data-confirm-acao="<?= $atual ? 'Manter plano' : ($vigente === null ? 'Assinar' : 'Trocar plano') ?>">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="plano" value="<?= (int) $plano['id'] ?>">
                                <button class="btn <?= $vigente === null ? 'btn-primary' : 'btn-wine' ?> btn-sm btn-block" type="submit">
                                    <?= $atual ? 'Manter este plano' : ($vigente === null ? 'Assinar' : 'Trocar para este') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($historico !== []): ?>
        <section class="bloco" aria-labelledby="titulo-historico">
            <div class="bloco-cabecalho"><h2 id="titulo-historico"><?= Icon::svg('file-text') ?>Histórico</h2></div>
            <ul class="historico">
                <?php foreach ($historico as $h): ?>
                    <li>
                        <span><strong><?= $e((string) $h['plano_nome']) ?></strong> · <?= $e(AssinaturaService::preco((string) $h['valor'], (string) $h['periodo'])) ?></span>
                        <span class="texto-suave"><?= $e($data((string) $h['inicio_em'])) ?><?= $h['vence_em'] !== null ? ' a ' . $e($data((string) $h['vence_em'])) : '' ?> · <?= $e($statusHistorico[$h['status']] ?? (string) $h['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
