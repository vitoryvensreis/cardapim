<?php

declare(strict_types=1);

// Regressao: interrupcao cadastrada nao equivale a fechamento confirmado pelo Merchant.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Ifood\EstadoLoja;

$erros = 0;
$testar = static function (bool $condicao, string $descricao) use (&$erros): void {
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: $descricao\n");
        $erros++;
    }
};

$agora = time();
$pausas = [[
    'id' => 'pausa-ci',
    'inicio' => gmdate('Y-m-d\TH:i:s', $agora - 120),
    'fim' => gmdate('Y-m-d\TH:i:s', $agora + 3600),
]];
$aberta = [['estado' => 'OK', 'disponivel' => true, 'mensagem' => 'Loja aberta']];
$fechada = [['estado' => 'ERROR', 'disponivel' => false, 'mensagem' => 'Loja indisponivel']];

$divergente = EstadoLoja::resumoOperacional($aberta, $pausas, $agora);
$testar($divergente['fechado'] === false, 'status OK prevalece sobre pausa vigente para estado externo');
$testar($divergente['pausada'] === true && $divergente['divergente'] === true, 'interrupcao vigente e divergencia sinalizadas');
$testar($divergente['motivo'] === '', 'nao inventar motivo de fechamento externo');

$confirmado = EstadoLoja::resumoOperacional($fechada, $pausas, $agora);
$testar($confirmado['fechado'] === true && $confirmado['divergente'] === false, 'status indisponivel realmente fecha');
$testar($confirmado['motivo'] === 'Loja indisponivel', 'motivo vem do status');

$limpa = EstadoLoja::resumoOperacional($aberta, [], $agora);
$testar($limpa['fechado'] === false && $limpa['pausada'] === false && $limpa['divergente'] === false, 'sem pausa e status OK fica aberta');

$passada = [[
    'inicio' => gmdate('Y-m-d\TH:i:s', $agora - 7200),
    'fim' => gmdate('Y-m-d\TH:i:s', $agora - 3600),
]];
$testar(EstadoLoja::resumoOperacional($aberta, $passada, $agora)['divergente'] === false, 'pausa encerrada nao causa divergencia');

$desconhecida = EstadoLoja::resumoOperacional([], $pausas, $agora);
$testar($desconhecida['divergente'] === false, 'sem GET status nao declarar contradicao confirmada');

$incoerente = EstadoLoja::resumoOperacional([['estado' => 'ERROR', 'disponivel' => true]], [], $agora);
$testar($incoerente['fechado'] === true, 'estado ERROR nao vira abertura apenas pelo campo available');

if ($erros !== 0) {
    exit(1);
}
echo "OK: status Merchant prevalece sem eliminar bloqueio local preventivo.\n";
