<?php

declare(strict_types=1);

namespace Cardapim\Services\Entregas;

use Cardapim\Modules\Entrega\Distancia;
use PDO;

/**
 * Onde esta o entregador do pedido (para o cliente acompanhar no mapa, estilo apps de corrida).
 * So responde com a posicao se: o pedido e de entrega, esta "saiu para entrega" e tem entregador. Posicao de ate
 * 30 minutos aparece (com "ha X min"), para o mapa nao sumir quando o celular do entregador perde o sinal ou sai da tela.
 * Quem chama ja conferiu que o pedido e do cliente logado.
 */
final class Rastreio
{
    private const VALIDADE_SEGUNDOS = 1800;
    /** Acima disso o cliente ve "ultima localizacao ha X min" em vez de "ao vivo". */
    public const AO_VIVO_SEGUNDOS = 120;
    private const VELOCIDADE_MEDIA_KMH = 22; // moto na cidade, para a estimativa de chegada

    /**
     * @param array<string,mixed> $pedido
     * @return array<string,mixed>
     */
    public static function paraCliente(PDO $db, array $pedido): array
    {
        $emRota = $pedido['tipo'] === 'entrega' && $pedido['status'] === 'saiu_entrega';
        $destino = $emRota ? DestinoEntrega::garantir($db, $pedido) : (Distancia::coordenadaValida($pedido['entrega_latitude'] ?? null, $pedido['entrega_longitude'] ?? null)
            ? ['latitude' => (float) $pedido['entrega_latitude'], 'longitude' => (float) $pedido['entrega_longitude']] : null);
        $q = $db->prepare('SELECT latitude, longitude FROM restaurantes WHERE id = ?');
        $q->execute([(int) $pedido['id_restaurante']]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $restaurante = Distancia::coordenadaValida($r['latitude'] ?? null, $r['longitude'] ?? null)
            ? ['latitude' => (float) $r['latitude'], 'longitude' => (float) $r['longitude']] : null;
        $base = ['ativo' => false, 'destino' => $destino, 'restaurante' => $restaurante];
        if (!$emRota || empty($pedido['id_entregador'])) {
            return $base + ['motivo' => 'fora_de_rota'];
        }
        $q = $db->prepare("SELECT p.latitude, p.longitude, p.precisao_m, p.direcao, p.atualizado_em, u.nome
            FROM entregador_posicoes p JOIN `user` u ON u.id = p.id_user WHERE p.id_user = ? AND p.id_restaurante = ?");
        $q->execute([(int) $pedido['id_entregador'], (int) $pedido['id_restaurante']]);
        $pos = $q->fetch(PDO::FETCH_ASSOC);
        $idade = $pos !== false ? time() - (int) strtotime((string) $pos['atualizado_em']) : null;
        if ($pos === false || $idade === null || $idade > self::VALIDADE_SEGUNDOS) {
            return $base + ['motivo' => 'sem_localizacao'];
        }
        $lat = (float) $pos['latitude'];
        $lon = (float) $pos['longitude'];
        $km = $destino !== null ? round(Distancia::km($lat, $lon, $destino['latitude'], $destino['longitude']), 1) : null;
        return [
            'ativo' => true,
            'entregador' => [
                'nome' => explode(' ', trim((string) $pos['nome']))[0],
                'latitude' => $lat,
                'longitude' => $lon,
                'precisao_m' => $pos['precisao_m'] !== null ? (int) $pos['precisao_m'] : null,
                'direcao' => $pos['direcao'] !== null ? (int) $pos['direcao'] : null,
                'atualizado_ha' => max(0, $idade),
                'ao_vivo' => $idade <= self::AO_VIVO_SEGUNDOS,
            ],
            'distancia_km' => $km,
            'minutos' => $km !== null ? max(1, (int) ceil($km / self::VELOCIDADE_MEDIA_KMH * 60)) : null,
        ] + $base;
    }
}
