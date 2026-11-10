<?php

declare(strict_types=1);

namespace Cardapim\Services\Geo;

use Cardapim\Core\Config;
use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\ErroHttp;
use Throwable;

/**
 * Endereco e coordenadas SEM custo:
 * - CEP -> endereco: BrasilAPI v2 (as vezes traz coordenadas) e ViaCEP como reserva.
 * - Endereco -> coordenadas: Nominatim (OpenStreetMap), so quando o restaurante entrega por raio.
 *
 * Politica do Nominatim publico (https://operations.osmfoundation.org/policies/nominatim/): no maximo
 * 1 consulta por segundo para o site inteiro, User-Agent que identifique o Cardapim, sem uso pesado e com cache.
 * Por isso: cache em arquivo por 30 dias, trava global de 1 s e GEO_NOMINATIM=0 desliga.
 * Com volume grande: instalar um Nominatim proprio (GEO_NOMINATIM_URL) ou um provedor pago.
 */
class Geocodificador
{
    private const CACHE_DIAS = 30;

    public function __construct(private readonly ClienteHttp $http = new ClienteHttp(6))
    {
    }

    /**
     * @return array{cep:string,logradouro:string,bairro:string,cidade:string,estado:string,latitude:?float,longitude:?float}|null
     */
    public function cep(string $cep): ?array
    {
        $cep = (string) preg_replace('/\D/', '', $cep);
        if (preg_match('/\A[0-9]{8}\z/', $cep) !== 1 || preg_match('/\A(\d)\1{7}\z/', $cep) === 1) {
            return null;
        }
        $cache = $this->cache('cep-' . $cep);
        if ($cache !== null) {
            return $cache['vazio'] ?? false ? null : $cache;
        }
        $achado = $this->brasilApi($cep) ?? $this->viaCep($cep);
        $this->guardar('cep-' . $cep, $achado ?? ['vazio' => true], $achado === null ? 1 : self::CACHE_DIAS);
        return $achado;
    }

    /**
     * Coordenadas de um endereco (rua, numero, bairro, cidade, UF). Null se nao achar ou se desligado.
     * @return array{latitude:float,longitude:float}|null
     */
    public function coordenadas(string $logradouro, string $numero, string $bairro, string $cidade, string $estado, string $cep = ''): ?array
    {
        if (Config::get('GEO_NOMINATIM', '1') === '0' || trim($logradouro) === '' || trim($cidade) === '') {
            return null;
        }
        $rua = trim($logradouro . (preg_match('/\d/', $numero) === 1 ? ' ' . $numero : ''));
        $chave = 'nom-' . hash('sha256', TextoLocal::chave($rua . '|' . $bairro . '|' . $cidade . '|' . $estado));
        $cache = $this->cache($chave);
        if ($cache !== null) {
            return isset($cache['latitude']) ? ['latitude' => (float) $cache['latitude'], 'longitude' => (float) $cache['longitude']] : null;
        }
        // Busca estruturada (mais precisa); sem resultado, tenta sem o numero.
        $achado = $this->nominatim(['street' => $rua, 'city' => $cidade, 'state' => $estado, 'postalcode' => $cep]);
        if ($achado === null && $rua !== trim($logradouro)) {
            $achado = $this->nominatim(['street' => trim($logradouro), 'city' => $cidade, 'state' => $estado]);
        }
        $this->guardar($chave, $achado ?? ['vazio' => true], $achado === null ? 1 : self::CACHE_DIAS);
        return $achado;
    }

    /** @return array<string,mixed>|null */
    private function brasilApi(string $cep): ?array
    {
        $d = $this->json(rtrim(Config::get('GEO_BRASILAPI_URL', 'https://brasilapi.com.br/api'), '/') . '/cep/v2/' . $cep);
        if (!is_array($d) || !isset($d['city'], $d['state'])) {
            return null;
        }
        $coord = $d['location']['coordinates'] ?? [];
        $lat = $coord['latitude'] ?? null;
        $lon = $coord['longitude'] ?? null;
        $temCoord = Distancia::coordenadaValida($lat, $lon);
        return self::endereco($cep, (string) ($d['street'] ?? ''), (string) ($d['neighborhood'] ?? ''), (string) $d['city'], (string) $d['state'],
            $temCoord ? (float) $lat : null, $temCoord ? (float) $lon : null);
    }

    /** @return array<string,mixed>|null */
    private function viaCep(string $cep): ?array
    {
        $d = $this->json(rtrim(Config::get('GEO_VIACEP_URL', 'https://viacep.com.br/ws'), '/') . '/' . $cep . '/json/');
        if (!is_array($d) || ($d['erro'] ?? false) || !isset($d['localidade'], $d['uf'])) {
            return null;
        }
        return self::endereco($cep, (string) ($d['logradouro'] ?? ''), (string) ($d['bairro'] ?? ''), (string) $d['localidade'], (string) $d['uf'], null, null);
    }

    /** @param array<string,string> $campos @return array{latitude:float,longitude:float}|null */
    private function nominatim(array $campos): ?array
    {
        $campos = array_filter($campos, static fn (string $v): bool => trim($v) !== '');
        $url = rtrim(Config::get('GEO_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'), '/') . '/search?'
            . http_build_query($campos + ['country' => 'Brasil', 'countrycodes' => 'br', 'format' => 'jsonv2', 'limit' => '1']);
        if (!$this->aguardarVez()) {
            return null;
        }
        $email = Config::get('GEO_CONTATO', '');
        $d = $this->json($url . ($email !== '' ? '&email=' . rawurlencode($email) : ''), [
            'User-Agent' => 'Cardapim/1.0 (' . rtrim(Config::get('APP_URL', 'https://cardapim.escotilhaprodutora.com'), '/') . ')',
            'Accept-Language' => 'pt-BR',
        ]);
        $primeiro = is_array($d) ? ($d[0] ?? null) : null;
        if (!is_array($primeiro) || !Distancia::coordenadaValida($primeiro['lat'] ?? null, $primeiro['lon'] ?? null)) {
            return null;
        }
        return ['latitude' => round((float) $primeiro['lat'], 7), 'longitude' => round((float) $primeiro['lon'], 7)];
    }

    /** Trava global: no maximo 1 consulta por segundo ao Nominatim (somando todos os clientes). */
    private function aguardarVez(): bool
    {
        $pasta = $this->pasta();
        $h = @fopen($pasta . '/nominatim.lock', 'c+');
        if ($h === false) {
            return true;
        }
        try {
            flock($h, LOCK_EX);
            $ultimo = (float) stream_get_contents($h);
            $espera = $ultimo + 1.05 - microtime(true);
            if ($espera > 2.5) {
                return false; // fila grande: nao segura o cliente; cai no proximo metodo (CEP ou GPS).
            }
            if ($espera > 0) {
                usleep((int) ($espera * 1_000_000));
            }
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) microtime(true));
            return true;
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** @param array<string,string> $cab @return array<mixed>|null */
    private function json(string $url, array $cab = []): ?array
    {
        try {
            $r = $this->http->enviar('GET', $url, $cab);
            return $r->ok() ? $r->json() : null;
        } catch (ErroHttp|Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private static function endereco(string $cep, string $rua, string $bairro, string $cidade, string $uf, ?float $lat, ?float $lon): array
    {
        return [
            'cep' => $cep,
            'logradouro' => TextoLocal::limpo($rua, 150),
            'bairro' => TextoLocal::limpo($bairro, 100),
            'cidade' => TextoLocal::limpo($cidade, 100),
            'estado' => strtoupper(substr(trim($uf), 0, 2)),
            'latitude' => $lat,
            'longitude' => $lon,
        ];
    }

    private function pasta(): string
    {
        $pasta = dirname(__DIR__, 3) . '/storage/cache/geo';
        if (!is_dir($pasta)) {
            @mkdir($pasta, 0750, true);
        }
        return $pasta;
    }

    /** @return array<string,mixed>|null */
    private function cache(string $nome): ?array
    {
        $arquivo = $this->pasta() . '/' . $nome . '.json';
        if (!is_file($arquivo)) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($arquivo), true);
        if (!is_array($d) || (int) ($d['expira'] ?? 0) < time()) {
            return null;
        }
        return is_array($d['dados'] ?? null) ? $d['dados'] : null;
    }

    /** @param array<string,mixed> $dados */
    private function guardar(string $nome, array $dados, int $dias): void
    {
        @file_put_contents($this->pasta() . '/' . $nome . '.json', json_encode(['expira' => time() + $dias * 86400, 'dados' => $dados]), LOCK_EX);
    }
}
