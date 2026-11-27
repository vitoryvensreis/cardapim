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
     * Forma registrada no caixa (pedido_pagamentos.forma) => regra de taxa usada nas contas de mesa e balcao.
     * Pix so registrado (maquininha ou chave, conferido por fora) usa a taxa do Pix na entrega ou no caixa;
     * o QR Code do Asaas na mesa usa a taxa do Pix pelo app. Dinheiro nunca tem taxa.
     */
    public const FORMAS_CONTA = ['debito' => 'debito', 'credito' => 'credito', 'pix' => 'pix_entrega', 'pix_online' => 'pix_online', 'pix_chave' => 'pix_comprovante'];

    /** Nome da linha quando a conta tem taxas de formas diferentes. */
    public const ROTULO_VARIAS = 'Taxas de pagamento';

    /**
     * Regra de taxa de uma forma do caixa, a partir das regras do restaurante.
     * @param array<string,array{tipo:string,valor:string}> $regras TaxaPagamentoRepository::regras
     * @return array{tipo:string,valor:string}|null
     */
    public static function regraDaConta(array $regras, string $formaCaixa): ?array
    {
        $chave = self::FORMAS_CONTA[$formaCaixa] ?? null;
        return $chave !== null ? ($regras[$chave] ?? null) : null;
    }

    /**
     * Taxa de um pagamento da conta: valor em centavos e o rotulo (foto da regra). Sem regra = zero e sem rotulo.
     * @param array<string,array{tipo:string,valor:string}> $regras
     * @return array{centavos:int,rotulo:?string}
     */
    public static function daConta(array $regras, string $formaCaixa, int $baseCentavos): array
    {
        $regra = self::regraDaConta($regras, $formaCaixa);
        $centavos = self::calcular($baseCentavos, $regra);
        return ['centavos' => $centavos, 'rotulo' => $centavos > 0 && $regra !== null ? self::rotulo(self::FORMAS_CONTA[$formaCaixa], $regra) : null];
    }

    /**
     * Rotulo da taxa no pedido a partir dos rotulos de cada pagamento: um so = ele mesmo; varios diferentes = "Taxas de pagamento".
     * @param list<?string> $rotulos
     */
    public static function rotuloDaConta(array $rotulos): ?string
    {
        $unicos = array_values(array_unique(array_filter($rotulos, static fn (?string $r): bool => $r !== null && $r !== '')));
        return match (count($unicos)) {
            0 => null,
            1 => $unicos[0],
            default => self::ROTULO_VARIAS,
        };
    }

    /**
     * Regras das formas do caixa no formato da tela (o JS so mostra; o servidor confere).
     * @param array<string,array{tipo:string,valor:string}> $regras
     * @return array<string,array<string,mixed>> forma do caixa => {tipo, valor, texto, rotulo}
     */
    public static function paraConta(array $regras): array
    {
        $saida = [];
        foreach (self::FORMAS_CONTA as $formaCaixa => $chave) {
            if (isset($regras[$chave])) {
                $saida[$formaCaixa] = self::paraCheckout($regras[$chave]) + ['rotulo' => self::rotulo($chave, $regras[$chave])];
            }
        }
        return $saida;
    }

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

    /**
     * Mesma conta de calcular(), a partir do formato da tela (paraCheckout / paraConta: valor em centavos ou centesimos de ponto).
     * @param array<string,mixed>|null $tela
     */
    public static function calcularTela(int $baseCentavos, ?array $tela): int
    {
        if ($tela === null || $baseCentavos <= 0) {
            return 0;
        }
        $valor = (int) ($tela['valor'] ?? 0);
        return ($tela['tipo'] ?? '') === 'fixo' ? max(0, $valor) : max(0, intdiv($baseCentavos * $valor + 5000, 10000));
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
