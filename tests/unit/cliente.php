<?php

declare(strict_types=1);

// Teste sem banco e sem rede do cardapio publico e do cliente final: php tests/unit/cliente.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Services\Cliente\CadastroCliente;
use Cardapim\Services\Cliente\ClienteSessao;
use Cardapim\Services\Cliente\CodigoVerificacao;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};

// Valores monetarios sempre em centavos inteiros (sem float).
$ok(CardapioPublico::centavos('12.90') === 1290, 'centavos 12.90');
$ok(CardapioPublico::centavos('7.5') === 750, 'centavos 7.5');
$ok(CardapioPublico::centavos('30') === 3000, 'centavos 30');
$ok(CardapioPublico::centavos('0.01') === 1, 'centavos 0.01');
$ok(CardapioPublico::centavos('abc') === 0, 'centavos invalido');
$ok(CardapioPublico::centavos('-5.00') === 0, 'centavos negativo recusado');

// Nome do cliente.
$ok(CadastroCliente::nomeValido('  Maria   da  Silva ') === 'Maria da Silva', 'nome normaliza espacos');
$ok(CadastroCliente::nomeValido("João D'Ávila-Souza") === "João D'Ávila-Souza", 'nome com acento e apostrofo');
$ok(CadastroCliente::nomeValido('A') === null, 'nome curto');
$ok(CadastroCliente::nomeValido('Maria123') === null, 'nome com numero');
$ok(CadastroCliente::nomeValido('<script>') === null, 'nome com html');
$ok(CadastroCliente::nomeValido(str_repeat('a', 81)) === null, 'nome longo');

// Dados enviados ao navegador: nunca CPF nem endereco.
$publico = ClienteSessao::publico(['nome' => 'Maria da Silva', 'whatsapp' => '85999990000', 'cpf' => '12345678909', 'logradouro' => 'Rua A']);
$ok($publico === ['nome' => 'Maria da Silva', 'primeiro_nome' => 'Maria', 'whatsapp' => '(85) 99999-0000'], 'dados publicos do cliente');
$ok(ClienteSessao::publico(null) === null, 'sem cliente');

// Mensagem do codigo.
$mensagem = CodigoVerificacao::mensagem('123456');
$ok(str_starts_with($mensagem, '*123456*'), 'codigo em destaque na mensagem');
$ok(str_contains($mensagem, '10 minutos'), 'validade informada');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: cliente final (centavos, nome, dados publicos e mensagem do codigo).\n";
