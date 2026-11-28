<?php

declare(strict_types=1);

namespace Cardapim\Services\Caixa;

use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Fiscal\FiscalEmissao;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Ifood\DadosPedidoIfood;
use Cardapim\Services\Ifood\IfoodAcoes;
use Cardapim\Services\Ifood\IfoodConta;
use Cardapim\Services\Pedidos\AvisosWhatsApp;
use Cardapim\Services\Pedidos\CancelarPedido;
use Cardapim\Services\Pedidos\StatusPedido;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;
use PDO;
use Throwable;

/**
 * Pedidos do cardapio online (entrega e retirada) no caixa, do mais antigo para o mais novo.
 *
 * Caminho: Recebido -> Em preparo -> Pronto -> Saiu para entrega (com o motoqueiro escolhido) -> Entregue.
 * Retirada: Pronto -> Entregue ao cliente. O estoque ja foi baixado quando o cliente fez o pedido
 * (nao baixa de novo no despacho); cancelar devolve. Ao concluir, o pagamento entra no caixa aberto.
 * Pedido do iFood: aceitar, pronto, despachar e cancelar tambem vao para o iFood (fila IfoodAcoes).
 * Voltar etapa: cada etapa volta para a anterior (concluido desfaz o recebimento no caixa; so o gerente).
 */
final class DeliveryService
{
    public const ATIVOS = ['aguardando_pagamento', 'recebido', 'em_preparo', 'pronto', 'saiu_entrega'];

    /** Acao => [status de onde pode sair, status novo, texto da linha do tempo] */
    private const ACOES = [
        'aceitar' => [['recebido'], 'em_preparo', 'Pedido aceito. Em preparo.'],
        'pronto' => [['recebido', 'em_preparo'], 'pronto', 'Pedido pronto.'],
    ];

    /**
     * Fim do texto da linha do tempo ao concluir: diz se o pagamento entrou no caixa NA conclusao.
     * "Voltar etapa" usa isso para tirar do caixa so esse pagamento (o pago antes, no balcao, fica).
     */
    private const MARCA_PAGO_NA_CONCLUSAO = 'Pagamento registrado no caixa';
    private const MARCA_JA_NO_CAIXA = 'Pagamento já estava no caixa.';

    /** Status atual => etapa anterior ("Voltar etapa"). O concluido depende do tipo (etapaAnterior). */
    private const ANTERIOR = ['em_preparo' => 'recebido', 'pronto' => 'em_preparo', 'saiu_entrega' => 'pronto'];

    /** Forma do pedido online -> forma registrada no caixa. */
    private const FORMA_CAIXA = ['pix_entrega' => 'pix', 'debito' => 'debito', 'credito' => 'credito', 'dinheiro' => 'dinheiro', 'pix_online' => 'pix_online', 'ifood' => 'ifood',
        'pix_comprovante' => 'pix'];

    /** Para onde o pedido volta em "Voltar etapa" (null: nao tem etapa anterior). @param array<string,mixed> $p */
    public static function etapaAnterior(array $p): ?string
    {
        if (!in_array($p['tipo'] ?? '', ['entrega', 'retirada'], true)) {
            return null;
        }
        if (($p['status'] ?? '') === 'concluido') {
            return $p['tipo'] === 'entrega' ? 'saiu_entrega' : 'pronto';
        }
        return self::ANTERIOR[$p['status'] ?? ''] ?? null;
    }

    /** Pix pela chave do restaurante ainda nao confirmado ("Pix a confirmar"). @param array<string,mixed> $p */
    public static function pixParaConferir(array $p): bool
    {
        return ($p['forma_pagamento'] ?? '') === 'pix_comprovante' && ($p['pagamento_status'] ?? '') === 'pendente'
            && !in_array($p['status'] ?? '', ['concluido', 'cancelado'], true);
    }

    private readonly PedidoRepository $pedidos;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
        $this->pedidos = new PedidoRepository($db);
    }

    /** @return list<array<string,mixed>> */
    public function fila(): array
    {
        $marcadores = implode(',', array_fill(0, count(self::ATIVOS), '?'));
        $nd = \Cardapim\Services\Pedidos\NumeroDoDia::sql($this->db, 'p');
        $q = $this->db->prepare(
            "SELECT p.id, $nd AS numero, p.tipo, p.status, p.forma_pagamento, p.pagamento_status, p.total, p.cliente_nome, p.entrega_bairro, p.criado_em,
                    p.origem, p.ifood_display_id, p.ifood_entregue_por, p.despachado_em, p.id_entregador, CONCAT(u.nome, ' ', u.sobrenome) AS entregador,
                    (SELECT COUNT(*) FROM pedido_itens i WHERE i.id_pedido = p.id AND i.id_restaurante = p.id_restaurante) AS itens
             FROM pedidos p LEFT JOIN `user` u ON u.id = p.id_entregador
             WHERE p.id_restaurante = ? AND p.tipo IN ('entrega', 'retirada') AND p.status IN ($marcadores)
             ORDER BY p.criado_em, p.id"
        );
        $q->execute([$this->idRestaurante, ...self::ATIVOS]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Ultimos concluidos/cancelados (para conferir). @return list<array<string,mixed>> */
    public function recentes(int $limite = 15): array
    {
        $nd = \Cardapim\Services\Pedidos\NumeroDoDia::sql($this->db, 'p');
        $q = $this->db->prepare(
            "SELECT p.id, $nd AS numero, p.tipo, p.status, p.total, p.cliente_nome, p.concluido_em, p.cancelado_em, CONCAT(u.nome, ' ', u.sobrenome) AS entregador
             FROM pedidos p LEFT JOIN `user` u ON u.id = p.id_entregador
             WHERE p.id_restaurante = ? AND p.tipo IN ('entrega', 'retirada') AND p.status IN ('concluido', 'cancelado')
             ORDER BY p.atualizado_em DESC, p.id DESC LIMIT " . max(1, min(50, $limite))
        );
        $q->execute([$this->idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{pedido:array<string,mixed>, itens:list<array<string,mixed>>, eventos:list<array<string,mixed>>, entregador:?string}|null */
    public function detalhes(int $id): ?array
    {
        $p = $this->pedidos->buscar($this->idRestaurante, $id);
        if ($p === null || !in_array($p['tipo'], ['entrega', 'retirada'], true)) {
            return null;
        }
        $entregador = null;
        if ($p['id_entregador'] !== null) {
            $q = $this->db->prepare("SELECT CONCAT(nome, ' ', sobrenome) FROM `user` WHERE id = ?");
            $q->execute([(int) $p['id_entregador']]);
            $entregador = (string) $q->fetchColumn();
        }
        $posicao = null;
        if ($p['id_entregador'] !== null && $p['status'] === 'saiu_entrega') {
            $q = $this->db->prepare('SELECT latitude, longitude, atualizado_em FROM entregador_posicoes WHERE id_user = ? AND id_restaurante = ?');
            $q->execute([(int) $p['id_entregador'], $this->idRestaurante]);
            $posicao = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return ['pedido' => $p, 'itens' => $this->pedidos->itens($this->idRestaurante, $id), 'eventos' => $this->pedidos->eventos($this->idRestaurante, $id), 'entregador' => $entregador, 'posicao' => $posicao];
    }

    /** Entregadores (motoqueiros) ativos do restaurante. @return list<array{id:int,nome:string,em_rota:int}> */
    public function entregadores(): array
    {
        $q = $this->db->prepare(
            "SELECT u.id, CONCAT(u.nome, ' ', u.sobrenome) AS nome,
                    (SELECT COUNT(*) FROM pedidos p WHERE p.id_entregador = u.id AND p.id_restaurante = u.id_restaurante AND p.status = 'saiu_entrega') AS em_rota
             FROM `user` u
             WHERE u.id_restaurante = ? AND u.cargo = 'motoqueiro' AND u.ativo = 1 AND u.excluido_em IS NULL
             ORDER BY u.nome, u.sobrenome"
        );
        $q->execute([$this->idRestaurante]);
        return array_map(static fn (array $e): array => ['id' => (int) $e['id'], 'nome' => (string) $e['nome'], 'em_rota' => (int) $e['em_rota']], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function avancar(int $id, string $acao): Resultado
    {
        if (!isset(self::ACOES[$acao])) {
            return Resultado::falha('Ação desconhecida.');
        }
        [$de, $para, $texto] = self::ACOES[$acao];
        $p = $this->pedido($id);
        if ($p instanceof Resultado) {
            return $p;
        }
        if (!in_array($p['status'], $de, true)) {
            return Resultado::falha('Este pedido já mudou de situação. Confira na tela.');
        }
        // Pix pela chave ainda nao confirmado: pode aceitar e preparar; a producao sai com "SOLICITAR COMPROVANTE".
        $ifood = $this->ifoodAtivo($p);
        $this->mudar($p, $de, $para, $texto, [], $ifood ? function () use ($p, $acao): void {
            if ($p['status'] === 'recebido') {
                IfoodAcoes::enfileirar($this->db, $this->idRestaurante, (int) $p['id'], 'confirmar');
            }
            if ($acao === 'pronto') {
                IfoodAcoes::enfileirar($this->db, $this->idRestaurante, (int) $p['id'], 'pronto');
            }
        } : null);
        if ($ifood) {
            IfoodAcoes::agendarEnvio($this->idRestaurante);
        }
        if ($acao === 'aceitar') {
            // Vias de producao (Churrasqueira, Cozinha, Bar...) saem quando o pedido e aceito. Nunca impede o aceite.
            (new \Cardapim\Services\Impressao\FilaImpressao($this->db, $this->idRestaurante, (string) ($this->autor->restauranteNome ?? ''), $this->autor))->producaoPedido($id);
        }
        return Resultado::sucesso('Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . ': ' . mb_strtolower(rtrim($texto, '.')) . '.' . ($ifood ? ' O iFood foi avisado.' : ''));
    }

    /**
     * "Recebi o comprovante, confirmar Pix": o restaurante conferiu o Pix pela chave no banco e o pedido fica pago.
     * Ainda "recebido": tambem aceita (vai para o preparo e a producao imprime). Depois do aceite: so marca o pagamento.
     */
    public function confirmarPix(int $id): Resultado
    {
        $p = $this->pedido($id);
        if ($p instanceof Resultado) {
            return $p;
        }
        if (!self::pixParaConferir($p)) {
            return Resultado::falha('Este pedido já mudou de situação. Confira na tela.');
        }
        $agora = date('Y-m-d H:i:s');
        $aceitar = $p['status'] === 'recebido';
        try {
            if ($aceitar) {
                $this->mudar($p, ['recebido'], 'em_preparo', 'Pix conferido pelo restaurante. Pedido aceito. Em preparo.', ['pagamento_status' => 'pago', 'pago_em' => $agora]);
            } else {
                // Mesma etapa: so o pagamento muda (o cliente nao recebe aviso de etapa no WhatsApp).
                $this->mudar($p, [(string) $p['status']], (string) $p['status'], 'Pix conferido pelo restaurante. Pagamento confirmado.',
                    ['pagamento_status' => 'pago', 'pago_em' => $agora], null, false);
            }
        } catch (\RuntimeException) {
            return Resultado::falha('Este pedido já mudou de situação. Confira na tela.');
        }
        if ($aceitar) {
            (new \Cardapim\Services\Impressao\FilaImpressao($this->db, $this->idRestaurante, (string) ($this->autor->restauranteNome ?? ''), $this->autor))->producaoPedido($id);
        }
        Auditoria::registrar($this->autor, 'Pix pela chave conferido', sprintf('Pedido #%d: Pix de %s conferido no banco%s.', \Cardapim\Services\Pedidos\NumeroDoDia::de($p),
            Numero::reais((string) $p['total']), $aceitar ? ' e pedido aceito' : ''));
        return Resultado::sucesso('Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . ': Pix confirmado.' . ($aceitar ? ' Pedido aceito. Em preparo.' : ''));
    }

    public function despachar(int $id, int $idEntregador): Resultado
    {
        $p = $this->pedido($id);
        if ($p instanceof Resultado) {
            return $p;
        }
        if ($p['tipo'] !== 'entrega') {
            return Resultado::falha('Pedido para retirar no local não sai para entrega.');
        }
        if (!in_array($p['status'], ['recebido', 'em_preparo', 'pronto'], true)) {
            return Resultado::falha('Este pedido não pode sair para entrega agora.');
        }
        if ($p['origem'] === 'ifood' && $p['ifood_entregue_por'] === 'ifood') {
            return Resultado::falha('O entregador do iFood vai buscar este pedido. Ele sai sozinho daqui quando o entregador pegar.');
        }
        $entregador = null;
        foreach ($this->entregadores() as $e) {
            if ($e['id'] === $idEntregador) {
                $entregador = $e;
            }
        }
        if ($entregador === null) {
            return Resultado::falha('Escolha um entregador da sua equipe.', ['entregador' => 'Escolha quem vai levar.']);
        }
        $ifood = $this->ifoodAtivo($p);
        $this->mudar($p, ['recebido', 'em_preparo', 'pronto'], 'saiu_entrega', 'Saiu para entrega com ' . $entregador['nome'] . '.',
            ['id_entregador' => $idEntregador, 'despachado_em' => date('Y-m-d H:i:s')], $ifood ? function () use ($p): void {
                if ($p['status'] === 'recebido') {
                    IfoodAcoes::enfileirar($this->db, $this->idRestaurante, (int) $p['id'], 'confirmar');
                }
                if ($p['status'] !== 'pronto') {
                    IfoodAcoes::enfileirar($this->db, $this->idRestaurante, (int) $p['id'], 'pronto');
                }
                IfoodAcoes::enfileirar($this->db, $this->idRestaurante, (int) $p['id'], 'despachar');
            } : null);
        if ($ifood) {
            IfoodAcoes::agendarEnvio($this->idRestaurante);
        }
        TempoReal::publicar($this->idRestaurante, 'entregas', 'entrega.despachada', ['id' => $id, 'entregador' => $idEntregador]);
        // Casa do cliente no mapa: se o pedido veio sem coordenada (area por bairro), busca agora, uma vez so.
        \Cardapim\Services\Entregas\DestinoEntrega::garantir($this->db, $p);
        Auditoria::registrar($this->autor, 'Pedido despachado', sprintf('Pedido #%d para %s com %s.', \Cardapim\Services\Pedidos\NumeroDoDia::de($p), $p['entrega_bairro'] ?? 'entrega', $entregador['nome']));
        return Resultado::sucesso('Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . ' saiu com ' . $entregador['nome'] . '.');
    }

    /** Entregue (ou retirado): conclui e registra o pagamento no caixa aberto. */
    public function concluir(int $id): Resultado
    {
        $p = $this->pedido($id);
        if ($p instanceof Resultado) {
            return $p;
        }
        $de = $p['tipo'] === 'entrega' ? ['saiu_entrega'] : ['pronto', 'em_preparo', 'recebido'];
        if (!in_array($p['status'], $de, true)) {
            return Resultado::falha($p['tipo'] === 'entrega' ? 'Despache o pedido para um entregador antes de marcar como entregue.' : 'Este pedido já mudou de situação.');
        }
        if (self::pixParaConferir($p)) {
            return Resultado::falha('O Pix deste pedido ainda não foi confirmado. Confira o comprovante e toque em "Confirmar Pix" antes de concluir.');
        }
        if ($p['forma_pagamento'] === 'pix_online' && $p['pagamento_status'] !== 'pago') {
            // QR do Asaas gerado na entrega e ainda nao pago: nao entra no caixa como recebido.
            return Resultado::falha('O Pix por QR Code ainda não caiu. Espere a confirmação na tela do Pix ou troque a forma de pagamento.');
        }
        $forma = (string) $p['forma_pagamento'];
        $jaPago = $p['pagamento_status'] === 'pago';
        $caixa = (new CaixaRepository($this->db))->aberto($this->idRestaurante);
        if ($caixa === null && !$jaPago) {
            return Resultado::falha('Abra o caixa para receber o pagamento deste pedido.');
        }
        $total = Numero::centavos((string) $p['total']);
        $troco = $forma === 'dinheiro' && $p['troco_para'] !== null ? max(0, Numero::centavos((string) $p['troco_para']) - $total) : 0;
        $agora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $u = $this->db->prepare("UPDATE pedidos SET status = 'concluido', pagamento_status = 'pago', pago_em = COALESCE(pago_em, ?), concluido_em = ?, id_caixa = ?, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ? AND status = ?");
            $u->execute([$agora, $agora, $caixa !== null ? (int) $caixa['id'] : null, $agora, $id, $this->idRestaurante, $p['status']]);
            if ($u->rowCount() !== 1) {
                $this->db->rollBack();
                return Resultado::falha('Este pedido já mudou de situação. Confira na tela.');
            }
            // Pago no caixa na hora (entrega feita no balcao): o valor ja entrou no caixa, nao registra de novo.
            $registrou = false;
            $jaNoCaixa = ComandaService::totalPago($this->db, $this->idRestaurante, $id) > 0;
            if ($total > 0 && isset(self::FORMA_CAIXA[$forma]) && !$jaNoCaixa) {
                $registrou = true;
                // O total ja traz a taxa da forma (cardapio online ou balcao que virou entrega): o pagamento guarda quanto dele e taxa.
                $taxaPedido = min(Numero::centavos((string) ($p['taxa_pagamento'] ?? '0')), max(0, $total - 1));
                if (Esquema::tem($this->db, 'taxas_contas') && $taxaPedido > 0 && self::FORMA_CAIXA[$forma] !== 'dinheiro') {
                    $this->db->prepare('INSERT INTO pedido_pagamentos (id_pedido, id_restaurante, id_caixa, forma, valor, taxa, taxa_rotulo, troco, id_user, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$id, $this->idRestaurante, $caixa !== null ? (int) $caixa['id'] : null, self::FORMA_CAIXA[$forma], (string) $p['total'],
                            CaixaService::dec($taxaPedido), $p['taxa_pagamento_rotulo'] ?? null, CaixaService::dec($troco), $this->autor->id, $agora]);
                } else {
                    $this->db->prepare('INSERT INTO pedido_pagamentos (id_pedido, id_restaurante, id_caixa, forma, valor, troco, id_user, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$id, $this->idRestaurante, $caixa !== null ? (int) $caixa['id'] : null, self::FORMA_CAIXA[$forma], (string) $p['total'],
                            CaixaService::dec($troco), $this->autor->id, $agora]);
                }
            }
            $texto = ($p['tipo'] === 'entrega' ? 'Pedido entregue.' : 'Pedido retirado pelo cliente.')
                . ($registrou ? ' ' . self::MARCA_PAGO_NA_CONCLUSAO . ': ' . Numero::reais((string) $p['total']) . '.' : ($jaNoCaixa ? ' ' . self::MARCA_JA_NO_CAIXA : ''));
            $this->pedidos->registrarEvento($this->idRestaurante, $id, 'concluido', $texto, $this->autor->id);
            AvisosWhatsApp::enfileirar($this->db, $this->idRestaurante, $id, 'concluido');
            TempoReal::registrar($this->db, $this->idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => $id, 'numero' => \Cardapim\Services\Pedidos\NumeroDoDia::de($p), 'status' => 'concluido']);
            if ($caixa !== null) {
                TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.pagamento', ['caixa' => (int) $caixa['id']]);
            }
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        \Cardapim\Services\Fiscal\FiscalEmissao::solicitar($this->db, $this->idRestaurante, $id);
        return Resultado::sucesso('Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . ' concluído.' . ($jaPago ? '' : ' Pagamento de ' . Numero::reais((string) $p['total']) . ' no caixa.'));
    }

    public function cancelar(int $id, string $motivo, string $codigoIfood = ''): Resultado
    {
        $p = $this->pedido($id);
        if ($p instanceof Resultado) {
            return $p;
        }
        if ($this->ifoodAtivo($p)) {
            return $this->pedirCancelamentoIfood($p, $codigoIfood, $motivo);
        }
        $motivo = trim((string) preg_replace('/\s+/u', ' ', $motivo));
        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 140) {
            return Resultado::falha('Conte o motivo do cancelamento (o cliente vê esta mensagem).');
        }
        if (!(new CancelarPedido($this->db))->cancelar($this->idRestaurante, $id, 'Cancelado pelo restaurante: ' . $motivo, $this->autor->id)) {
            return Resultado::falha('Este pedido já foi concluído ou cancelado.');
        }
        $aviso = $p['origem'] === 'ifood' ? ' Cancele também no Gestor de Pedidos do iFood (a integração está desligada).'
            : ($p['pagamento_status'] === 'pago' ? (ComandaService::totalPago($this->db, $this->idRestaurante, $id) > 0
                ? ' Ele já estava pago no caixa: devolva o valor ao cliente e registre a saída em Entradas e saídas.'
                : ' Ele já estava pago: devolva o valor ao cliente pelo Asaas.') : '');
        Auditoria::registrar($this->autor, 'Pedido cancelado', sprintf('Pedido #%d (%s). Motivo: %s. Estoque devolvido.%s', \Cardapim\Services\Pedidos\NumeroDoDia::de($p), Numero::reais((string) $p['total']), $motivo, $aviso));
        return Resultado::sucesso('Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . ' cancelado. O estoque foi devolvido.' . $aviso);
    }

    /**
     * Botao "Voltar etapa" da tela: para onde volta, o aviso do que acontece e, se nao puder, o porque.
     * @param array<string,mixed> $p
     * @return array{para:string, rotulo:string, bloqueio:?string, aviso:?string}|null
     */
    public function opcaoVoltar(array $p): ?array
    {
        $para = self::etapaAnterior($p);
        if ($para === null) {
            return null;
        }
        $aviso = null;
        if ($p['status'] === 'concluido') {
            $valor = array_sum(array_map(static fn (array $pg): int => Numero::centavos((string) $pg['valor']), $this->pagamentosDaConclusao($p)));
            $aviso = $valor > 0
                ? 'O pagamento de ' . Numero::reais(CaixaService::dec($valor)) . ' sai do caixa e o pedido volta a ficar a receber.'
                : 'O pedido volta para ' . mb_strtolower(StatusPedido::rotulo($para, (string) $p['tipo'])) . '.';
        } elseif ($p['status'] === 'saiu_entrega') {
            $aviso = 'O pedido sai do painel do entregador e volta para Pronto.';
        }
        return ['para' => $para, 'rotulo' => StatusPedido::rotulo($para, (string) $p['tipo']), 'bloqueio' => $this->bloqueioVoltar($p), 'aviso' => $aviso];
    }

    /** "Voltar etapa": o pedido volta para a etapa anterior. Motivo opcional, so para a equipe (fica na linha do tempo). */
    public function voltar(int $id, string $motivo): Resultado
    {
        $p = $this->pedidos->buscar($this->idRestaurante, $id);
        if ($p === null || !in_array($p['tipo'], ['entrega', 'retirada'], true)) {
            return Resultado::falha('Pedido não encontrado.');
        }
        $para = self::etapaAnterior($p);
        if ($para === null) {
            return Resultado::falha('Este pedido não tem etapa para voltar.');
        }
        $bloqueio = $this->bloqueioVoltar($p);
        if ($bloqueio !== null) {
            return Resultado::falha($bloqueio);
        }
        $motivo = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $motivo)), 0, 140);
        $tipo = (string) $p['tipo'];
        $rotulo = StatusPedido::rotulo($para, $tipo);
        $texto = 'Voltou para ' . $rotulo . ' (desfeito por ' . $this->autor->nome . ').' . ($motivo !== '' ? ' Motivo: ' . $motivo : '');
        if ($p['status'] === 'concluido') {
            return $this->desfazerConclusao($p, $para, $texto, $motivo);
        }
        $extra = $p['status'] === 'saiu_entrega' ? ['id_entregador' => null, 'despachado_em' => null] : [];
        try {
            $this->mudar($p, [(string) $p['status']], $para, $texto, $extra, null, false);
        } catch (\RuntimeException) {
            return Resultado::falha('Este pedido já mudou de situação. Confira na tela.');
        }
        if ($p['status'] === 'saiu_entrega' && $p['id_entregador'] !== null) {
            $this->liberarEntregador((int) $p['id_entregador'], $id);
        }
        Auditoria::registrar($this->autor, 'Pedido voltou de etapa', sprintf('Pedido #%d: de %s para %s.%s', \Cardapim\Services\Pedidos\NumeroDoDia::de($p),
            StatusPedido::rotulo((string) $p['status'], $tipo), $rotulo, $motivo !== '' ? ' Motivo: ' . $motivo : ''));
        return Resultado::sucesso('Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . ' voltou para ' . mb_strtolower($rotulo) . '.');
    }

    /** Por que o pedido nao pode voltar de etapa (null: pode). @param array<string,mixed> $p */
    private function bloqueioVoltar(array $p): ?string
    {
        if ($this->ifoodAtivo($p)) {
            return 'O iFood não aceita voltar a etapa. Ajuste no Gestor de Pedidos do iFood.';
        }
        if ($p['origem'] === 'ifood' && ($p['ifood_entregue_por'] ?? null) === 'ifood' && in_array($p['status'], ['saiu_entrega', 'concluido'], true)) {
            return 'Este pedido foi levado pelo entregador do iFood. Não dá para voltar a etapa.';
        }
        if ($p['status'] !== 'concluido') {
            return null;
        }
        if (!in_array($this->autor->cargo, ['gerente', 'adm_master'], true)) {
            return 'Só o gerente pode desfazer um pedido já ' . ($p['tipo'] === 'entrega' ? 'entregue' : 'retirado') . ', porque mexe no dinheiro do caixa.';
        }
        $nota = FiscalEmissao::situacao($this->db, $this->idRestaurante, (int) $p['id']);
        if ($nota !== null && ($nota['numero'] !== null || !in_array($nota['status'], ['pendente', 'bloqueada', 'rejeitada'], true))) {
            return 'A nota fiscal deste pedido já foi emitida. Não dá para voltar a etapa.';
        }
        $caixa = (new CaixaRepository($this->db))->aberto($this->idRestaurante);
        foreach ($this->pagamentosDaConclusao($p) as $pg) {
            if ($pg['id_caixa'] !== null && ($caixa === null || (int) $pg['id_caixa'] !== (int) $caixa['id'])) {
                return 'O caixa em que este pedido foi recebido já foi fechado. Faça o ajuste em Entradas e saídas.';
            }
        }
        return null;
    }

    /**
     * Pagamentos lancados no caixa quando o pedido foi concluido (o pagamento feito antes, no balcao, nao entra).
     * @param array<string,mixed> $p @return list<array<string,mixed>>
     */
    private function pagamentosDaConclusao(array $p, bool $travar = false): array
    {
        if (($p['concluido_em'] ?? null) === null) {
            return [];
        }
        // A linha do tempo da conclusao diz se o pagamento entrou no caixa nela. Pedido concluido antes dessa marca:
        // vale o horario (pagamento lancado no momento da conclusao ou depois).
        $q = $this->db->prepare("SELECT descricao FROM pedido_eventos WHERE id_pedido = ? AND id_restaurante = ? AND status = 'concluido' ORDER BY id DESC LIMIT 1");
        $q->execute([(int) $p['id'], $this->idRestaurante]);
        if (str_contains((string) $q->fetchColumn(), self::MARCA_JA_NO_CAIXA)) {
            return [];
        }
        $q = $this->db->prepare('SELECT id, id_caixa, forma, valor FROM pedido_pagamentos WHERE id_pedido = ? AND id_restaurante = ? AND criado_em >= ?' . ($travar ? ' FOR UPDATE' : ''));
        $q->execute([(int) $p['id'], $this->idRestaurante, $p['concluido_em']]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Entregue/retirado volta uma etapa: tira do caixa o pagamento lancado na conclusao e tira a nota da fila
     * (se ainda nao saiu). Tudo numa transacao, com o caixa travado para nao fechar no meio. @param array<string,mixed> $p
     */
    private function desfazerConclusao(array $p, string $para, string $texto, string $motivo): Resultado
    {
        $id = (int) $p['id'];
        $tipo = (string) $p['tipo'];
        $pagamentos = [];
        $idCaixa = false;
        $this->db->beginTransaction();
        try {
            $c = $this->db->prepare("SELECT id FROM caixas WHERE id_restaurante = ? AND status = 'aberto' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $c->execute([$this->idRestaurante]);
            $idCaixa = $c->fetchColumn();
            $pagamentos = $this->pagamentosDaConclusao($p, true);
            foreach ($pagamentos as $pg) {
                if ($pg['id_caixa'] !== null && ($idCaixa === false || (int) $pg['id_caixa'] !== (int) $idCaixa)) {
                    $this->db->rollBack();
                    return Resultado::falha('O caixa em que este pedido foi recebido já foi fechado. Faça o ajuste em Entradas e saídas.');
                }
            }
            $ids = array_map(static fn (array $pg): int => (int) $pg['id'], $pagamentos);
            if ($ids !== []) {
                $marcadores = implode(',', array_fill(0, count($ids), '?'));
                $this->db->prepare("DELETE FROM pedido_pagamentos WHERE id_restaurante = ? AND id_pedido = ? AND id IN ($marcadores)")
                    ->execute([$this->idRestaurante, $id, ...$ids]);
            }
            // Ja estava pago antes de concluir (Pix pelo app e Pix conferido sao pagos antes; pago no balcao): continua pago.
            $pagoAntes = $p['pago_em'] !== null && (in_array($p['forma_pagamento'], ['pix_online', 'pix_comprovante', 'ifood'], true)
                || (string) $p['pago_em'] < (string) $p['concluido_em']);
            $sobrou = ComandaService::totalPago($this->db, $this->idRestaurante, $id) > 0;
            $pago = $pagoAntes || $sobrou;
            $u = $this->db->prepare("UPDATE pedidos SET status = ?, concluido_em = NULL, pagamento_status = ?, pago_em = ?, id_caixa = ?, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ? AND status = 'concluido'");
            $u->execute([$para, $pago ? 'pago' : 'pendente', $pago ? $p['pago_em'] : null, $sobrou ? $p['id_caixa'] : null,
                date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
            if ($u->rowCount() !== 1) {
                throw new \RuntimeException('Pedido mudou de situacao.');
            }
            if (Esquema::tem($this->db, 'fiscal_documentos')) {
                // Nota ainda na fila sai junto; ao concluir de novo ela entra outra vez. Nota emitida impede a volta.
                $this->db->prepare("DELETE FROM fiscal_documentos WHERE id_restaurante = ? AND id_pedido = ? AND numero IS NULL AND status IN ('pendente', 'bloqueada', 'rejeitada')")
                    ->execute([$this->idRestaurante, $id]);
                $resta = $this->db->prepare('SELECT 1 FROM fiscal_documentos WHERE id_restaurante = ? AND id_pedido = ?');
                $resta->execute([$this->idRestaurante, $id]);
                if ($resta->fetchColumn() !== false) {
                    $this->db->rollBack();
                    return Resultado::falha('A nota fiscal deste pedido já foi emitida. Não dá para voltar a etapa.');
                }
            }
            $this->pedidos->registrarEvento($this->idRestaurante, $id, $para, $texto, $this->autor->id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => $id, 'numero' => \Cardapim\Services\Pedidos\NumeroDoDia::de($p), 'status' => $para]);
            if ($idCaixa !== false) {
                TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.pagamento', ['caixa' => (int) $idCaixa]);
            }
            $this->db->commit();
        } catch (\RuntimeException) {
            $this->db->rollBack();
            return Resultado::falha('Este pedido já mudou de situação. Confira na tela.');
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        if ($para === 'saiu_entrega' && $p['id_entregador'] !== null) {
            TempoReal::publicar($this->idRestaurante, 'entregas', 'entrega.despachada', ['id' => $id, 'entregador' => (int) $p['id_entregador']]);
        }
        $valor = array_sum(array_map(static fn (array $pg): int => Numero::centavos((string) $pg['valor']), $pagamentos));
        $rotulo = StatusPedido::rotulo($para, $tipo);
        Auditoria::registrar($this->autor, 'Pedido voltou de etapa', sprintf('Pedido #%d: de %s para %s. %s%s', \Cardapim\Services\Pedidos\NumeroDoDia::de($p),
            StatusPedido::rotulo('concluido', $tipo), $rotulo,
            $valor > 0 ? 'Pagamento de ' . Numero::reais(CaixaService::dec($valor)) . ' removido do caixa.' : 'Nenhum pagamento removido do caixa.',
            $motivo !== '' ? ' Motivo: ' . $motivo : ''));
        return Resultado::sucesso('Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . ' voltou para ' . mb_strtolower($rotulo) . '.'
            . ($valor > 0 ? ' O pagamento de ' . Numero::reais(CaixaService::dec($valor)) . ' saiu do caixa.' : ''));
    }

    /** O pedido saiu do motoboy: atualiza o painel dele e apaga a posicao se ele nao leva mais nada (LGPD). */
    private function liberarEntregador(int $idEntregador, int $idPedido): void
    {
        TempoReal::publicar($this->idRestaurante, 'entregas', 'entrega.retirada', ['id' => $idPedido, 'entregador' => $idEntregador]);
        $q = $this->db->prepare("SELECT COUNT(*) FROM pedidos WHERE id_restaurante = ? AND id_entregador = ? AND status = 'saiu_entrega'");
        $q->execute([$this->idRestaurante, $idEntregador]);
        if ((int) $q->fetchColumn() === 0) {
            $this->db->prepare('DELETE FROM entregador_posicoes WHERE id_user = ? AND id_restaurante = ?')->execute([$idEntregador, $this->idRestaurante]);
        }
    }

    /** Pedido do iFood com a integracao ligada: as mudancas tambem vao para o iFood. @param array<string,mixed> $p */
    private function ifoodAtivo(array $p): bool
    {
        return $p['origem'] === 'ifood' && is_string($p['ifood_id'] ?? null) && $p['ifood_id'] !== ''
            && (new IfoodConta($this->db, $this->idRestaurante))->conectada();
    }

    /**
     * O cancelamento de pedido do iFood e pedido ao iFood: o pedido so e cancelado aqui (e o estoque volta)
     * quando o iFood confirmar. @param array<string,mixed> $p
     */
    private function pedirCancelamentoIfood(array $p, string $codigo, string $detalhe): Resultado
    {
        if (!isset(IfoodAcoes::MOTIVOS[$codigo])) {
            return Resultado::falha('Escolha o motivo do cancelamento (o iFood pede um da lista).', ['motivo_ifood' => 'Escolha o motivo.']);
        }
        $dados = DadosPedidoIfood::ler($p['ifood_dados'] ?? null);
        if (($dados['cancelamento'] ?? null) === 'solicitado') {
            return Resultado::falha('O cancelamento já foi pedido ao iFood. Aguarde a resposta.');
        }
        $detalhe = trim((string) preg_replace('/\s+/u', ' ', $detalhe));
        $motivo = IfoodAcoes::MOTIVOS[$codigo] . ($detalhe !== '' ? ': ' . mb_substr($detalhe, 0, 140) : '');
        $this->db->beginTransaction();
        try {
            IfoodAcoes::enfileirar($this->db, $this->idRestaurante, (int) $p['id'], 'cancelar', ['codigo' => $codigo, 'motivo' => $motivo]);
            DadosPedidoIfood::alterar($this->db, $this->idRestaurante, (int) $p['id'], static function (array $d): array {
                $d['cancelamento'] = 'solicitado';
                return $d;
            });
            $this->pedidos->registrarEvento($this->idRestaurante, (int) $p['id'], (string) $p['status'], 'Cancelamento pedido ao iFood: ' . $motivo . '.', $this->autor->id);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        IfoodAcoes::agendarEnvio($this->idRestaurante);
        Auditoria::registrar($this->autor, 'Cancelamento pedido ao iFood', sprintf('Pedido #%d (%s). Motivo: %s.', \Cardapim\Services\Pedidos\NumeroDoDia::de($p), Numero::reais((string) $p['total']), $motivo));
        return Resultado::sucesso('Pedimos ao iFood para cancelar o pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . '. Ele é cancelado aqui (e o estoque volta) quando o iFood confirmar, em poucos segundos.');
    }

    /** @return array<string,mixed>|Resultado */
    private function pedido(int $id): array|Resultado
    {
        $p = $this->pedidos->buscar($this->idRestaurante, $id);
        if ($p === null || !in_array($p['tipo'], ['entrega', 'retirada'], true)) {
            return Resultado::falha('Pedido não encontrado.');
        }
        if ($p['status'] === 'aguardando_pagamento') {
            return Resultado::falha('Este pedido ainda espera o Pix do cliente. Ele aparece para preparar quando for pago.');
        }
        if (in_array($p['status'], ['concluido', 'cancelado'], true)) {
            return Resultado::falha('Este pedido já foi ' . ($p['status'] === 'concluido' ? 'concluído.' : 'cancelado.'));
        }
        return $p;
    }

    /**
     * @param array<string,mixed> $p
     * @param list<string> $de
     * @param array<string,mixed> $extra colunas extras (id_entregador, despachado_em)
     * @param (callable():void)|null $naTransacao gravado junto (ex.: fila de acoes do iFood)
     * @param bool $avisarCliente false em "Voltar etapa": nao manda WhatsApp
     */
    private function mudar(array $p, array $de, string $para, string $texto, array $extra, ?callable $naTransacao = null, bool $avisarCliente = true): void
    {
        $sets = 'status = ?, atualizado_em = ?';
        $valores = [$para, date('Y-m-d H:i:s')];
        foreach ($extra as $coluna => $valor) {
            $sets .= ', ' . $coluna . ' = ?';
            $valores[] = $valor;
        }
        $marcadores = implode(',', array_fill(0, count($de), '?'));
        $this->db->beginTransaction();
        try {
            $u = $this->db->prepare("UPDATE pedidos SET $sets WHERE id = ? AND id_restaurante = ? AND status IN ($marcadores)");
            $u->execute([...$valores, (int) $p['id'], $this->idRestaurante, ...$de]);
            if ($u->rowCount() !== 1) {
                throw new \RuntimeException('Pedido mudou de situacao.');
            }
            $this->pedidos->registrarEvento($this->idRestaurante, (int) $p['id'], $para, $texto, $this->autor->id);
            if ($naTransacao !== null) {
                $naTransacao();
            }
            if ($avisarCliente) {
                AvisosWhatsApp::enfileirar($this->db, $this->idRestaurante, (int) $p['id'], $para);
            }
            TempoReal::registrar($this->db, $this->idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => (int) $p['id'], 'numero' => \Cardapim\Services\Pedidos\NumeroDoDia::de($p), 'status' => $para]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
    }
}
