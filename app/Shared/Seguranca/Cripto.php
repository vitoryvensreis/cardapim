<?php

declare(strict_types=1);

namespace Cardapim\Shared\Seguranca;

use RuntimeException;

/**
 * Criptografia de dados sensiveis guardados no banco (ex.: segredo do autenticador).
 * AES-256-GCM (confidencialidade + integridade). A chave fica FORA do banco e do Git:
 * variavel APP_KEY (base64 de 32 bytes) ou arquivo storage/keys/app.key (criado na primeira vez, permissao 0600).
 * Se a chave for perdida, os segredos nao podem ser lidos: os usuarios entram com o codigo de recuperacao.
 */
final class Cripto
{
    private const VERSAO = 'v1';
    private const CIFRA = 'aes-256-gcm';

    private static ?string $chave = null;

    public static function disponivel(): bool
    {
        return function_exists('openssl_encrypt') && in_array(self::CIFRA, openssl_get_cipher_methods(), true);
    }

    public static function cifrar(string $texto): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cifrado = openssl_encrypt($texto, self::CIFRA, self::chave(), OPENSSL_RAW_DATA, $iv, $tag, self::VERSAO, 16);
        if ($cifrado === false) {
            throw new RuntimeException('Falha ao cifrar.');
        }
        return self::VERSAO . ':' . base64_encode($iv . $tag . $cifrado);
    }

    public static function decifrar(string $pacote): string
    {
        [$versao, $dados] = array_pad(explode(':', $pacote, 2), 2, '');
        $bruto = base64_decode($dados, true);
        if ($versao !== self::VERSAO || $bruto === false || strlen($bruto) < 29) {
            throw new RuntimeException('Dado cifrado invalido.');
        }
        $texto = openssl_decrypt(substr($bruto, 28), self::CIFRA, self::chave(), OPENSSL_RAW_DATA, substr($bruto, 0, 12), substr($bruto, 12, 16), self::VERSAO);
        if ($texto === false) {
            throw new RuntimeException('Nao foi possivel decifrar (chave diferente ou dado alterado).');
        }
        return $texto;
    }

    /** Para testes: usa uma chave fixa em memoria. */
    public static function usarChave(?string $chaveBinaria): void
    {
        self::$chave = $chaveBinaria;
    }

    private static function chave(): string
    {
        if (self::$chave !== null) {
            return self::$chave;
        }
        $env = \Cardapim\Core\Config::get('APP_KEY');
        if (is_string($env) && $env !== '') {
            $chave = base64_decode(str_starts_with($env, 'base64:') ? substr($env, 7) : $env, true);
            if ($chave !== false && strlen($chave) === 32) {
                return self::$chave = $chave;
            }
        }
        $arquivo = dirname(__DIR__, 3) . '/storage/keys/app.key';
        if (!is_file($arquivo)) {
            $pasta = dirname($arquivo);
            if (!is_dir($pasta) && !mkdir($pasta, 0700, true) && !is_dir($pasta)) {
                throw new RuntimeException('Nao foi possivel criar a pasta da chave.');
            }
            $nova = base64_encode(random_bytes(32));
            // 'x' falha se outro processo criou o arquivo ao mesmo tempo: entao so lemos o dele.
            $handle = @fopen($arquivo, 'x');
            if ($handle !== false) {
                fwrite($handle, $nova);
                fclose($handle);
                @chmod($arquivo, 0600);
            }
        }
        $chave = base64_decode(trim((string) file_get_contents($arquivo)), true);
        if ($chave === false || strlen($chave) !== 32) {
            throw new RuntimeException('Chave de criptografia invalida.');
        }
        return self::$chave = $chave;
    }
}
