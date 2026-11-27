<?php

declare(strict_types=1);

namespace Cardapim\Modules\Catalog;

use Cardapim\Modules\Inventory\Quantidade;
use Cardapim\Repositories\Estoque\ComplementoRepository;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Regra UNICA dos complementos, usada pelo cardapio online, sacola, pedido, caixa e garcom:
 * - carregar(): grupos de cada produto com as opcoes e se cada opcao pode ser vendida agora (pausa + insumo);
 * - conferir(): valida as escolhas (pertence ao produto, disponivel, minimo/maximo) e calcula preco e consumo.
 * O preco vem SEMPRE do banco: o navegador manda so ids e quantidades.
 */
final class Complementos
{
    public const MAX_ESCOLHAS_LINHA = 60;

    /**
     * Grupos (com opcoes) dos produtos. Cada opcao ganha 'centavos' e 'vendavel' (disponivel e com insumo para 1 unidade).
     * Sem a migracao aplicada, devolve [] (tudo funciona como antes).
     * @param list<int> $idsProdutos
     * @return array<int, list<array{id:int, nome:string, minimo:int, maximo:int, opcoes:list<array<string,mixed>>}>>
     */
    public static function carregar(PDO $db, int $idRestaurante, array $idsProdutos): array
    {
        if ($idsProdutos === []) {
            return [];
        }
        // Meio a Meio: o grupo "Sabores" entra primeiro (MeioAMeio monta com a disponibilidade de cada sabor).
        $meio = MeioAMeio::carregar($db, $idRestaurante, $idsProdutos);
        if (!Esquema::tem($db, 'complementos')) {
            return array_map(static fn (array $g): array => [$g], $meio);
        }
        $grupos = (new ComplementoRepository($db))->gruposDosProdutos($idRestaurante, $idsProdutos);
        if ($grupos === []) {
            return array_map(static fn (array $g): array => [$g], $meio);
        }
        $idsInsumos = [];
        foreach ($grupos as $lista) {
            foreach ($lista as $g) {
                foreach ($g['opcoes'] as $o) {
                    foreach ($o['ficha'] as $f) {
                        $idsInsumos[$f['id_insumo']] = true;
                    }
                }
            }
        }
        $estoque = [];
        if ($idsInsumos !== []) {
            $ids = array_keys($idsInsumos);
            $q = $db->prepare('SELECT id, estoque_atual, ativo FROM insumos WHERE id_restaurante = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $q->execute([$idRestaurante, ...$ids]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $i) {
                $estoque[(int) $i['id']] = (int) $i['ativo'] === 1 ? max(0, Quantidade::paraMilesimos((string) $i['estoque_atual'])) : -1;
            }
        }
        foreach ($grupos as &$lista) {
            foreach ($lista as &$g) {
                foreach ($g['opcoes'] as &$o) {
                    $o['centavos'] = Numero::centavos($o['preco']);
                    $ok = $o['disponivel'];
                    foreach ($o['ficha'] as $f) {
                        $tem = $estoque[$f['id_insumo']] ?? -1;
                        $ok = $ok && $tem >= Quantidade::paraMilesimos($f['quantidade']);
                    }
                    $o['vendavel'] = $ok;
                }
                unset($o);
            }
            unset($g);
        }
        unset($lista);
        foreach ($meio as $idProduto => $g) {
            $grupos[$idProduto] = [$g, ...($grupos[$idProduto] ?? [])];
        }
        return $grupos;
    }

    /** Algum grupo obrigatorio ficou sem opcoes suficientes? (o produto nao pode ser vendido) @param list<array<string,mixed>> $grupos */
    public static function bloqueia(array $grupos): bool
    {
        foreach ($grupos as $g) {
            $vendaveis = count(array_filter($g['opcoes'], static fn (array $o): bool => !empty($o['vendavel'])));
            if ($g['minimo'] > 0 && $vendaveis < $g['minimo']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Le as escolhas vindas do navegador/formulario: [{id, quantidade}], lista de ids ou id => quantidade. Sem banco.
     * @return list<array{id:int, quantidade:int}>|null null = formato invalido
     */
    public static function formato(mixed $entrada): ?array
    {
        if ($entrada === null || $entrada === '' || $entrada === []) {
            return [];
        }
        if (!is_array($entrada) || count($entrada) > self::MAX_ESCOLHAS_LINHA) {
            return null;
        }
        $somado = [];
        foreach ($entrada as $chave => $item) {
            if (is_array($item)) {
                $id = $item['id'] ?? null;
                $qtd = $item['quantidade'] ?? 1;
            } elseif (array_is_list($entrada)) {
                $id = $item; // lista de ids (formulario: complementos[] = id), 1 de cada
                $qtd = 1;
            } else {
                $id = $chave; // id => quantidade
                $qtd = $item;
            }
            $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : null);
            $qtd = is_int($qtd) ? $qtd : (is_string($qtd) && ctype_digit($qtd) ? (int) $qtd : null);
            if ($id === null || $id < 1 || $qtd === null || $qtd < 0 || $qtd > 99) {
                return null;
            }
            if ($qtd > 0) {
                $somado[$id] = ($somado[$id] ?? 0) + $qtd;
            }
        }
        $lista = [];
        foreach ($somado as $id => $qtd) {
            if ($qtd > 99) {
                return null;
            }
            $lista[] = ['id' => $id, 'quantidade' => $qtd];
        }
        return $lista;
    }

    /**
     * Confere as escolhas de UMA unidade do produto contra os grupos dele.
     * @param list<array<string,mixed>> $grupos grupos do produto (de carregar())
     * @param list<array{id:int, quantidade:int}> $escolhas (de formato())
     * @return array{ok:bool, erro:?string, centavos:int, itens:list<array{id:int, grupo:string, nome:string, centavos:int, quantidade:int}>, texto:string, insumos:array<int,int>, produtos:array<int,int>}
     *         centavos = soma dos complementos para 1 unidade (no Meio a Meio, mais a diferenca do preco pela regra);
     *         insumos = id_insumo => milesimos para 1 unidade; produtos = sabor do Meio a Meio => milesimos para 1 unidade
     */
    public static function conferir(array $grupos, array $escolhas): array
    {
        $falha = static fn (string $m): array => ['ok' => false, 'erro' => $m, 'centavos' => 0, 'itens' => [], 'texto' => '', 'insumos' => [], 'produtos' => []];
        $porOpcao = [];
        foreach ($grupos as $gi => $g) {
            foreach ($g['opcoes'] as $o) {
                $porOpcao[$o['id']] = [$gi, $o];
            }
        }
        $porGrupo = [];
        foreach ($escolhas as $esc) {
            if (!isset($porOpcao[$esc['id']])) {
                return $falha('Um complemento escolhido não existe mais neste produto. Escolha de novo.');
            }
            [$gi, $o] = $porOpcao[$esc['id']];
            if (empty($o['vendavel'])) {
                return $falha('"' . $o['nome'] . '" acabou. Escolha outra opção.');
            }
            $porGrupo[$gi][] = [$o, $esc['quantidade']];
        }
        $itens = [];
        $partes = [];
        $centavos = 0;
        $insumos = [];
        $produtos = [];
        foreach ($grupos as $gi => $g) {
            $escolhidos = $porGrupo[$gi] ?? [];
            $soma = array_sum(array_map(static fn (array $x): int => $x[1], $escolhidos));
            if ($soma < $g['minimo']) {
                return $falha($g['minimo'] === 1 && $g['maximo'] === 1
                    ? 'Escolha uma opção em "' . $g['nome'] . '".'
                    : 'Escolha pelo menos ' . $g['minimo'] . ' em "' . $g['nome'] . '".');
            }
            if ($soma > $g['maximo']) {
                return $falha('Escolha no máximo ' . $g['maximo'] . ' em "' . $g['nome'] . '".');
            }
            // Grupo sem quantidade por opcao: cada opcao uma vez so. Combo (repetir = true) tem a regra dele.
            foreach ($escolhidos as [$o, $qtd]) {
                if ($qtd > 1 && array_key_exists('repetir', $g) && !$g['repetir']) {
                    return $falha('Em "' . $g['nome'] . '" cada opção pode ser escolhida uma vez.');
                }
                if (!empty($g['repetir']) && ($o['qtd_max'] ?? null) !== null && $qtd > (int) $o['qtd_max']) {
                    return $falha('No máximo ' . (int) $o['qtd_max'] . ' de "' . $o['nome'] . '".');
                }
            }
            // Meio a Meio: preco pela regra (fica no preco do item) e cada sabor baixa a fracao do proprio produto.
            if (isset($g['meio'])) {
                if ($escolhidos === []) {
                    continue;
                }
                $n = count($escolhidos);
                $base = (int) $g['meio']['base'];
                $centavos += MeioAMeio::preco((string) $g['meio']['regra'], $base, array_map(static fn (array $x): int => (int) $x[0]['sabor_centavos'], $escolhidos)) - $base;
                $fracoes = MeioAMeio::fracoes($n);
                $nomes = [];
                foreach ($escolhidos as $i => [$o]) {
                    $produtos[(int) $o['id_produto']] = ($produtos[(int) $o['id_produto']] ?? 0) + $fracoes[$i];
                    $nomes[] = trim(MeioAMeio::fracaoTexto($n) . ' ' . $o['nome']);
                }
                $partes[] = $g['nome'] . ': ' . implode(', ', $nomes);
                continue;
            }
            $nomes = [];
            foreach ($escolhidos as [$o, $qtd]) {
                $c = (int) ($o['centavos'] ?? Numero::centavos((string) $o['preco']));
                $centavos += $c * $qtd;
                $itens[] = ['id' => (int) $o['id'], 'grupo' => (string) $g['nome'], 'nome' => (string) $o['nome'], 'centavos' => $c, 'quantidade' => $qtd];
                $nomes[] = ($qtd > 1 ? $qtd . 'x ' : '') . $o['nome'];
                foreach ($o['ficha'] ?? [] as $f) {
                    $insumos[$f['id_insumo']] = ($insumos[$f['id_insumo']] ?? 0) + Quantidade::paraMilesimos($f['quantidade']) * $qtd;
                }
            }
            if ($nomes !== []) {
                $partes[] = $g['nome'] . ': ' . implode(', ', $nomes);
            }
        }
        return ['ok' => true, 'erro' => null, 'centavos' => $centavos, 'itens' => $itens, 'texto' => mb_substr(implode(' · ', $partes), 0, 500), 'insumos' => $insumos, 'produtos' => $produtos];
    }

    /** Dados dos grupos que podem ir para o navegador (sem ficha tecnica, codigo ou estoque). @param list<array<string,mixed>> $grupos @return list<array<string,mixed>> */
    public static function publico(array $grupos): array
    {
        return array_map(static fn (array $g): array => [
            'id' => $g['id'], 'nome' => $g['nome'], 'minimo' => $g['minimo'], 'maximo' => $g['maximo'],
            // repetir: o cliente escolhe a quantidade de cada opcao (- e +); maximo da opcao: null = livre.
            'repetir' => !empty($g['repetir']),
            // Meio a Meio: regra do preco e preco do proprio produto; cada sabor leva o preco dele em sabor_centavos.
            'meio' => isset($g['meio']) ? ['regra' => (string) $g['meio']['regra'], 'base' => (int) $g['meio']['base']] : null,
            'opcoes' => array_map(static fn (array $o): array => [
                'maximo' => !empty($g['repetir']) && ($o['qtd_max'] ?? null) !== null ? (int) $o['qtd_max'] : null,
                'id' => $o['id'], 'nome' => $o['nome'], 'descricao' => (string) ($o['descricao'] ?? ''), 'centavos' => (int) $o['centavos'],
                'preco' => isset($o['sabor_centavos']) ? Numero::reais(self::dec((int) $o['sabor_centavos'])) : Numero::reais((string) $o['preco']),
                'sabor_centavos' => isset($o['sabor_centavos']) ? (int) $o['sabor_centavos'] : null,
                'disponivel' => !empty($o['vendavel']),
                'imagem' => $o['imagem_path'] !== null ? \Cardapim\Shared\MidiaUrl::publica((string) $o['imagem_path']) : null,
            ], $g['opcoes']),
        ], $grupos);
    }

    /**
     * Grava os complementos de um item do pedido (chamar dentro da transacao do pedido).
     * @param list<array{id:int, grupo:string, nome:string, centavos:int, quantidade:int}> $itens
     */
    public static function gravarNoItem(PDO $db, int $idRestaurante, int $idItem, array $itens): void
    {
        if ($itens === []) {
            return;
        }
        $ins = $db->prepare('INSERT INTO pedido_item_complementos (id_restaurante, id_pedido_item, id_complemento, grupo, nome, preco_unitario, quantidade, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($itens as $c) {
            if (MeioAMeio::ehSabor((int) $c['id'])) {
                continue; // sabor do Meio a Meio nao e complemento (fica no texto do item)
            }
            $ins->execute([$idRestaurante, $idItem, $c['id'], mb_substr($c['grupo'], 0, 80), mb_substr($c['nome'], 0, 120),
                self::dec($c['centavos']), $c['quantidade'], self::dec($c['centavos'] * $c['quantidade'])]);
        }
    }

    /** Consumo de insumos de uma linha (por unidade x unidades). @param array<int,int> $porUnidade @return array<int,int> */
    public static function consumoDaLinha(array $porUnidade, int $unidades): array
    {
        return array_map(static fn (int $m): int => $m * $unidades, $porUnidade);
    }

    private static function dec(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }
}
