<?php

declare(strict_types=1);

namespace Cardapim\Modules\Inventory;

use InvalidArgumentException;

/**
 * Quantidade VENDIDA de um produto (venda por peso). Sempre em milesimos inteiros, como o estoque:
 * - 'un' (por unidade): 1 a 99 unidades inteiras (2 un = 2000).
 * - 'kg' / 'l' (por quilo / litro): 3 casas = grama / mililitro (0,846 kg = 846).
 * O preco do produto e o da unidade de venda (R$ por unidade, por quilo ou por litro).
 * Valor do item = preco x quantidade, arredondado ao centavo com meio centavo para cima, igual ao
 * CHECK do banco (total = ROUND(preco_unitario * quantidade, 2)).
 */
final class QuantidadeVenda
{
    public const UNIDADES = ['un', 'kg', 'l'];
    public const MAX_UNIDADES = 99;
    /** Maior quantidade por item vendido por peso: 99,999 kg ou L. */
    public const MAX_FRACAO = 99999;

    public static function unidade(?string $valor): string
    {
        return in_array($valor, self::UNIDADES, true) ? (string) $valor : 'un';
    }

    public static function porPeso(string $unidade): bool
    {
        return $unidade === 'kg' || $unidade === 'l';
    }

    /**
     * Le o que a equipe digitou ("2", "0,846", "0.846") e devolve milesimos, ja conferindo os limites.
     * @throws InvalidArgumentException com mensagem amigavel
     */
    public static function ler(string|int $valor, string $unidade, ?int $minimo = null): int
    {
        $unidade = self::unidade($unidade);
        $texto = trim(str_replace(' ', '', (string) $valor));
        if ($unidade === 'un') {
            if (preg_match('/\A\d{1,3}\z/', $texto) !== 1 || (int) $texto < 1 || (int) $texto > self::MAX_UNIDADES) {
                throw new InvalidArgumentException('Quantidade de 1 a ' . self::MAX_UNIDADES . '.');
            }
            return (int) $texto * 1000;
        }
        $sigla = $unidade === 'kg' ? 'kg' : 'L';
        try {
            $milesimos = $texto === '' ? 0 : Quantidade::paraMilesimos($texto);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Digite o ' . ($unidade === 'kg' ? 'peso' : 'volume') . ' em ' . $sigla . ', com até 3 casas (ex.: 0,846).');
        }
        if ($milesimos <= 0) {
            throw new InvalidArgumentException('Digite o ' . ($unidade === 'kg' ? 'peso' : 'volume') . ' (ex.: 0,846 ' . $sigla . ').');
        }
        if ($milesimos > self::MAX_FRACAO) {
            throw new InvalidArgumentException('No máximo 99,999 ' . $sigla . ' por item.');
        }
        if ($minimo !== null && $milesimos < $minimo) {
            throw new InvalidArgumentException('O mínimo para este produto é ' . self::texto($minimo, $unidade) . '.');
        }
        return $milesimos;
    }

    /** Valor do item em centavos: preco (centavos por unidade de venda) x milesimos, meio centavo para cima. */
    public static function totalCentavos(int $centavosPorUnidade, int $milesimos): int
    {
        return intdiv($centavosPorUnidade * $milesimos + 500, 1000);
    }

    /** "2.000" do banco -> 2000. */
    public static function doBanco(string|int|float|null $valor): int
    {
        if ($valor === null || $valor === '') {
            return 0;
        }
        return Quantidade::paraMilesimos(is_string($valor) ? $valor : (string) $valor);
    }

    /** Para gravar no banco: 846 -> "0.846". */
    public static function paraBanco(int $milesimos): string
    {
        return Quantidade::paraDecimal($milesimos);
    }

    /**
     * Texto para a tela: "2x" | "846 g" | "1,250 kg" | "350 ml" | "1,500 L".
     */
    public static function texto(int $milesimos, string $unidade): string
    {
        $unidade = self::unidade($unidade);
        if ($unidade === 'un') {
            return intdiv($milesimos, 1000) . 'x';
        }
        if ($milesimos < 1000) {
            return $milesimos . ($unidade === 'kg' ? ' g' : ' ml');
        }
        return str_replace('.', ',', Quantidade::paraDecimal($milesimos)) . ($unidade === 'kg' ? ' kg' : ' L');
    }

    /** Quantidade sem o "x": "3" | "846 g" | "1,250 kg" (ex.: "Restam 3", "Restam 846 g"). */
    public static function quanto(int $milesimos, string $unidade): string
    {
        return self::unidade($unidade) === 'un' ? (string) intdiv($milesimos, 1000) : self::texto($milesimos, $unidade);
    }

    /** "2x X-Burger" | "846 g Picanha". */
    public static function comNome(int $milesimos, string $unidade, string $nome): string
    {
        return self::texto($milesimos, $unidade) . ' ' . $nome;
    }

    /** Mesma coisa a partir de uma linha do banco (quantidade "0.846", unidade "kg"). */
    public static function textoDoBanco(string|int|float|null $quantidade, ?string $unidade): string
    {
        return self::texto(self::doBanco($quantidade), self::unidade($unidade));
    }

    /** Sufixo do preco: "" | "/kg" | "/L". */
    public static function sufixoPreco(string $unidade): string
    {
        return match (self::unidade($unidade)) {
            'kg' => '/kg',
            'l' => '/L',
            default => '',
        };
    }

    /** Quantos "itens" a linha conta (2 latas = 2; 0,846 kg de picanha = 1). */
    public static function contaItens(int $milesimos, string $unidade): int
    {
        return self::unidade($unidade) === 'un' ? intdiv($milesimos, 1000) : 1;
    }

    /** Texto do campo (0,846) para preencher formularios. */
    public static function campo(?int $milesimos): string
    {
        return $milesimos === null ? '' : str_replace('.', ',', Quantidade::paraDecimal($milesimos));
    }
}
