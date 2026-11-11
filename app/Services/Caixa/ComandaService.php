<?php

declare(strict_types=1);

namespace Cardapim\Services\Caixa;

use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Modules\Inventory\EstoqueInsuficiente;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\Caixa\MesaRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Pedidos\CancelarPedido;
use Cardapim\Shared\Numero;
use PDO;
use PDOException;
use Throwable;

/**
 * Comanda = pedido aberto (status 'aberto') de uma MESA (tipo 'mesa', a mesa e o "cliente") ou do BALCAO.
 *
 * - Cada item lancado baixa o estoque NA HORA (ficha tecnica) e guarda o que baixou no proprio item.
 *   Item cancelado devolve exatamente esse consumo. Comanda cancelada devolve tudo.
 * - Total = itens + taxa de servico (percentual) - desconto (com motivo). Recalculado no servidor a cada mudanca.
 * - Pagamento em uma ou varias formas, sempre no caixa aberto. Pagou tudo: comanda concluida e mesa livre.
 */
final class ComandaService
{
    public const SERVICO_PADRAO = '10.00';
    public const FORMAS_PRESENCIAIS = ['dinheiro' => 'Dinheiro', 'debito' => 'Débito', 'credito' => 'Crédito', 'pix' => 'Pix'];

    private readonly PedidoRepository $pedidos;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
        $this->pedidos = new PedidoRepository($db);
    }

    /** Abre (ou devolve a ja aberta) a comanda da mesa. */
    public function abrirMesa(int $idMesa): Resultado
    {
        $mesa = (new MesaRepository($this->db))->buscar($this->idRestaurante, $idMesa);
        if ($mesa === null) {
            return Resultado::falha('Mesa não encontrada. Ela pode ter sido excluída.');
        }
        $aberta = $this->abertaDaMesa($idMesa);
        if ($aberta !== null) {
            return Resultado::sucesso('', $aberta);
        }
        if ((int) $mesa['ativo'] !== 1) {
            return Resultado::falha('Esta mesa está pausada. Ative em "Gerenciar mesas" para usar.');
        }
        try {
            $id = $this->criar('mesa', $idMesa, MesaService::rotulo($mesa), self::SERVICO_PADRAO);
        } catch (PDOException $erro) {
            // Outro aparelho abriu a mesma mesa no mesmo instante (regra "uma comanda aberta por mesa" do banco).
            $aberta = $this->abertaDaMesa($idMesa);
            if ($aberta !== null) {
                return Resultado::sucesso('', $aberta);
            }
            throw $erro;
        }
        return Resultado::sucesso(MesaService::rotulo($mesa) . ' aberta.', $id);
    }

    public function abrirBalcao(string $nome): Resultado
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        if (mb_strlen($nome) > 60) {
            return Resultado::falha('Use até 60 caracteres no nome.');
        }
        $id = $this->criar('balcao', null, $nome !== '' ? $nome : null, '0.00');
        return Resultado::sucesso('Venda no balcão aberta.', $id);
    }

    /** Comanda com itens, pagamentos e contas prontas (centavos). @return array<string,mixed>|null */
    public function detalhes(int $id): ?array
    {
        $p = $this->pedidos->buscar($this->idRestaurante, $id);
        if ($p === null || !in_array($p['tipo'], ['mesa', 'balcao'], true)) {
            return null;
        }
        $q = $this->db->prepare(
            "SELECT i.id, i.id_produto, i.nome, i.preco_unitario, i.quantidade, i.observacao, i.total, i.criado_em, i.cancelado_em, i.motivo_cancelamento,
                    u.nome AS autor
             FROM pedido_itens i LEFT JOIN `user` u ON u.id = i.id_user
             WHERE i.id_pedido = ? AND i.id_restaurante = ? ORDER BY i.id"
        );
        $q->execute([$id, $this->idRestaurante]);
        $itens = $q->fetchAll(PDO::FETCH_ASSOC);
        $pagamentos = $this->pagamentos($id);
        $pago = array_sum(array_map(static fn (array $pg): int => Numero::centavos((string) $pg['valor']), $pagamentos));
        $mesa = $p['id_mesa'] !== null ? (new MesaRepository($this->db))->buscarQualquer($this->idRestaurante, (int) $p['id_mesa']) : null;
        return [
            'pedido' => $p,
            'mesa' => $mesa,
            'rotulo' => $p['tipo'] === 'mesa' && $mesa !== null ? MesaService::rotulo($mesa) : 'Balcão' . ($p['cliente_nome'] ? ' · ' . $p['cliente_nome'] : ''),
            'itens' => $itens,
            'pagamentos' => $pagamentos,
            'subtotal' => Numero::centavos((string) $p['subtotal']),
            'servico' => Numero::centavos((string) $p['taxa_servico']),
            'desconto' => Numero::centavos((string) $p['desconto']),
            'total' => Numero::centavos((string) $p['total']),
            'pago' => $pago,
            'restante' => max(0, Numero::centavos((string) $p['total']) - $pago),
            'aberta' => $p['status'] === 'aberto',
        ];
    }

    /** Comandas de balcao abertas (para a tela de mesas). @return list<array<string,mixed>> */
    public function balcoesAbertos(): array
    {
        $q = $this->db->prepare("SELECT id, numero, cliente_nome, total, criado_em FROM pedidos WHERE id_restaurante = ? AND tipo = 'balcao' AND status = 'aberto' ORDER BY id");
        $q->execute([$this->idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function lancar(int $id, int $idProduto, int $quantidade, string $observacao): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        if ($quantidade < 1 || $quantidade > 99) {
            return Resultado::falha('Quantidade de 1 a 99.');
        }
        $observacao = trim((string) preg_replace('/\s+/u', ' ', $observacao));
        if (mb_strlen($observacao) > 140) {
            return Resultado::falha('Observação com até 140 caracteres.');
        }
        $q = $this->db->prepare('SELECT id, nome, preco, disponivel FROM produtos WHERE id = ? AND id_restaurante = ? AND ativo = 1');
        $q->execute([$idProduto, $this->idRestaurante]);
        $produto = $q->fetch(PDO::FETCH_ASSOC);
        if ($produto === false) {
            return Resultado::falha('Produto não encontrado no cardápio.');
        }
        if ((int) $produto['disponivel'] !== 1) {
            return Resultado::falha('"' . $produto['nome'] . '" está pausado no cardápio.');
        }
        $preco = Numero::centavos((string) $produto['preco']);
        $agora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            // Baixa primeiro: se faltar insumo, nada e gravado.
            $consumo = (new BaixaEstoque($this->db))->baixar($this->idRestaurante, [$idProduto => $quantidade]);
            $this->db->prepare('INSERT INTO pedido_itens (id_pedido, id_restaurante, id_produto, nome, preco_unitario, quantidade, observacao, total, id_user, consumo_estoque, criado_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $this->idRestaurante, $idProduto, mb_substr((string) $produto['nome'], 0, 180), CaixaService::dec($preco), $quantidade,
                    $observacao !== '' ? $observacao : null, CaixaService::dec($preco * $quantidade), $this->autor->id,
                    $consumo !== [] ? json_encode($consumo, JSON_THROW_ON_ERROR) : null, $agora]);
            $this->recalcular($id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (EstoqueInsuficiente $falta) {
            $this->db->rollBack();
            $nomes = implode(', ', array_map(static fn (array $f): string => $f['nome'] . ' (tem ' . self::quantidade((string) $f['disponivel']) . ')', $falta->faltas));
            return Resultado::falha('Não deu para lançar "' . $produto['nome'] . '": falta ' . $nomes . '.');
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        return Resultado::sucesso($quantidade . 'x ' . $produto['nome'] . ' lançado.');
    }

    public function cancelarItem(int $id, int $idItem, string $motivo): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $motivo = trim((string) preg_replace('/\s+/u', ' ', $motivo));
        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 140) {
            return Resultado::falha('Conte o motivo do cancelamento (ex.: cliente desistiu, lançado errado).');
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id, nome, quantidade, total, consumo_estoque FROM pedido_itens WHERE id = ? AND id_pedido = ? AND id_restaurante = ? AND cancelado_em IS NULL');
            $q->execute([$idItem, $id, $this->idRestaurante]);
            $item = $q->fetch(PDO::FETCH_ASSOC);
            if ($item === false) {
                $this->db->rollBack();
                return Resultado::falha('Item não encontrado. Ele pode já ter sido cancelado.');
            }
            $agora = date('Y-m-d H:i:s');
            $this->db->prepare('UPDATE pedido_itens SET cancelado_em = ?, motivo_cancelamento = ?, id_user_cancelou = ?, consumo_estoque = NULL WHERE id = ? AND id_restaurante = ?')
                ->execute([$agora, $motivo, $this->autor->id, $idItem, $this->idRestaurante]);
            $consumo = json_decode((string) ($item['consumo_estoque'] ?? ''), true);
            if (is_array($consumo) && $consumo !== []) {
                (new BaixaEstoque($this->db))->devolver($this->idRestaurante, array_map('strval', $consumo));
            }
            $this->recalcular($id);
            if (Numero::centavos((string) $this->pedidos->buscar($this->idRestaurante, $id)['total']) < $this->somaPagamentos($id)) {
                $this->db->rollBack();
                return Resultado::falha('A conta ficaria menor que o que já foi pago. Remova um pagamento antes de cancelar este item.');
            }
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        Auditoria::registrar($this->autor, 'Item cancelado', sprintf('%s: %dx %s (%s). Motivo: %s. Estoque devolvido.',
            $this->rotulo($comanda), (int) $item['quantidade'], $item['nome'], Numero::reais((string) $item['total']), $motivo));
        return Resultado::sucesso($item['quantidade'] . 'x ' . $item['nome'] . ' cancelado. O estoque foi devolvido.');
    }

    /** Taxa de servico (liga/desliga), desconto com motivo e quantas pessoas vao dividir. */
    public function ajustar(int $id, bool $servico, string $descontoTexto, string $motivo, string $pessoasTexto): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $erros = [];
        $desconto = trim($descontoTexto) === '' ? '0.00' : Numero::dinheiroParaDecimal($descontoTexto);
        $motivo = trim((string) preg_replace('/\s+/u', ' ', $motivo));
        if ($desconto === null) {
            $erros['desconto'] = 'Valor inválido.';
        } elseif (Numero::centavos($desconto) > 0 && mb_strlen($motivo) < 3) {
            $erros['motivo_desconto'] = 'Conte o motivo do desconto.';
        }
        if (mb_strlen($motivo) > 140) {
            $erros['motivo_desconto'] = 'Use até 140 caracteres.';
        }
        $pessoas = null;
        if (trim($pessoasTexto) !== '') {
            $pessoas = ctype_digit(trim($pessoasTexto)) ? (int) trim($pessoasTexto) : 0;
            if ($pessoas < 1 || $pessoas > 50) {
                $erros['pessoas'] = 'De 1 a 50 pessoas.';
            }
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $antes = $comanda;
        $percentual = $servico ? self::SERVICO_PADRAO : '0.00';
        $subtotal = Numero::centavos((string) $comanda['subtotal']);
        $taxa = self::servico($subtotal, $percentual);
        if (Numero::centavos((string) $desconto) > $subtotal + $taxa) {
            return Resultado::falha('O desconto não pode ser maior que a conta.', ['desconto' => 'Máximo ' . Numero::reais(CaixaService::dec($subtotal + $taxa)) . '.']);
        }
        if ($subtotal + $taxa - Numero::centavos((string) $desconto) < $this->somaPagamentos($id)) {
            return Resultado::falha('A conta ficaria menor que o que já foi pago. Remova um pagamento antes.');
        }
        $this->db->beginTransaction();
        try {
            // Tudo num UPDATE so: o banco confere "total = itens + servico - desconto" (CHECK) a cada comando.
            $descontoCent = Numero::centavos((string) $desconto);
            $this->db->prepare('UPDATE pedidos SET servico_percentual = ?, taxa_servico = ?, desconto = ?, total = ?, motivo_desconto = ?, pessoas = ?, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ?')
                ->execute([$percentual, CaixaService::dec($taxa), CaixaService::dec($descontoCent), CaixaService::dec($subtotal + $taxa - $descontoCent),
                    $descontoCent > 0 ? $motivo : null, $pessoas, date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
            $this->recalcular($id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        if (Numero::centavos((string) $desconto) !== Numero::centavos((string) $antes['desconto'])) {
            Auditoria::registrar($this->autor, 'Desconto na conta', sprintf('%s: desconto %s -> %s. Motivo: %s.', $this->rotulo($comanda),
                Numero::reais((string) $antes['desconto']), Numero::reais((string) $desconto), $motivo !== '' ? $motivo : '-'));
        }
        return Resultado::sucesso('Conta atualizada.');
    }

    public function transferir(int $id, int $idMesaDestino): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $mesas = new MesaRepository($this->db);
        $destino = $mesas->buscar($this->idRestaurante, $idMesaDestino);
        if ($destino === null || (int) $destino['ativo'] !== 1) {
            return Resultado::falha('Escolha uma mesa livre.');
        }
        if ($comanda['tipo'] === 'mesa' && (int) $comanda['id_mesa'] === $idMesaDestino) {
            return Resultado::falha('A comanda já está nesta mesa.');
        }
        if ($mesas->temComandaAberta($this->idRestaurante, $idMesaDestino)) {
            return Resultado::falha(MesaService::rotulo($destino) . ' já está ocupada.');
        }
        $origem = $this->rotulo($comanda);
        try {
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE pedidos SET tipo = 'mesa', origem = 'mesa', id_mesa = ?, cliente_nome = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ? AND status = 'aberto'")
                ->execute([$idMesaDestino, MesaService::rotulo($destino), date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
            $this->pedidos->registrarEvento($this->idRestaurante, $id, 'aberto', 'Transferida de ' . $origem . ' para ' . MesaService::rotulo($destino) . '.', $this->autor->id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (PDOException) {
            $this->db->rollBack();
            return Resultado::falha(MesaService::rotulo($destino) . ' acabou de ser ocupada. Escolha outra.');
        }
        Auditoria::registrar($this->autor, 'Comanda transferida', 'Pedido #' . $comanda['numero'] . ': ' . $origem . ' -> ' . MesaService::rotulo($destino) . '.');
        return Resultado::sucesso('Comanda levada para a ' . MesaService::rotulo($destino) . '.');
    }

    /**
     * Recebe um pagamento. Dinheiro: "recebido" pode ser maior que o valor e a diferenca vira troco.
     * Quando a conta fica paga, a comanda e concluida e a mesa fica livre.
     */
    public function pagar(int $id, string $forma, string $valorTexto, string $recebidoTexto): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        if (!isset(self::FORMAS_PRESENCIAIS[$forma])) {
            return Resultado::falha('Escolha a forma de pagamento.', ['forma' => 'Escolha a forma de pagamento.']);
        }
        $caixa = (new CaixaRepository($this->db))->aberto($this->idRestaurante);
        if ($caixa === null) {
            return Resultado::falha('Abra o caixa para receber pagamentos.');
        }
        $total = Numero::centavos((string) $comanda['total']);
        $restante = $total - $this->somaPagamentos($id);
        if ($total <= 0) {
            return Resultado::falha('A conta está zerada. Lance itens ou cancele a comanda.');
        }
        $valor = trim($valorTexto) === '' ? CaixaService::dec($restante) : Numero::dinheiroParaDecimal($valorTexto);
        if ($valor === null || Numero::centavos($valor) <= 0) {
            return Resultado::falha('Digite o valor a pagar.', ['valor' => 'Digite um valor maior que zero.']);
        }
        $centavos = Numero::centavos($valor);
        $troco = 0;
        if ($forma === 'dinheiro' && trim($recebidoTexto) !== '') {
            $recebido = Numero::dinheiroParaDecimal($recebidoTexto);
            if ($recebido === null || Numero::centavos($recebido) < min($centavos, $restante)) {
                return Resultado::falha('O valor recebido é menor que o valor a pagar.', ['recebido' => 'Recebido precisa ser pelo menos ' . Numero::reais(CaixaService::dec(min($centavos, $restante))) . '.']);
            }
            // Recebeu mais que a conta: so o que falta abate da conta, o resto e troco.
            $centavos = min($centavos, $restante);
            $troco = Numero::centavos($recebido) - $centavos;
        } elseif ($forma === 'dinheiro' && $centavos > $restante) {
            $troco = $centavos - $restante;
            $centavos = $restante;
        }
        if ($centavos > $restante) {
            return Resultado::falha('Valor maior que o que falta pagar (' . Numero::reais(CaixaService::dec($restante)) . ').', ['valor' => 'Falta pagar ' . Numero::reais(CaixaService::dec($restante)) . '.']);
        }
        if ($troco > 100_000) {
            return Resultado::falha('Troco muito alto. Confira o valor recebido.', ['recebido' => 'Confira o valor recebido.']);
        }
        $agora = date('Y-m-d H:i:s');
        $concluiu = false;
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO pedido_pagamentos (id_pedido, id_restaurante, id_caixa, forma, valor, troco, id_user, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $this->idRestaurante, (int) $caixa['id'], $forma, CaixaService::dec($centavos), CaixaService::dec($troco), $this->autor->id, $agora]);
            if ($this->somaPagamentos($id) >= $total) {
                $concluiu = $this->concluir($id, (int) $caixa['id'], $agora);
            }
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', $concluiu ? 'comanda.fechada' : 'comanda.atualizada', ['id' => $id]);
            TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.pagamento', ['caixa' => (int) $caixa['id']]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        $msg = self::FORMAS_PRESENCIAIS[$forma] . ' ' . Numero::reais(CaixaService::dec($centavos)) . ' recebido.' . ($troco > 0 ? ' Troco: ' . Numero::reais(CaixaService::dec($troco)) . '.' : '');
        if ($concluiu) {
            Auditoria::registrar($this->autor, 'Conta fechada', sprintf('%s: pedido #%d, total %s.', $this->rotulo($comanda), (int) $comanda['numero'], Numero::reais((string) $comanda['total'])));
            return Resultado::sucesso($msg . ' Conta fechada' . ($comanda['tipo'] === 'mesa' ? ' e mesa liberada.' : '.'), $id);
        }
        return Resultado::sucesso($msg);
    }

    public function removerPagamento(int $id, int $idPagamento): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $q = $this->db->prepare('SELECT forma, valor FROM pedido_pagamentos WHERE id = ? AND id_pedido = ? AND id_restaurante = ?');
        $q->execute([$idPagamento, $id, $this->idRestaurante]);
        $pg = $q->fetch(PDO::FETCH_ASSOC);
        if ($pg === false) {
            return Resultado::falha('Pagamento não encontrado.');
        }
        $this->db->prepare('DELETE FROM pedido_pagamentos WHERE id = ? AND id_pedido = ? AND id_restaurante = ?')->execute([$idPagamento, $id, $this->idRestaurante]);
        TempoReal::publicar($this->idRestaurante, 'caixa', 'caixa.pagamento', ['pedido' => $id]);
        Auditoria::registrar($this->autor, 'Pagamento removido', sprintf('%s: %s %s.', $this->rotulo($comanda), self::FORMAS_PRESENCIAIS[$pg['forma']] ?? $pg['forma'], Numero::reais((string) $pg['valor'])));
        return Resultado::sucesso('Pagamento removido.');
    }

    public function cancelar(int $id, string $motivo): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        // Motivo opcional (se escrever, fica no historico e no log).
        $motivo = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $motivo)), 0, 140);
        if ($motivo === '') {
            $motivo = 'Cancelada no caixa';
        }
        if ($this->somaPagamentos($id) > 0) {
            return Resultado::falha('Esta conta já tem pagamento. Remova os pagamentos antes de cancelar.');
        }
        (new CancelarPedido($this->db))->cancelar($this->idRestaurante, $id, $motivo, $this->autor->id);
        TempoReal::publicar($this->idRestaurante, 'mesas', 'comanda.fechada', ['id' => $id]);
        Auditoria::registrar($this->autor, 'Comanda cancelada', sprintf('%s: pedido #%d (%s). Motivo: %s. Estoque devolvido.',
            $this->rotulo($comanda), (int) $comanda['numero'], Numero::reais((string) $comanda['total']), $motivo));
        return Resultado::sucesso('Comanda cancelada. O estoque dos itens foi devolvido.');
    }

    /** @return list<array<string,mixed>> */
    public function pagamentos(int $id): array
    {
        $q = $this->db->prepare(
            "SELECT pg.id, pg.forma, pg.valor, pg.troco, pg.criado_em, u.nome AS autor
             FROM pedido_pagamentos pg LEFT JOIN `user` u ON u.id = pg.id_user
             WHERE pg.id_pedido = ? AND pg.id_restaurante = ? ORDER BY pg.id"
        );
        $q->execute([$id, $this->idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** "2.0" / "0.250 kg" -> "2" / "0,25 kg" (quantidade de insumo legivel na mensagem). */
    private static function quantidade(string $valor): string
    {
        return (string) preg_replace_callback('/\d+(?:\.\d+)?/', static fn (array $m): string => rtrim(rtrim(number_format((float) $m[0], 3, ',', '.'), '0'), ','), $valor);
    }

    /** Taxa de servico em centavos (arredonda meio centavo para cima). */
    public static function servico(int $subtotal, string $percentual): int
    {
        $pct = Numero::centavos($percentual); // 10.00 -> 1000
        return intdiv($subtotal * $pct + 5000, 10000);
    }

    private function criar(string $tipo, ?int $idMesa, ?string $nome, string $servico): int
    {
        $agora = date('Y-m-d H:i:s');
        $chave = bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->db->prepare('SELECT id FROM restaurantes WHERE id = ? FOR UPDATE')->execute([$this->idRestaurante]);
            }
            $n = $this->db->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM pedidos WHERE id_restaurante = ?');
            $n->execute([$this->idRestaurante]);
            $numero = (int) $n->fetchColumn();
            $this->db->prepare("INSERT INTO pedidos (id_restaurante, numero, id_cliente, id_user, id_mesa, origem, tipo, status, forma_pagamento, pagamento_status,
                    subtotal, taxa_entrega, servico_percentual, taxa_servico, desconto, total, cliente_nome, chave_idempotencia, criado_em, atualizado_em)
                VALUES (?, ?, NULL, ?, ?, ?, ?, 'aberto', NULL, 'pendente', '0.00', '0.00', ?, '0.00', '0.00', '0.00', ?, ?, ?, ?)")
                ->execute([$this->idRestaurante, $numero, $this->autor->id, $idMesa, $tipo, $tipo, $servico, $nome, $chave, $agora, $agora]);
            $id = (int) $this->pedidos->porChave($this->idRestaurante, $chave)['id'];
            $this->pedidos->registrarEvento($this->idRestaurante, $id, 'aberto', ($tipo === 'mesa' ? 'Comanda aberta' : 'Venda no balcão aberta') . ' por ' . $this->autor->nome . '.', $this->autor->id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.aberta', ['id' => $id]);
            $this->db->commit();
            return $id;
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
    }

    /** Fecha a comanda paga: forma (unica ou 'misto'), caixa, datas e linha do tempo. */
    private function concluir(int $id, int $idCaixa, string $agora): bool
    {
        return self::fecharPaga($this->db, $this->idRestaurante, $id, $idCaixa, $agora, $this->autor->id, 'Conta paga e fechada por ' . $this->autor->nome . '.');
    }

    /**
     * Fecha a comanda que ficou paga (usado aqui e pelo Pix da mesa, que pode ser confirmado sem ninguem logado).
     * Deve rodar dentro da transacao do pagamento. @return bool false se ela ja estava fechada
     */
    public static function fecharPaga(PDO $db, int $idRestaurante, int $id, int $idCaixa, string $agora, ?int $idUser, string $descricao): bool
    {
        $q = $db->prepare('SELECT DISTINCT forma FROM pedido_pagamentos WHERE id_pedido = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        $formas = $q->fetchAll(PDO::FETCH_COLUMN);
        $forma = count($formas) === 1 ? ($formas[0] === 'pix' ? 'pix_entrega' : $formas[0]) : 'misto';
        $u = $db->prepare("UPDATE pedidos SET status = 'concluido', pagamento_status = 'pago', forma_pagamento = ?, id_caixa = ?, pago_em = ?, concluido_em = ?, atualizado_em = ?
            WHERE id = ? AND id_restaurante = ? AND status = 'aberto'");
        $u->execute([$forma, $idCaixa, $agora, $agora, $agora, $id, $idRestaurante]);
        if ($u->rowCount() !== 1) {
            return false;
        }
        (new PedidoRepository($db))->registrarEvento($idRestaurante, $id, 'concluido', $descricao, $idUser);
        return true;
    }

    /** Soma do que ja foi pago na comanda, em centavos. */
    public static function totalPago(PDO $db, int $idRestaurante, int $id): int
    {
        $q = $db->prepare('SELECT valor FROM pedido_pagamentos WHERE id_pedido = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        return array_sum(array_map(static fn ($v): int => Numero::centavos((string) $v), $q->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function recalcular(int $id): void
    {
        $q = $this->db->prepare('SELECT total FROM pedido_itens WHERE id_pedido = ? AND id_restaurante = ? AND cancelado_em IS NULL');
        $q->execute([$id, $this->idRestaurante]);
        $subtotal = 0;
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $subtotal += Numero::centavos((string) $t);
        }
        $p = $this->pedidos->buscar($this->idRestaurante, $id);
        $taxa = self::servico($subtotal, (string) $p['servico_percentual']);
        $desconto = min(Numero::centavos((string) $p['desconto']), $subtotal + $taxa);
        $this->db->prepare('UPDATE pedidos SET subtotal = ?, taxa_servico = ?, desconto = ?, total = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ?')
            ->execute([CaixaService::dec($subtotal), CaixaService::dec($taxa), CaixaService::dec($desconto), CaixaService::dec($subtotal + $taxa - $desconto),
                date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
    }

    private function somaPagamentos(int $id): int
    {
        return self::totalPago($this->db, $this->idRestaurante, $id);
    }

    private function abertaDaMesa(int $idMesa): ?int
    {
        $q = $this->db->prepare("SELECT id FROM pedidos WHERE id_mesa = ? AND id_restaurante = ? AND status = 'aberto' LIMIT 1");
        $q->execute([$idMesa, $this->idRestaurante]);
        $id = $q->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<string,mixed>|Resultado */
    private function aberta(int $id): array|Resultado
    {
        $p = $this->pedidos->buscar($this->idRestaurante, $id);
        if ($p === null || !in_array($p['tipo'], ['mesa', 'balcao'], true)) {
            return Resultado::falha('Comanda não encontrada.');
        }
        if ($p['status'] !== 'aberto') {
            return Resultado::falha('Esta comanda já foi fechada.');
        }
        return $p;
    }

    /** @param array<string,mixed> $p */
    private function rotulo(array $p): string
    {
        return $p['tipo'] === 'mesa' ? (string) ($p['cliente_nome'] ?: 'Mesa') : 'Balcão' . ($p['cliente_nome'] ? ' (' . $p['cliente_nome'] . ')' : '');
    }
}
