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
// Imagens enviadas: endereco publico sem extensao (o Nginx do CloudPanel intercepta .svg/.png e responde 404).
$h = str_repeat('a', 32);
$ok(\Cardapim\Shared\MidiaUrl::publica("/midia/marca/$h.svg") === "/midia/marca/$h-svg", 'marca sem extensao');
$ok(\Cardapim\Shared\MidiaUrl::publica("/midia/marca/$h-512.png") === "/midia/marca/$h-512-png", 'icone 512 sem extensao');
$ok(\Cardapim\Shared\MidiaUrl::armazenada("/midia/logos/$h-webp") === "/midia/logos/$h.webp", 'volta ao nome do arquivo');
$ok(\Cardapim\Shared\MidiaUrl::publica('/assets/img/logo-preta.svg') === '/assets/img/logo-preta.svg', 'arquivo do repositorio nao muda');
$ok(\Cardapim\Modules\Marca\MarcaUpload::arquivoDe("/midia/marca/$h-svg") !== null && \Cardapim\Modules\Marca\MarcaUpload::arquivoDe("/midia/marca/$h.svg") !== null, 'arquivo achado nos dois formatos');
$ok(\Cardapim\Modules\Marca\MarcaUpload::arquivoDe('/midia/marca/../.env-svg') === null, 'caminho estranho recusado');
$ok(\Cardapim\Shared\RestauranteLogo::urlSegura("/midia/logos/$h.webp") === "/midia/logos/$h-webp", 'logo do restaurante sem extensao na tela');
if ($falhas > 0) {
    exit(1);
}
echo "Marca: nome vem de configuracoes.nome_sistema com padrao seguro.\n";
