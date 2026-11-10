<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/documento.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Shared\Validation\Documento;

$falhas = 0;
$verificar = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};

$verificar(Documento::cpfValido('529.982.247-25'), 'CPF valido com mascara');
$verificar(Documento::cpfValido('52998224725'), 'CPF valido sem mascara');
$verificar(!Documento::cpfValido('529.982.247-24'), 'CPF com digito errado');
$verificar(!Documento::cpfValido('111.111.111-11'), 'CPF com digitos repetidos');
$verificar(!Documento::cpfValido('1234'), 'CPF curto');
$verificar(Documento::normalizarCpf('529.982.247-25') === '52998224725', 'Normaliza CPF');
$verificar(Documento::normalizarCpf('000.000.000-00') === null, 'Rejeita CPF zerado');
$verificar(Documento::formatarCpf('52998224725') === '529.982.247-25', 'Formata CPF');
$verificar(Documento::mascararCpf('52998224725') === '***.982.247-**', 'Mascara CPF para exibicao');

$verificar(Documento::cnpjValido('11.222.333/0001-81'), 'CNPJ valido');
$verificar(Documento::cnpjValido('64.069.224/0001-32'), 'CNPJ da Escotilha');
$verificar(!Documento::cnpjValido('11.222.333/0001-80'), 'CNPJ com digito errado');
$verificar(!Documento::cnpjValido('00.000.000/0000-00'), 'CNPJ zerado');

$verificar(Documento::normalizarTelefone('(85) 99999-1234') === '85999991234', 'Celular');
$verificar(Documento::normalizarTelefone('(85) 3333-4444') === '8533334444', 'Fixo');
$verificar(Documento::normalizarTelefone('+55 85 99999-1234') === '85999991234', 'Celular com DDI');
$verificar(Documento::normalizarTelefone('(85) 89999-1234') === null, 'Celular sem 9');
$verificar(Documento::normalizarTelefone('9999') === null, 'Telefone curto');

if ($falhas > 0) {
    exit(1);
}
echo "Documento: CPF, CNPJ e telefone validados.\n";
