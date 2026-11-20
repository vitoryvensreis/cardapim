<?php

declare(strict_types=1);

namespace Cardapim\Modules\Catalog;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Le planilhas enviadas pelo gerente: CSV (Excel, Google Planilhas, LibreOffice) e XLSX (Excel).
 * Devolve as linhas com o numero real da linha na planilha (para as mensagens "Linha 12: ...").
 * Sem bibliotecas externas: CSV com fgetcsv; XLSX e um ZIP com XML (so a primeira aba e lida).
 * Erros viram RuntimeException com texto simples para o gerente.
 */
final class LeitorPlanilha
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_LINHAS = 3000;
    public const MAX_COLUNAS = 40;
    /** Limite do XML descompactado do XLSX (protege contra arquivo ZIP "bomba"). */
    private const MAX_XML = 25 * 1024 * 1024;
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return array<int, list<string>> numero da linha (1 = primeira) => celulas (texto, sem espacos nas pontas)
     * @throws RuntimeException
     */
    public static function ler(string $caminho, string $nomeOriginal): array
    {
        $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        $tamanho = @filesize($caminho);
        if ($tamanho === false || $tamanho === 0) {
            throw new RuntimeException('O arquivo chegou vazio. Escolha a planilha de novo.');
        }
        if ($tamanho > self::MAX_BYTES) {
            throw new RuntimeException('A planilha passou de 2 MB. Apague abas e imagens que não são do cardápio e envie de novo.');
        }
        $inicio = (string) file_get_contents($caminho, false, null, 0, 4);
        $ehZip = str_starts_with($inicio, "PK\x03\x04");
        return match (true) {
            $extensao === 'xls' => throw new RuntimeException('Arquivos .xls (Excel antigo) não são aceitos. No Excel, use "Salvar como" e escolha "Pasta de Trabalho do Excel (.xlsx)" ou "CSV".'),
            $extensao === 'ods' => throw new RuntimeException('Arquivos .ods não são aceitos. No LibreOffice, use "Salvar como" e escolha .xlsx ou .csv.'),
            $extensao === 'xlsx' && $ehZip => self::xlsx($caminho),
            in_array($extensao, ['csv', 'txt'], true) && !$ehZip => self::csv((string) file_get_contents($caminho)),
            default => throw new RuntimeException('Envie a planilha em .xlsx (Excel) ou .csv. Baixe o modelo nesta página se tiver dúvida.'),
        };
    }

    /** @return array<int, list<string>> */
    public static function csv(string $conteudo): array
    {
        if (str_starts_with($conteudo, "\xEF\xBB\xBF")) {
            $conteudo = substr($conteudo, 3);
        }
        if (str_contains($conteudo, "\0")) {
            throw new RuntimeException('Este arquivo não parece ser uma planilha CSV. Envie em .xlsx ou .csv.');
        }
        // Excel em portugues salva CSV em "ANSI" (Windows-1252): converte para nao estragar os acentos.
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = (string) mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $separador = self::separador($conteudo);
        $fluxo = fopen('php://temp', 'r+');
        fwrite($fluxo, $conteudo);
        rewind($fluxo);
        $linhas = [];
        $numero = 0;
        while (($celulas = fgetcsv($fluxo, null, $separador, '"', '')) !== false) {
            $numero++;
            if ($numero > self::MAX_LINHAS) {
                fclose($fluxo);
                throw new RuntimeException('A planilha tem mais de ' . self::MAX_LINHAS . ' linhas. Divida em duas e envie uma de cada vez.');
            }
            $linhas[$numero] = self::limpar($celulas);
        }
        fclose($fluxo);
        return $linhas;
    }

    /** O separador que mais aparece na primeira linha: ";" (Excel em portugues), "," ou tabulacao. */
    private static function separador(string $conteudo): string
    {
        $primeira = strtok($conteudo, "\r\n") ?: '';
        $contagem = [';' => substr_count($primeira, ';'), ',' => substr_count($primeira, ','), "\t" => substr_count($primeira, "\t")];
        arsort($contagem);
        $maior = (string) array_key_first($contagem);
        return $contagem[$maior] > 0 ? $maior : ';';
    }

    /** @return array<int, list<string>> */
    private static function xlsx(string $caminho): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Este servidor ainda não lê arquivos .xlsx. No Excel, use "Salvar como" > "CSV" e envie o arquivo .csv.');
        }
        $zip = new ZipArchive();
        if ($zip->open($caminho, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Não deu para abrir a planilha. Ela pode estar corrompida ou protegida por senha. Salve de novo e tente outra vez.');
        }
        try {
            $abas = self::abaPrincipal($zip);
            $textos = self::textosCompartilhados($zip);
            $xml = self::xml($zip, $abas);
            if ($xml === null) {
                throw new RuntimeException('Não achamos nenhuma aba com dados nesta planilha.');
            }
            return self::linhasDaAba($xml, $textos);
        } finally {
            $zip->close();
        }
    }

    /** Caminho da primeira aba (a que aparece primeiro no Excel). */
    private static function abaPrincipal(ZipArchive $zip): string
    {
        $padrao = 'xl/worksheets/sheet1.xml';
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook === null || $rels === null || !isset($workbook->sheets->sheet[0])) {
            return $padrao;
        }
        $rid = (string) ($workbook->sheets->sheet[0]->attributes(self::NS_REL)['id'] ?? '');
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $alvo = ltrim((string) $rel['Target'], '/');
                $alvo = str_starts_with($alvo, 'xl/') ? $alvo : 'xl/' . $alvo;
                // Nunca sair da pasta da planilha (alvo com "..").
                return str_contains($alvo, '..') ? $padrao : $alvo;
            }
        }
        return $padrao;
    }

    /** @return list<string> */
    private static function textosCompartilhados(ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $textos = [];
        foreach ($xml->si as $si) {
            $textos[] = self::textoRico($si);
        }
        return $textos;
    }

    /** Texto de <si> ou <is>: simples (<t>) ou com partes formatadas (<r><t>), sem a guia fonetica (<rPh>). */
    private static function textoRico(SimpleXMLElement $no): string
    {
        $no->registerXPathNamespace('m', self::NS_MAIN);
        $partes = $no->xpath('.//m:t[not(ancestor::m:rPh)]') ?: [];
        return implode('', array_map('strval', $partes));
    }

    /**
     * @param list<string> $textos
     * @return array<int, list<string>>
     */
    private static function linhasDaAba(SimpleXMLElement $aba, array $textos): array
    {
        $linhas = [];
        $proxima = 1;
        if (!isset($aba->sheetData)) {
            return [];
        }
        foreach ($aba->sheetData->row as $row) {
            $numero = isset($row['r']) && ctype_digit((string) $row['r']) ? (int) $row['r'] : $proxima;
            $proxima = $numero + 1;
            if ($numero > self::MAX_LINHAS) {
                throw new RuntimeException('A planilha tem mais de ' . self::MAX_LINHAS . ' linhas. Divida em duas e envie uma de cada vez.');
            }
            $celulas = [];
            $coluna = 0;
            foreach ($row->c as $c) {
                $ref = (string) ($c['r'] ?? '');
                $coluna = preg_match('/\A([A-Z]{1,3})\d+\z/', $ref, $m) === 1 ? self::indiceColuna($m[1]) : $coluna;
                if ($coluna >= self::MAX_COLUNAS) {
                    $coluna++;
                    continue;
                }
                $celulas[$coluna] = self::valorCelula($c, $textos);
                $coluna++;
            }
            if ($celulas === []) {
                continue;
            }
            $lista = array_fill(0, max(array_keys($celulas)) + 1, '');
            foreach ($celulas as $i => $v) {
                $lista[$i] = $v;
            }
            $linhas[$numero] = self::limpar($lista);
        }
        return $linhas;
    }

    /** @param list<string> $textos */
    private static function valorCelula(SimpleXMLElement $c, array $textos): string
    {
        $tipo = (string) ($c['t'] ?? 'n');
        $v = isset($c->v) ? (string) $c->v : '';
        return match ($tipo) {
            's' => $textos[(int) $v] ?? '',
            'inlineStr' => isset($c->is) ? self::textoRico($c->is) : '',
            'b' => $v === '1' ? 'Sim' : 'Não',
            'str', 'e' => $v,
            default => self::numero($v),
        };
    }

    /** Numero do Excel sem o "ruido" do ponto flutuante: 12.990000000000002 -> 12.99; 101 -> 101. */
    public static function numero(string $v): string
    {
        if ($v === '' || !is_numeric($v)) {
            return $v;
        }
        $f = (float) $v;
        if (floor($f) === $f && abs($f) < 1e15) {
            return (string) (int) $f;
        }
        return rtrim(rtrim(sprintf('%.10F', round($f, 10)), '0'), '.');
    }

    private static function indiceColuna(string $letras): int
    {
        $n = 0;
        foreach (str_split($letras) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }
        return $n - 1;
    }

    private static function xml(ZipArchive $zip, string $nome): ?SimpleXMLElement
    {
        $info = $zip->statName($nome);
        if ($info === false) {
            return null;
        }
        if ((int) $info['size'] > self::MAX_XML) {
            throw new RuntimeException('A planilha é grande demais para ler. Deixe só a aba do cardápio e envie de novo.');
        }
        $conteudo = $zip->getFromName($nome);
        if ($conteudo === false || $conteudo === '') {
            return null;
        }
        if (preg_match('/<!DOCTYPE/i', $conteudo) === 1) {
            throw new RuntimeException('A planilha tem um formato que não aceitamos. Salve de novo no Excel e tente outra vez.');
        }
        $anterior = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($conteudo, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
        if ($xml === false) {
            throw new RuntimeException('Não deu para ler a planilha. Ela pode estar corrompida. Salve de novo e tente outra vez.');
        }
        return $xml;
    }

    /**
     * Texto de cada celula sem espacos sobrando e no maximo MAX_COLUNAS colunas.
     * @param array<int, mixed> $celulas
     * @return list<string>
     */
    private static function limpar(array $celulas): array
    {
        $saida = [];
        foreach (array_slice(array_values($celulas), 0, self::MAX_COLUNAS) as $c) {
            $texto = is_string($c) ? $c : (is_scalar($c) ? (string) $c : '');
            $saida[] = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $texto));
        }
        return $saida;
    }
}
