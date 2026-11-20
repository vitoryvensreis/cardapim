<?php

declare(strict_types=1);

namespace Cardapim\Modules\Catalog;

use Cardapim\Shared\Numero;

/**
 * Regras da planilha de importacao do cardapio (sem banco): reconhece as colunas, junta as linhas de
 * complemento ao produto de cima e valida cada linha. O resultado e um "plano" so com textos e numeros,
 * que pode ser guardado na sessao, conferido com o banco e importado.
 *
 * Formato (uma linha por produto; linhas de baixo sem "Produto" acrescentam opcoes de complemento):
 *   Categoria | Produto | Descricao | Preco | Codigo | Complemento | Obrigatorio | Maximo | Opcao | Preco da opcao
 * - Categoria vazia = mesma categoria da linha de cima.
 * - Complemento vazio numa linha de opcao = mesmo complemento da linha de cima.
 * - Preco da opcao = valor somado ao preco do produto (0 = gratis).
 */
final class PlanilhaCardapio
{
    public const MAX_PRODUTOS = 1000;
    public const MAX_GRUPOS_POR_PRODUTO = 20;
    public const MAX_OPCOES_POR_GRUPO = 50;
    public const MAX_ESCOLHAS = 30;

    /** Titulos do modelo, na ordem. */
    public const TITULOS = ['Categoria', 'Produto', 'Descrição', 'Preço', 'Código', 'Complemento', 'Obrigatório', 'Máximo', 'Opção', 'Preço da opção'];

    /** Nomes aceitos para cada coluna (sem acento, minusculo). */
    private const COLUNAS = [
        'categoria' => ['categoria', 'categorias', 'secao'],
        'produto' => ['produto', 'produtos', 'nome', 'nome do produto', 'item'],
        'descricao' => ['descricao', 'descricao do produto', 'ingredientes'],
        'preco' => ['preco', 'valor', 'preco r', 'preco de venda', 'preco do produto'],
        'codigo' => ['codigo', 'cod', 'codigo pdv', 'sku'],
        'complemento' => ['complemento', 'complementos', 'grupo', 'grupo de complemento', 'grupo de complementos'],
        'obrigatorio' => ['obrigatorio', 'obrigatoria', 'escolha obrigatoria'],
        'maximo' => ['maximo', 'max', 'maximo de escolhas', 'quantas escolhas'],
        'opcao' => ['opcao', 'opcoes', 'opcao do complemento'],
        'preco_opcao' => ['preco da opcao', 'preco opcao', 'valor da opcao', 'preco a mais', 'adicional', 'preco do complemento'],
    ];

    private const SIM = ['sim', 's', 'x', '1', 'obrigatorio', 'obrigatoria', 'true', 'verdadeiro', 'yes'];
    private const NAO = ['', 'nao', 'n', '0', 'opcional', 'false', 'falso', 'no', '-'];

    /**
     * @param array<int, list<string>> $linhas numero da linha => celulas (LeitorPlanilha::ler)
     * @return array{
     *   produtos: list<array{linha:int, categoria:?string, nome:string, descricao:?string, preco:string, codigo:?string,
     *     grupos: list<array{nome:string, minimo:int, maximo:int, opcoes: list<array{nome:string, preco:string}>}>}>,
     *   erros: list<array{linha:int, mensagem:string}>
     * }
     */
    public static function interpretar(array $linhas): array
    {
        ksort($linhas);
        $erros = [];
        $mapa = null;
        $linhaTitulos = 0;
        foreach ($linhas as $numero => $celulas) {
            if (self::vazia($celulas)) {
                continue;
            }
            $mapa = self::mapaColunas($celulas);
            $linhaTitulos = $numero;
            break;
        }
        if ($mapa === null) {
            return ['produtos' => [], 'erros' => [['linha' => 0, 'mensagem' => 'A planilha está vazia.']]];
        }
        if (!isset($mapa['produto'], $mapa['preco'])) {
            return ['produtos' => [], 'erros' => [['linha' => $linhaTitulos, 'mensagem' =>
                'Não achamos as colunas "Produto" e "Preço" na primeira linha. Use os títulos do modelo: ' . implode(', ', self::TITULOS) . '.']]];
        }
        $temComplemento = isset($mapa['opcao']);

        $produtos = [];
        $atual = null;          // indice do produto que recebe as opcoes
        $grupoAtual = null;     // indice do grupo atual dentro do produto
        $categoria = null;
        $nomes = [];
        $codigos = [];
        $erroDoProduto = false; // produto com erro: suas linhas de opcao nao geram mais erros repetidos

        foreach ($linhas as $numero => $celulas) {
            if ($numero <= $linhaTitulos || self::vazia($celulas)) {
                continue;
            }
            $c = static fn (string $coluna): string => isset($mapa[$coluna]) ? ($celulas[$mapa[$coluna]] ?? '') : '';
            $nome = $c('produto');
            $opcao = $temComplemento ? $c('opcao') : '';
            $complemento = $c('complemento');

            if ($c('categoria') !== '') {
                $categoria = $c('categoria');
                if (mb_strlen($categoria) < 2 || mb_strlen($categoria) > 80) {
                    $erros[] = ['linha' => $numero, 'mensagem' => 'O nome da categoria precisa ter de 2 a 80 letras.'];
                    $categoria = null;
                }
            }

            if ($nome !== '') {
                if (count($produtos) >= self::MAX_PRODUTOS) {
                    $erros[] = ['linha' => $numero, 'mensagem' => 'A planilha passou de ' . self::MAX_PRODUTOS . ' produtos. Divida em duas.'];
                    break;
                }
                $falhas = [];
                if (mb_strlen($nome) < 2 || mb_strlen($nome) > 150) {
                    $falhas[] = 'o nome do produto precisa ter de 2 a 150 letras';
                } elseif (isset($nomes[self::chave($nome)])) {
                    $falhas[] = '"' . $nome . '" aparece de novo na linha ' . $nomes[self::chave($nome)] . '. Cada produto precisa de um nome diferente (ex.: "' . $nome . ' grande")';
                }
                $precoTexto = $c('preco');
                $preco = self::preco($precoTexto);
                if ($precoTexto === '') {
                    $falhas[] = 'falta o preço (use 0 se o preço vem só dos complementos)';
                } elseif ($preco === null) {
                    $falhas[] = 'o preço "' . $precoTexto . '" não é um valor válido. Exemplo: 24,90';
                }
                $descricao = $c('descricao');
                if (mb_strlen($descricao) > 500) {
                    $falhas[] = 'a descrição passou de 500 letras';
                }
                $codigo = $c('codigo');
                if ($codigo !== '' && preg_match('/\A[A-Za-z0-9._-]{1,40}\z/', $codigo) !== 1) {
                    $falhas[] = 'o código pode ter até 40 letras, números, ponto, hífen ou sublinhado';
                } elseif ($codigo !== '' && isset($codigos[$codigo])) {
                    $falhas[] = 'o código ' . $codigo . ' já foi usado na linha ' . $codigos[$codigo];
                }
                foreach ($falhas as $f) {
                    $erros[] = ['linha' => $numero, 'mensagem' => self::frase($f)];
                }
                $nomes[self::chave($nome)] ??= $numero;
                if ($codigo !== '') {
                    $codigos[$codigo] ??= $numero;
                }
                $produtos[] = [
                    'linha' => $numero, 'categoria' => $categoria, 'nome' => $nome, 'descricao' => $descricao !== '' ? $descricao : null,
                    'preco' => $preco ?? '0.00', 'codigo' => $codigo !== '' ? $codigo : null, 'grupos' => [],
                ];
                $atual = array_key_last($produtos);
                $grupoAtual = null;
                $erroDoProduto = $falhas !== [];
                if ($opcao === '' && $complemento === '') {
                    continue;
                }
            } elseif ($opcao === '' && $complemento === '') {
                // Linha so com categoria (titulo de secao) ou com dados soltos.
                if (self::temDadosDeProduto($c)) {
                    $erros[] = ['linha' => $numero, 'mensagem' => 'Falta o nome do produto nesta linha.'];
                }
                continue;
            }

            // A partir daqui a linha traz um complemento (no proprio produto ou abaixo dele).
            if ($atual === null) {
                $erros[] = ['linha' => $numero, 'mensagem' => 'Esta opção de complemento está antes de qualquer produto. Coloque-a logo abaixo do produto.'];
                continue;
            }
            if ($opcao === '') {
                $erros[] = ['linha' => $numero, 'mensagem' => 'Falta o nome da opção do complemento "' . $complemento . '". Exemplo: Bacon'];
                continue;
            }
            $grupos = &$produtos[$atual]['grupos'];
            if ($complemento !== '') {
                $grupoAtual = null;
                foreach ($grupos as $i => $g) {
                    if (self::chave($g['nome']) === self::chave($complemento)) {
                        $grupoAtual = $i;
                    }
                }
                if ($grupoAtual === null) {
                    if (mb_strlen($complemento) > 80) {
                        $erros[] = ['linha' => $numero, 'mensagem' => 'O nome do complemento pode ter até 80 letras.'];
                        unset($grupos);
                        continue;
                    }
                    if (count($grupos) >= self::MAX_GRUPOS_POR_PRODUTO) {
                        $erros[] = ['linha' => $numero, 'mensagem' => 'Use no máximo ' . self::MAX_GRUPOS_POR_PRODUTO . ' complementos por produto.'];
                        unset($grupos);
                        continue;
                    }
                    $obrigatorio = self::simNao($c('obrigatorio'));
                    if ($obrigatorio === null) {
                        $erros[] = ['linha' => $numero, 'mensagem' => 'Em "Obrigatório", escreva Sim ou Não.'];
                        $obrigatorio = false;
                    }
                    $maximoTexto = $c('maximo');
                    $maximo = $maximoTexto === '' ? 1 : (ctype_digit($maximoTexto) ? (int) $maximoTexto : 0);
                    if ($maximo < 1 || $maximo > self::MAX_ESCOLHAS) {
                        $erros[] = ['linha' => $numero, 'mensagem' => 'Em "Máximo", escreva quantas opções o cliente pode escolher (de 1 a ' . self::MAX_ESCOLHAS . ').'];
                        $maximo = 1;
                    }
                    $grupos[] = ['nome' => $complemento, 'minimo' => $obrigatorio ? 1 : 0, 'maximo' => $maximo, 'opcoes' => []];
                    $grupoAtual = array_key_last($grupos);
                }
            } elseif ($grupoAtual === null) {
                $erros[] = ['linha' => $numero, 'mensagem' => 'Escreva o nome do complemento desta opção (exemplo: Tamanho, Adicionais, Sabor).'];
                unset($grupos);
                continue;
            }

            $grupo = &$grupos[$grupoAtual];
            $falhas = [];
            if (mb_strlen($opcao) > 120) {
                $falhas[] = 'o nome da opção pode ter até 120 letras';
            }
            foreach ($grupo['opcoes'] as $o) {
                if (self::chave($o['nome']) === self::chave($opcao)) {
                    $falhas[] = '"' . $opcao . '" aparece duas vezes no complemento "' . $grupo['nome'] . '"';
                    break;
                }
            }
            if (count($grupo['opcoes']) >= self::MAX_OPCOES_POR_GRUPO) {
                $falhas[] = 'cada complemento aceita no máximo ' . self::MAX_OPCOES_POR_GRUPO . ' opções';
            }
            $precoOpcaoTexto = $c('preco_opcao');
            $precoOpcao = $precoOpcaoTexto === '' ? '0.00' : self::preco($precoOpcaoTexto);
            if ($precoOpcao === null) {
                $falhas[] = 'o preço da opção "' . $precoOpcaoTexto . '" não é válido. Exemplo: 4,50 (ou deixe vazio se for grátis)';
            }
            if (!$erroDoProduto) {
                foreach ($falhas as $f) {
                    $erros[] = ['linha' => $numero, 'mensagem' => self::frase($f)];
                }
            }
            if ($falhas === []) {
                $grupo['opcoes'][] = ['nome' => $opcao, 'preco' => (string) $precoOpcao];
            }
            unset($grupo, $grupos);
        }

        if ($produtos === [] && $erros === []) {
            $erros[] = ['linha' => $linhaTitulos, 'mensagem' => 'Não achamos nenhum produto abaixo dos títulos.'];
        }
        usort($erros, static fn (array $a, array $b): int => $a['linha'] <=> $b['linha']);
        return ['produtos' => $produtos, 'erros' => $erros];
    }

    /**
     * Linhas do modelo para download (titulos + exemplos de cada caso).
     * @return list<list<string>>
     */
    public static function modelo(): array
    {
        return [
            self::TITULOS,
            ['Lanches', 'X-Burger', 'Pão, carne 150 g, queijo e salada', '24,90', '101', 'Adicionais', 'Não', '3', 'Bacon', '4,00'],
            ['', '', '', '', '', '', '', '', 'Ovo', '2,00'],
            ['', '', '', '', '', '', '', '', 'Cheddar', '3,00'],
            ['', 'X-Salada', 'Pão, carne 150 g, queijo, alface e tomate', '22,90', '102', '', '', '', '', ''],
            ['Porções', 'Batata frita', '', '18,00', '', 'Tamanho', 'Sim', '1', 'Pequena (300 g)', '0,00'],
            ['', '', '', '', '', '', '', '', 'Grande (500 g)', '10,00'],
            ['', 'Bolinha de queijo', '12 unidades', '19,00', '', '', '', '', '', ''],
            ['Bebidas', 'Suco natural', '', '7,00', '', 'Sabor', 'Sim', '1', 'Goiaba', ''],
            ['', '', '', '', '', '', '', '', 'Acerola', ''],
            ['', '', '', '', '', 'Tamanho', 'Sim', '1', '500 ml', '0,00'],
            ['', '', '', '', '', '', '', '', '1 litro', '5,00'],
            ['', 'Água mineral 500 ml', '', '4,00', '', '', '', '', '', ''],
        ];
    }

    /** Preco em reais: "24,90", "24.90", "R$ 1.234,56" ou o numero do Excel. */
    public static function preco(string $texto): ?string
    {
        $texto = trim($texto);
        // Numero do Excel com muitas casas (ex.: 12.990000001) ja foi arredondado pelo leitor; aqui aceita 3+ casas so arredondando.
        if (preg_match('/\A\d{1,8}\.\d{3,}\z/', $texto) === 1 && preg_match('/\A\d{1,3}\.\d{3}\z/', $texto) !== 1) {
            $texto = number_format(round((float) $texto, 2), 2, '.', '');
        }
        return Numero::dinheiroParaDecimal($texto);
    }

    /** Texto para comparar nomes: minusculo, sem acento e sem espacos repetidos. */
    public static function chave(string $texto): string
    {
        $t = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texto)));
        $sem = class_exists(\Normalizer::class) ? \Normalizer::normalize($t, \Normalizer::FORM_D) : $t;
        return (string) preg_replace('/\p{Mn}+/u', '', is_string($sem) ? $sem : $t);
    }

    /**
     * Assinatura de um grupo de complementos (nome, limites e opcoes com preco): grupos iguais sao reaproveitados.
     * @param array{nome:string, minimo:int, maximo:int, opcoes:list<array{nome:string, preco:string}>} $g
     */
    public static function assinaturaGrupo(array $g): string
    {
        $partes = [self::chave($g['nome']), (string) $g['minimo'], (string) $g['maximo']];
        foreach ($g['opcoes'] as $o) {
            $partes[] = self::chave($o['nome']) . '=' . Numero::dinheiroParaDecimal(str_replace('.', ',', (string) $o['preco']));
        }
        return hash('sha256', implode("\x1F", $partes));
    }

    /** @param list<string> $celulas @return array<string,int> coluna logica => indice */
    private static function mapaColunas(array $celulas): array
    {
        $mapa = [];
        foreach ($celulas as $i => $titulo) {
            $t = trim((string) preg_replace('/[^a-z0-9]+/', ' ', self::chave($titulo)));
            foreach (self::COLUNAS as $coluna => $nomes) {
                if (!isset($mapa[$coluna]) && in_array($t, $nomes, true)) {
                    $mapa[$coluna] = $i;
                    break;
                }
            }
        }
        return $mapa;
    }

    /** @param list<string> $celulas */
    private static function vazia(array $celulas): bool
    {
        foreach ($celulas as $c) {
            if ($c !== '') {
                return false;
            }
        }
        return true;
    }

    private static function temDadosDeProduto(callable $c): bool
    {
        foreach (['preco', 'descricao', 'codigo', 'preco_opcao'] as $coluna) {
            if ($c($coluna) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function simNao(string $texto): ?bool
    {
        $t = self::chave($texto);
        return match (true) {
            in_array($t, self::SIM, true) => true,
            in_array($t, self::NAO, true) => false,
            default => null,
        };
    }

    private static function frase(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1) . (str_ends_with($texto, '.') ? '' : '.');
    }
}
