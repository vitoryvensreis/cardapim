<?php
/**
 * Layout imersivo do KDS. Não inclui painel.php, alerta-pedido.js, campainha,
 * faixa de pedidos ou formulário de ação: apenas leitura.
 */
use Cardapim\Services\Configuracoes;

$e = static fn (string $valor): string => htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$title = $e((string) $pageTitle);
$description = $e('Fila de preparo do restaurante.');
$favicon = Configuracoes::logoOuPadrao('icone');
$noIndex = true;
$styles = ['css/cozinha.css'];
$scripts = [...($tempoReal !== null ? ['js/tempo-real.js'] : []), 'js/cozinha.js'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php $pwa = true; require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-cozinha"
      data-cozinha-url="<?= $e($base . '/cozinha/conteudo') ?>"
      <?php if ($tempoReal !== null): ?>data-tempo-real="<?= $e($tempoReal['ticket']) ?>" data-tempo-real-canais="<?= $e($tempoReal['canais']) ?>"<?php endif; ?>>
    <a class="skip-link" href="#conteudo">Pular para os pedidos</a>
    <?= $content ?>
<?php require __DIR__ . '/../partials/consentimento.php'; ?>
</body>
</html>
