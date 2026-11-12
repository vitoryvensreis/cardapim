<?php

declare(strict_types=1);

namespace Cardapim\Shared;

/** Planilha CSV com ; e BOM (abre direto no Excel em portugues), protegida contra formulas injetadas. */
final class Planilha
{
    /** @param iterable<list<string>> $linhas */
    public static function csv(iterable $linhas): string
    {
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}");
        foreach ($linhas as $linha) {
            fputcsv($saida, array_map(self::celula(...), $linha), ';', '"', '');
        }
        rewind($saida);
        $csv = (string) stream_get_contents($saida);
        fclose($saida);
        return $csv;
    }

    /** Texto que comecaria com =, +, -, @ (formula no Excel) ganha um apostrofo; numeros ficam como estao. */
    public static function celula(string $valor): string
    {
        if ($valor !== '' && preg_match('/\A-?\d[\d.,]*\z/', $valor) !== 1 && in_array($valor[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $valor;
        }
        return $valor;
    }
}
