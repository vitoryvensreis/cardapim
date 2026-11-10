<?php
/** Assinatura. Espera: $vigente (com 'situacao') ou null, $planos, $historico, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = static fn (?string $v): string => $v ? AssinaturaService::data($v) : '-';
$periodo = static fn (string $p): string => AssinaturaService::ROTULO_PERIODO[$p] ?? '';
$situacoes = [
    'ativa' => ['etiqueta-ok', 'circle-check', 'Ativa'],
    'vencida' => ['etiqueta-atencao', 'triangle-exclamation-point', 'Vencida'],
    'cancelada' => ['etiqueta-neutra', 'ban', 'Cancelada'],
];
$statusHistorico = ['ativa' => 'Ativa', 'cancelada' => 'Cancelada', 'encerrada' => 'Encerrada'];
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Assinatura</h1>
            <p>Seu plano do Cardapim: veja o que está contratado, troque de plano ou cancele.</p>
        </div>
    </header>

    <div class="notice notice-info bloco-aviso" role="note">
        <?= Icon::svg('info') ?>
        <div>
            <strong>O pagamento ainda não é feito por aqui.</strong>
            <p>Quando você assina ou troca de plano, registramos a sua escolha e a equipe do Cardapim combina o pagamento com você.</p>
        </div>
    </div>

    <section class="bloco" aria-labelledby="titulo-plano">
        <div class="bloco-cabecalho"><h2 id="titulo-plano"><?= Icon::svg('crown') ?>Seu plano</h2></div>
        <?php if ($vigente === null): ?>
            <div class="lista-vazia">
                <?= Icon::svg('credit-card') ?>
                <strong>Você ainda não tem um plano ativo</strong>
                <p>Escolha um dos planos abaixo para continuar usando o Cardapim.</p>
            </div>
        <?php else:
            [$classe, $icone, $rotulo] = $situacoes[$vigente['situacao']];
        ?>
            <div class="plano-atual">
                <div class="plano-atual-topo">
                    <div>
                        <h3><?= $e((string) $vigente['plano_nome']) ?></h3>
                        <p class="preco"><?= $e(Numero::reais((string) $vigente['valor'])) ?> <small><?= $e($periodo((string) $vigente['periodo'])) ?></small></p>
                    </div>
                    <span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $e($rotulo) ?></span>
                </div>
                <div class="resumo-assinatura">
                    <div class="resumo-linha"><span>Começou em</span><strong><?= $e($data((string) $vigente['inicio_em'])) ?></strong></div>
                    <?php if ($vigente['situacao'] === 'cancelada'): ?>
                        <div class="resumo-linha"><span>Você pode usar até</span><strong><?= $e($data((string) $vigente['vence_em'])) ?></strong></div>
                        <div class="resumo-linha"><span>Cancelada em</span><strong><?= $e($data((string) $vigente['cancelada_em'])) ?></strong></div>
                    <?php elseif ($vigente['situacao'] === 'vencida'): ?>
                        <div class="resumo-linha"><span>Venceu em</span><strong><?= $e($data((string) $vigente['vence_em'])) ?></strong></div>
                    <?php else: ?>
                        <div class="resumo-linha"><span>Próxima renovação</span><strong><?= $e($data((string) $vigente['vence_em'])) ?></strong></div>
                    <?php endif; ?>
                </div>
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
                        <form method="post" action="<?= $e($base . '/assinatura/cancelar') ?>"
                              data-confirm="<?= $e('Você continua usando tudo até ' . $data((string) $vigente['vence_em']) . '. Depois disso, o acesso do restaurante é encerrado. Dá para desfazer até lá.') ?>"
                              data-confirm-titulo="Cancelar a assinatura?" data-confirm-acao="Sim, cancelar" data-confirm-perigo="1">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn btn-outline btn-sm link-perigo" type="submit"><?= Icon::svg('ban', 'icon-sm') ?>Cancelar assinatura</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="bloco" aria-labelledby="titulo-planos">
        <div class="bloco-cabecalho">
            <h2 id="titulo-planos"><?= Icon::svg('sparkles') ?><?= $vigente === null ? 'Escolha um plano' : 'Trocar de plano' ?></h2>
            <?php if ($vigente !== null): ?><p>Ao trocar, o novo plano começa hoje e o atual é encerrado.</p><?php endif; ?>
        </div>
        <?php if ($planos === []): ?>
            <div class="lista-vazia"><?= Icon::svg('life-buoy') ?><strong>Nenhum plano disponível agora</strong><p>Fale com o suporte do Cardapim.</p></div>
        <?php else: ?>
            <div class="planos-grade">
                <?php foreach ($planos as $plano):
                    $atual = $vigente !== null && (int) $vigente['plano_id'] === (int) $plano['id'];
                    $preco = Numero::reais((string) $plano['valor']) . ' ' . $periodo((string) $plano['periodo']);
                ?>
                    <article class="plano-opcao<?= $atual ? ' is-atual' : '' ?>">
                        <h3><?= $e((string) $plano['nome']) ?></h3>
                        <p class="preco"><?= $e(Numero::reais((string) $plano['valor'])) ?> <small><?= $e($periodo((string) $plano['periodo'])) ?></small></p>
                        <?php if (!empty($plano['descricao'])): ?><p><?= $e((string) $plano['descricao']) ?></p><?php endif; ?>
                        <?php if ($atual && $vigente['status'] === 'ativa'): ?>
                            <p class="plano-selo"><span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>Seu plano atual</span></p>
                        <?php else: ?>
                            <form method="post" action="<?= $e($base . '/assinatura/assinar') ?>"
                                  data-confirm="<?= $e($atual ? 'O cancelamento é desfeito e o plano continua ativo.' : ($vigente === null ? 'Plano ' . $plano['nome'] . ': ' . $preco . '.' : 'Seu plano muda hoje para ' . $plano['nome'] . ' (' . $preco . ').')) ?>"
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
                        <span><strong><?= $e((string) $h['plano_nome']) ?></strong> · <?= $e(Numero::reais((string) $h['valor']) . ' ' . $periodo((string) $h['periodo'])) ?></span>
                        <span class="texto-suave"><?= $e($data((string) $h['inicio_em'])) ?> a <?= $e($data((string) $h['vence_em'])) ?> · <?= $e($statusHistorico[$h['status']] ?? (string) $h['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
