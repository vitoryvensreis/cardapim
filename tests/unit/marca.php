<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/marca.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Configuracoes;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};
// Sem banco o nome cai no padrao (a tela nunca quebra por causa da marca).
$ok(marca() === Configuracoes::NOME_PADRAO, 'nome padrao sem banco');
$ok(marca_html() === htmlspecialchars(Configuracoes::NOME_PADRAO, ENT_QUOTES, 'UTF-8'), 'versao escapada');
$ok(!str_contains(\Cardapim\Shared\Seguranca\Totp::uri('ABC', 'a@b.c'), 'Cardapim'), 'autenticador com o nome da marca');
if ($falhas > 0) {
    exit(1);
}
echo "Marca: nome vem de configuracoes.nome_sistema com padrao seguro.\n";
