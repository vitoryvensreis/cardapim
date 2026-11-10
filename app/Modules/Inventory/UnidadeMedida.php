<?php

declare(strict_types=1);

namespace Cardapim\Modules\Inventory;

/** Unidades aceitas em insumos.unidade. */
enum UnidadeMedida: string
{
    case Unidade = 'un';
    case Quilo = 'kg';
    case Litro = 'l';

    public function rotulo(): string
    {
        return match ($this) {
            self::Unidade => 'Unidade',
            self::Quilo => 'Quilo (kg)',
            self::Litro => 'Litro (L)',
        };
    }

    public function sigla(): string
    {
        return match ($this) {
            self::Unidade => 'un',
            self::Quilo => 'kg',
            self::Litro => 'L',
        };
    }

    /** Exibicao amigavel: "0,08 kg", "1 un", "0,5 L" (sem zeros desnecessarios). */
    public function formatar(int $milesimos): string
    {
        $texto = rtrim(rtrim(Quantidade::paraDecimal($milesimos), '0'), '.');
        return str_replace('.', ',', $texto) . ' ' . $this->sigla();
    }
}
