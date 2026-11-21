<?php
/**
 * Tela "Sem conexao" do app instalavel (guardada pelo service worker). Estatica: sem dados de ninguem.
 * Espera: $logo (logo atual da marca, de Configuracoes), $icone, $marca.
 */
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#651424">
<meta name="robots" content="noindex, nofollow">
<title>Sem conexão · <?= $e($marca) ?></title>
<link rel="icon" href="<?= $e($icone) ?>">
<link rel="stylesheet" href="/assets/css/core.css">
<link rel="stylesheet" href="/assets/css/pages.css">
</head>
<body class="page-offline">
<main id="conteudo">
    <section class="error-stage" aria-labelledby="offline-titulo">
        <div class="error-card">
            <img class="offline-logo" src="<?= $e($logo) ?>" width="132" height="43" alt="<?= $e($marca) ?>" data-offline-logo>
            <p class="kicker">SEM CONEXÃO</p>
            <h1 id="offline-titulo">Você está sem internet.</h1>
            <p>O app precisa de internet para mostrar pedidos, mesas e o caixa atualizados. Confira o Wi-Fi ou os dados móveis e tente de novo.</p>
            <div class="error-actions">
                <a class="btn btn-wine" href=""><svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide.svg#refresh-cw"></use></svg>Tentar de novo</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>
