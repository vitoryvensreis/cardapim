<?php

declare(strict_types=1);

namespace Cardapim\Realtime;

use Cardapim\Shared\Seguranca\Cripto;

/**
 * Ticket de curta duracao para abrir a conexao WebSocket.
 * Emitido pela API (com a sessao do login) e conferido pelo servidor WebSocket, que nao le a sessao.
 * Formato: base64url(json).base64url(hmac-sha256). Uso unico (o servidor guarda o "n" ate expirar).
 */
final class Ticket
{
    public const VALIDADE = 60;
    private const PROPOSITO = 'tempo-real-ticket-v1';

    public static function emitir(int $idUsuario, int $idRestaurante, string $cargo, ?int $agora = null): string
    {
        $agora ??= time();
        $dados = self::b64(json_encode([
            'u' => $idUsuario,
            'r' => $idRestaurante,
            'c' => $cargo,
            'exp' => $agora + self::VALIDADE,
            'n' => bin2hex(random_bytes(12)),
        ], JSON_THROW_ON_ERROR));
        return $dados . '.' . self::b64(self::assinar($dados));
    }

    /**
     * @return array{u:int,r:int,c:string,exp:int,n:string}|null null = invalido, adulterado ou vencido
     */
    public static function validar(string $ticket, ?int $agora = null): ?array
    {
        if (strlen($ticket) > 400 || preg_match('/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\z/', $ticket, $m) !== 1) {
            return null;
        }
        $assinatura = self::deB64($m[2]);
        if ($assinatura === null || !hash_equals(self::assinar($m[1]), $assinatura)) {
            return null;
        }
        $json = self::deB64($m[1]);
        $dados = $json !== null ? json_decode($json, true) : null;
        if (!is_array($dados) || !is_int($dados['u'] ?? null) || !is_int($dados['r'] ?? null) || !is_int($dados['exp'] ?? null)
            || !is_string($dados['c'] ?? null) || !is_string($dados['n'] ?? null) || preg_match('/\A[a-f0-9]{24}\z/', $dados['n']) !== 1) {
            return null;
        }
        if ($dados['exp'] < ($agora ?? time()) || $dados['u'] < 1 || $dados['r'] < 1) {
            return null;
        }
        return ['u' => $dados['u'], 'r' => $dados['r'], 'c' => $dados['c'], 'exp' => $dados['exp'], 'n' => $dados['n']];
    }

    private static function assinar(string $dados): string
    {
        return hash_hmac('sha256', $dados, Cripto::derivarChave(self::PROPOSITO), true);
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function deB64(string $txt): ?string
    {
        $bin = base64_decode(strtr($txt, '-_', '+/'), true);
        return $bin === false ? null : $bin;
    }
}
