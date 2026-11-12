<?php
/** Chamado do restaurante: conversa, responder e marcar como resolvido. Espera: $chamado, $mensagens, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Services\Suporte\SuporteService;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$c = $chamado;
$resolvido = $c['status'] === 'resolvido';
$url = $base . '/suporte/' . (int) $c['id'];
?>
<div class="painel-page suporte-pagina">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/suporte') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Suporte</a>
            <h1>#<?= (int) $c['id'] ?> <?= $e((string) $c['assunto']) ?></h1>
            <p class="suporte-meta">
                <?php $chamadoSelo = $c; $ladoSelo = 'restaurante'; require __DIR__ . '/../../suporte/_selo.php'; ?>
                <span><?= $e(SuporteService::CATEGORIAS[$c['categoria']] ?? '') ?> · aberto em <?= $e(date('d/m/Y H:i', (int) strtotime((string) $c['criado_em']))) ?><?= $c['autor'] ? ' por ' . $e((string) $c['autor']) : '' ?></span>
            </p>
        </div>
    </header>

    <section class="bloco suporte-bloco" aria-label="Conversa">
        <?php $lado = 'restaurante'; require __DIR__ . '/../../suporte/_conversa.php'; ?>
        <?php if (!$resolvido && $c['ultima_de'] === 'restaurante'): ?>
            <p class="suporte-espera" id="fim"><?= Icon::svg('clock', 'icon-sm') ?>A nossa equipe vai responder aqui. Você recebe um aviso por e-mail.</p>
        <?php endif; ?>
    </section>

    <section class="bloco" aria-labelledby="titulo-responder" id="<?= !$resolvido && $c['ultima_de'] === 'restaurante' ? 'responder' : 'fim' ?>">
        <div class="bloco-cabecalho">
            <h2 id="titulo-responder"><?= Icon::svg('message-circle') ?><?= $resolvido ? 'Precisa de mais ajuda?' : 'Responder' ?></h2>
            <?php if ($resolvido): ?><p>Este chamado está resolvido. Se escrever de novo, ele é reaberto.</p><?php endif; ?>
        </div>
        <form method="post" action="<?= $e($url . '/responder') ?>" class="suporte-responder" novalidate data-validate-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <label class="sr-only" for="campo-mensagem">Sua mensagem</label>
            <textarea id="campo-mensagem" name="mensagem" rows="4" maxlength="4000" required placeholder="Escreva a sua mensagem"></textarea>
            <div class="bloco-rodape">
                <button class="btn btn-primary" type="submit" data-submit-label="Enviando..."><?= Icon::svg('send', 'icon-sm') ?><?= $resolvido ? 'Reabrir e enviar' : 'Enviar' ?></button>
            </div>
        </form>
    </section>

    <?php if (!$resolvido): ?>
        <form class="suporte-resolver" method="post" action="<?= $e($url . '/resolver') ?>"
              data-confirm="Se ainda precisar, é só responder no chamado de novo." data-confirm-titulo="O problema foi resolvido?" data-confirm-acao="Sim, resolvido">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('circle-check', 'icon-sm') ?>Meu problema foi resolvido</button>
        </form>
    <?php endif; ?>
</div>
