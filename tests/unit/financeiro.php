<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/financeiro.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Gerencia\ContaFinanceiraService;
use Cardapim\Services\Gerencia\FinanceiroService;
use Cardapim\Services\Gerencia\PeriodoFinanceiro;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};
$hoje = new DateTimeImmutable('2026-03-15');
$p = static fn (array $q): PeriodoFinanceiro => PeriodoFinanceiro::daRequisicao($q, $hoje);

$ok([$p(['periodo' => 'hoje'])->de, $p(['periodo' => 'hoje'])->ate] === ['2026-03-15', '2026-03-15'], 'hoje');
$ok($p(['periodo' => 'ontem'])->de === '2026-03-14', 'ontem');
$ok($p(['periodo' => '7dias'])->de === '2026-03-09' && $p(['periodo' => '7dias'])->ate === '2026-03-15', '7 dias contando hoje');
$ok($p([])->chave === 'mes' && $p([])->de === '2026-03-01' && $p([])->ate === '2026-03-15', 'padrao: este mes ate hoje');
$ok($p(['periodo' => 'mes_passado'])->de === '2026-02-01' && $p(['periodo' => 'mes_passado'])->ate === '2026-02-28', 'mes passado inteiro');
$ok($p(['periodo' => ['x']])->chave === 'mes', 'periodo em lista cai no mes');
$pers = $p(['periodo' => 'personalizado', 'de' => '2026-03-10', 'ate' => '2026-03-01']);
$ok($pers->de === '2026-03-01' && $pers->ate === '2026-03-10' && $pers->aviso === null, 'datas invertidas sao trocadas');
$ok($p(['periodo' => 'personalizado', 'de' => '2026-02-30', 'ate' => '2026-03-01'])->chave === 'mes', 'data inexistente cai no mes');
$ok($p(['periodo' => 'personalizado', 'de' => '2026-02-30', 'ate' => '2026-03-01'])->aviso !== null, 'data inexistente avisa');
$longo = $p(['periodo' => 'personalizado', 'de' => '2020-01-01', 'ate' => '2026-03-15']);
$ok($longo->de === '2025-03-15' && $longo->aviso !== null, 'limite de 366 dias');
$ok($pers->inicio() === '2026-03-01 00:00:00' && $pers->fimExclusivo() === '2026-03-11 00:00:00', 'intervalo [inicio, fim)');
$ok($pers->query() === 'periodo=personalizado&de=2026-03-01&ate=2026-03-10', 'query mantem as datas');
$ok($p(['periodo' => 'hoje'])->query() === 'periodo=hoje', 'query do atalho');
$ok(preg_match('/\A[a-z0-9=&_-]{1,80}\z/', $pers->query()) === 1, 'query passa no filtro de volta da tela');

$ok(ContaFinanceiraService::rotuloCategoria('pagar', 'aluguel') === 'Aluguel', 'categoria conhecida');
$ok(ContaFinanceiraService::rotuloCategoria('receber', 'inventada') === 'Outra receita', 'categoria desconhecida');
$ok(FinanceiroService::rotuloSaida('caixa:fornecedor') === 'Pagamento a fornecedor (caixa)', 'saida do caixa');
$ok(FinanceiroService::rotuloSaida('conta:internet') === 'Internet e telefone', 'saida de conta');
$ok(!array_intersect(array_keys(ContaFinanceiraService::CATEGORIAS['pagar']), array_keys(ContaFinanceiraService::CATEGORIAS['receber'])), 'categorias de pagar e receber nao se misturam');

if ($falhas > 0) {
    exit(1);
}
echo "Financeiro: periodos, limites e rotulos conferidos.\n";
