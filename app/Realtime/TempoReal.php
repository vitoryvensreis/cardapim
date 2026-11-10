<?php

declare(strict_types=1);

namespace Cardapim\Realtime;

use Cardapim\Core\Config;
use Cardapim\Core\Database;
use Cardapim\Repositories\OutboxRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Ponto unico para publicar eventos de tempo real a partir do PHP (Transactional Outbox).
 *
 * - registrar(): grava o evento usando a MESMA conexao/transacao da regra de negocio. Use em operacoes
 *   criticas (pedido, pagamento): se a transacao for desfeita, o evento some junto.
 * - publicar(): para avisos de tela (estoque alterado, integracao). Nunca derruba a acao principal:
 *   se der erro, registra no log e segue.
 *
 * No fim da requisicao, um aviso curto vai para o Redis ("tem evento novo") para o servidor WebSocket
 * buscar na hora. Sem Redis, o servidor busca sozinho a cada segundo.
 */
final class TempoReal
{
    private const TAMANHO_MAXIMO = 8192;
    private static bool $avisoAgendado = false;

    /** @param array<string,mixed> $dados */
    public static function registrar(PDO $db, ?int $idRestaurante, string $canal, string $tipo, array $dados = []): int
    {
        if (!Canais::existe($canal)) {
            throw new InvalidArgumentException('Canal de tempo real desconhecido: ' . $canal);
        }
        if (preg_match('/\A[a-z]+([._][a-z]+){1,6}\z/', $tipo) !== 1 || strlen($tipo) > 80) {
            throw new InvalidArgumentException('Tipo de evento invalido: ' . $tipo);
        }
        $json = json_encode($dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::TAMANHO_MAXIMO) {
            throw new InvalidArgumentException('Dados do evento grandes demais (a tela deve buscar o resto pela API).');
        }
        $id = (new OutboxRepository($db))->inserir($idRestaurante, $canal, $tipo, $json, date('Y-m-d H:i:s'));
        self::agendarAviso();
        return $id;
    }

    /** @param array<string,mixed> $dados */
    public static function publicar(?int $idRestaurante, string $canal, string $tipo, array $dados = []): ?int
    {
        try {
            return self::registrar(Database::connect(), $idRestaurante, $canal, $tipo, $dados);
        } catch (InvalidArgumentException $erro) {
            throw $erro; // erro de programacao: precisa aparecer nos testes
        } catch (Throwable $erro) {
            error_log('[Cardapim] Tempo real: evento nao gravado (' . get_class($erro) . ').');
            return null;
        }
    }

    /** O navegador deve tentar conectar? (WS_ATIVO=1 depois que o servidor e o Nginx estiverem prontos) */
    public static function ativo(): bool
    {
        return Config::get('WS_ATIVO') === '1';
    }

    /** Endereco publico do WebSocket: WS_URL ou derivado do APP_URL (https -> wss, /ws). */
    public static function urlPublica(): string
    {
        $url = Config::get('WS_URL');
        if (preg_match('~\Awss?://[A-Za-z0-9.:-]+(/[A-Za-z0-9/_-]*)?\z~', $url) === 1) {
            return $url;
        }
        $app = rtrim(Config::get('APP_URL'), '/');
        if (preg_match('~\Ahttps://([A-Za-z0-9.-]+(?::\d+)?)\z~', $app, $m) === 1) {
            return 'wss://' . $m[1] . '/ws';
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        return preg_match('/\A[A-Za-z0-9.-]+(:\d+)?\z/', $host) === 1 ? 'wss://' . $host . '/ws' : '';
    }

    private static function agendarAviso(): void
    {
        if (self::$avisoAgendado || !RedisConexao::configurado()) {
            return;
        }
        self::$avisoAgendado = true;
        // No fim da requisicao a transacao ja foi confirmada (ou desfeita: o servidor so nao acha nada).
        register_shutdown_function(static function (): void {
            self::$avisoAgendado = false;
            try {
                $redis = RedisConexao::conectar(0.3);
                $redis->comando('PUBLISH', RedisConexao::canal('outbox'), 'novo');
                $redis->fechar();
            } catch (Throwable) {
                // Sem Redis o servidor busca sozinho em ate 1 s. Nao precisa registrar a cada requisicao.
            }
        });
    }
}
