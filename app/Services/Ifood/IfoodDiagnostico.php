<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Shared\Http\RespostaHttp;
use Throwable;

/**
 * Evidencias das respostas Merchant do iFood, fora da pasta public/.
 * Somente status, interrupcoes e horarios; nunca pedidos, OAuth, Authorization ou cookies.
 * Um arquivo por restaurante e dia, com dados e mensagens sanitizados.
 */
final class IfoodDiagnostico
{
    private const MAX_CORPO = 16000;
    private const MAX_LINHA = 24000;
    private const MAX_ITENS = 80;

    /**
     * @param array<string,mixed>|null $enviado
     */
    public static function registrar(int $restaurante, string $metodo, string $caminho, ?array $enviado, ?RespostaHttp $resposta, ?string $erro, int $duracaoMs): void
    {
        if ($restaurante <= 0 || !self::monitorado($caminho)) {
            return;
        }

        try {
            $dir = self::diretorio();
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                error_log('[Cardapim] Log Merchant indisponivel: nao foi possivel criar diretorio.');
                return;
            }
            $arquivo = self::arquivo($restaurante, gmdate('Y-m-d'));
            $novo = !is_file($arquivo);
            $entrada = [
                'em_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'origem' => PHP_SAPI === 'cli' ? 'cron' : 'painel',
                'restaurante_id' => $restaurante,
                'metodo' => strtoupper($metodo),
                'rota' => self::rota($caminho),
                'loja_id' => self::lojaId($caminho),
                'pausa_id' => self::pausaId($caminho),
                'duracao_ms' => max(0, $duracaoMs),
                'http' => $resposta?->status,
                'solicitacao' => $enviado === null ? null : self::limpar($enviado),
                'resposta' => self::corpo($resposta),
                'erro_rede' => $erro === null ? null : self::limparTexto($erro),
            ];
            $json = json_encode($entrada, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                return;
            }
            if (strlen($json) > self::MAX_LINHA) {
                $entrada['resposta'] = ['truncada' => true, 'resumo' => 'Resposta maior que o limite seguro de registro'];
                $json = json_encode($entrada, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($novo) {
                $handle = @fopen($arquivo, 'x'); // criado com permissao restrita
                if (is_resource($handle)) {
                    @chmod($arquivo, 0600);
                    fclose($handle);
                }
            }
            if (@file_put_contents($arquivo, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
                error_log('[Cardapim] Nao foi possivel gravar diagnostico Merchant.');
            }
        } catch (Throwable $e) {
            error_log('[Cardapim] Falha no diagnostico Merchant: ' . get_class($e));
        }
    }

    /** @return list<array<string,mixed>> Ultimos eventos deste restaurante, sem consultar outras lojas */
    public static function recentes(int $restaurante, int $limite = 30): array
    {
        if ($restaurante <= 0) {
            return [];
        }
        $limite = max(1, min(60, $limite));
        $resultado = [];
        for ($d = 0; $d < 3 && count($resultado) < $limite; $d++) {
            $dia = gmdate('Y-m-d', time() - 86400 * $d);
            $arquivo = self::arquivo($restaurante, $dia);
            if (!is_file($arquivo) || !is_readable($arquivo)) {
                continue;
            }
            $linhas = [];
            $handle = @fopen($arquivo, 'rb');
            if ($handle === false) {
                continue;
            }
            while (($linha = fgets($handle, self::MAX_LINHA + 2)) !== false) {
                $dados = json_decode($linha, true);
                if (is_array($dados) && ($dados['restaurante_id'] ?? null) === $restaurante) {
                    $linhas[] = $dados;
                    if (count($linhas) > $limite) {
                        array_shift($linhas);
                    }
                }
            }
            fclose($handle);
            foreach (array_reverse($linhas) as $linha) {
                $resultado[] = $linha;
                if (count($resultado) >= $limite) {
                    break;
                }
            }
        }
        return $resultado;
    }

    private static function diretorio(): string
    {
        return dirname(__DIR__, 3) . '/storage/logs/ifood-merchant';
    }

    private static function arquivo(int $restaurante, string $dia): string
    {
        return self::diretorio() . '/restaurante-' . $restaurante . '-' . $dia . '.jsonl';
    }

    private static function monitorado(string $caminho): bool
    {
        return preg_match('~^/merchant/v1\.0/merchants/[A-Za-z0-9-]{1,64}/(status(?:/[A-Z]+)?|interruptions(?:/[A-Za-z0-9-]{1,64})?|opening-hours)$~', $caminho) === 1;
    }

    private static function rota(string $caminho): string
    {
        return (string) preg_replace('~^/merchant/v1\.0/merchants/[A-Za-z0-9-]{1,64}~', '/merchant/v1.0/merchants/{loja}', $caminho);
    }

    private static function lojaId(string $caminho): ?string
    {
        return preg_match('~^/merchant/v1\.0/merchants/([A-Za-z0-9-]{1,64})/~', $caminho, $m) === 1 ? $m[1] : null;
    }

    private static function pausaId(string $caminho): ?string
    {
        return preg_match('~/interruptions/([A-Za-z0-9-]{1,64})$~', $caminho, $m) === 1 ? $m[1] : null;
    }

    /** @return array<string,mixed>|list<mixed>|string|null */
    private static function corpo(?RespostaHttp $r): array|string|null
    {
        if ($r === null) {
            return null;
        }
        if (trim($r->corpo) === '') {
            return ['sem_corpo' => true];
        }
        $json = json_decode($r->corpo, true);
        if (!is_array($json)) {
            return ['formato' => 'nao_json', 'http' => $r->status, 'tamanho_bytes' => strlen($r->corpo)];
        }
        $limpo = self::limpar($json);
        $codificado = json_encode($limpo, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        return strlen((string) $codificado) > self::MAX_CORPO
            ? ['truncada' => true, 'tamanho_bytes' => strlen($r->corpo), 'itens' => count($json)]
            : $limpo;
    }

    /** Protecao por nomes de campos, formato e mascaramento; NUNCA grava segredos em bruto. */
    private static function limpar(mixed $valor, int $nivel = 0): mixed
    {
        if ($nivel > 7) {
            return '[profundidade limitada]';
        }
        if (is_array($valor)) {
            $resultado = [];
            $i = 0;
            foreach ($valor as $chave => $item) {
                if (++$i > self::MAX_ITENS) {
                    $resultado['_outros_itens'] = '[limite de itens]';
                    break;
                }
                if (is_string($chave) && preg_match('/token|secret|authorization|password|credential|cookie|cpf|cnpj|document|email|phone|address|customer|payment|card|pix|base64|photo|image|signature|refresh|private|access_key/i', $chave)) {
                    $resultado[$chave] = '[oculto]';
                } else {
                    $resultado[$chave] = self::limpar($item, $nivel + 1);
                }
            }
            return $resultado;
        }
        if (is_string($valor)) {
            return self::limparTexto($valor);
        }
        return is_scalar($valor) || $valor === null ? $valor : '[tipo nao suportado]';
    }

    private static function limparTexto(string $valor): string
    {
        $valor = mb_substr($valor, 0, 500);
        $valor = (string) preg_replace('~Bearer\s+\S+~i', 'Bearer [oculto]', $valor);
        $valor = (string) preg_replace('~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i', '[email oculto]', $valor);
        $valor = (string) preg_replace('~\b\d{11,14}\b~', '[numero oculto]', $valor);
        return $valor;
    }
}
