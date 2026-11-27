<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Gerencia\TaxaPagamentoRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;
use PDO;
use Throwable;

/**
 * "Trocar forma de pagamento" no delivery (motoboy e caixa): o cliente vai pagar de outro jeito na entrega ou na
 * retirada. Nao ha pergunta no caminho normal; o botao abre um popup com a taxa de cada forma (regras do PR 1).
 *
 * - So pedido de entrega/retirada em andamento, com o pagamento pendente e fora do iFood. Motoboy: so as entregas
 *   que estao com ele ("saiu para entrega").
 * - Base da taxa = total - taxa da forma atual (itens + entrega - desconto). Dinheiro nunca tem taxa.
 * - Grava forma, taxa, rotulo, total e troco no mesmo UPDATE (CHECK do total), com o pedido travado.
 * - O servidor confere a taxa que a tela mostrou: se mudou, avisa e nao grava.
 */
final class TrocarFormaPagamento
{
    /** Formas da troca (forma do pedido => nome). "pix_entrega" = Pix so registrado; "pix_online" = QR do Asaas (motoboy). */
    public const FORMAS = [
        'dinheiro' => 'Dinheiro',
        'debito' => 'Cartão de débito',
        'credito' => 'Cartão de crédito',
        'pix_entrega' => 'Pix',
    ];

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
    }

    /** Pode trocar? null = sim; texto = por que nao. @param array<string,mixed> $p @param int|null $entregador motoboy que pede */
    public static function motivoBloqueio(array $p, ?int $entregador = null): ?string
    {
        if (!in_array($p['tipo'] ?? '', ['entrega', 'retirada'], true) || ($p['origem'] ?? '') === 'ifood' || ($p['forma_pagamento'] ?? '') === 'ifood') {
            return 'Este pedido não permite trocar a forma de pagamento.';
        }
        if (in_array($p['status'] ?? '', ['concluido', 'cancelado', 'aguardando_pagamento', 'aberto'], true)) {
            return 'Este pedido já mudou de situação. Confira na tela.';
        }
        if (($p['pagamento_status'] ?? '') !== 'pendente') {
            return 'Este pedido já está pago. Não dá para trocar a forma.';
        }
        if ($entregador !== null && ((int) ($p['id_entregador'] ?? 0) !== $entregador || ($p['status'] ?? '') !== 'saiu_entrega')) {
            return 'Esta entrega não está com você.';
        }
        return null;
    }

    /** Base da taxa: o total sem a taxa da forma atual. @param array<string,mixed> $p */
    public static function base(array $p): int
    {
        return Numero::centavos((string) $p['total']) - Numero::centavos((string) ($p['taxa_pagamento'] ?? '0'));
    }

    /**
     * Opcoes do popup, calculadas pelo servidor. $comQr = motoboy com o Asaas conectado (Pix por QR Code).
     * @param array<string,mixed> $p
     * @return array{forma_atual:string,forma_atual_rotulo:string,base:int,taxa_atual:int,opcoes:list<array<string,mixed>>}
     */
    public function opcoes(array $p, bool $comQr = false): array
    {
        $base = self::base($p);
        $regras = (new TaxaPagamentoRepository($this->db))->regras($this->idRestaurante);
        $formas = self::FORMAS + ($comQr ? ['pix_online' => 'Pix por QR Code'] : []);
        $opcoes = [];
        foreach ($formas as $forma => $rotulo) {
            $regra = $forma === 'dinheiro' ? null : ($regras[$forma] ?? null);
            $taxa = TaxaPagamento::calcular($base, $regra);
            $opcoes[] = [
                'forma' => $forma,
                'rotulo' => $rotulo,
                'taxa' => $taxa,
                'taxa_rotulo' => $taxa > 0 && $regra !== null ? TaxaPagamento::rotulo($forma, $regra) : null,
                'total' => $base + $taxa,
                'atual' => $forma === $p['forma_pagamento'],
            ];
        }
        return [
            'forma_atual' => (string) $p['forma_pagamento'],
            'forma_atual_rotulo' => StatusPedido::FORMAS[(string) $p['forma_pagamento']] ?? (string) $p['forma_pagamento'],
            'base' => $base,
            'taxa_atual' => Numero::centavos((string) ($p['taxa_pagamento'] ?? '0')),
            'opcoes' => $opcoes,
        ];
    }

    /**
     * Troca a forma. $taxaVista = taxa (centavos) que a tela mostrou; $trocoPara so em dinheiro.
     * @return Resultado id = pedido
     */
    public function trocar(int $id, string $novaForma, string $taxaVista, string $trocoPara = '', ?int $entregador = null, bool $permitirQr = false): Resultado
    {
        if (!isset(self::FORMAS[$novaForma]) && !($permitirQr && $novaForma === 'pix_online')) {
            return Resultado::falha('Escolha a nova forma de pagamento.');
        }
        $agora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->db->prepare('SELECT id FROM pedidos WHERE id = ? AND id_restaurante = ? FOR UPDATE')->execute([$id, $this->idRestaurante]);
            }
            $p = (new PedidoRepository($this->db))->buscar($this->idRestaurante, $id);
            $bloqueio = $p === null ? 'Pedido não encontrado.' : self::motivoBloqueio($p, $entregador);
            if ($bloqueio !== null) {
                $this->db->rollBack();
                return Resultado::falha($bloqueio);
            }
            $base = self::base($p);
            $regra = $novaForma === 'dinheiro' ? null : ((new TaxaPagamentoRepository($this->db))->regras($this->idRestaurante)[$novaForma] ?? null);
            $taxa = TaxaPagamento::calcular($base, $regra);
            $vista = trim($taxaVista);
            if (!ctype_digit($vista) || strlen($vista) > 9 || (int) $vista !== $taxa) {
                $this->db->rollBack();
                return Resultado::falha($taxa > 0
                    ? sprintf('A taxa mudou: %s. Receba %s do cliente e confirme de novo.', Numero::reais(self::dec($taxa)), Numero::reais(self::dec($base + $taxa)))
                    : 'A taxa mudou. Confira o novo valor e confirme de novo.', ['taxa_vista' => (string) $taxa]);
            }
            $total = $base + $taxa;
            $troco = null;
            if ($novaForma === 'dinheiro' && trim($trocoPara) !== '') {
                $trocoDec = Numero::dinheiroParaDecimal($trocoPara);
                if ($trocoDec === null || Numero::centavos($trocoDec) < $total || Numero::centavos($trocoDec) > $total + 100000) {
                    $this->db->rollBack();
                    return Resultado::falha('O troco precisa ser para um valor igual ou maior que ' . Numero::reais(self::dec($total)) . '.', ['troco_para' => 'Confira o troco.']);
                }
                $troco = $trocoDec;
            }
            $rotulo = $taxa > 0 && $regra !== null ? TaxaPagamento::rotulo($novaForma, $regra) : null;
            // QR do Asaas gerado na entrega e ainda nao pago: trocou de forma, o QR deixa de valer.
            $cobrancaAntiga = $novaForma !== 'pix_online' && !empty($p['pix_cobranca_id']) ? (string) $p['pix_cobranca_id'] : null;
            $limparPix = $cobrancaAntiga !== null ? ', pix_cobranca_id = NULL, pix_copia_cola = NULL, pix_expira_em = NULL' : '';
            if (Esquema::tem($this->db, 'taxas_pagamento')) {
                $this->db->prepare("UPDATE pedidos SET forma_pagamento = ?, taxa_pagamento = ?, taxa_pagamento_rotulo = ?, troco_para = ?,
                    total = subtotal + taxa_entrega + taxa_servico + ? - desconto, atualizado_em = ?$limparPix
                    WHERE id = ? AND id_restaurante = ? AND pagamento_status = 'pendente'")
                    ->execute([$novaForma, self::dec($taxa), $rotulo, $troco, self::dec($taxa), $agora, $id, $this->idRestaurante]);
            } else {
                $this->db->prepare("UPDATE pedidos SET forma_pagamento = ?, troco_para = ?, atualizado_em = ?$limparPix WHERE id = ? AND id_restaurante = ? AND pagamento_status = 'pendente'")
                    ->execute([$novaForma, $troco, $agora, $id, $this->idRestaurante]);
            }
            $deRotulo = StatusPedido::FORMAS[(string) $p['forma_pagamento']] ?? (string) $p['forma_pagamento'];
            $paraRotulo = $novaForma === 'pix_online' ? 'Pix por QR Code' : (StatusPedido::FORMAS[$novaForma] ?? $novaForma);
            $texto = sprintf('Forma trocada de %s para %s%s, por %s.', $deRotulo, $paraRotulo,
                $taxa > 0 ? ', taxa ' . Numero::reais(self::dec($taxa)) : '', trim($this->autor->nome . ' ' . $this->autor->sobrenome));
            (new PedidoRepository($this->db))->registrarEvento($this->idRestaurante, $id, (string) $p['status'], $texto, $this->autor->id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => $id, 'numero' => (int) $p['numero'], 'status' => (string) $p['status']]);
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
        if ($cobrancaAntiga !== null) {
            (new PixPedido($this->db))->removerCobranca($this->idRestaurante, $cobrancaAntiga);
        }
        TempoReal::publicar($this->idRestaurante, 'entregas', 'entrega.atualizada', ['id' => $id]);
        Auditoria::registrar($this->autor, 'Forma de pagamento trocada', sprintf('Pedido #%d: %s Total %s.', (int) $p['numero'], $texto, Numero::reais(self::dec($total))));
        $antes = Numero::centavos((string) ($p['taxa_pagamento'] ?? '0'));
        $msg = sprintf('Forma trocada para %s.', $paraRotulo);
        if ($taxa > 0) {
            $msg .= sprintf(' %s: + %s. Receber %s.', $rotulo, Numero::reais(self::dec($taxa)), Numero::reais(self::dec($total)));
        } elseif ($antes > 0) {
            $msg .= sprintf(' A taxa de %s saiu. Receber %s.', Numero::reais(self::dec($antes)), Numero::reais(self::dec($total)));
        } else {
            $msg .= ' Receber ' . Numero::reais(self::dec($total)) . '.';
        }
        return Resultado::sucesso($msg, $id);
    }

    private static function dec(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
