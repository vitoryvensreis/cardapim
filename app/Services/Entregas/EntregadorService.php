<?php

declare(strict_types=1);

namespace Cardapim\Services\Entregas;

use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Caixa\DeliveryService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Painel do entregador: as entregas que estao com ele, as que ja fez e o compartilhamento da localizacao.
 *
 * Localizacao (LGPD): so a ULTIMA posicao e guardada (entregador_posicoes, uma linha por entregador), apenas enquanto
 * ele escolhe compartilhar. Apagada ao parar ou quando nao ha mais entrega com ele. O cliente ve somente enquanto o
 * pedido DELE esta "saiu para entrega" com este entregador (Rastreio::paraCliente).
 */
final class EntregadorService
{
    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
    }

    /** Entregas com ele agora (saiu para entrega), da mais antiga para a mais nova. @return list<array<string,mixed>> */
    public function emRota(): array
    {
        $q = $this->db->prepare("SELECT * FROM pedidos WHERE id_restaurante = ? AND id_entregador = ? AND status = 'saiu_entrega' ORDER BY despachado_em, id");
        $q->execute([$this->idRestaurante, $this->autor->id]);
        $pedidos = $q->fetchAll(PDO::FETCH_ASSOC);
        $repo = new PedidoRepository($this->db);
        foreach ($pedidos as &$p) {
            $p['itens'] = $repo->itens($this->idRestaurante, (int) $p['id']);
        }
        unset($p);
        return $pedidos;
    }

    /** Entregas feitas por ele desde uma data. @return list<array<string,mixed>> */
    public function feitas(string $desde, int $limite = 30): array
    {
        $q = $this->db->prepare("SELECT id, numero, cliente_nome, entrega_bairro, total, taxa_entrega, forma_pagamento, despachado_em, concluido_em
            FROM pedidos WHERE id_restaurante = ? AND id_entregador = ? AND status = 'concluido' AND concluido_em >= ?
            ORDER BY concluido_em DESC, id DESC LIMIT " . max(1, min(100, $limite)));
        $q->execute([$this->idRestaurante, $this->autor->id, $desde]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Quantidade e soma das taxas de entrega (o que o motoqueiro costuma receber) de hoje e dos ultimos 7 dias. @return array<string,array{qtd:int,taxas:int}> */
    public function resumo(): array
    {
        $saida = [];
        foreach (['hoje' => date('Y-m-d 00:00:00'), 'semana' => date('Y-m-d 00:00:00', strtotime('-6 days'))] as $chave => $desde) {
            $q = $this->db->prepare("SELECT taxa_entrega FROM pedidos WHERE id_restaurante = ? AND id_entregador = ? AND status = 'concluido' AND concluido_em >= ?");
            $q->execute([$this->idRestaurante, $this->autor->id, $desde]);
            $taxas = $q->fetchAll(PDO::FETCH_COLUMN);
            $saida[$chave] = ['qtd' => count($taxas), 'taxas' => array_sum(array_map(static fn ($t): int => Numero::centavos((string) $t), $taxas))];
        }
        return $saida;
    }

    /** "Entreguei": conclui o pedido (pagamento no caixa aberto). So os pedidos que estao com ele. */
    public function entregar(int $id): Resultado
    {
        $p = (new PedidoRepository($this->db))->buscar($this->idRestaurante, $id);
        if ($p === null || (int) ($p['id_entregador'] ?? 0) !== $this->autor->id || $p['tipo'] !== 'entrega') {
            return Resultado::falha('Esta entrega não está com você.');
        }
        if ($p['status'] !== 'saiu_entrega') {
            return Resultado::falha('Esta entrega já foi concluída ou cancelada.');
        }
        if ($p['pagamento_status'] !== 'pago' && (new CaixaRepository($this->db))->aberto($this->idRestaurante) === null) {
            return Resultado::falha('O caixa do restaurante está fechado. Peça para abrirem o caixa e toque em "Entreguei" de novo.');
        }
        $r = (new DeliveryService($this->db, $this->idRestaurante, $this->autor))->concluir($id);
        if ($r->ok && $this->emRota() === []) {
            $this->pararPosicao(); // sem entrega com ele: nao precisa mais da localizacao
        }
        return $r->ok ? Resultado::sucesso('Entrega do pedido #' . $p['numero'] . ' confirmada. Bom trabalho!') : $r;
    }

    /** Grava a ultima posicao (substitui a anterior). */
    public function salvarPosicao(mixed $lat, mixed $lon, mixed $precisao, mixed $direcao, mixed $velocidade): Resultado
    {
        if (!Distancia::coordenadaValida($lat, $lon)) {
            return Resultado::falha('Localização inválida.');
        }
        $inteiro = static fn (mixed $v, int $max): ?int => is_numeric($v) && (float) $v >= 0 && (float) $v <= $max ? (int) round((float) $v) : null;
        $valores = [
            round((float) $lat, 7), round((float) $lon, 7), $inteiro($precisao, 65000),
            ($d = $inteiro($direcao, 360)) !== null ? $d % 360 : null, $inteiro(is_numeric($velocidade) ? (float) $velocidade * 3.6 : null, 300),
            date('Y-m-d H:i:s'),
        ];
        $u = $this->db->prepare('UPDATE entregador_posicoes SET latitude = ?, longitude = ?, precisao_m = ?, direcao = ?, velocidade_kmh = ?, atualizado_em = ?, id_restaurante = ? WHERE id_user = ?');
        $u->execute([...$valores, $this->idRestaurante, $this->autor->id]);
        if ($u->rowCount() === 0) {
            $this->db->prepare('INSERT INTO entregador_posicoes (latitude, longitude, precisao_m, direcao, velocidade_kmh, atualizado_em, id_restaurante, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$valores, $this->idRestaurante, $this->autor->id]);
        }
        return Resultado::sucesso('Localização atualizada.');
    }

    public function pararPosicao(): void
    {
        $this->db->prepare('DELETE FROM entregador_posicoes WHERE id_user = ?')->execute([$this->autor->id]);
        TempoReal::publicar($this->idRestaurante, 'entregas', 'entregador.parou', ['entregador' => $this->autor->id]);
    }

    /** @return array<string,mixed>|null */
    public function posicao(): ?array
    {
        $q = $this->db->prepare('SELECT latitude, longitude, atualizado_em FROM entregador_posicoes WHERE id_user = ?');
        $q->execute([$this->autor->id]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        return $p === false ? null : $p;
    }
}
