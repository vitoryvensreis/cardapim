<?php

declare(strict_types=1);

// Servidor SMTP FALSO, somente para testes (CI/local): php tests/fakes/smtp-fake.php <porta> <arquivo-de-saida>
// Aceita AUTH LOGIN com usuario "ci@deliveryzim.test" e senha "senha-ci"; grava cada mensagem no arquivo. Sem TLS.
$porta = (int) ($argv[1] ?? 18025);
$saida = (string) ($argv[2] ?? sys_get_temp_dir() . '/smtp-fake.log');
$servidor = stream_socket_server('tcp://127.0.0.1:' . $porta, $errno, $errstr);
if ($servidor === false) {
    fwrite(STDERR, "Nao abriu a porta $porta: $errstr\n");
    exit(1);
}
while ($c = @stream_socket_accept($servidor, -1)) {
    $diz = static fn (string $l) => fwrite($c, $l . "\r\n");
    $diz('220 smtp-fake ESMTP');
    $etapa = '';
    $usuario = '';
    $de = '';
    $para = [];
    while (($linha = fgets($c, 4096)) !== false) {
        $linha = rtrim($linha, "\r\n");
        if ($etapa === 'user') {
            $usuario = base64_decode($linha);
            $etapa = 'pass';
            $diz('334 UGFzc3dvcmQ6');
            continue;
        }
        if ($etapa === 'pass') {
            $etapa = '';
            $diz($usuario === 'ci@deliveryzim.test' && base64_decode($linha) === 'senha-ci' ? '235 2.7.0 Authentication successful' : '535 5.7.8 Error: authentication failed');
            continue;
        }
        $cmd = strtoupper(substr($linha, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            fwrite($c, "250-smtp-fake\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n");
        } elseif ($linha === 'AUTH LOGIN') {
            $etapa = 'user';
            $diz('334 VXNlcm5hbWU6');
        } elseif ($cmd === 'MAIL') {
            $de = $linha;
            $diz('250 2.1.0 Ok');
        } elseif ($cmd === 'RCPT') {
            $para[] = $linha;
            $diz(str_contains($linha, 'recusado@') ? '550 5.1.1 Recipient rejected' : '250 2.1.5 Ok');
        } elseif ($cmd === 'DATA') {
            $diz('354 End data with <CR><LF>.<CR><LF>');
            $dados = '';
            while (($l = fgets($c, 4096)) !== false && rtrim($l, "\r\n") !== '.') {
                $dados .= $l;
            }
            file_put_contents($saida, json_encode(['usuario' => $usuario, 'de' => $de, 'para' => $para, 'dados' => $dados]) . "\n", FILE_APPEND);
            $diz('250 2.0.0 Ok: queued');
        } elseif ($cmd === 'QUIT') {
            $diz('221 2.0.0 Bye');
            break;
        } else {
            $diz('502 5.5.2 Error: command not recognized');
        }
    }
    fclose($c);
}
