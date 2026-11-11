<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#651424">
    <title>Status da plataforma | <?= marca_html() ?></title>
    <?php if ($iconeUrl !== null): ?>
    <link rel="icon" href="<?= htmlspecialchars($iconeUrl, ENT_QUOTES, 'UTF-8') ?>" type="image/svg+xml">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= \Cardapim\Core\AssetUrl::asset('css/status.css') ?>">
</head>
<body>
<?= $content ?>
</body>
</html>
