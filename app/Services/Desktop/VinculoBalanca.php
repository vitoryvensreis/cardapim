<?php

declare(strict_types=1);

namespace Cardapim\Services\Desktop;

use Cardapim\Shared\Seguranca\Cripto;

/**
 * Associa o navegador deste caixa ao agente Windows configurado.
 * O agente gera um link de curta duracao, o navegador recebe um cookie assinado.
 * Nunca aceita o id do dispositivo como prova de vinculo.
 */
final class VinculoBalanca
{
    public const COOKIE = 'deliveryzim_balanca';
    private const PRAZO_LINK = 600; // 10 min: da tempo de entrar (com 2FA) quando o Agent abre o navegador
    private const PRAZO_COOKIE = 7776000; // 90 dias

    public static function gerarTicket(int $restaurante, int $dispositivo, ?int $agora = null): string
    {
        $agora ??= time();
        $dados = $restaurante . ':' . $dispositivo . ':' . ($agora + self::PRAZO_LINK) . ':' . bin2hex(random_bytes(12));
        return self::base64url($dados) . '.' . self::assinar('link:' . $dados);
    }

    public static function verificarTicket(string $ticket, int $restaurante, ?int $agora = null): ?int
    {
        $agora ??= time();
        if (strlen($ticket) > 256 || !preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D', $ticket, $m)) {
            return null;
        }
        $dados = self::decodificar($m[1]);
        if ($dados === null || !hash_equals(self::assinar('link:' . $dados), $m[2])) {
            return null;
        }
        if (!preg_match('/\A([1-9][0-9]*):([1-9][0-9]*):([0-9]{10}):([a-f0-9]{24})\z/D', $dados, $partes)) {
            return null;
        }
        $expira = (int) $partes[3];
        return (int) $partes[1] === $restaurante && $expira >= $agora && $expira <= $agora + self::PRAZO_LINK
            ? (int) $partes[2] : null;
    }

    public static function cookieAssinado(int $restaurante, int $dispositivo, ?int $agora = null): string
    {
        $agora ??= time();
        $dados = $restaurante . ':' . $dispositivo . ':' . ($agora + self::PRAZO_COOKIE);
        return self::base64url($dados) . '.' . self::assinar('cookie:' . $dados);
    }

    public static function verificarCookie(mixed $cookie, int $restaurante, ?int $agora = null): ?int
    {
        $agora ??= time();
        if (!is_string($cookie) || strlen($cookie) > 256
            || !preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D', $cookie, $m)) {
            return null;
        }
        $dados = self::decodificar($m[1]);
        if ($dados === null || !hash_equals(self::assinar('cookie:' . $dados), $m[2])
            || !preg_match('/\A([1-9][0-9]*):([1-9][0-9]*):([0-9]{10})\z/D', $dados, $partes)) {
            return null;
        }
        $expira = (int) $partes[3];
        return (int) $partes[1] === $restaurante && $expira >= $agora && $expira <= $agora + self::PRAZO_COOKIE
            ? (int) $partes[2] : null;
    }

    public static function gravarCookie(int $restaurante, int $dispositivo): void
    {
        setcookie(self::COOKIE, self::cookieAssinado($restaurante, $dispositivo), [
            'expires' => time() + self::PRAZO_COOKIE,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function assinar(string $dados): string
    {
        return hash_hmac('sha256', $dados, Cripto::derivarChave('desktop-balanca-pareamento-v1'));
    }

    private static function base64url(string $texto): string
    {
        return rtrim(strtr(base64_encode($texto), '+/', '-_'), '=');
    }

    private static function decodificar(string $texto): ?string
    {
        $decoded = base64_decode(strtr($texto, '-_', '+/') . str_repeat('=', (4 - strlen($texto) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }
}
