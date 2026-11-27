<?php

declare(strict_types=1);

// Sem rede nem banco MySQL: valida o prazo, a exclusao de cobranca e o filtro de seguranca.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Services\Pedidos\CancelarPedido;
use Cardapim\Services\Pedidos\PixPedido;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;

$falhas = 0;
$ok = static function (bool $c, string $mensagem) use (&$falhas): void {
    if (!$c) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $mensagem\n");
    }
};

final class HttpPixTeste extends ClienteHttp
{
    public array $requisicoes = [];
    public function __construct(private readonly int $codigo)
    {
        parent::__construct(1);
    }
    public function enviar(string $metodo, string $url, array $cabecalhos = [], array|string|null $corpo = null): RespostaHttp
    {
        $this->requisicoes[] = [$metodo, $url];
        return new RespostaHttp($this->codigo, $this->codigo === 400
            ? '{"errors":[{"description":"Cobranca ja recebida"}]}' : '{"deleted":true}');
    }
}

$ok(PixPedido::PRAZO_MINUTOS === 60, 'Pix do cardapio expira em uma hora');
foreach ([200 => false, 404 => false, 400 => true, 500 => true] as $httpStatus => $deveFalhar) {
    $http = new HttpPixTeste($httpStatus);
    $erro = false;
    try {
        (new AsaasApi($http))->cancelarCobranca('chave_teste', 'sandbox', 'pay_123456');
    } catch (IntegracaoErro) {
        $erro = true;
    }
    $ok($erro === $deveFalhar, 'DELETE Asaas HTTP ' . $httpStatus);
    $ok($http->requisicoes === [['DELETE', AsaasApi::urlBase('sandbox') . '/payments/pay_123456']],
        'DELETE chama cobranca certa');
}

// O UPDATE de cancelamento por expiracao nao pode atingir pedido pago, nao-Pix ou nao-vencido.
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE pedidos (
        id INTEGER PRIMARY KEY, id_restaurante INTEGER, status TEXT, pagamento_status TEXT,
        forma_pagamento TEXT, pix_expira_em TEXT, atualizado_em TEXT, cancelado_em TEXT,
        motivo_cancelamento TEXT, consumo_estoque TEXT
    )");
    $antigo = date('Y-m-d H:i:s', time() - 3600);
    $futuro = date('Y-m-d H:i:s', time() + 3600);
    $ins = $db->prepare("INSERT INTO pedidos(id,id_restaurante,status,pagamento_status,forma_pagamento,pix_expira_em)
        VALUES(?,?,?,?,?,?)");
    $ins->execute([1, 1, 'recebido', 'pago', 'pix_online', $antigo]);
    $ins->execute([2, 1, 'aguardando_pagamento', 'pago', 'pix_online', $antigo]);
    $ins->execute([3, 1, 'aguardando_pagamento', 'pendente', 'dinheiro', $antigo]);
    $ins->execute([4, 1, 'aguardando_pagamento', 'pendente', 'pix_online', $futuro]);
    $ins->execute([5, 2, 'aguardando_pagamento', 'pendente', 'pix_online', $antigo]);
    // Pix pela chave com o comprovante ja enviado (pedido "recebido"): o prazo nao cancela mais.
    $ins->execute([6, 1, 'recebido', 'pendente', 'pix_comprovante', $antigo]);
    $ins->execute([7, 1, 'aguardando_pagamento', 'pendente', 'pix_comprovante', $futuro]);
    foreach ([1,2,3,4,5,6,7] as $id) {
        $ok(!(new CancelarPedido($db))->cancelar(1, $id, 'Prazo do Pix encerrado.', null, null, true),
            'nao cancela pedido protegido ' . $id);
    }
    $ok((int) $db->query("SELECT COUNT(*) FROM pedidos WHERE status = 'cancelado'")->fetchColumn() === 0,
        'nenhum pedido pago, futuro ou de outra loja foi cancelado');
}

exit($falhas > 0 ? 1 : 0);
