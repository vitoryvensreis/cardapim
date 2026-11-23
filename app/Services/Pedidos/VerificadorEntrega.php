<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Modules\Entrega\AreaEntrega;
use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Modules\Integracoes\GoogleRoutesApi;
use Cardapim\Core\RateLimiter;
use Cardapim\Repositories\Plataforma\GoogleMapsRepository;
use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Services\Geo\Geocodificador;
use Cardapim\Shared\Numero;
use PDO;
use Throwable;

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
        $googleAtivo = false;
        if ($escolha['area'] === null && AreaEntrega::precisaDeLocalizacao($areas)) {
            $local = $repo->local($idRestaurante);
            if ($local['latitude'] !== null) {
                // A configuracao e GLOBAL e somente o adm_master pode altera-la.
                // Bairros continuam com prioridade e nao consomem chamadas pagas.
                $google = (new GoogleMapsRepository($this->db))->buscar();
                $googleAtivo = $google !== null && $google['ativo'];
                if ($googleAtivo) {
                    $destino = trim(implode(', ', array_filter([
                        trim((string) ($e['logradouro'] ?? '') . ' ' . (string) ($e['numero'] ?? '')),
                        (string) ($e['bairro'] ?? ''),
                        (string) ($e['cidade'] ?? ''),
                        (string) ($e['estado'] ?? ''),
                        (string) ($e['cep'] ?? ''),
                        'Brasil',
                    ])));
                    if (trim((string) ($e['logradouro'] ?? '')) === '' || trim((string) ($e['numero'] ?? '')) === ''
                        || trim((string) ($e['cidade'] ?? '')) === '' || trim((string) ($e['estado'] ?? '')) === '') {
                        return ['entrega' => false, 'motivo' => 'sem_localizacao',
                            'mensagem' => 'Informe rua, número, cidade e estado para calcular a entrega pelas ruas.'] + $base;
                    }

                    // Haversine so pode RECUSAR antecipadamente, nunca aprovar uma faixa.
                    // Usa apenas GPS recente e preciso, obtido por acao explicita no checkout.
                    // Sem GPS ou com precisao duvidosa, a Routes API continua sendo definitiva.
                    $raioMaximo = 0.0;
                    foreach ($areas as $area) {
                        if ($area['tipo'] === 'raio' && (int) $area['ativo'] === 1) {
                            $raioMaximo = max($raioMaximo, (float) $area['raio_km']);
                        }
                    }
                    if (Distancia::foraDoRaioComGpsConfiavel(
                        (float) $local['latitude'], (float) $local['longitude'],
                        $e['latitude'] ?? null, $e['longitude'] ?? null, $e['gps_precisao_m'] ?? null, $raioMaximo
                    )) {
                        // Sem chamada paga: o percurso pelas ruas nunca e menor que a linha reta.
                        // Nao expor a distancia Haversine como se fosse a distancia do Google.
                        $escolha = ['area' => null, 'motivo' => 'fora_raio'];
                    } else {
                    if (!RateLimiter::padrao(300, 60)->permitir('google-routes-global')) {
                        return ['entrega' => false, 'motivo' => 'erro_rota',
                            'mensagem' => 'O cálculo de entrega está muito solicitado agora. Tente novamente em instantes.'] + $base;
                    }
                    try {
                        $distancia = (new GoogleRoutesApi($google['chave']))->distanciaKm(
                            (float) $local['latitude'], (float) $local['longitude'], $destino
                        );
                    } catch (Throwable $erro) {
                        error_log('[Cardapim] Routes API indisponivel no restaurante ' . $idRestaurante . ': ' . get_class($erro));
                        return ['entrega' => false, 'motivo' => 'erro_rota',
                            'mensagem' => 'Não conseguimos calcular a entrega agora. Tente novamente em instantes.'] + $base;
                    }
                    // O resultado do Google e usado APENAS para selecionar a taxa; nao e salvo no pedido.
                    // Coordenadas para o mapa do pedido seguem vindas do mecanismo independente do Deliveryzim.
                    $ponto = $this->coordenadas($e);
                    if ($ponto !== null) {
                        $resultado = ['latitude' => $ponto['latitude'], 'longitude' => $ponto['longitude'],
                            'localizacao' => $ponto['como']] + $resultado;
                    }
                    $escolha = AreaEntrega::escolher($areas, $e['bairro'] ?? '', $e['cidade'] ?? '', $distancia);
                    }
                } else {
                    // Codigo original preservado integralmente quando o ADM desliga o Google.
                    $ponto = $this->coordenadas($e);
                    if ($ponto !== null) {
                        $distancia = round(Distancia::km($local['latitude'], $local['longitude'], $ponto['latitude'], $ponto['longitude']), 2);
                        $resultado = ['distancia_km' => $distancia, 'latitude' => $ponto['latitude'],
                            'longitude' => $ponto['longitude'], 'localizacao' => $ponto['como']] + $resultado;
                        $escolha = AreaEntrega::escolher($areas, $e['bairro'] ?? '', $e['cidade'] ?? '', $distancia);
                    }
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
            $escolha['motivo'] === 'fora_raio' && $googleAtivo => 'Ainda não entregamos neste endereço pelas faixas de distância cadastradas.' . $retirada,
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
