<?php

declare(strict_types=1);

// Teste sem banco das contas do caixa: php tests/unit/caixa.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Services\Caixa\MesaService;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};

// Taxa de servico em centavos inteiros (sem float), meio centavo arredonda para cima.
$ok(ComandaService::servico(2800, '10.00') === 280, '10% de 28,00');
$ok(ComandaService::servico(1995, '10.00') === 200, '10% de 19,95 = 2,00 (1,995 arredonda)');
$ok(ComandaService::servico(1994, '10.00') === 199, '10% de 19,94 = 1,99');
$ok(ComandaService::servico(5000, '0.00') === 0, 'sem servico');
$ok(ComandaService::servico(3333, '12.50') === 417, '12,5% de 33,33');

// Centavos para decimal do banco.
$ok(CaixaService::dec(0) === '0.00', 'zero');
$ok(CaixaService::dec(5) === '0.05', 'cinco centavos');
$ok(CaixaService::dec(123456) === '1234.56', 'mil e poucos');
$ok(CaixaService::dec(-250) === '-2.50', 'diferenca negativa (faltou)');

// Nome da mesa na tela.
$ok(MesaService::rotulo(['numero' => 7, 'apelido' => null]) === 'Mesa 7', 'mesa sem nome');
$ok(MesaService::rotulo(['numero' => 7, 'apelido' => 'Varanda']) === 'Mesa 7 · Varanda', 'mesa com nome');
$ok(MesaService::rotulo(['numero' => null, 'apelido' => 'Mesa 7']) === 'Mesa 7', 'mesa excluida mantem o nome');

// Listas fechadas de motivos (o relatorio depende delas).
$ok(isset(CaixaService::SAIDAS['sangria'], CaixaService::ENTRADAS['reforco']), 'sangria e reforco existem');
$ok(array_keys(ComandaService::FORMAS_PRESENCIAIS) === ['dinheiro', 'debito', 'credito', 'pix'], 'formas do caixa');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: contas do caixa (servico, centavos, nome da mesa).\n";
