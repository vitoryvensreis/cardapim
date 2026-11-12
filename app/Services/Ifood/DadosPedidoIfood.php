<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Realtime\TempoReal;
use PDO;

/** Leitura e atualizacao do JSON ifood_dados do pedido (entregador, avisos, cancelamento). */
final class DadosPedidoIfood
{
    /** @return array<string,mixed> */
    public static function ler(?string $json): array
    {
        $d = json_decode((string) $json, true);
        $d = is_array($d) ? $d : [];
        $d['avisos'] = is_array($d['avisos'] ?? null) ? array_values(array_filter($d['avisos'], 'is_string')) : [];
        $d['cupons'] = is_array($d['cupons'] ?? null) ? $d['cupons'] : [];
        $d['entregador'] = is_array($d['entregador'] ?? null) ? $d['entregador'] : null;
        return $d;
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mudar */
    public static function alterar(PDO $db, int $idRestaurante, int $idPedido, callable $mudar, bool $avisarTela = true): void
    {
        $q = $db->prepare('SELECT numero, status, ifood_dados FROM pedidos WHERE id = ? AND id_restaurante = ?');
        $q->execute([$idPedido, $idRestaurante]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        if ($p === false) {
            return;
        }
        $novo = $mudar(self::ler($p['ifood_dados']));
        $novo['avisos'] = array_values(array_unique(array_slice($novo['avisos'] ?? [], -8)));
        $db->prepare('UPDATE pedidos SET ifood_dados = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ?')
            ->execute([json_encode($novo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), date('Y-m-d H:i:s'), $idPedido, $idRestaurante]);
        if ($avisarTela) {
            TempoReal::registrar($db, $idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => $idPedido, 'numero' => (int) $p['numero'], 'status' => (string) $p['status']]);
        }
    }

    public static function adicionarAviso(PDO $db, int $idRestaurante, int $idPedido, string $aviso): void
    {
        self::alterar($db, $idRestaurante, $idPedido, static function (array $d) use ($aviso): array {
            $d['avisos'][] = mb_substr($aviso, 0, 300);
            return $d;
        });
    }
}
