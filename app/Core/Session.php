<?php

declare(strict_types=1);

namespace Cardapim\Core;

/**
 * Sessao PHP com cookie seguro, expiracao por inatividade e token CSRF.
 * Arquivos em storage/sessions (fora do diretorio publico, persistente entre releases).
 */
final class Session
{
    public const NOME = 'cardapim_sessao';
    private const INATIVIDADE_SEGUNDOS = 7200;   // 2 horas sem uso
    private const DURACAO_MAXIMA_SEGUNDOS = 43200; // 12 horas desde o login

    private static bool $iniciada = false;

    public static function iniciar(): void
    {
        if (self::$iniciada || session_status() === PHP_SESSION_ACTIVE) {
            self::$iniciada = true;
            return;
        }

        $dir = dirname(__DIR__, 2) . '/storage/sessions';
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            session_save_path($dir);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::DURACAO_MAXIMA_SEGUNDOS);

        session_name(self::NOME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$iniciada = true;

        $agora = time();
        $ultimo = (int) ($_SESSION['_ultimo_uso'] ?? $agora);
        $inicio = (int) ($_SESSION['_inicio'] ?? $agora);
        if ($agora - $ultimo > self::INATIVIDADE_SEGUNDOS || $agora - $inicio > self::DURACAO_MAXIMA_SEGUNDOS) {
            self::encerrar();
            session_start();
            $inicio = $agora;
        }
        $_SESSION['_inicio'] = $inicio;
        $_SESSION['_ultimo_uso'] = $agora;
    }

    /** Novo ID apos login para impedir fixacao de sessao. */
    public static function renovarId(): void
    {
        self::iniciar();
        session_regenerate_id(true);
        $_SESSION['_inicio'] = time();
        unset($_SESSION['_csrf']);
    }

    public static function get(string $chave, mixed $padrao = null): mixed
    {
        self::iniciar();
        return $_SESSION[$chave] ?? $padrao;
    }

    public static function set(string $chave, mixed $valor): void
    {
        self::iniciar();
        $_SESSION[$chave] = $valor;
    }

    public static function remover(string $chave): void
    {
        self::iniciar();
        unset($_SESSION[$chave]);
    }

    /** Le e apaga (mensagens de uma unica exibicao). */
    public static function flash(string $chave): mixed
    {
        $valor = self::get($chave);
        self::remover($chave);
        return $valor;
    }

    public static function encerrar(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(self::NOME, '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }

    public static function tokenCsrf(): string
    {
        $token = self::get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            self::set('_csrf', $token);
        }
        return $token;
    }

    public static function csrfValido(mixed $token): bool
    {
        $esperado = self::get('_csrf');
        return is_string($token) && is_string($esperado) && $esperado !== '' && hash_equals($esperado, $token);
    }

    private static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
