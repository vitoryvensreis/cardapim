<?php

declare(strict_types=1);

namespace Cardapim\Services\Caixa;

use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Ifood\DadosPedidoIfood;
use Cardapim\Services\Ifood\IfoodAcoes;
use Cardapim\Services\Ifood\IfoodConta;
use Cardapim\Services\Pedidos\AvisosWhatsApp;
use Cardapim\Services\Pedidos\CancelarPedido;
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
 */
final class DeliveryService
{
    public const ATIVOS = ['aguardando_pagamento', 'recebido', 'em_preparo', 'pronto', 'saiu_entrega'];

    /** Acao => [status de onde pode sair, status novo, texto da linha do tempo] */
    private const ACOES = [
        'aceitar' => [['recebido'], 'em_preparo', 'Pedido aceito. Em preparo.'],
        'pronto' => [['recebido', 'em_preparo'], 'pronto', 'Pedido pronto.'],
    ];

    /** Forma do pedido online -> forma registrada no caixa. */
    private const FORMA_CAIXA = ['pix_entrega' => 'pix', 'debito' => 'debito', 'credito' => 'credito', 'dinheiro' => 'dinheiro', 'pix_online' => 'pix_online', 'ifood' => 'ifood'];

    private readonly PedidoRepository $pedidos;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
        $this->pedidos = new PedidoRepository($db);
    }

    /** @return list<array<string,mixed>> */
    public function fila(): array
    {
        $marcadores = implode(',', array_fill(0, count(self::ATIVOS), '?'));
        $q = $this->db->prepare(
            "SELECT p.id, p.numero, p.tipo, p.status, p.forma_pagamento, p.pagamento_status, p.total, p.cliente_nome, p.entrega_bairro, p.criado_em,
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
        $q = $this->db->prepare(
            "SELECT p.id, p.numero, p.tipo, p.status, p.total, p.cliente_nome, p.concluido_em, p.cancelado_em, CONCAT(u.nome, ' ', u.sobrenome) AS entregador
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
        return Resultado::sucesso('Pedido #' . $p['numero'] . ': ' . mb_strtolower(rtrim($texto, '.')) . '.' . ($ifood ? ' O iFood foi avisado.' : ''));
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
        Auditoria::registrar($this->autor, 'Pedido despachado', sprintf('Pedido #%d para %s com %s.', (int) $p['numero'], $p['entrega_bairro'] ?? 'entrega', $entregador['nome']));
        return Resultado::sucesso('Pedido #' . $p['numero'] . ' saiu com ' . $entregador['nome'] . '.');
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
            if ($total > 0 && isset(self::FORMA_CAIXA[$forma])) {
                $this->db->prepare('INSERT INTO pedido_pagamentos (id_pedido, id_restaurante, id_caixa, forma, valor, troco, id_user, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$id, $this->idRestaurante, $caixa !== null ? (int) $caixa['id'] : null, self::FORMA_CAIXA[$forma], (string) $p['total'],
                        CaixaService::dec($troco), $this->autor->id, $agora]);
            }
            $texto = $p['tipo'] === 'entrega' ? 'Pedido entregue.' : 'Pedido retirado pelo cliente.';
            $this->pedidos->registrarEvento($this->idRestaurante, $id, 'concluido', $texto, $this->autor->id);
            AvisosWhatsApp::enfileirar($this->db, $this->idRestaurante, $id, 'concluido');
            TempoReal::registrar($this->db, $this->idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => $id, 'numero' => (int) $p['numero'], 'status' => 'concluido']);
            if ($caixa !== null) {
                TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.pagamento', ['caixa' => (int) $caixa['id']]);
            }
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        return Resultado::sucesso('Pedido #' . $p['numero'] . ' concluído.' . ($jaPago ? '' : ' Pagamento de ' . Numero::reais((string) $p['total']) . ' no caixa.'));
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
            : ($p['pagamento_status'] === 'pago' ? ' Ele já estava pago: devolva o valor ao cliente pelo Asaas.' : '');
        Auditoria::registrar($this->autor, 'Pedido cancelado', sprintf('Pedido #%d (%s). Motivo: %s. Estoque devolvido.%s', (int) $p['numero'], Numero::reais((string) $p['total']), $motivo, $aviso));
        return Resultado::sucesso('Pedido #' . $p['numero'] . ' cancelado. O estoque foi devolvido.' . $aviso);
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
        Auditoria::registrar($this->autor, 'Cancelamento pedido ao iFood', sprintf('Pedido #%d (%s). Motivo: %s.', (int) $p['numero'], Numero::reais((string) $p['total']), $motivo));
        return Resultado::sucesso('Pedimos ao iFood para cancelar o pedido #' . $p['numero'] . '. Ele é cancelado aqui (e o estoque volta) quando o iFood confirmar, em poucos segundos.');
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
     */
    private function mudar(array $p, array $de, string $para, string $texto, array $extra, ?callable $naTransacao = null): void
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
            AvisosWhatsApp::enfileirar($this->db, $this->idRestaurante, (int) $p['id'], $para);
            TempoReal::registrar($this->db, $this->idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => (int) $p['id'], 'numero' => (int) $p['numero'], 'status' => $para]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
    }
}
