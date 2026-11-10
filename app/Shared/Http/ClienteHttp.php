<?php

declare(strict_types=1);

namespace Cardapim\Shared\Http;

/**
 * Cliente HTTP minimo para as integracoes (iFood, Asaas, WPPConnect), com tempo limite curto.
 * Usa cURL; sem cURL, usa streams do PHP. Nunca registra cabecalhos (podem conter chaves).
 */
class ClienteHttp
{
    public function __construct(private readonly int $tempoLimite = 15)
    {
    }

    /**
     * @param array<string,string> $cabecalhos
     * @param array<mixed>|string|null $corpo array = JSON; string = enviado como esta (ex.: form-urlencoded)
     */
    public function enviar(string $metodo, string $url, array $cabecalhos = [], array|string|null $corpo = null): RespostaHttp
    {
        if (preg_match('~\Ahttps?://~i', $url) !== 1) {
            throw new ErroHttp('Endereço de serviço inválido.');
        }
        $cabecalhos += ['Accept' => 'application/json', 'User-Agent' => 'Cardapim/1.0'];
        if (is_array($corpo)) {
            $corpo = $corpo === [] ? '{}' : json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $cabecalhos += ['Content-Type' => 'application/json'];
        }
        $linhas = [];
        foreach ($cabecalhos as $nome => $valor) {
            $linhas[] = $nome . ': ' . str_replace(["\r", "\n"], '', $valor);
        }
        return function_exists('curl_init')
            ? $this->viaCurl(strtoupper($metodo), $url, $linhas, $corpo)
            : $this->viaStream(strtoupper($metodo), $url, $linhas, $corpo);
    }

    /** Formulario application/x-www-form-urlencoded. @param array<string,string> $campos @param array<string,string> $cabecalhos */
    public function formulario(string $url, array $campos, array $cabecalhos = []): RespostaHttp
    {
        return $this->enviar('POST', $url, $cabecalhos + ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($campos));
    }

    /** @param list<string> $linhas */
    private function viaCurl(string $metodo, string $url, array $linhas, ?string $corpo): RespostaHttp
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $linhas,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->tempoLimite),
            CURLOPT_TIMEOUT => $this->tempoLimite,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($corpo !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $corpo);
        }
        $resposta = curl_exec($curl);
        if ($resposta === false) {
            $codigo = curl_errno($curl);
            curl_close($curl);
            throw new ErroHttp($codigo === CURLE_OPERATION_TIMEDOUT ? 'O serviço demorou demais para responder.' : 'Não foi possível conectar ao serviço.');
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $tipo = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        return new RespostaHttp($status, (string) $resposta, $tipo);
    }

    /** @param list<string> $linhas */
    private function viaStream(string $metodo, string $url, array $linhas, ?string $corpo): RespostaHttp
    {
        $contexto = stream_context_create(['http' => [
            'method' => $metodo,
            'header' => implode("\r\n", $linhas),
            'content' => $corpo ?? '',
            'timeout' => $this->tempoLimite,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $resposta = @file_get_contents($url, false, $contexto);
        if ($resposta === false || !isset($http_response_header[0])) {
            throw new ErroHttp('Não foi possível conectar ao serviço.');
        }
        preg_match('~\AHTTP/\S+\s+(\d{3})~', $http_response_header[0], $m);
        $tipo = '';
        foreach ($http_response_header as $linha) {
            if (stripos($linha, 'content-type:') === 0) {
                $tipo = trim(substr($linha, 13));
            }
        }
        return new RespostaHttp((int) ($m[1] ?? 0), $resposta, $tipo);
    }
}
