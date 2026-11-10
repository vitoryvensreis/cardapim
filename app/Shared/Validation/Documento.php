<?php

declare(strict_types=1);

namespace Cardapim\Shared\Validation;

/**
 * Normalizacao e validacao server-side de documentos e telefones brasileiros.
 * As mascaras do navegador sao apenas conforto; esta classe e a regra oficial.
 */
final class Documento
{
    public static function somenteDigitos(string $valor): string
    {
        return (string) preg_replace('/\D+/', '', $valor);
    }

    public static function cpfValido(string $cpf): bool
    {
        $cpf = self::somenteDigitos($cpf);
        if (strlen($cpf) !== 11 || preg_match('/\A(\d)\1{10}\z/', $cpf) === 1) {
            return false;
        }
        for ($tamanho = 9; $tamanho < 11; $tamanho++) {
            $soma = 0;
            for ($i = 0; $i < $tamanho; $i++) {
                $soma += (int) $cpf[$i] * ($tamanho + 1 - $i);
            }
            if ((($soma * 10) % 11) % 10 !== (int) $cpf[$tamanho]) {
                return false;
            }
        }
        return true;
    }

    public static function cnpjValido(string $cnpj): bool
    {
        $cnpj = self::somenteDigitos($cnpj);
        if (strlen($cnpj) !== 14 || preg_match('/\A(\d)\1{13}\z/', $cnpj) === 1) {
            return false;
        }
        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $posicao => $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }
            $resto = $soma % 11;
            if (($resto < 2 ? 0 : 11 - $resto) !== (int) $cnpj[$posicao]) {
                return false;
            }
        }
        return true;
    }

    /** CPF normalizado (11 digitos) ou null quando invalido. */
    public static function normalizarCpf(string $cpf): ?string
    {
        return self::cpfValido($cpf) ? self::somenteDigitos($cpf) : null;
    }

    /** Telefone nacional com DDD (10 ou 11 digitos; celular comeca com 9). */
    public static function normalizarTelefone(string $telefone): ?string
    {
        $digitos = self::somenteDigitos($telefone);
        if (strlen($digitos) === 13 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }
        $tamanho = strlen($digitos);
        if (($tamanho !== 10 && $tamanho !== 11) || $digitos[0] === '0') {
            return null;
        }
        if ($tamanho === 11 && $digitos[2] !== '9') {
            return null;
        }
        return $digitos;
    }

    /** "11987654321" -> "(11) 98765-4321" */
    public static function formatarTelefone(string $telefone): string
    {
        $d = self::somenteDigitos($telefone);
        return match (strlen($d)) {
            11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
            10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
            default => $telefone,
        };
    }

    /** "11222333000181" -> "11.222.333/0001-81" */
    public static function formatarCnpj(string $cnpj): string
    {
        $d = self::somenteDigitos($cnpj);
        return strlen($d) === 14
            ? sprintf('%s.%s.%s/%s-%s', substr($d, 0, 2), substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 4), substr($d, 12))
            : $cnpj;
    }

    public static function formatarCpf(string $cpf): string
    {
        $d = self::somenteDigitos($cpf);
        return strlen($d) === 11 ? sprintf('%s.%s.%s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9)) : $cpf;
    }

    /** Exibicao segura (LGPD): ***.456.789-** */
    public static function mascararCpf(string $cpf): string
    {
        $d = self::somenteDigitos($cpf);
        return strlen($d) === 11 ? '***.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-**' : '***';
    }
}
