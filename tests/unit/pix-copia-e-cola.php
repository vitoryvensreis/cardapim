<?php

declare(strict_types=1);

// Pix pela chave (sem banco): BR Code estatico do Banco Central, CRC, tipos de chave, nome e cidade sem acento.
// php tests/unit/pix-copia-e-cola.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Shared\PixCopiaECola;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    echo ($cond ? '  ok  ' : '  FALHOU  ') . $nome . "\n";
    $falhas += $cond ? 0 : 1;
};
/** Le os campos EMV (id => valor) de um codigo. @return array<string,string> */
$campos = static function (string $codigo): array {
    $saida = [];
    for ($i = 0; $i + 4 <= strlen($codigo);) {
        $id = substr($codigo, $i, 2);
        $tam = (int) substr($codigo, $i + 2, 2);
        $saida[$id] = substr($codigo, $i + 4, $tam);
        $i += 4 + $tam;
    }
    return $saida;
};

// Exemplo do manual do BR Code (Banco Central): chave aleatoria, sem valor.
$exemplo = '00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913Fulano de Tal6008BRASILIA62070503***63041D3D';
$ok(PixCopiaECola::gerar('123e4567-e12b-12d1-a456-426655440000', 'Fulano de Tal', 'BRASILIA', null) === $exemplo, 'igual ao exemplo do Banco Central (CRC 1D3D)');
$ok(PixCopiaECola::crc16('123456789') === '29B1', 'CRC16-CCITT de referencia (123456789 = 29B1)');

$c = PixCopiaECola::gerar('+5585999990000', 'Pizzaria São João', 'Juazeiro do Norte', 4590, 'CARDAPIM-123');
$f = $campos($c);
$ok(($f['54'] ?? '') === '45.90', 'valor com centavos no campo 54');
$ok(($f['59'] ?? '') === 'Pizzaria Sao Joao', 'nome sem acento');
$ok(($f['60'] ?? '') === 'Juazeiro do Nor', 'cidade cortada em 15 letras');
$ok(($f['62'] ?? '') === '0511CARDAPIM123', 'txid so com letras e numeros');
$ok(($f['26'] ?? '') === '0014br.gov.bcb.pix0114+5585999990000', 'chave dentro do campo 26');
$ok(PixCopiaECola::crc16(substr($c, 0, -4)) === substr($c, -4), 'CRC do codigo confere');
$ok(($campos(PixCopiaECola::gerar('a@b.co', 'X', 'Y', 100))['54'] ?? '') === '1.00', 'R$ 1,00 = 1.00');
$ok(strlen(PixCopiaECola::texto(str_repeat('Á', 40), 25)) === 25, 'nome com acento ainda cabe em 25');

$erro = static function (callable $f): bool {
    try {
        $f();
    } catch (\InvalidArgumentException) {
        return true;
    }
    return false;
};
$ok($erro(static fn () => PixCopiaECola::gerar('', 'Nome', 'Cidade', 100)), 'sem chave recusa');
$ok($erro(static fn () => PixCopiaECola::gerar('a@b.co', '!!!', 'Cidade', 100)), 'nome vazio depois de limpar recusa');
$ok($erro(static fn () => PixCopiaECola::gerar('a@b.co', 'Nome', 'Cidade', 0)), 'valor zero recusa');

// Tipos de chave
$n = static fn (string $t, string $v) => PixCopiaECola::normalizarChave($t, $v);
$ok($n('cpf', '529.982.247-25')['chave'] === '52998224725', 'CPF com pontos vira so numeros');
$ok($n('cpf', '111.111.111-11')['erro'] !== null, 'CPF invalido recusado');
$ok($n('cnpj', '11.222.333/0001-81')['chave'] === '11222333000181', 'CNPJ valido');
$ok($n('cnpj', '11.222.333/0001-00')['erro'] !== null, 'CNPJ invalido recusado');
$ok($n('celular', '(85) 99999-0000')['chave'] === '+5585999990000', 'celular vira +55 com DDD');
$ok($n('celular', '+55 85 99999-0000')['chave'] === '+5585999990000', 'celular com +55 aceito');
$ok($n('celular', '99999-0000')['erro'] !== null, 'celular sem DDD recusado');
$ok($n('email', 'Pix@Restaurante.com.br')['chave'] === 'pix@restaurante.com.br', 'e-mail em minusculas');
$ok($n('email', 'nao-e-email')['erro'] !== null, 'e-mail invalido recusado');
$ok($n('aleatoria', '123E4567-E12B-12D1-A456-426655440000')['chave'] === '123e4567-e12b-12d1-a456-426655440000', 'chave aleatoria em minusculas');
$ok($n('aleatoria', '123e4567')['erro'] !== null, 'chave aleatoria incompleta recusada');
$ok($n('outro', 'x')['erro'] !== null, 'tipo desconhecido recusado');

if ($falhas > 0) {
    echo "$falhas falha(s) no Pix pela chave.\n";
    exit(1);
}
echo "Pix pela chave: tudo certo.\n";
