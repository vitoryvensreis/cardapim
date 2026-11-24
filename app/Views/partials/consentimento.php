<?php
/**
 * Aviso de cookies (LGPD). Aparece ate o visitante escolher; depois fica escondido e pode ser reaberto
 * por qualquer botao com data-consentimento-abrir (rodape e pagina /cookies). Logica em js/consentimento.js.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Consentimento;

$jaEscolheu = Consentimento::escolha() !== null;
?>
<section class="consentimento" data-consentimento aria-labelledby="consentimento-titulo"<?= $jaEscolheu ? ' hidden' : '' ?>>
    <div class="consentimento-texto">
        <h2 id="consentimento-titulo"><?= Icon::svg('cookie') ?>Sua privacidade</h2>
        <p>Usamos cookies necessários para o site funcionar (login, sacola e segurança). Com a sua permissão, também carregamos fontes externas e ferramentas de análise e marketing (Google Analytics, Google Tag Manager e Meta Pixel) quando habilitadas no site. <a href="/cookies">Saiba mais</a></p>
    </div>
    <div class="consentimento-acoes">
        <button class="btn btn-ghost btn-sm" type="button" data-consentimento-escolha="necessarios">Só os necessários</button>
        <button class="btn btn-primary btn-sm" type="button" data-consentimento-escolha="todos">Aceitar todos</button>
    </div>
    <p class="consentimento-feito" data-consentimento-feito role="status" aria-live="polite"></p>
</section>
