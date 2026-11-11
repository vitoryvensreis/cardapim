<?php

declare(strict_types=1);

namespace Cardapim\Shared\Seguranca;

/**
 * Codigos de 6 digitos que mudam a cada 30 segundos (TOTP, RFC 6238 / RFC 4226),
 * compativeis com Google Authenticator, Microsoft Authenticator, Authy etc.
 */
final class Totp
{
    public const DIGITOS = 6;
    public const PASSO = 30;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Segredo novo em Base32 (160 bits, como recomenda a RFC 4226). */
    public static function gerarSegredo(): string
    {
        return self::base32(random_bytes(20));
    }

    /** Codigo para um instante (padrao: agora). */
    public static function codigo(string $segredoBase32, ?int $instante = null): string
    {
        return self::hotp(self::base32Decodificar($segredoBase32), intdiv($instante ?? time(), self::PASSO));
    }

    /**
     * Confere o codigo aceitando 1 passo antes/depois (relogio do celular um pouco adiantado/atrasado).
     * @return int|null passo de tempo que bateu (para impedir reuso), ou null se invalido
     */
    public static function verificar(string $segredoBase32, string $codigo, ?int $instante = null, int $janela = 1): ?int
    {
        $codigo = preg_replace('/\D+/', '', $codigo) ?? '';
        if (strlen($codigo) !== self::DIGITOS) {
            return null;
        }
        $chave = self::base32Decodificar($segredoBase32);
        $passo = intdiv($instante ?? time(), self::PASSO);
        for ($i = -$janela; $i <= $janela; $i++) {
            if (hash_equals(self::hotp($chave, $passo + $i), $codigo)) {
                return $passo + $i;
            }
        }
        return null;
    }

    /** Endereco otpauth:// que o aplicativo le pelo QR Code. */
    public static function uri(string $segredoBase32, string $conta, ?string $emissor = null): string
    {
        $emissor ??= marca();
        return 'otpauth://totp/' . rawurlencode($emissor . ':' . $conta)
            . '?secret=' . $segredoBase32 . '&issuer=' . rawurlencode($emissor)
            . '&algorithm=SHA1&digits=' . self::DIGITOS . '&period=' . self::PASSO;
    }

    /** "JBSWY3DPEHPK3PXP" -> "JBSW Y3DP EHPK 3PXP" (mais facil de digitar). */
    public static function agrupar(string $segredoBase32): string
    {
        return trim(chunk_split($segredoBase32, 4, ' '));
    }

    public static function hotp(string $chaveBinaria, int $contador): string
    {
        $mensagem = pack('N*', ($contador >> 32) & 0xFFFFFFFF, $contador & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $mensagem, $chaveBinaria, true);
        $offset = ord($hash[19]) & 0x0F;
        $valor = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($valor % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    public static function base32(string $binario): string
    {
        $bits = '';
        foreach (str_split($binario) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $saida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $saida .= self::BASE32[bindec(str_pad($grupo, 5, '0'))];
        }
        return $saida;
    }

    public static function base32Decodificar(string $texto): string
    {
        $texto = strtoupper(preg_replace('/[\s=-]+/', '', $texto) ?? '');
        $bits = '';
        foreach (str_split($texto) as $letra) {
            $posicao = strpos(self::BASE32, $letra);
            if ($posicao === false) {
                throw new \InvalidArgumentException('Segredo Base32 invalido.');
            }
            $bits .= str_pad(decbin($posicao), 5, '0', STR_PAD_LEFT);
        }
        $binario = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binario .= chr(bindec($byte));
            }
        }
        return $binario;
    }
}
