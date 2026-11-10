<?php

declare(strict_types=1);

/**
 * Cliente WebSocket de linha de comando, usado nos testes do tempo real (CI e local).
 *
 * php tests/realtime/cliente.php --url=ws://127.0.0.1:18095/ws --ticket=... --origem=https://cardapim.test
 *     [--canais=estoque,geral] [--desde=N] [--esperar=estoque.alterado] [--tempo=8] [--mensagem='{"acao":"ping"}']
 *
 * Imprime cada mensagem recebida (uma por linha). Codigos de saida:
 *   0 = recebeu o tipo esperado (ou, sem --esperar, conectou e assinou)
 *   3 = tempo esgotado   4 = servidor recusou (imprime "HTTP <status>")   5 = conexao fechada (imprime "FECHADO <codigo>")
 */
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Realtime\Quadro;

$opcoes = array_map(static fn ($v) => is_array($v) ? end($v) : $v, getopt('', ['url:', 'ticket:', 'origem:', 'canais::', 'desde::', 'esperar::', 'tempo::', 'mensagem::']));
$url = parse_url((string) ($opcoes['url'] ?? ''));
if (!is_array($url) || !isset($url['host'], $url['port'])) {
    fwrite(STDERR, "Informe --url=ws://host:porta/ws\n");
    exit(2);
}
$socket = @stream_socket_client('tcp://' . $url['host'] . ':' . $url['port'], $codigo, $erro, 3);
if ($socket === false) {
    echo "SEM CONEXAO\n";
    exit(4);
}
$chave = base64_encode(random_bytes(16));
$caminho = ($url['path'] ?? '/ws') . '?ticket=' . rawurlencode((string) ($opcoes['ticket'] ?? ''));
fwrite($socket, "GET $caminho HTTP/1.1\r\nHost: {$url['host']}:{$url['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
    . "Sec-WebSocket-Key: $chave\r\nSec-WebSocket-Version: 13\r\nOrigin: " . ($opcoes['origem'] ?? '') . "\r\n\r\n");

$limite = microtime(true) + (float) ($opcoes['tempo'] ?? 8);
$buffer = '';
stream_set_timeout($socket, 1);
while (!str_contains($buffer, "\r\n\r\n")) {
    $parte = fread($socket, 8192);
    if ($parte === false || ($parte === '' && feof($socket)) || microtime(true) > $limite) {
        echo "HTTP sem resposta\n";
        exit(4);
    }
    $buffer .= $parte;
}
[$cabecalho, $buffer] = explode("\r\n\r\n", $buffer, 2);
preg_match('~\AHTTP/1\.1 (\d{3})~', $cabecalho, $m);
if (($m[1] ?? '') !== '101' || !str_contains($cabecalho, 'Sec-WebSocket-Accept: ' . Quadro::chaveAceite($chave))) {
    echo 'HTTP ' . ($m[1] ?? '?') . "\n";
    exit(4);
}

$esperar = $opcoes['esperar'] ?? null;
$canais = array_values(array_filter(explode(',', (string) ($opcoes['canais'] ?? 'geral'))));
$desde = isset($opcoes['desde']) ? (int) $opcoes['desde'] : null;
stream_set_blocking($socket, false);

while (microtime(true) < $limite) {
    $lido = Quadro::ler($buffer, false, 1_048_576);
    $buffer = $lido['resto'];
    foreach ($lido['quadros'] as $q) {
        if ($q['opcode'] === Quadro::PING) {
            fwrite($socket, Quadro::montarCliente($q['dados'], Quadro::PONG));
            continue;
        }
        if ($q['opcode'] === Quadro::FECHAR) {
            echo 'FECHADO ' . (strlen($q['dados']) >= 2 ? unpack('n', $q['dados'])[1] : 1005) . "\n";
            exit(5);
        }
        echo $q['dados'], "\n";
        $msg = json_decode($q['dados'], true);
        $tipo = is_array($msg) ? ($msg['tipo'] ?? '') : '';
        if ($tipo === 'sistema.conectado') {
            fwrite($socket, Quadro::montarCliente(json_encode(['acao' => 'assinar', 'canais' => $canais, 'desde' => $desde])));
            if (isset($opcoes['mensagem'])) {
                fwrite($socket, Quadro::montarCliente((string) $opcoes['mensagem']));
            }
        }
        if (($esperar === null && $tipo === 'sistema.assinado') || ($esperar !== null && $tipo === $esperar)) {
            fwrite($socket, Quadro::montarCliente(pack('n', 1000), Quadro::FECHAR));
            exit(0);
        }
    }
    $ler = [$socket];
    $nada = null;
    if (@stream_select($ler, $nada, $nada, 0, 200_000) > 0) {
        $parte = fread($socket, 65536);
        if ($parte === '' && feof($socket)) {
            echo "FECHADO sem aviso\n";
            exit(5);
        }
        $buffer .= (string) $parte;
    }
}
echo "TEMPO ESGOTADO\n";
exit(3);
