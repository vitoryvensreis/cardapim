<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Desktop\VinculoBalanca;
use Cardapim\Shared\Seguranca\Cripto;

Cripto::usarChave(str_repeat('D', 32));
$falhas = 0;
$ok = static function (bool $condicao, string $nome) use (&$falhas): void {
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: $nome\n");
        $falhas++;
    }
};

$agora = 1770000000;
$t = VinculoBalanca::gerarTicket(15, 98, $agora);
$ok(VinculoBalanca::verificarTicket($t, 15, $agora) === 98, 'link valido');
$ok(VinculoBalanca::verificarTicket($t, 16, $agora) === null, 'link de outro restaurante');
$ok(VinculoBalanca::verificarTicket($t, 15, $agora + 601) === null, 'link vencido (10 min)');
$ok(VinculoBalanca::verificarTicket($t . 'a', 15, $agora) === null, 'assinatura adulterada');
$ok(VinculoBalanca::verificarTicket('98', 15, $agora) === null, 'id puro nunca e ticket');
$cookie = VinculoBalanca::cookieAssinado(15, 98, $agora);
$ok(VinculoBalanca::verificarCookie($cookie, 15, $agora) === 98, 'cookie valido');
$ok(VinculoBalanca::verificarCookie($cookie, 16, $agora) === null, 'cookie de outro restaurante');
$ok(VinculoBalanca::verificarCookie($cookie, 15, $agora + 7776001) === null, 'cookie vencido');
$ok(VinculoBalanca::verificarCookie($cookie . 'a', 15, $agora) === null, 'cookie adulterado');
$ok(VinculoBalanca::verificarCookie('98', 15, $agora) === null, 'id sem assinatura rejeitado');
$ok(VinculoBalanca::verificarCookie(null, 15, $agora) === null, 'sem vinculo nao escolhe dispositivo');
Cripto::usarChave(null);

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: vínculo de balança do Agent, escopo e vencimentos.\n";
