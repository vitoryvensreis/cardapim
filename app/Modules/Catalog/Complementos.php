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
        if ($idsProdutos === [] || !Esquema::tem($db, 'complementos')) {
            return [];
        }
        $grupos = (new ComplementoRepository($db))->gruposDosProdutos($idRestaurante, $idsProdutos);
        if ($grupos === []) {
            return [];
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
     * @return array{ok:bool, erro:?string, centavos:int, itens:list<array{id:int, grupo:string, nome:string, centavos:int, quantidade:int}>, texto:string, insumos:array<int,int>}
     *         centavos = soma dos complementos para 1 unidade; insumos = id_insumo => milesimos para 1 unidade
     */
    public static function conferir(array $grupos, array $escolhas): array
    {
        $falha = static fn (string $m): array => ['ok' => false, 'erro' => $m, 'centavos' => 0, 'itens' => [], 'texto' => '', 'insumos' => []];
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
        return ['ok' => true, 'erro' => null, 'centavos' => $centavos, 'itens' => $itens, 'texto' => mb_substr(implode(' · ', $partes), 0, 500), 'insumos' => $insumos];
    }

    /** Dados dos grupos que podem ir para o navegador (sem ficha tecnica, codigo ou estoque). @param list<array<string,mixed>> $grupos @return list<array<string,mixed>> */
    public static function publico(array $grupos): array
    {
        return array_map(static fn (array $g): array => [
            'id' => $g['id'], 'nome' => $g['nome'], 'minimo' => $g['minimo'], 'maximo' => $g['maximo'],
            'opcoes' => array_map(static fn (array $o): array => [
                'id' => $o['id'], 'nome' => $o['nome'], 'descricao' => (string) ($o['descricao'] ?? ''), 'centavos' => (int) $o['centavos'],
                'preco' => Numero::reais((string) $o['preco']), 'disponivel' => !empty($o['vendavel']),
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
