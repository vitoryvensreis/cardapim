<?php

declare(strict_types=1);

namespace Cardapim\Realtime;

use Cardapim\Core\Config;
use RuntimeException;

/**
 * Cliente Redis minimo (protocolo RESP), sem extensao: AUTH, PUBLISH, SUBSCRIBE e PING.
 * O Redis e so a "campainha" do tempo real; o MySQL continua sendo a fonte de verdade.
 */
final class RedisConexao
{
    /** @var resource */
    private $socket;

    /** @param resource $socket */
    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    /** Redis configurado no .env? (REDIS_PORT vazio = desligado) */
    public static function configurado(): bool
    {
        return Config::get('REDIS_PORT') !== '';
    }

    public static function canal(string $nome): string
    {
        $prefixo = Config::get('REDIS_PREFIXO', 'cardapim');
        return (preg_match('/\A[a-z0-9_-]{1,30}\z/', $prefixo) === 1 ? $prefixo : 'cardapim') . ':' . $nome;
    }

    /** Conecta usando REDIS_HOST, REDIS_PORT e REDIS_PASSWORD. Lanca RuntimeException se falhar. */
    public static function conectar(float $tempoLimite = 0.5): self
    {
        $host = Config::get('REDIS_HOST', '127.0.0.1');
        $porta = Config::get('REDIS_PORT');
        if (!ctype_digit($porta) || (int) $porta < 1 || (int) $porta > 65535 || preg_match('/\A[A-Za-z0-9.:-]{1,100}\z/', $host) !== 1) {
            throw new RuntimeException('Redis nao configurado.');
        }
        $socket = @stream_socket_client('tcp://' . $host . ':' . (int) $porta, $codigo, $mensagem, $tempoLimite);
        if ($socket === false) {
            throw new RuntimeException('Redis indisponivel.');
        }
        $segundos = (int) $tempoLimite;
        stream_set_timeout($socket, $segundos, (int) (($tempoLimite - $segundos) * 1_000_000));
        $conexao = new self($socket);
        $senha = Config::get('REDIS_PASSWORD');
        if ($senha !== '' && $conexao->comando('AUTH', $senha) !== 'OK') {
            $conexao->fechar();
            throw new RuntimeException('Redis recusou a senha.');
        }
        return $conexao;
    }

    /** @return resource */
    public function socket()
    {
        return $this->socket;
    }

    public function comando(string ...$partes): mixed
    {
        $this->escrever($partes);
        return $this->lerResposta();
    }

    /** Entra no modo assinatura (depois disso so lerMensagem/fechar). */
    public function assinar(string $canal): void
    {
        $this->escrever(['SUBSCRIBE', $canal]);
        $resposta = $this->lerResposta();
        if (!is_array($resposta) || ($resposta[0] ?? null) !== 'subscribe') {
            throw new RuntimeException('Redis recusou a assinatura.');
        }
    }

    /**
     * Le uma mensagem de assinatura (chamar quando o socket estiver pronto para leitura).
     * @return array{canal:string, mensagem:string}|null
     */
    public function lerMensagem(): ?array
    {
        $resposta = $this->lerResposta();
        if (is_array($resposta) && ($resposta[0] ?? null) === 'message' && is_string($resposta[1] ?? null) && is_string($resposta[2] ?? null)) {
            return ['canal' => $resposta[1], 'mensagem' => $resposta[2]];
        }
        return null;
    }

    public function fechar(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    /** Ainda ha bytes no buffer interno do PHP (mais de uma mensagem chegou de uma vez)? */
    public function temDadosPendentes(): bool
    {
        $meta = stream_get_meta_data($this->socket);
        return ($meta['unread_bytes'] ?? 0) > 0;
    }

    /** @param list<string> $partes */
    private function escrever(array $partes): void
    {
        $saida = '*' . count($partes) . "\r\n";
        foreach ($partes as $parte) {
            $saida .= '$' . strlen($parte) . "\r\n" . $parte . "\r\n";
        }
        $escrito = 0;
        while ($escrito < strlen($saida)) {
            $n = @fwrite($this->socket, substr($saida, $escrito));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Conexao com o Redis caiu.');
            }
            $escrito += $n;
        }
    }

    private function lerResposta(): mixed
    {
        $linha = @fgets($this->socket);
        if ($linha === false || !str_ends_with($linha, "\r\n")) {
            throw new RuntimeException('Conexao com o Redis caiu ou demorou demais.');
        }
        $tipo = $linha[0];
        $valor = substr($linha, 1, -2);
        switch ($tipo) {
            case '+':
                return $valor;
            case '-':
                throw new RuntimeException('Redis respondeu com erro.');
            case ':':
                return (int) $valor;
            case '$':
                $tamanho = (int) $valor;
                if ($tamanho < 0) {
                    return null;
                }
                $dados = '';
                while (strlen($dados) < $tamanho + 2) {
                    $parte = @fread($this->socket, $tamanho + 2 - strlen($dados));
                    if ($parte === false || $parte === '') {
                        throw new RuntimeException('Conexao com o Redis caiu.');
                    }
                    $dados .= $parte;
                }
                return substr($dados, 0, $tamanho);
            case '*':
                $quantidade = (int) $valor;
                if ($quantidade < 0) {
                    return null;
                }
                $itens = [];
                for ($i = 0; $i < $quantidade; $i++) {
                    $itens[] = $this->lerResposta();
                }
                return $itens;
            default:
                throw new RuntimeException('Resposta do Redis invalida.');
        }
    }
}
