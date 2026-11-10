<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\AssetUrl;
use Cardapim\Core\Database;
use Cardapim\Services\Configuracoes;

$db = Database::connect();

$tables = $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'configuracoes'"
)->fetchColumn();
if ((int) $tables !== 1) {
    throw new RuntimeException('Tabela configuracoes nao criada.');
}

$keys = $db->query("SELECT COUNT(*) FROM configuracoes")->fetchColumn();
if ((int) $keys < 7) {
    throw new RuntimeException('Configuracoes iniciais incompletas.');
}

foreach ([
    'logo_colorida' => '/assets/img/logo-colorida.svg',
    'logo_branca' => '/assets/img/logo-branca.svg',
    'logo_preta' => '/assets/img/logo-preta.svg',
    'icone' => '/assets/img/icone.svg',
] as $key => $expectedPath) {
    if (Configuracoes::logo($key) !== $expectedPath) {
        throw new RuntimeException("Logo $key nao foi carregada do banco.");
    }
}
if (Configuracoes::get('texto_rodape') !== 'Menos complicação. Mais restaurante.') {
    throw new RuntimeException('Texto do rodape nao carregado.');
}
if (AssetUrl::asset('css/site.css') !== '/assets/css/site.css'
    || AssetUrl::asset('js/site.js') !== '/assets/js/site.js'
    || AssetUrl::asset('img/icone.svg') !== '/assets/img/icone.svg') {
    throw new RuntimeException('URL relativa de asset inesperada.');
}

putenv('APP_URL=https://cardapim.example.test');
if (AssetUrl::absolute('css/site.css') !== 'https://cardapim.example.test/assets/css/site.css') {
    throw new RuntimeException('APP_URL nao foi respeitada.');
}

try {
    AssetUrl::asset('../.env');
    throw new RuntimeException('Path traversal nao foi bloqueado.');
} catch (InvalidArgumentException) {
    // Esperado.
}

$check = $db->prepare(<<<'SQL'
SELECT r.nome, r.plano_id, r.status_rest, r.ultimo_pagamento, p.periodo, p.nome AS plano_nome
FROM restaurantes r
JOIN planos p ON p.id = r.plano_id
WHERE r.slug = ?
SQL);
$check->execute(['restaurante-modelo-cardapim']);
$row = $check->fetch();
if (!$row || $row['nome'] !== 'Restaurante Modelo Cardapim'
    || $row['plano_nome'] !== 'Demonstração Mensal' || $row['periodo'] !== 'mensal'
    || $row['status_rest'] !== 'ativo' || $row['ultimo_pagamento'] !== null) {
    throw new RuntimeException('Restaurante modelo sem vinculo mensal correto.');
}

echo "Configuracoes, URLs de assets e restaurante modelo validados.\n";
