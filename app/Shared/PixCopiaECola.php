<?php

declare(strict_types=1);

namespace Cardapim\Shared;

use Cardapim\Shared\Validation\Documento;

/**
 * Pix pela chave do restaurante: monta o "copia e cola" (BR Code estatico) pelo padrao EMV do Banco Central,
 * sem chamar nenhum servico. O QR Code e so este texto desenhado. Nao ha confirmacao automatica do pagamento.
 *
 * Campos: 00 versao, 26 conta (GUI br.gov.bcb.pix + chave), 52 categoria, 53 moeda (986 = real), 54 valor,
 * 58 pais, 59 nome de quem recebe, 60 cidade, 62/05 identificador (txid), 63 CRC16-CCITT.
 */
final class PixCopiaECola
{
    public const TIPOS = [
        'cpf' => 'CPF',
        'cnpj' => 'CNPJ',
        'celular' => 'Celular',
        'email' => 'E-mail',
        'aleatoria' => 'Chave aleatória',
    ];
    public const MAX_NOME = 25;
    public const MAX_CIDADE = 15;

    /**
     * @param int|null $centavos null = o cliente digita o valor (so usado no exemplo do Banco Central)
     * @throws \InvalidArgumentException chave, nome ou cidade vazios
     */
    public static function gerar(string $chave, string $nome, string $cidade, ?int $centavos, string $txid = '***'): string
    {
        $chave = trim($chave);
        $nome = self::texto($nome, self::MAX_NOME);
        $cidade = self::texto($cidade, self::MAX_CIDADE);
        if ($chave === '' || strlen($chave) > 77 || $nome === '' || $cidade === '') {
            throw new \InvalidArgumentException('Pix pela chave incompleto.');
        }
        if ($centavos !== null && ($centavos <= 0 || $centavos > 99999999)) {
            throw new \InvalidArgumentException('Valor do Pix fora do limite.');
        }
        $txid = preg_replace('/[^A-Za-z0-9]/', '', $txid) ?? '';
        $txid = $txid === '' ? '***' : substr($txid, 0, 25);
        $codigo = self::campo('00', '01')
            . self::campo('26', self::campo('00', 'br.gov.bcb.pix') . self::campo('01', $chave))
            . self::campo('52', '0000')
            . self::campo('53', '986')
            . ($centavos !== null ? self::campo('54', number_format($centavos / 100, 2, '.', '')) : '')
            . self::campo('58', 'BR')
            . self::campo('59', $nome)
            . self::campo('60', $cidade)
            . self::campo('62', self::campo('05', $txid))
            . '6304';
        return $codigo . self::crc16($codigo);
    }

    /** CRC16-CCITT (polinomio 0x1021, inicio 0xFFFF), em 4 digitos hexadecimais maiusculos. */
    public static function crc16(string $dados): string
    {
        $crc = 0xFFFF;
        $tamanho = strlen($dados);
        for ($i = 0; $i < $tamanho; $i++) {
            $crc ^= ord($dados[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return sprintf('%04X', $crc);
    }

    /**
     * Confere e padroniza a chave como o banco espera.
     * @return array{chave:?string,erro:?string}
     */
    public static function normalizarChave(string $tipo, string $chave): array
    {
        $chave = trim($chave);
        $digitos = preg_replace('/\D/', '', $chave) ?? '';
        switch ($tipo) {
            case 'cpf':
                return Documento::cpfValido($digitos) ? ['chave' => $digitos, 'erro' => null] : ['chave' => null, 'erro' => 'Digite um CPF válido (11 números).'];
            case 'cnpj':
                return Documento::cnpjValido($digitos) ? ['chave' => $digitos, 'erro' => null] : ['chave' => null, 'erro' => 'Digite um CNPJ válido (14 números).'];
            case 'celular':
                if (strlen($digitos) === 13 && str_starts_with($digitos, '55')) {
                    $digitos = substr($digitos, 2);
                }
                return preg_match('/\A[1-9][0-9][2-9][0-9]{7,8}\z/', $digitos) === 1
                    ? ['chave' => '+55' . $digitos, 'erro' => null]
                    : ['chave' => null, 'erro' => 'Digite o celular com DDD, como (85) 99999-9999.'];
            case 'email':
                $email = mb_strtolower($chave);
                return strlen($email) <= 77 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                    ? ['chave' => $email, 'erro' => null]
                    : ['chave' => null, 'erro' => 'Digite um e-mail válido.'];
            case 'aleatoria':
                $uuid = mb_strtolower($chave);
                return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $uuid) === 1
                    ? ['chave' => $uuid, 'erro' => null]
                    : ['chave' => null, 'erro' => 'Cole a chave aleatória completa (36 caracteres, com os tracinhos).'];
        }
        return ['chave' => null, 'erro' => 'Escolha o tipo da chave Pix.'];
    }

    /** Nome e cidade: sem acento, so letras, numeros e espacos, cortado no limite do padrao. */
    public static function texto(string $texto, int $limite): string
    {
        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Î' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ò' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Û' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
        ]);
        $texto = preg_replace('/[^A-Za-z0-9 ]/', '', $texto) ?? '';
        $texto = trim((string) preg_replace('/\s+/', ' ', $texto));
        return trim(substr($texto, 0, $limite));
    }

    private static function campo(string $id, string $valor): string
    {
        return $id . sprintf('%02d', strlen($valor)) . $valor;
    }
}
