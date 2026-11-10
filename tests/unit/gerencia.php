<?php

declare(strict_types=1);

// Teste sem banco das regras do painel do gerente: php tests/unit/gerencia.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Inventory\AnaliseEstoque;
use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Shared\Numero;
use Cardapim\Shared\Seguranca\CodigosRecuperacao;
use Cardapim\Shared\Seguranca\Cripto;
use Cardapim\Shared\Seguranca\Totp;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};

/* ---------- TOTP: vetores oficiais da RFC 6238 (SHA1, 8 digitos -> conferimos os 6 finais) ---------- */
$chave = '12345678901234567890';
foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $t => $esperado) {
    $ok(Totp::hotp($chave, intdiv($t, 30)) === substr($esperado, -6), "TOTP RFC 6238 em $t");
}
$segredo = Totp::base32($chave);
$ok($segredo === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 do segredo da RFC');
$ok(Totp::base32Decodificar($segredo) === $chave, 'base32 ida e volta');
$ok(Totp::base32Decodificar(Totp::agrupar($segredo)) === $chave, 'base32 aceita espacos');
$ok(Totp::verificar($segredo, '287082', 59) !== null, 'TOTP valido no instante');
$ok(Totp::verificar($segredo, '287082', 59 + 30) !== null, 'TOTP aceita 1 passo de atraso');
$ok(Totp::verificar($segredo, '287082', 59 + 90) === null, 'TOTP recusa codigo velho');
$ok(Totp::verificar($segredo, '28708', 59) === null, 'TOTP recusa 5 digitos');
$ok(strlen(Totp::gerarSegredo()) === 32, 'segredo novo com 160 bits');
$ok(str_starts_with(Totp::uri($segredo, 'ana@x.com'), 'otpauth://totp/Cardapim%3Aana%40x.com?secret='), 'URI otpauth');

/* ---------- Cripto ---------- */
Cripto::usarChave(random_bytes(32));
$pacote = Cripto::cifrar('segredo-123');
$ok($pacote !== 'segredo-123' && str_starts_with($pacote, 'v1:'), 'cifra com versao');
$ok(Cripto::decifrar($pacote) === 'segredo-123', 'decifra o que cifrou');
$ok(Cripto::cifrar('a') !== Cripto::cifrar('a'), 'IV aleatorio');
$alterado = substr($pacote, 0, -2) . (substr($pacote, -2, 1) === 'A' ? 'B' : 'A') . substr($pacote, -1);
try {
    Cripto::decifrar($alterado);
    $ok(false, 'dado alterado deveria falhar');
} catch (RuntimeException) {
    $ok(true, '');
}
Cripto::usarChave(random_bytes(32));
try {
    Cripto::decifrar($pacote);
    $ok(false, 'chave diferente deveria falhar');
} catch (RuntimeException) {
    $ok(true, '');
}
Cripto::usarChave(null);

/* ---------- Codigos de recuperacao ---------- */
$codigos = CodigosRecuperacao::gerar();
$ok(count($codigos) === CodigosRecuperacao::QUANTIDADE && count(array_unique($codigos)) === count($codigos), 'codigos unicos');
$ok(preg_match('/\A[a-z0-9]{4}-[a-z0-9]{4}\z/', $codigos[0]) === 1, 'formato xxxx-xxxx');
$ok(CodigosRecuperacao::hash($codigos[0]) === CodigosRecuperacao::hash(strtoupper(str_replace('-', ' ', $codigos[0]))), 'hash ignora maiusculas e separador');

/* ---------- Horarios ---------- */
[$turnos, $erros] = HorarioFuncionamento::validar([
    1 => ['aberto' => '1', 'abre1' => '11:00', 'fecha1' => '15:00', 'turno2' => '1', 'abre2' => '18:00', 'fecha2' => '23:00'],
    5 => ['aberto' => '1', 'abre1' => '18:00', 'fecha1' => '02:00'],
    6 => ['aberto' => '', 'abre1' => '10:00', 'fecha1' => '12:00'],
]);
$ok($erros === [] && count($turnos) === 3, 'horarios validos');
[, $erros] = HorarioFuncionamento::validar([2 => ['aberto' => '1', 'abre1' => '10:00', 'fecha1' => '10:00']]);
$ok(isset($erros[2]), 'abre igual a fecha');
[, $erros] = HorarioFuncionamento::validar([2 => ['aberto' => '1', 'abre1' => '11:00', 'fecha1' => '15:00', 'turno2' => '1', 'abre2' => '14:00', 'fecha2' => '22:00']]);
$ok(isset($erros[2]), 'segundo turno sobreposto');
[, $erros] = HorarioFuncionamento::validar([2 => ['aberto' => '1', 'abre1' => '18:00', 'fecha1' => '01:00', 'turno2' => '1', 'abre2' => '02:00', 'fecha2' => '03:00']]);
$ok(isset($erros[2]), 'primeiro turno vira o dia e tem segundo');
[, $erros] = HorarioFuncionamento::validar([3 => ['aberto' => '1', 'abre1' => '25:00', 'fecha1' => '10:00']]);
$ok(isset($erros[3]), 'hora invalida');
[, $erros] = HorarioFuncionamento::validar([]);
$ok(isset($erros[-1]), 'nenhum dia aberto');
// Sexta 18:00-02:00: sabado 01:30 ainda aberto; sabado 02:00 fechado. 2026-11-13 e sexta.
$sexta = [['dia_semana' => 5, 'turno' => 1, 'abre' => '18:00', 'fecha' => '02:00']];
$ok(HorarioFuncionamento::abertoEm(false, $sexta, new DateTimeImmutable('2026-11-13 19:00')), 'aberto sexta 19h');
$ok(HorarioFuncionamento::abertoEm(false, $sexta, new DateTimeImmutable('2026-11-14 01:30')), 'aberto sabado 01:30 (turno de sexta)');
$ok(!HorarioFuncionamento::abertoEm(false, $sexta, new DateTimeImmutable('2026-11-14 02:00')), 'fechado sabado 02:00');
$ok(!HorarioFuncionamento::abertoEm(false, $sexta, new DateTimeImmutable('2026-11-13 17:59')), 'fechado sexta 17:59');
$ok(HorarioFuncionamento::abertoEm(true, [], new DateTimeImmutable('2026-11-13 04:00')), 'sempre aberto');
$ok(HorarioFuncionamento::resumo(false, $sexta) === 'Dom: fechado; Seg: fechado; Ter: fechado; Qua: fechado; Qui: fechado; Sex: 18:00-02:00; Sáb: fechado', 'resumo');

/* ---------- Numero ---------- */
$ok(Numero::dinheiroParaDecimal('1.234,56') === '1234.56', 'dinheiro com milhar');
$ok(Numero::dinheiroParaDecimal('24,9') === '24.90', 'dinheiro uma casa');
$ok(Numero::dinheiroParaDecimal('24.90') === '24.90', 'dinheiro com ponto');
$ok(Numero::dinheiroParaDecimal('1.234') === '1234.00', 'milhar sem centavos');
$ok(Numero::dinheiroParaDecimal('abc') === null && Numero::dinheiroParaDecimal('-1') === null, 'dinheiro invalido');
$ok(Numero::custoParaDecimal('0,0125') === '0.0125', 'custo com 4 casas');
$ok(Numero::reais('1234.5') === 'R$ 1.234,50', 'formatar reais');
$ok(Numero::centavos('24.905') === 2491, 'arredonda centavos');
$ok(Numero::decimalCampo('2.5000') === '2,5' && Numero::decimalCampo('3.000') === '3', 'decimal para campo');

/* ---------- Analise de estoque ---------- */
$c = AnaliseEstoque::classificarInsumo(['estoque_atual' => '0.000', 'estoque_minimo' => '5.000', 'ativo' => 1]);
$ok($c['situacao'] === AnaliseEstoque::FALTA && $c['nivel'] === 0, 'insumo em falta');
$c = AnaliseEstoque::classificarInsumo(['estoque_atual' => '3.000', 'estoque_minimo' => '5.000', 'ativo' => 1]);
$ok($c['situacao'] === AnaliseEstoque::BAIXO && $c['faltaParaMinimo'] === 2000, 'insumo baixo');
$c = AnaliseEstoque::classificarInsumo(['estoque_atual' => '9.000', 'estoque_minimo' => '0', 'ativo' => 1]);
$ok($c['situacao'] === AnaliseEstoque::OK && $c['nivel'] === 7, 'insumo sem minimo');
$c = AnaliseEstoque::classificarInsumo(['estoque_atual' => '9.000', 'estoque_minimo' => '1', 'ativo' => 0]);
$ok($c['situacao'] === AnaliseEstoque::INATIVO, 'insumo inativo');
$a = AnaliseEstoque::analisar(
    [['id' => 1, 'nome' => 'Pao', 'estoque_atual' => '10.000', 'estoque_minimo' => '0', 'custo_unitario' => '1.2500', 'ativo' => 1],
     ['id' => 2, 'nome' => 'Carne', 'estoque_atual' => '0.500', 'estoque_minimo' => '1', 'custo_unitario' => '40.0000', 'ativo' => 1],
     ['id' => 3, 'nome' => 'Gelo', 'estoque_atual' => '1', 'estoque_minimo' => '0', 'custo_unitario' => null, 'ativo' => 1]],
    [['id' => 7, 'nome' => 'X', 'ativo' => 1], ['id' => 8, 'nome' => 'Y', 'ativo' => 1]],
    [7 => ['disponivel' => true, 'motivo' => null, 'porcoes' => 3, 'faltando' => []], 8 => ['disponivel' => false, 'motivo' => 'sem_insumo', 'porcoes' => 0, 'faltando' => ['Carne']]],
);
$ok($a['valorEstoqueCentavos'] === 3250, 'valor em estoque (10 x 1,25 + 0,5 x 40)');
$ok($a['insumosSemCusto'] === 1 && count($a['baixos']) === 1 && count($a['acabando']) === 1 && count($a['indisponiveis']) === 1, 'resumo da analise');
$ok($a['indisponiveis'][0]['motivoTexto'] === 'Acabou: Carne', 'motivo legivel');

/* ---------- Auditoria ---------- */
$ok(Auditoria::diferencas(['preco' => '24.900', 'nome' => 'X'], ['preco' => '26.9', 'nome' => 'X'], ['preco' => 'Preço', 'nome' => 'Nome']) === 'Preço: 24,9 -> 26,9.', 'diferenca de decimal');
$ok(Auditoria::diferencas(['a' => true], ['a' => true], ['a' => 'A']) === 'Nenhuma alteração.', 'sem diferenca');
$ok(Auditoria::diferencas(['a' => true], ['a' => false], ['a' => 'A']) === 'A: sim -> não.', 'diferenca booleana');

/* ---------- Assinatura ---------- */
$ok(AssinaturaService::somarMeses(new DateTimeImmutable('2026-01-31'), 1)->format('Y-m-d') === '2026-02-28', '31/01 + 1 mes');
$ok(AssinaturaService::somarMeses(new DateTimeImmutable('2026-11-10'), 12)->format('Y-m-d') === '2027-11-10', '+12 meses');
$ok(AssinaturaService::somarMeses(new DateTimeImmutable('2027-08-31'), 6)->format('Y-m-d') === '2028-02-29', 'ano bissexto');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: regras do painel do gerente.\n";
