<?php
/**
 * Selo da situacao do chamado (texto + icone, nunca so cor). Espera: $chamadoSelo (array do chamado), $ladoSelo (restaurante|suporte).
 */
use Cardapim\Core\Icon;

$stSelo = (string) $chamadoSelo['status'];
if ($stSelo === 'resolvido') {
    [$clSelo, $icSelo, $txSelo] = ['etiqueta-ok', 'circle-check', 'Resolvido'];
} elseif ($chamadoSelo['ultima_de'] === 'suporte') {
    [$clSelo, $icSelo, $txSelo] = $ladoSelo === 'restaurante' ? ['etiqueta-marca', 'message-circle', 'Respondido'] : ['etiqueta-neutra', 'clock', 'Esperando o restaurante'];
} else {
    [$clSelo, $icSelo, $txSelo] = $ladoSelo === 'restaurante'
        ? ['etiqueta-atencao', 'clock', $stSelo === 'em_atendimento' ? 'Em atendimento' : 'Aguardando o suporte']
        : ['etiqueta-alerta', 'bell', 'Aguardando resposta'];
}
?>
<span class="etiqueta <?= $clSelo ?>"><?= Icon::svg($icSelo) ?><?= htmlspecialchars($txSelo, ENT_QUOTES, 'UTF-8') ?></span>
