<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Shared\Numero;

/**
 * Taxa opcional por forma de pagamento (pedidos do cardapio online). Cada forma tem porcentagem OU valor fixo;
 * dinheiro nunca tem taxa. Sem regra intermediaria: aqui so o calculo e os textos (nada de banco).
 *
 * Calculo: base = itens + entrega - desconto (o que o cliente pagaria sem a taxa).
 * Porcentagem em centesimos de ponto (3,5% = 350) e arredondada meio para cima, em centavos inteiros.
 * O JS do checkout usa a MESMA conta (checkout.js taxaDe) so para mostrar; o servidor confere.
 */
final class TaxaPagamento
{
    /** forma => nome curto usado no rotulo ("Taxa do crédito (3,5%)") */
    public const FORMAS = [
        'pix_online' => 'Pix',
        'pix_comprovante' => 'Pix',
        'pix_entrega' => 'Pix',
        'debito' => 'débito',
        'credito' => 'crédito',
    ];
    public const MAX_PERCENTUAL = 2000; // 20,00%
    public const MAX_FIXO = 5000;       // R$ 50,00

    /**
     * Valor da taxa em centavos.
     * @param array{tipo:string,valor:string}|null $regra
     */
    public static function calcular(int $baseCentavos, ?array $regra): int
    {
        if ($regra === null || $baseCentavos <= 0) {
            return 0;
        }
        $valor = Numero::centavos($regra['valor']); // 3.50 -> 350 (centesimos de ponto, para a porcentagem)
        if ($regra['tipo'] === 'fixo') {
            return max(0, $valor);
        }
        return max(0, intdiv($baseCentavos * $valor + 5000, 10000));
    }

    /** "+ 3,5%" ou "+ R$ 1,00" (ao lado da forma no checkout). @param array{tipo:string,valor:string} $regra */
    public static function texto(array $regra): string
    {
        return '+ ' . ($regra['tipo'] === 'fixo' ? Numero::reais($regra['valor']) : self::porcentagem($regra['valor']));
    }

    /** "Taxa do crédito (3,5%)": guardado no pedido como foto da regra. @param array{tipo:string,valor:string} $regra */
    public static function rotulo(string $forma, array $regra): string
    {
        $nome = self::FORMAS[$forma] ?? $forma;
        $de = $nome === 'Pix' ? 'do Pix' : 'do ' . $nome;
        return 'Taxa ' . $de . ' (' . ($regra['tipo'] === 'fixo' ? Numero::reais($regra['valor']) : self::porcentagem($regra['valor'])) . ')';
    }

    /** "3,5%" (sem zeros sobrando). */
    public static function porcentagem(string $valor): string
    {
        return rtrim(rtrim(number_format((float) $valor, 2, ',', ''), '0'), ',') . '%';
    }

    /**
     * Le o que o gerente digitou. Vazio ou "Sem taxa" = sem regra.
     * @return array{regra:?array{tipo:string,valor:string},erro:?string}
     */
    public static function ler(string $tipo, string $texto): array
    {
        $texto = trim($texto);
        if ($tipo === '' || $tipo === 'nenhuma') {
            return ['regra' => null, 'erro' => null];
        }
        if (!in_array($tipo, ['percentual', 'fixo'], true)) {
            return ['regra' => null, 'erro' => 'Escolha porcentagem ou valor fixo.'];
        }
        if ($tipo === 'percentual') {
            $limpo = str_replace([' ', '%'], '', $texto);
            if (preg_match('/\A\d{1,2}([.,]\d{1,2})?\z/', $limpo) !== 1) {
                return ['regra' => null, 'erro' => 'Digite a porcentagem (ex.: 3,5).'];
            }
            $centesimos = Numero::centavos(str_replace(',', '.', $limpo));
            if ($centesimos < 1 || $centesimos > self::MAX_PERCENTUAL) {
                return ['regra' => null, 'erro' => 'A porcentagem precisa ser de 0,01% a 20%.'];
            }
            return ['regra' => ['tipo' => 'percentual', 'valor' => self::dec($centesimos)], 'erro' => null];
        }
        $dec = Numero::dinheiroParaDecimal($texto);
        $centavos = $dec !== null ? Numero::centavos($dec) : 0;
        if ($dec === null || $centavos < 1 || $centavos > self::MAX_FIXO) {
            return ['regra' => null, 'erro' => 'Digite o valor da taxa, de R$ 0,01 a R$ 50,00.'];
        }
        return ['regra' => ['tipo' => 'fixo', 'valor' => self::dec($centavos)], 'erro' => null];
    }

    /** Regra em formato simples para o checkout (o JS so faz a conta para mostrar). @param array{tipo:string,valor:string} $regra @return array<string,mixed> */
    public static function paraCheckout(array $regra): array
    {
        return ['tipo' => $regra['tipo'], 'valor' => Numero::centavos($regra['valor']), 'texto' => self::texto($regra)];
    }

    private static function dec(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }
}
