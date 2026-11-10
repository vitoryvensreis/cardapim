<?php

declare(strict_types=1);

// Servidor FALSO de iFood, Asaas e WPPConnect, somente para testes automaticos (CI/local).
// Uso: php -S 127.0.0.1:18099 tests/fakes/integracoes-fake.php
// Nunca usar em producao. Estado guardado em arquivos temporarios.

$pasta = sys_get_temp_dir() . '/cardapim-fake-integracoes';
if (!is_dir($pasta)) {
    mkdir($pasta, 0700, true);
}
$metodo = $_SERVER['REQUEST_METHOD'];
$caminho = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$corpo = (string) file_get_contents('php://input');
$json = json_decode($corpo, true) ?: [];
parse_str($corpo, $form);
$cab = static fn (string $nome): string => (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $nome))] ?? '');
$responder = static function (int $status, array $dados): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($dados);
    exit;
};
$guardar = static fn (string $nome, mixed $valor) => file_put_contents($pasta . '/' . $nome, json_encode($valor));
$ler = static fn (string $nome): mixed => is_file($pasta . '/' . $nome) ? json_decode((string) file_get_contents($pasta . '/' . $nome), true) : null;

/* ---------- Asaas ---------- */
if (preg_match('~\A/asaas/v3(/.*)\z~', $caminho, $m)) {
    if ($cab('User-Agent') === '') {
        $responder(400, ['errors' => [['description' => 'User-Agent obrigatorio']]]);
    }
    if (!str_starts_with($cab('access_token'), '$aact_ci_valida')) {
        $responder(401, ['errors' => [['code' => 'invalid_access_token', 'description' => 'A chave de API fornecida é inválida']]]);
    }
    if ($metodo === 'GET' && $m[1] === '/finance/balance') {
        $responder(200, ['balance' => 150.5]);
    }
    if ($metodo === 'GET' && $m[1] === '/myAccount/commercialInfo') {
        $responder(200, ['companyName' => 'Restaurante CI Ltda', 'email' => 'financeiro@example.test']);
    }
    if ($metodo === 'POST' && $m[1] === '/webhooks') {
        if (strlen((string) ($json['authToken'] ?? '')) < 32 || !str_starts_with((string) ($json['url'] ?? ''), 'https://')) {
            $responder(400, ['errors' => [['description' => 'authToken ou url invalidos']]]);
        }
        $guardar('asaas-webhook.json', $json);
        $responder(200, ['id' => 'wh_ci_' . substr(md5((string) $json['url']), 0, 8)] + $json);
    }
    if ($metodo === 'DELETE' && str_starts_with($m[1], '/webhooks/')) {
        $responder(200, ['deleted' => true]);
    }
    // Clientes e cobrancas Pix (pedidos do cardapio online).
    if ($metodo === 'POST' && $m[1] === '/customers') {
        if (trim((string) ($json['name'] ?? '')) === '' || preg_match('/\A\d{11}\z/', (string) ($json['cpfCnpj'] ?? '')) !== 1) {
            $responder(400, ['errors' => [['description' => 'Nome e CPF obrigatorios']]]);
        }
        if (str_contains((string) $json['name'], 'Falha Pix')) {
            $responder(400, ['errors' => [['description' => 'Cliente recusado (teste)']]]);
        }
        $responder(200, ['id' => 'cus_ci_' . substr(md5((string) $json['cpfCnpj'] . $json['name']), 0, 10)] + $json);
    }
    $cobrancas = $ler('asaas-cobrancas.json') ?? [];
    if ($metodo === 'POST' && $m[1] === '/payments') {
        if (!str_starts_with((string) ($json['customer'] ?? ''), 'cus_') || ($json['billingType'] ?? '') !== 'PIX' || (float) ($json['value'] ?? 0) <= 0
            || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', (string) ($json['dueDate'] ?? '')) !== 1) {
            $responder(400, ['errors' => [['description' => 'Cobranca invalida']]]);
        }
        $id = 'pay_ci_' . (count($cobrancas) + 1) . substr(md5((string) microtime(true)), 0, 6);
        $cobrancas[$id] = ['id' => $id, 'status' => 'PENDING', 'value' => (float) $json['value'], 'externalReference' => (string) ($json['externalReference'] ?? ''), 'customer' => $json['customer']];
        $guardar('asaas-cobrancas.json', $cobrancas);
        $responder(200, $cobrancas[$id]);
    }
    if (preg_match('~\A/payments/([A-Za-z0-9_-]+)(/pixQrCode)?\z~', $m[1], $pm)) {
        $c = $cobrancas[$pm[1]] ?? null;
        if ($c === null) {
            $responder(404, ['errors' => [['description' => 'Cobranca nao encontrada']]]);
        }
        if ($metodo === 'GET' && ($pm[2] ?? '') === '/pixQrCode') {
            $responder(200, ['encodedImage' => '', 'payload' => '00020126580014br.gov.bcb.pix0136ci-' . $pm[1] . '5204000053039865802BR6304ABCD', 'expirationDate' => '2027-11-10 23:59:59']);
        }
        if ($metodo === 'GET') {
            $responder(200, $c);
        }
        if ($metodo === 'DELETE') {
            $cobrancas[$pm[1]]['status'] = 'DELETED';
            $guardar('asaas-cobrancas.json', $cobrancas);
            $responder(200, ['deleted' => true, 'id' => $pm[1]]);
        }
    }
    $responder(404, ['errors' => [['description' => 'nao encontrado']]]);
}
if (preg_match('~\A/_teste/asaas-pagar/([A-Za-z0-9_-]+)\z~', $caminho, $m)) {
    $cobrancas = $ler('asaas-cobrancas.json') ?? [];
    if (!isset($cobrancas[$m[1]])) {
        $responder(404, []);
    }
    $cobrancas[$m[1]]['status'] = 'RECEIVED';
    $guardar('asaas-cobrancas.json', $cobrancas);
    $responder(200, $cobrancas[$m[1]]);
}
if ($caminho === '/_teste/asaas-cobrancas') {
    $responder(200, $ler('asaas-cobrancas.json') ?? []);
}

/* ---------- CEP e mapa (BrasilAPI, ViaCEP, Nominatim) ---------- */
$enderecos = [
    '01310100' => ['Avenida Paulista', 'Bela Vista', -23.5614, -46.6559],
    '01414000' => ['Rua Haddock Lobo', 'Cerqueira César', -23.5600, -46.6650],
    '05422000' => ['Rua dos Pinheiros', 'Pinheiros', -23.5670, -46.6830],
    '04538133' => ['Avenida Brigadeiro Faria Lima', 'Itaim Bibi', -23.5900, -46.6870],
];
if (preg_match('~\A/brasilapi/cep/v2/(\d{8})\z~', $caminho, $m)) {
    $d = $enderecos[$m[1]] ?? null;
    if ($d === null) {
        $responder(404, ['message' => 'CEP nao encontrado']);
    }
    // Como na BrasilAPI real, as coordenadas nem sempre vem: so no CEP do restaurante.
    $coord = $m[1] === '01310100' ? ['latitude' => (string) $d[2], 'longitude' => (string) $d[3]] : [];
    $responder(200, ['cep' => $m[1], 'state' => 'SP', 'city' => 'São Paulo', 'neighborhood' => $d[1], 'street' => $d[0], 'service' => 'fake', 'location' => ['type' => 'Point', 'coordinates' => $coord]]);
}
if (preg_match('~\A/viacep/ws/(\d{8})/json/\z~', $caminho, $m)) {
    $d = $enderecos[$m[1]] ?? null;
    $responder(200, $d === null ? ['erro' => 'true'] : ['cep' => $m[1], 'logradouro' => $d[0], 'bairro' => $d[1], 'localidade' => 'São Paulo', 'uf' => 'SP']);
}
if ($caminho === '/nominatim/search') {
    if (!str_contains($cab('User-Agent'), 'Cardapim')) {
        $responder(403, []);
    }
    $rua = mb_strtolower((string) ($_GET['street'] ?? ''));
    foreach ($enderecos as $d) {
        if ($rua !== '' && str_contains($rua, mb_strtolower($d[0]))) {
            $responder(200, [['lat' => (string) $d[2], 'lon' => (string) $d[3], 'display_name' => $d[0]]]);
        }
    }
    $responder(200, []);
}
if ($caminho === '/_teste/asaas-webhook') {
    $responder(200, $ler('asaas-webhook.json') ?? []);
}

/* ---------- WPPConnect ---------- */
if (preg_match('~\A/wpp/api/([A-Za-z0-9_-]+)/([^/]+)/generate-token\z~', $caminho, $m) && $metodo === 'POST') {
    if (rawurldecode($m[2]) !== 'segredo-ci') {
        $responder(401, ['status' => 'error', 'message' => 'secret key invalida']);
    }
    $responder(201, ['status' => 'success', 'session' => $m[1], 'token' => 'tok-' . $m[1], 'full' => $m[1] . ':tok-' . $m[1]]);
}
if (preg_match('~\A/wpp/api/([A-Za-z0-9_-]+)/([a-z-]+)\z~', $caminho, $m)) {
    [$sessao, $acao] = [$m[1], $m[2]];
    if ($cab('Authorization') !== 'Bearer tok-' . $sessao) {
        $responder(401, ['status' => 'error', 'message' => 'token invalido']);
    }
    $estado = $ler('wpp-' . $sessao . '.json') ?? ['status' => 'CLOSED'];
    switch ($acao) {
        case 'start-session':
            if ($estado['status'] !== 'CONNECTED') {
                $guardar('wpp-' . $sessao . '.json', ['status' => 'QRCODE']);
            }
            $responder(200, ['status' => 'INITIALIZING']);
        case 'status-session':
            $responder(200, ['status' => $estado['status'], 'qrcode' => $estado['status'] === 'QRCODE'
                ? 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' : null]);
        case 'host-device':
            $responder(200, ['status' => 'success', 'response' => ['phoneNumber' => '5585999990000', 'pushname' => 'Restaurante']]);
        case 'send-message':
            if ($estado['status'] !== 'CONNECTED' || !preg_match('/\A55\d{10,11}\z/', (string) ($json['phone'] ?? ''))) {
                $responder(400, ['status' => 'error', 'message' => 'nao conectado ou numero invalido']);
            }
            file_put_contents($pasta . '/wpp-mensagens.log', $sessao . '|' . $json['phone'] . '|' . $json['message'] . "\n", FILE_APPEND);
            $responder(201, ['status' => 'success', 'response' => [['id' => 'msg1']]]);
        case 'logout-session':
        case 'close-session':
            $guardar('wpp-' . $sessao . '.json', ['status' => 'CLOSED']);
            $responder(200, ['status' => true, 'message' => 'Session successfully closed']);
    }
    $responder(404, ['status' => 'error']);
}
if (preg_match('~\A/_teste/wpp-conectar/([A-Za-z0-9_-]+)\z~', $caminho, $m)) {
    $guardar('wpp-' . $m[1] . '.json', ['status' => 'CONNECTED']);
    $responder(200, ['ok' => true]);
}
if ($caminho === '/_teste/wpp-mensagens') {
    $responder(200, ['linhas' => is_file($pasta . '/wpp-mensagens.log') ? file($pasta . '/wpp-mensagens.log', FILE_IGNORE_NEW_LINES) : []]);
}

/* ---------- iFood ---------- */
if ($caminho === '/ifood/authentication/v1.0/oauth/userCode' && $metodo === 'POST') {
    if (($form['clientId'] ?? '') !== 'ifood-ci') {
        $responder(401, ['error' => 'invalid client']);
    }
    $responder(200, ['userCode' => 'ABCD-EFGH', 'authorizationCodeVerifier' => 'verificador-ci', 'verificationUrl' => 'https://portal.ifood.com.br/apps/code',
        'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expiresIn' => 600]);
}
if ($caminho === '/ifood/authentication/v1.0/oauth/token' && $metodo === 'POST') {
    if (($form['clientId'] ?? '') !== 'ifood-ci' || ($form['clientSecret'] ?? '') !== 'ifood-segredo-ci') {
        $responder(401, ['error' => 'invalid client']);
    }
    $ok = match ($form['grantType'] ?? '') {
        'authorization_code' => ($form['authorizationCode'] ?? '') === 'AUTH-CI-OK' && ($form['authorizationCodeVerifier'] ?? '') === 'verificador-ci',
        'refresh_token' => str_starts_with((string) ($form['refreshToken'] ?? ''), 'ref-'),
        default => false,
    };
    if (!$ok) {
        $responder(400, ['error' => 'invalid_grant']);
    }
    $responder(200, ['accessToken' => 'acc-' . bin2hex(random_bytes(4)), 'refreshToken' => 'ref-' . bin2hex(random_bytes(4)), 'type' => 'bearer', 'expiresIn' => 10800]);
}
if (str_starts_with($caminho, '/ifood/merchant/v1.0/merchants')) {
    if (!str_starts_with($cab('Authorization'), 'Bearer acc-')) {
        $responder(401, ['error' => 'unauthorized']);
    }
    if ($caminho === '/ifood/merchant/v1.0/merchants') {
        $responder(200, [['id' => '11111111-2222-3333-4444-555555555555', 'name' => 'Loja CI Centro'], ['id' => '66666666-7777-8888-9999-000000000000', 'name' => 'Loja CI Praia']]);
    }
    if (preg_match('~/merchants/[0-9a-f-]+/status\z~', $caminho)) {
        $responder(200, [['operation' => 'delivery', 'available' => true, 'state' => 'OK', 'message' => ['title' => 'Loja aberta no iFood']]]);
    }
}
$responder(404, ['error' => 'rota falsa nao encontrada', 'caminho' => $caminho]);
