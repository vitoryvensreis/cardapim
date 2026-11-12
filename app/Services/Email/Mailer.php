<?php

declare(strict_types=1);

namespace Cardapim\Services\Email;

use Cardapim\Core\Config;
use Throwable;

/**
 * Envio de e-mails simples (texto).
 * - MAIL_DRIVER=smtp: servidor de e-mail (ex.: mailcow) com MAIL_HOST, MAIL_PORT (587), MAIL_ENCRYPTION (tls|ssl|none),
 *   MAIL_USERNAME e MAIL_PASSWORD. E o recomendado em producao (o servidor assina com DKIM).
 * - MAIL_DRIVER=mail (padrao): mail() do servidor.
 * - MAIL_DRIVER=log: grava em storage/logs/mail.log (testes e desenvolvimento, nunca em producao).
 * Remetente: MAIL_FROM, ou no-reply@ dominio do APP_URL. Nome: a marca.
 */
final class Mailer
{
    private static ?string $ultimoErro = null;

    /** @var list<array{0:string,1:string,2:string,3:?string}> */
    private static array $fila = [];

    public static function enviar(string $para, string $assunto, string $texto, ?string $responderPara = null): bool
    {
        self::$ultimoErro = null;
        if (filter_var($para, FILTER_VALIDATE_EMAIL) === false || str_ends_with(strtolower($para), '.invalid')) {
            self::$ultimoErro = 'Endereço de e-mail inválido.';
            return false;
        }
        $driver = Config::get('MAIL_DRIVER', 'mail');
        if ($driver === 'log') {
            $linha = sprintf("[%s] Para: %s | Assunto: %s\n%s\n---\n", date('Y-m-d H:i:s'), $para, $assunto, $texto);
            return file_put_contents(dirname(__DIR__, 3) . '/storage/logs/mail.log', $linha, FILE_APPEND | LOCK_EX) !== false;
        }
        $remetente = self::remetente();
        if ($driver === 'smtp') {
            try {
                self::smtp()->enviar($remetente, marca(), $para, $assunto, $texto, $responderPara);
                return true;
            } catch (Throwable $erro) {
                self::$ultimoErro = $erro->getMessage();
                error_log('[Cardapim] Falha ao enviar e-mail por SMTP: ' . $erro->getMessage());
                return false;
            }
        }
        if (!function_exists('mail')) {
            self::$ultimoErro = 'O servidor não tem envio de e-mail configurado.';
            return false;
        }
        $cabecalhos = implode("\r\n", [
            'From: ' . marca() . ' <' . $remetente . '>',
            'Reply-To: ' . ($responderPara !== null && filter_var($responderPara, FILTER_VALIDATE_EMAIL) !== false ? $responderPara : $remetente),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: ' . marca(),
        ]);
        try {
            $ok = mail($para, mb_encode_mimeheader($assunto, 'UTF-8', 'B'), $texto, $cabecalhos, '-f' . $remetente);
            if (!$ok) {
                self::$ultimoErro = 'O servidor não aceitou o e-mail.';
            }
            return $ok;
        } catch (Throwable $exception) {
            self::$ultimoErro = 'O servidor não aceitou o e-mail.';
            error_log('[Cardapim] Falha ao enviar e-mail: ' . get_class($exception));
            return false;
        }
    }

    /** Envia depois de a tela responder (avisos que nao precisam travar quem clicou). */
    public static function agendar(string $para, string $assunto, string $texto, ?string $responderPara = null): void
    {
        if (filter_var($para, FILTER_VALIDATE_EMAIL) === false || str_ends_with(strtolower($para), '.invalid')) {
            return;
        }
        $primeiro = self::$fila === [];
        self::$fila[] = [$para, $assunto, $texto, $responderPara];
        if (!$primeiro) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            register_shutdown_function(static fn () => self::enviarFila());
            return;
        }
        register_shutdown_function(static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            self::enviarFila();
        });
    }

    public static function ultimoErro(): ?string
    {
        return self::$ultimoErro;
    }

    /** Como o envio esta configurado (sem segredos), para a tela da administracao. @return array{driver:string, host:?string, porta:?int, seguranca:?string, usuario:?string, remetente:string} */
    public static function configuracao(): array
    {
        $driver = Config::get('MAIL_DRIVER', 'mail');
        $smtp = $driver === 'smtp';
        return [
            'driver' => $driver,
            'host' => $smtp ? Config::get('MAIL_HOST') : null,
            'porta' => $smtp ? (int) Config::get('MAIL_PORT', '587') : null,
            'seguranca' => $smtp ? self::seguranca() : null,
            'usuario' => $smtp ? Config::get('MAIL_USERNAME') : null,
            'remetente' => self::remetente(),
        ];
    }

    private static function enviarFila(): void
    {
        $fila = self::$fila;
        self::$fila = [];
        foreach ($fila as [$para, $assunto, $texto, $responder]) {
            try {
                self::enviar($para, $assunto, $texto, $responder);
            } catch (Throwable $erro) {
                error_log('[Cardapim] Aviso por e-mail nao enviado: ' . get_class($erro));
            }
        }
    }

    private static function smtp(): SmtpCliente
    {
        $host = Config::get('MAIL_HOST');
        if (preg_match('/\A[A-Za-z0-9.-]{1,253}\z/', $host) !== 1) {
            throw new \RuntimeException('MAIL_HOST não configurado.');
        }
        $seguranca = self::seguranca();
        $porta = (int) Config::get('MAIL_PORT', $seguranca === 'ssl' ? '465' : '587');
        return new SmtpCliente($host, $porta > 0 && $porta < 65536 ? $porta : 587, $seguranca, Config::get('MAIL_USERNAME'), Config::get('MAIL_PASSWORD'));
    }

    private static function seguranca(): string
    {
        $s = strtolower(Config::get('MAIL_ENCRYPTION', 'tls'));
        return in_array($s, ['tls', 'ssl', 'none'], true) ? $s : 'tls';
    }

    private static function remetente(): string
    {
        $configurado = Config::get('MAIL_FROM');
        if (filter_var($configurado, FILTER_VALIDATE_EMAIL) !== false) {
            return $configurado;
        }
        $host = parse_url(Config::get('APP_URL'), PHP_URL_HOST);
        return 'no-reply@' . (is_string($host) && $host !== '' ? $host : 'localhost');
    }
}
