<?php

declare(strict_types=1);

// Teste sem banco e sem rede do tempo real: php tests/unit/tempo-real.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Realtime\Canais;
use Cardapim\Realtime\Quadro;
use Cardapim\Realtime\TempoReal;
use Cardapim\Realtime\Ticket;
use Cardapim\Shared\Seguranca\Cripto;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};

Cripto::usarChave(str_repeat("\x07", 32));

// ---------- Ticket
$agora = 1_800_000_000;
$t = Ticket::emitir(15, 3, 'gerente', $agora);
$v = Ticket::validar($t, $agora + 10);
$ok($v !== null && $v['u'] === 15 && $v['r'] === 3 && $v['c'] === 'gerente' && $v['exp'] === $agora + 60, 'ticket valido e lido');
$ok(Ticket::validar($t, $agora + 61) === null, 'ticket vencido recusado');
[$corpo, $assinatura] = explode('.', $t);
$adulterado = rtrim(strtr(base64_encode(str_replace('"r":3', '"r":4', base64_decode(strtr($corpo, '-_', '+/')))), '+/', '-_'), '=');
$ok(Ticket::validar($adulterado . '.' . $assinatura, $agora) === null, 'ticket com restaurante trocado recusado');
$ok(Ticket::validar($corpo . '.' . strrev($assinatura), $agora) === null, 'assinatura invalida recusada');
$ok(Ticket::validar('lixo', $agora) === null && Ticket::validar('', $agora) === null && Ticket::validar(str_repeat('a', 500), $agora) === null, 'formatos invalidos');
$ok(Ticket::emitir(15, 3, 'gerente', $agora) !== $t, 'cada ticket tem um nonce diferente');
Cripto::usarChave(str_repeat("\x08", 32));
$ok(Ticket::validar($t, $agora) === null, 'outra chave do servidor recusa o ticket');
Cripto::usarChave(str_repeat("\x07", 32));
$ok(Cripto::derivarChave('a') !== Cripto::derivarChave('b') && strlen(Cripto::derivarChave('a')) === 32, 'chaves derivadas por proposito');

// ---------- Canais
$ok(Canais::permitidos('gerente') === Canais::TODOS, 'gerente ouve tudo');
$ok(Canais::permitidos('cliente') === [] && Canais::permitidos('qualquer') === [], 'cliente e cargo desconhecido nao ouvem nada');
$ok(!in_array('financeiro', Canais::permitidos('caixa'), true) && in_array('pedidos', Canais::permitidos('caixa'), true), 'caixa sem financeiro');
$ok(Canais::permitidos('motoqueiro') === ['geral', 'entregas'], 'entregador so geral e entregas');
$ok(Canais::filtrar('garcom', ['pedidos', 'financeiro', 'pedidos', 5, 'inexistente', 'mesas']) === ['pedidos', 'mesas'], 'filtrar remove o que nao pode, repetidos e lixo');
$ok(Canais::daSecao('estoque') === ['estoque'] && Canais::daSecao(null) === [] && Canais::daSecao('perfil') === [], 'canais por secao');
foreach (['caixa', 'garcom', 'motoqueiro'] as $cargo) {
    $ok(array_diff(Canais::permitidos($cargo), Canais::TODOS) === [], "canais de $cargo existem");
}

// ---------- Protocolo WebSocket (RFC 6455)
$ok(Quadro::chaveAceite('dGhlIHNhbXBsZSBub25jZQ==') === 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=', 'chave de aceite do exemplo da RFC');
$ok(Quadro::chaveClienteValida('dGhlIHNhbXBsZSBub25jZQ==') && !Quadro::chaveClienteValida('curta') && !Quadro::chaveClienteValida(''), 'chave do cliente');
foreach ([0, 5, 125, 126, 1000, 65535, 65536, 70000] as $tamanho) {
    $dados = str_repeat('x', $tamanho);
    $lido = Quadro::ler(Quadro::montarCliente($dados), true, 100000);
    $ok($lido['erro'] === null && count($lido['quadros']) === 1 && $lido['quadros'][0]['dados'] === $dados && $lido['resto'] === '', "quadro do cliente com $tamanho bytes");
    $servidor = Quadro::ler(Quadro::montar($dados), false, 100000);
    $ok($servidor['erro'] === null && $servidor['quadros'][0]['dados'] === $dados, "quadro do servidor com $tamanho bytes");
}
$dois = Quadro::montarCliente('{"a":1}') . Quadro::montarCliente('ola');
$ok(count(Quadro::ler($dois, true, 1000)['quadros']) === 2, 'dois quadros juntos');
$primeiro = strlen(Quadro::montarCliente('{"a":1}'));
$parcial = substr($dois, 0, $primeiro + 3);
$lido = Quadro::ler($parcial, true, 1000);
$ok(count($lido['quadros']) === 1 && $lido['resto'] === substr($parcial, $primeiro), 'quadro completo lido e o incompleto fica para depois');
$metade = substr(Quadro::montarCliente('abcdef'), 0, 5);
$lido = Quadro::ler($metade, true, 1000);
$ok($lido['quadros'] === [] && $lido['resto'] === $metade && $lido['erro'] === null, 'pedaco de quadro aguarda o resto');
$ok(Quadro::ler(Quadro::montar('sem mascara'), true, 1000)['erro'] === 1002, 'cliente sem mascara e recusado');
$ok(Quadro::ler(Quadro::montarCliente(str_repeat('y', 5000)), true, 4096)['erro'] === 1009, 'mensagem grande demais');
$ok(Quadro::ler(Quadro::montarCliente(str_repeat('p', 126), Quadro::PING), true, 1000)['erro'] === 1002, 'ping grande demais e erro de protocolo');
$frag = Quadro::ler(Quadro::montarCliente('ab', Quadro::TEXTO, false) . Quadro::montarCliente('cd', Quadro::CONTINUACAO), true, 1000);
$ok(count($frag['quadros']) === 2 && !$frag['quadros'][0]['fin'] && $frag['quadros'][1]['fin'] && $frag['quadros'][1]['opcode'] === 0, 'fragmentos lidos em ordem');
$fechar = Quadro::ler(Quadro::fechar(1012, 'reiniciando'), false, 1000)['quadros'][0];
$ok($fechar['opcode'] === Quadro::FECHAR && unpack('n', substr($fechar['dados'], 0, 2))[1] === 1012, 'quadro de fechamento com codigo');
$ok(Quadro::ler(chr(0xC1) . chr(0x80) . 'mask', true, 1000)['erro'] === 1002, 'bits reservados recusados');

// ---------- Publicacao (validacao, sem banco)
$erroDe = static function (callable $f): ?string {
    try {
        $f();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return null;
};
$pdo = new PDO('sqlite::memory:');
$ok($erroDe(fn () => TempoReal::registrar($pdo, 1, 'inexistente', 'estoque.alterado')) !== null, 'canal desconhecido e recusado');
$ok($erroDe(fn () => TempoReal::registrar($pdo, 1, 'estoque', 'Estoque Alterado')) !== null, 'tipo fora do padrao e recusado');
$ok($erroDe(fn () => TempoReal::registrar($pdo, 1, 'estoque', 'estoque.alterado', ['x' => str_repeat('a', 9000)])) !== null, 'dados grandes demais');
$pdo->exec('CREATE TABLE outbox_eventos (id INTEGER PRIMARY KEY AUTOINCREMENT, id_restaurante INT, canal TEXT, tipo TEXT, dados TEXT, criado_em TEXT, publicado_em TEXT)');
putenv('REDIS_PORT=');
$id = TempoReal::registrar($pdo, 7, 'estoque', 'estoque.alterado', ['area' => 'insumo', 'acao' => 'reposto', 'nome' => 'Pão']);
$linha = $pdo->query('SELECT * FROM outbox_eventos')->fetch(PDO::FETCH_ASSOC);
$ok($id === 1 && (int) $linha['id_restaurante'] === 7 && $linha['dados'] === '{"area":"insumo","acao":"reposto","nome":"Pão"}', 'evento gravado na outbox');

// ---------- Endereco publico
putenv('WS_URL=');
putenv('APP_URL=https://cardapim.exemplo.com.br');
$ok(TempoReal::urlPublica() === 'wss://cardapim.exemplo.com.br/ws', 'url derivada do APP_URL');
putenv('WS_URL=ws://127.0.0.1:18095/ws');
$ok(TempoReal::urlPublica() === 'ws://127.0.0.1:18095/ws', 'url configurada');
putenv('WS_URL=javascript:alert(1)');
$ok(TempoReal::urlPublica() === 'wss://cardapim.exemplo.com.br/ws', 'url invalida ignorada');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s) no tempo real.\n");
    exit(1);
}
echo "Tempo real: testes unitarios OK.\n";
