<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Services\Fiscal\EmissorFiscal;
use Cardapim\Services\Fiscal\FiscalEmissao;
use Cardapim\Shared\Seguranca\Cripto;

/**
 * 1) Módulo fiscal DESLIGADO: a venda segue igual e nada é gravado na fila fiscal.
 * 2) Módulo LIGADO: uma nota por venda, origem desligada não emite, iFood desligado por padrão,
 *    CPF validado e pendência de cadastro bloqueia sem reservar número.
 * Sem transação externa (o emissor abre as suas); tudo o que o teste cria é apagado no final.
 */
function falha(string $m): never { throw new RuntimeException('Fiscal emissão: ' . $m); }

$db = \Cardapim\Core\Database::connect();
Cripto::usarChave(str_repeat('f', 32));
$id = (int) $db->query('SELECT r.id FROM restaurantes r LEFT JOIN restaurante_fiscal f ON f.id_restaurante = r.id WHERE f.id_restaurante IS NULL ORDER BY r.id LIMIT 1')->fetchColumn();
if ($id < 1) { falha('restaurante de teste ausente.'); }
$q = $db->prepare('SELECT cnpj FROM restaurantes WHERE id = ?');
$q->execute([$id]);
$cnpj = preg_replace('/\D/', '', (string) $q->fetchColumn());
$visivelAntes = $db->query('SELECT visivel FROM fiscal_visibilidade WHERE id = 1')->fetchColumn();
$q = $db->prepare('SELECT permitido FROM fiscal_liberacoes WHERE id_restaurante = ?');
$q->execute([$id]);
$liberacaoAntes = $q->fetchColumn();
$pedidos = [];

$novoPedido = static function (string $origem, string $tipo) use ($db, $id, &$pedidos): int {
    $q = $db->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM pedidos WHERE id_restaurante = ?');
    $q->execute([$id]);
    $agora = date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO pedidos (id_restaurante, numero, origem, tipo, status, forma_pagamento, pagamento_status, subtotal, taxa_entrega, desconto, total, cliente_nome, chave_idempotencia, pago_em, concluido_em, criado_em, atualizado_em)
        VALUES (?, ?, ?, ?, 'concluido', 'dinheiro', 'pago', '20.00', '0.00', '0.00', '20.00', 'CI fiscal', ?, ?, ?, ?, ?)")
        ->execute([$id, (int) $q->fetchColumn(), $origem, $tipo, bin2hex(random_bytes(16)), $agora, $agora, $agora, $agora]);
    $p = (int) $db->lastInsertId();
    // Item avulso (sem produto): o emissor deve tratar como pendência de cadastro.
    $db->prepare("INSERT INTO pedido_itens (id_pedido, id_restaurante, nome, preco_unitario, quantidade, unidade, total, criado_em) VALUES (?, ?, 'Item avulso CI', '10.00', '2.000', 'un', '20.00', ?)")
        ->execute([$p, $id, $agora]);
    $pedidos[] = $p;
    return $p;
};
$linhas = static function (int $p) use ($db, $id): int {
    $q = $db->prepare('SELECT COUNT(*) FROM fiscal_documentos WHERE id_restaurante = ? AND id_pedido = ?');
    $q->execute([$id, $p]);
    return (int) $q->fetchColumn();
};

try {
    // ---------- 1) Desligado ----------
    $p1 = $novoPedido('balcao', 'balcao');
    if (FiscalEmissao::pronto($db, $id) !== null) { falha('restaurante sem módulo fiscal apareceu como pronto.'); }
    FiscalEmissao::solicitar($db, $id, $p1);
    FiscalEmissao::solicitar($db, $id, $p1, true);
    if ($linhas($p1) !== 0) { falha('módulo desligado gravou nota na fila.'); }
    if (FiscalEmissao::situacao($db, $id, $p1) !== null || FiscalEmissao::reenviar($db, $id, $p1) || FiscalEmissao::informarDocumento($db, $id, $p1, '52998224725')) {
        falha('com módulo desligado as ações da nota deveriam ser neutras.');
    }

    // Liberado pela plataforma mas não habilitado/aprovado: continua desligado.
    $db->prepare('INSERT INTO fiscal_visibilidade (id, visivel) VALUES (1, 1) ON DUPLICATE KEY UPDATE visivel = 1')->execute();
    $db->prepare('INSERT INTO fiscal_liberacoes (id_restaurante, permitido, autor) VALUES (?, 1, 1) ON DUPLICATE KEY UPDATE permitido = 1')->execute([$id]);
    $repo = new FiscalRepository($db);
    $dadosBase = ['token' => 'tok-ci', 'ambiente' => 3, 'software_house' => '00000000000000',
        'empresa' => ['razao' => 'Empresa CI', 'cidade' => 'Fortaleza', 'estado' => 'CE', 'ibge' => '2304400', 'ibge_verificado' => true, 'crt' => '4']];
    $repo->serializar($id, static fn () => $repo->salvar($id, ['cnpj' => $cnpj, 'habilitado' => false, 'aprovado' => true, 'id_impressora' => null, 'dados' => $dadosBase]));
    FiscalEmissao::solicitar($db, $id, $p1, true);
    if ($linhas($p1) !== 0) { falha('módulo desabilitado no restaurante gravou nota.'); }

    // ---------- 2) Ligado ----------
    $repo->serializar($id, static fn () => $repo->salvar($id, ['cnpj' => $cnpj, 'habilitado' => true, 'aprovado' => true, 'id_impressora' => null, 'dados' => $dadosBase]));
    $pronto = FiscalEmissao::pronto($db, $id);
    if ($pronto === null || $pronto['ambiente'] !== 3 || $pronto['config']['ifood'] !== false) { falha('restaurante habilitado não ficou pronto com o padrão aprovado.'); }

    FiscalEmissao::solicitar($db, $id, $p1);
    FiscalEmissao::solicitar($db, $id, $p1);
    if ($linhas($p1) !== 1) { falha('deveria existir exatamente uma nota por venda.'); }
    $s = FiscalEmissao::situacao($db, $id, $p1);
    if ($s === null || $s['status'] !== 'pendente' || $s['numero'] !== null) { falha('nota nova deveria ficar na fila sem número.'); }

    if (FiscalEmissao::informarDocumento($db, $id, $p1, '111.111.111-11')) { falha('CPF inválido aceito.'); }
    if (!FiscalEmissao::informarDocumento($db, $id, $p1, '529.982.247-25') || FiscalEmissao::situacao($db, $id, $p1)['cpf_cnpj'] !== '52998224725') { falha('CPF válido não gravado.'); }

    $pIfood = $novoPedido('ifood', 'retirada');
    FiscalEmissao::solicitar($db, $id, $pIfood);
    if ($linhas($pIfood) !== 0) { falha('iFood emitiu com a opção desligada (padrão).'); }

    $dadosSemMesa = $dadosBase + ['emissao' => ['mesa' => false, 'balcao' => true, 'delivery' => true, 'ifood' => false, 'imprimir' => false]];
    $repo->serializar($id, static fn () => $repo->salvar($id, ['cnpj' => $cnpj, 'habilitado' => true, 'aprovado' => true, 'id_impressora' => null, 'dados' => $dadosSemMesa]));
    $pMesa = $novoPedido('mesa', 'mesa');
    FiscalEmissao::solicitar($db, $id, $pMesa);
    if ($linhas($pMesa) !== 0) { falha('mesa emitiu com a origem desligada.'); }

    // Emissor: pendência (item avulso / sem conexão global) bloqueia e não reserva número.
    $q = $db->prepare('SELECT id FROM fiscal_documentos WHERE id_restaurante = ? AND id_pedido = ?');
    $q->execute([$id, $p1]);
    $idDoc = (int) $q->fetchColumn();
    $db->prepare('UPDATE fiscal_documentos SET proxima_tentativa_em = NOW() WHERE id = ?')->execute([$idDoc]);
    $status = (new EmissorFiscal($db))->processar($idDoc);
    $s = FiscalEmissao::situacao($db, $id, $p1);
    if ($status !== 'bloqueada' || $s['status'] !== 'bloqueada' || $s['numero'] !== null || trim((string) $s['motivo']) === '') { falha('pendência deveria bloquear sem numerar (' . $status . ').'); }
    $q = $db->prepare('SELECT COUNT(*) FROM fiscal_numeracao WHERE id_restaurante = ?');
    $q->execute([$id]);
    if ((int) $q->fetchColumn() !== 0) { falha('número reservado para nota bloqueada.'); }
    if (!FiscalEmissao::reenviar($db, $id, $p1) || FiscalEmissao::situacao($db, $id, $p1)['status'] !== 'pendente') { falha('"Tentar de novo" não voltou a nota para a fila.'); }

    // Isolamento: outro restaurante não enxerga a nota.
    $outro = (int) $db->query('SELECT id FROM restaurantes WHERE id <> ' . $id . ' ORDER BY id LIMIT 1')->fetchColumn();
    if ($outro > 0 && (FiscalEmissao::situacao($db, $outro, $p1) !== null || FiscalEmissao::reenviar($db, $outro, $p1))) { falha('isolamento entre restaurantes.'); }
} finally {
    if ($pedidos !== []) {
        $in = implode(',', array_map('intval', $pedidos));
        $db->exec("DELETE e FROM fiscal_eventos e JOIN fiscal_documentos d ON d.id = e.id_documento WHERE d.id_pedido IN ($in)");
        $db->exec("DELETE FROM fiscal_documentos WHERE id_pedido IN ($in)");
        $db->exec("DELETE FROM pedido_itens WHERE id_pedido IN ($in)");
        $db->exec("DELETE FROM pedidos WHERE id IN ($in)");
    }
    $db->prepare('DELETE FROM fiscal_numeracao WHERE id_restaurante = ?')->execute([$id]);
    $db->prepare('DELETE FROM restaurante_fiscal WHERE id_restaurante = ?')->execute([$id]);
    if ($liberacaoAntes === false) {
        $db->prepare('DELETE FROM fiscal_liberacoes WHERE id_restaurante = ?')->execute([$id]);
    } else {
        $db->prepare('UPDATE fiscal_liberacoes SET permitido = ? WHERE id_restaurante = ?')->execute([(int) $liberacaoAntes, $id]);
    }
    if ($visivelAntes !== false) {
        $db->prepare('UPDATE fiscal_visibilidade SET visivel = ? WHERE id = 1')->execute([(int) $visivelAntes]);
    }
    Cripto::usarChave(null);
}
echo "fiscal-emissao (integração): ok\n";
