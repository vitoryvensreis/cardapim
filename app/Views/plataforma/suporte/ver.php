<?php
/** Chamado na plataforma: conversa, responder (e resolver) e situacao. Espera: $chamado, $mensagens, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Services\Plataforma\RestauranteAdminService;
use Cardapim\Services\Suporte\SuporteService;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$c = $chamado;
$url = '/painel/suporte/' . (int) $c['id'];
$resolvido = $c['status'] === 'resolvido';
?>
<div class="painel-page suporte-pagina">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="/painel/suporte"><?= Icon::svg('arrow-left', 'icon-sm') ?>Suporte</a>
            <h1>#<?= (int) $c['id'] ?> <?= $e((string) $c['assunto']) ?></h1>
            <p class="suporte-meta">
                <?php $chamadoSelo = $c; $ladoSelo = 'suporte'; require __DIR__ . '/../../suporte/_selo.php'; ?>
                <span><?= $e(SuporteService::CATEGORIAS[$c['categoria']] ?? '') ?> · aberto em <?= $e(date('d/m/Y H:i', (int) strtotime((string) $c['criado_em']))) ?></span>
            </p>
        </div>
    </header>

    <div class="suporte-colunas">
        <div>
            <section class="bloco suporte-bloco" aria-label="Conversa">
                <?php $lado = 'suporte'; require __DIR__ . '/../../suporte/_conversa.php'; ?>
            </section>

            <section class="bloco" aria-labelledby="titulo-responder" id="fim">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-responder"><?= Icon::svg('message-circle') ?>Responder ao restaurante</h2>
                    <p>O gerente vê a resposta no painel e recebe um aviso por e-mail.</p>
                </div>
                <form method="post" action="<?= $e($url . '/responder') ?>" class="suporte-responder" novalidate data-validate-form>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <label class="sr-only" for="campo-mensagem">Resposta</label>
                    <textarea id="campo-mensagem" name="mensagem" rows="5" maxlength="4000" required placeholder="Escreva a resposta"></textarea>
                    <?php if (!$resolvido): ?>
                        <label class="suporte-check"><input type="checkbox" name="resolver" value="1"> Marcar como resolvido ao enviar</label>
                    <?php endif; ?>
                    <div class="bloco-rodape">
                        <button class="btn btn-primary" type="submit" data-submit-label="Enviando..."><?= Icon::svg('send', 'icon-sm') ?>Enviar resposta</button>
                    </div>
                </form>
            </section>
        </div>

        <aside class="bloco suporte-lateral" aria-labelledby="titulo-dados-chamado">
            <h2 id="titulo-dados-chamado" class="sr-only">Dados do chamado</h2>
            <div class="resumo-assinatura">
                <div class="resumo-linha"><span>Restaurante</span><strong><a href="/painel/restaurantes/<?= (int) $c['id_restaurante'] ?>"><?= $e((string) $c['restaurante_nome']) ?></a></strong></div>
                <div class="resumo-linha"><span>Situação do restaurante</span><strong><?= $e(RestauranteAdminService::rotuloStatus((string) $c['status_rest'])) ?></strong></div>
                <div class="resumo-linha"><span>Aberto por</span><strong><?= $c['autor'] ? $e((string) $c['autor']) : '—' ?></strong></div>
                <?php if (!empty($c['autor_email']) && !str_ends_with((string) $c['autor_email'], '.invalid')): ?>
                    <div class="resumo-linha"><span>E-mail</span><strong><?= $e((string) $c['autor_email']) ?></strong></div>
                <?php endif; ?>
            </div>
            <h3 class="suporte-lateral-titulo">Situação do chamado</h3>
            <div class="suporte-status">
                <?php foreach (SuporteService::STATUS as $st => $rotulo): if ($st === $c['status']) { continue; } ?>
                    <form method="post" action="<?= $e($url . '/status') ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="status" value="<?= $e($st) ?>">
                        <button class="btn btn-outline btn-sm btn-block" type="submit"><?= Icon::svg($st === 'resolvido' ? 'circle-check' : ($st === 'aberto' ? 'rotate-ccw' : 'clock'), 'icon-sm') ?><?= $st === 'aberto' ? 'Reabrir' : ($st === 'resolvido' ? 'Marcar como resolvido' : 'Marcar em atendimento') ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</div>
