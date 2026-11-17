<?php
/**
 * Contexto compartilhado entre as abas Status, Loja e Dados do iFood.
 * Espera: $painel (IfoodLojaService::painel), $erros ['pausa'|'horarios' => campo => msg], $valores, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;

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
