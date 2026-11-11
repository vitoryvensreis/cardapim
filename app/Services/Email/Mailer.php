<?php

declare(strict_types=1);

namespace Cardapim\Services\Email;

use Cardapim\Core\Config;

/**
 * Envio de e-mails simples (texto). MAIL_DRIVER=mail (padrao) usa o mail() do servidor;
 * MAIL_DRIVER=log grava em storage/logs/mail.log (testes e desenvolvimento, nunca em producao).
 * Remetente: MAIL_FROM, ou no-reply@ dominio do APP_URL.
 */
final class Mailer
{
    public static function enviar(string $para, string $assunto, string $texto): bool
    {
        if (filter_var($para, FILTER_VALIDATE_EMAIL) === false || str_ends_with(strtolower($para), '.invalid')) {
            return false;
        }
        $driver = Config::get('MAIL_DRIVER', 'mail');
        if ($driver === 'log') {
            $linha = sprintf("[%s] Para: %s | Assunto: %s\n%s\n---\n", date('Y-m-d H:i:s'), $para, $assunto, $texto);
            return file_put_contents(dirname(__DIR__, 3) . '/storage/logs/mail.log', $linha, FILE_APPEND | LOCK_EX) !== false;
        }
        if (!function_exists('mail')) {
            return false;
        }
        $remetente = self::remetente();
        $cabecalhos = implode("\r\n", [
            'From: ' . marca() . ' <' . $remetente . '>',
            'Reply-To: ' . $remetente,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: ' . marca(),
        ]);
        try {
            return mail($para, mb_encode_mimeheader($assunto, 'UTF-8', 'B'), $texto, $cabecalhos, '-f' . $remetente);
        } catch (\Throwable $exception) {
            error_log('[Cardapim] Falha ao enviar e-mail: ' . get_class($exception));
            return false;
        }
    }

    private static function remetente(): string
    {
        $configurado = Config::get('MAIL_FROM');
        if (is_string($configurado) && filter_var($configurado, FILTER_VALIDATE_EMAIL) !== false) {
            return $configurado;
        }
        $host = parse_url(Config::get('APP_URL'), PHP_URL_HOST);
        return 'no-reply@' . (is_string($host) && $host !== '' ? $host : 'localhost');
    }
}
