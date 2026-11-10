<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Modules\Entrega\AreaEntrega;
use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Services\Geo\Geocodificador;
use Cardapim\Shared\Numero;
use PDO;

/**
 * O restaurante entrega neste endereco? Quanto custa? Mesma regra no cardapio (ao digitar), na criacao do
 * pedido (de novo, no servidor) e no "Testar um endereco" do gerente.
 *
 * Coordenadas do cliente (so quando ha faixa de km e o bairro nao esta na lista), nesta ordem:
 * endereco digitado no mapa (Nominatim) -> coordenadas do CEP -> localizacao do celular.
 * Se o celular e o endereco discordarem por mais de 1,5 km, vale o endereco digitado (e para la que vai a entrega).
 */
final class VerificadorEntrega
{
    private const TOLERANCIA_GPS_KM = 1.5;

    public function __construct(private readonly PDO $db, private readonly Geocodificador $geo = new Geocodificador())
    {
    }

    /**
     * @param array<string,mixed> $e endereco normalizado (Endereco::normalizar)
     * @return array{entrega:bool, motivo:string, mensagem:string, area_id:?int, area:string, taxa:string, pedido_minimo:?string,
     *   distancia_km:?float, latitude:?float, longitude:?float, localizacao:?string}
     */
    public function verificar(int $idRestaurante, array $e): array
    {
        $repo = new EntregaPagamentoRepository($this->db);
        $config = $repo->config($idRestaurante);
        $base = ['area_id' => null, 'area' => '', 'taxa' => '0.00', 'pedido_minimo' => null, 'distancia_km' => null, 'latitude' => null, 'longitude' => null, 'localizacao' => null];
        $retirada = $config['aceita_retirada'] === 1 ? ' Você pode escolher retirar no restaurante.' : '';
        if ($config['aceita_entrega'] !== 1) {
            return ['entrega' => false, 'motivo' => 'sem_entrega', 'mensagem' => 'Este restaurante não faz entregas pelo cardápio online.' . $retirada] + $base;
        }
        $areas = $repo->areas($idRestaurante);
        $escolha = AreaEntrega::escolher($areas, $e['bairro'] ?? '', $e['cidade'] ?? '', null);
        $resultado = $base;
        if ($escolha['area'] === null && AreaEntrega::precisaDeLocalizacao($areas)) {
            $local = $repo->local($idRestaurante);
            if ($local['latitude'] !== null) {
                $ponto = $this->coordenadas($e);
                if ($ponto !== null) {
                    $distancia = round(Distancia::km($local['latitude'], $local['longitude'], $ponto['latitude'], $ponto['longitude']), 2);
                    $resultado = ['distancia_km' => $distancia, 'latitude' => $ponto['latitude'], 'longitude' => $ponto['longitude'], 'localizacao' => $ponto['como']] + $resultado;
                    $escolha = AreaEntrega::escolher($areas, $e['bairro'] ?? '', $e['cidade'] ?? '', $distancia);
                }
            }
        }
        if ($escolha['area'] !== null) {
            $a = $escolha['area'];
            $taxa = Numero::centavos((string) $a['taxa']) === 0 ? 'Entrega grátis' : 'Taxa de entrega ' . Numero::reais((string) $a['taxa']);
            return [
                'entrega' => true,
                'motivo' => $escolha['motivo'],
                'mensagem' => 'Entregamos aqui. ' . $taxa . '.',
                'area_id' => (int) $a['id'],
                'area' => AreaEntrega::descricao($a),
                'taxa' => number_format(Numero::centavos((string) $a['taxa']) / 100, 2, '.', ''),
                'pedido_minimo' => $a['pedido_minimo'] !== null && $a['pedido_minimo'] !== '' ? number_format(Numero::centavos((string) $a['pedido_minimo']) / 100, 2, '.', '') : null,
            ] + $resultado;
        }
        $maior = 0.0;
        foreach ($areas as $a) {
            if ($a['tipo'] === 'raio' && (int) $a['ativo'] === 1) {
                $maior = max($maior, (float) $a['raio_km']);
            }
        }
        $bairro = TextoLocal::limpo((string) ($e['bairro'] ?? ''), 100);
        $mensagem = match (true) {
            $escolha['motivo'] === 'sem_areas' => 'Este restaurante ainda não definiu onde entrega.' . $retirada,
            $escolha['motivo'] === 'fora_raio' => sprintf('Ainda não entregamos aqui: o endereço fica a %s e entregamos até %s.', AreaEntrega::km((string) $resultado['distancia_km']), AreaEntrega::km((string) $maior)) . $retirada,
            $escolha['motivo'] === 'sem_localizacao' => 'Não conseguimos achar este endereço no mapa para calcular a distância. Confira a rua e o número ou toque em "Usar minha localização" se você estiver nele.',
            default => ($bairro !== '' ? 'Ainda não entregamos no bairro ' . $bairro . '.' : 'Ainda não entregamos neste endereço.') . $retirada,
        };
        return ['entrega' => false, 'motivo' => $escolha['motivo'], 'mensagem' => $mensagem] + $resultado;
    }

    /** @param array<string,mixed> $e @return array{latitude:float,longitude:float,como:string}|null */
    private function coordenadas(array $e): ?array
    {
        $gps = Distancia::coordenadaValida($e['latitude'] ?? null, $e['longitude'] ?? null)
            ? ['latitude' => (float) $e['latitude'], 'longitude' => (float) $e['longitude']] : null;
        $endereco = $this->geo->coordenadas((string) ($e['logradouro'] ?? ''), (string) ($e['numero'] ?? ''), (string) ($e['bairro'] ?? ''),
            (string) ($e['cidade'] ?? ''), (string) ($e['estado'] ?? ''), (string) ($e['cep'] ?? ''));
        $como = 'endereco';
        if ($endereco === null && ($e['cep'] ?? '') !== '') {
            $cep = $this->geo->cep((string) $e['cep']);
            if ($cep !== null && $cep['latitude'] !== null) {
                $endereco = ['latitude' => $cep['latitude'], 'longitude' => $cep['longitude']];
                $como = 'cep';
            }
        }
        if ($gps !== null && ($endereco === null || Distancia::km($gps['latitude'], $gps['longitude'], $endereco['latitude'], $endereco['longitude']) <= self::TOLERANCIA_GPS_KM)) {
            return $gps + ['como' => 'gps'];
        }
        return $endereco !== null ? $endereco + ['como' => $como] : null;
    }
}
