<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/navegador.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Shared\Navegador;

$casos = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36' => 'Chrome no Windows',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36 Edg/131.0' => 'Edge no Windows',
    'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0 Mobile Safari/537.36' => 'Samsung Internet no Android',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' => 'Safari no iPhone',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:132.0) Gecko/20100101 Firefox/132.0' => 'Firefox no Mac',
    '' => 'Navegador desconhecido',
];
$falhas = 0;
foreach ($casos as $ua => $esperado) {
    $obtido = Navegador::resumo($ua);
    if ($obtido !== $esperado) {
        $falhas++;
        fwrite(STDERR, "FALHOU: esperado '$esperado', obtido '$obtido'\n");
    }
}
if ($falhas > 0) {
    exit(1);
}
echo "Navegador: resumo de navegador e sistema validado.\n";
