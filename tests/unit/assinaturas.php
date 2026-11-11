<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/assinaturas.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Modules\Marca\MarcaUpload;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Services\Plataforma\PlanoService;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};
$recusa = static function (string $svg): bool {
    try {
        MarcaUpload::conferirSvg($svg);
        return false;
    } catch (RuntimeException) {
        return true;
    }
};

/* Fatura do Asaas -> formato do Cardapim */
$f = AsaasApi::fatura(['id' => 'pay_1', 'status' => 'received', 'value' => 59.9, 'dueDate' => '2026-12-14', 'billingType' => 'PIX',
    'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_1', 'bankSlipUrl' => 'javascript:alert(1)', 'paymentDate' => '2026-12-10', 'subscription' => 'sub_9']);
$ok($f !== null && $f['status'] === 'RECEIVED' && $f['valor'] === '59.90' && $f['pago_em'] === '2026-12-10', 'fatura normalizada');
$ok($f !== null && $f['link_fatura'] === 'https://sandbox.asaas.com/i/pay_1' && $f['link_boleto'] === null, 'so links https');
$ok($f !== null && $f['assinatura'] === 'sub_9', 'assinatura da fatura');
$ok(AsaasApi::fatura(['id' => 'pay_2', 'value' => 10]) === null, 'sem vencimento = ignorada');
$ok(AsaasApi::fatura(['id' => '../x', 'dueDate' => '2026-01-01']) === null, 'id invalido ignorado');

/* SVG enviado como logo */
$ok(!$recusa('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'), 'SVG simples aceito');
$ok($recusa('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'script recusado');
$ok($recusa('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'), 'evento recusado');
$ok($recusa('<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)">x</a></svg>'), 'javascript: recusado');
$ok($recusa('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>'), 'entidade (XXE) recusada');
$ok($recusa('<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.test/x.png"/></svg>'), 'link externo recusado');
$ok($recusa('<html><body>oi</body></html>'), 'nao-SVG recusado');
$ok(MarcaUpload::arquivoDe('/midia/marca/' . str_repeat('a', 32) . '-512.png') !== null, 'caminho do icone 512 aceito');
$ok(MarcaUpload::arquivoDe('/midia/marca/../../.env') === null && MarcaUpload::arquivoDe('/midia/logos/' . str_repeat('a', 32) . '.png') === null, 'caminhos estranhos recusados');

/* Planos e precos */
$ok(AssinaturaService::preco('0.00', 'vitalicio') === 'Grátis', 'vitalicio e gratis');
$ok(str_contains(AssinaturaService::preco('59.90', 'mensal'), 'por mês'), 'preco mensal');
$ok(preg_match('/\A[a-f0-9]{24}\z/', PlanoService::novoConvite()) === 1 && PlanoService::novoConvite() !== PlanoService::novoConvite(), 'convite aleatorio de 24 caracteres');

if ($falhas > 0) {
    exit(1);
}
echo "Assinaturas: faturas do Asaas, SVG seguro, convites e precos.\n";
