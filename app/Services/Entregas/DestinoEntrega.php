<?php

declare(strict_types=1);

namespace Cardapim\Services\Entregas;

use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Services\Geo\Geocodificador;
use PDO;
use Throwable;

/**
 * Coordenadas do endereco de entrega, para o mapa do cliente mostrar a casa e calcular distancia e tempo.
 * Pedidos de area "por bairro" nao precisam de coordenada para calcular a taxa, entao podem chegar sem ela:
 * aqui buscamos uma vez (Nominatim pela rua, ou o centro do CEP pela BrasilAPI), com cache, e gravamos no pedido.
 * Nunca derruba quem chamou: sem achar, o mapa so mostra o entregador.
 */
final class DestinoEntrega
{
    /** @param array<string,mixed> $pedido @return array{latitude:float,longitude:float}|null */
    public static function garantir(PDO $db, array $pedido, ?Geocodificador $geo = null): ?array
    {
        if (Distancia::coordenadaValida($pedido['entrega_latitude'] ?? null, $pedido['entrega_longitude'] ?? null)) {
            return ['latitude' => (float) $pedido['entrega_latitude'], 'longitude' => (float) $pedido['entrega_longitude']];
        }
        if (($pedido['tipo'] ?? '') !== 'entrega' || trim((string) ($pedido['entrega_logradouro'] ?? '')) === '') {
            return null;
        }
        try {
            $geo ??= new Geocodificador();
            $ponto = $geo->coordenadas(
                (string) $pedido['entrega_logradouro'], (string) ($pedido['entrega_numero'] ?? ''), (string) ($pedido['entrega_bairro'] ?? ''),
                (string) ($pedido['entrega_cidade'] ?? ''), (string) ($pedido['entrega_estado'] ?? ''), (string) ($pedido['entrega_cep'] ?? ''),
            );
            if ($ponto === null && !empty($pedido['entrega_cep'])) {
                $cep = $geo->cep((string) $pedido['entrega_cep']);
                if ($cep !== null && Distancia::coordenadaValida($cep['latitude'] ?? null, $cep['longitude'] ?? null)) {
                    $ponto = ['latitude' => (float) $cep['latitude'], 'longitude' => (float) $cep['longitude']];
                }
            }
            if ($ponto === null || !Distancia::coordenadaValida($ponto['latitude'], $ponto['longitude'])) {
                return null;
            }
            $db->prepare('UPDATE pedidos SET entrega_latitude = ?, entrega_longitude = ? WHERE id = ? AND id_restaurante = ? AND entrega_latitude IS NULL')
                ->execute([round($ponto['latitude'], 7), round($ponto['longitude'], 7), (int) $pedido['id'], (int) $pedido['id_restaurante']]);
            return $ponto;
        } catch (Throwable $erro) {
            error_log('[Cardapim] Coordenada da entrega indisponivel: ' . get_class($erro));
            return null;
        }
    }
}
