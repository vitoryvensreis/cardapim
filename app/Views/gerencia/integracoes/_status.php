<?php
/** Etiqueta de situacao de uma integracao. Espera: $statusIntegracao (desconectado|pendente|conectado|erro). */
use Cardapim\Core\Icon;

$mapa = [
    'conectado' => ['etiqueta-ok', 'circle-check', 'Conectado'],
    'pendente' => ['etiqueta-atencao', 'clock', 'Aguardando você'],
    'erro' => ['etiqueta-alerta', 'circle-exclamation-point', 'Precisa de atenção'],
    'desconectado' => ['etiqueta-neutra', 'unplug', 'Não conectado'],
];
[$classeStatus, $iconeStatus, $rotuloStatus] = $mapa[$statusIntegracao] ?? $mapa['desconectado'];
?>
<span class="etiqueta <?= $classeStatus ?>"><?= Icon::svg($iconeStatus) ?><?= $rotuloStatus ?></span>
