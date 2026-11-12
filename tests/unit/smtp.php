<?php

declare(strict_types=1);

// Teste do envio de e-mail por SMTP contra um servidor falso local: php tests/unit/smtp.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Email\SmtpCliente;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};

$porta = 18000 + random_int(100, 900);
$saida = tempnam(sys_get_temp_dir(), 'smtp');
$proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/fakes/smtp-fake.php', (string) $porta, $saida], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $porta) === false; $i++) {
    usleep(100000);
}

try {
    (new SmtpCliente('127.0.0.1', $porta, 'none', 'ci@deliveryzim.test', 'senha-ci'))
        ->enviar('no-reply@deliveryzim.test', 'Deliveryzim', 'gerente@exemplo.test', 'Chamado #12 respondido: ação', "Olá!\nSua solicitação foi respondida.", 'suporte@deliveryzim.test');
    $ok(true, 'envio com login');
} catch (Throwable $e) {
    $ok(false, 'envio com login: ' . $e->getMessage());
}
$linhas = array_values(array_filter(explode("\n", (string) file_get_contents($saida))));
$msg = json_decode($linhas[0] ?? '{}', true) ?: [];
$ok(($msg['usuario'] ?? '') === 'ci@deliveryzim.test', 'autenticou com o usuario');
$ok(str_contains((string) ($msg['de'] ?? ''), '<no-reply@deliveryzim.test>'), 'remetente');
$ok(str_contains(implode(' ', $msg['para'] ?? []), '<gerente@exemplo.test>'), 'destinatario');
$dados = (string) ($msg['dados'] ?? '');
$ok(str_contains($dados, 'Subject: ') && str_contains($dados, '=?UTF-8?B?'), 'assunto codificado (acentos)');
$ok(str_contains($dados, 'Reply-To: <suporte@deliveryzim.test>'), 'responder para');
$corpo = substr($dados, (int) strpos($dados, "\r\n\r\n") + 4);
$ok(str_contains(base64_decode(str_replace(["\r", "\n"], '', $corpo)), 'Sua solicitação foi respondida.'), 'corpo em UTF-8 base64');

try {
    (new SmtpCliente('127.0.0.1', $porta, 'none', 'ci@deliveryzim.test', 'errada'))->enviar('no-reply@deliveryzim.test', 'X', 'a@exemplo.test', 'x', 'x');
    $ok(false, 'senha errada recusada');
} catch (RuntimeException $e) {
    $ok(str_contains($e->getMessage(), 'recusou o usuário ou a senha') && !str_contains($e->getMessage(), 'errada'), 'senha errada recusada sem mostrar a senha');
}
try {
    (new SmtpCliente('127.0.0.1', $porta, 'none', 'ci@deliveryzim.test', 'senha-ci'))->enviar('no-reply@deliveryzim.test', 'X', 'recusado@exemplo.test', 'x', 'x');
    $ok(false, 'destinatario recusado');
} catch (RuntimeException $e) {
    $ok(str_contains($e->getMessage(), 'recusou o destinatário'), 'destinatario recusado');
}
try {
    (new SmtpCliente('127.0.0.1', 1, 'none', '', '', 2))->enviar('no-reply@deliveryzim.test', 'X', 'a@exemplo.test', 'x', 'x');
    $ok(false, 'servidor fora do ar');
} catch (RuntimeException $e) {
    $ok(str_contains($e->getMessage(), 'Não foi possível conectar'), 'servidor fora do ar');
}
try {
    SmtpCliente::mensagem("no-reply@deliveryzim.test\r\nBcc: x@y.z", 'X', 'a@exemplo.test', 'x', 'x');
    $ok(false, 'cabecalho injetado recusado');
} catch (RuntimeException) {
    $ok(true, 'cabecalho injetado recusado');
}

proc_terminate($proc);
proc_close($proc);
@unlink($saida);
if ($falhas > 0) {
    exit(1);
}
echo "OK: SMTP (login, UTF-8, recusas e protecao de cabecalho).\n";
