<?php

declare(strict_types=1);

// Credenciais dos apps da plataforma nos marketplaces, com MySQL: php tests/integration/aplicativos-parceiros.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Modules\Integracoes\AplicativosParceiros;
use Cardapim\Repositories\Plataforma\AplicativoParceiroRepository;
use Cardapim\Services\Configuracoes;

$db = Database::connect();
$repo = new AplicativoParceiroRepository($db);
$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};

putenv('IFOOD_CLIENT_ID=id-do-env');
putenv('IFOOD_CLIENT_SECRET=segredo-do-env');
$repo->remover('ifood');
AplicativosParceiros::esquecer();
$ok(AplicativosParceiros::credenciais('ifood')['origem'] === 'env', 'sem cadastro no painel vale o .env');

try {
    $repo->salvar('ifood', 'id-do-painel', 'segredo-do-painel-123', 'CI');
    AplicativosParceiros::esquecer();
    $c = AplicativosParceiros::credenciais('ifood');
    $ok($c === ['client_id' => 'id-do-painel', 'client_secret' => 'segredo-do-painel-123', 'origem' => 'painel'], 'painel tem prioridade sobre o .env');

    $bruto = (string) $db->query("SELECT valor FROM configuracoes WHERE chave = 'app_parceiro_ifood'")->fetchColumn();
    $ok(str_starts_with($bruto, 'v1:') && !str_contains($bruto, 'segredo-do-painel') && !str_contains($bruto, 'id-do-painel'), 'gravado cifrado no banco');

    $repo->salvar('ifood', 'id-novo', 'segredo-novo-456', 'CI');
    $ok($repo->buscar('ifood')['client_id'] === 'id-novo', 'salvar de novo atualiza a mesma linha');
    $ok((int) $db->query("SELECT COUNT(*) FROM configuracoes WHERE chave = 'app_parceiro_ifood'")->fetchColumn() === 1, 'uma linha por marketplace');

    $recusou = false;
    try {
        Configuracoes::get('app_parceiro_ifood');
    } catch (RuntimeException) {
        $recusou = true;
    }
    $ok($recusou, 'credenciais fora das configuracoes publicas');
    $ok($repo->lojasVinculadas('99food') === 0, 'marketplace sem lojas vinculadas');
} finally {
    $repo->remover('ifood');
    AplicativosParceiros::esquecer();
}
$ok($repo->buscar('ifood') === null && AplicativosParceiros::credenciais('ifood')['origem'] === 'env', 'apagar volta ao .env');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: credenciais dos aplicativos (painel, .env e criptografia).\n";
