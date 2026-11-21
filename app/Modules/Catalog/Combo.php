<?php

declare(strict_types=1);

namespace Cardapim\Modules\Catalog;

use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Modules\Inventory\Quantidade;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Regra unica dos combos (cardapio online, caixa, garcom e pedidos do iFood).
 *
 * Um combo e um produto comum com:
 * - itens obrigatorios (ja inclusos, com quantidade): combo_itens;
 * - grupos de complemento para escolher (os mesmos de sempre), onde cada opcao pode ser ligada a um produto e ter
 *   um maximo por combo (combo_opcoes);
 * - limite total opcional de itens (inclusos + escolhidos): combos.maximo.
 *
 * Ao lancar, o combo vira linhas separadas (cada uma com o proprio produto: estoque, impressao por estacao e
 * relatorios), com o preco do combo dividido proporcionalmente ao preco de cada item. O valor a mais de uma opcao
 * ("Picanha +R$ 5") fica na propria linha. Opcao sem produto ligado vira uma linha com o nome dela e baixa a ficha
 * de insumos da opcao.
 */
final class Combo
{
    public const MAX_FIXOS = 12;

    private static bool $carregando = false;

    public static function ativo(PDO $db): bool
    {
        return Esquema::tem($db, 'complementos') && Esquema::tem($db, 'combos');
    }

    /**
     * Configuracao dos combos entre os produtos pedidos (produto que nao e combo nao aparece).
     * @param list<int> $idsProdutos
     * @return array<int, array{maximo:?int, fixos:list<array{id_produto:int,nome:string,centavos:int,quantidade:int,disponivel:bool}>,
     *         grupos:list<array<string,mixed>>, disponivel:bool, faltando:list<string>}>
     */
    public static function carregar(PDO $db, int $idRestaurante, array $idsProdutos): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsProdutos), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || self::$carregando || !self::ativo($db)) {
            return [];
        }
        $q = $db->prepare('SELECT id_produto, maximo FROM combos WHERE id_restaurante = ? AND id_produto IN (' . self::marcas($ids) . ')');
        $q->execute([$idRestaurante, ...$ids]);
        $combos = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $combos[(int) $c['id_produto']] = $c['maximo'] !== null ? (int) $c['maximo'] : null;
        }
        if ($combos === []) {
            return [];
        }
        self::$carregando = true; // a disponibilidade dos itens nao volta a carregar combos
        try {
            $idsCombos = array_keys($combos);
            $q = $db->prepare('SELECT id_combo, id_produto, quantidade FROM combo_itens WHERE id_restaurante = ? AND id_combo IN ('
                . self::marcas($idsCombos) . ') ORDER BY id_combo, ordem, id_produto');
            $q->execute([$idRestaurante, ...$idsCombos]);
            $fixosRaw = $q->fetchAll(PDO::FETCH_ASSOC);
            $grupos = Complementos::carregar($db, $idRestaurante, $idsCombos);
            $idsOpcoes = [];
            foreach ($grupos as $lista) {
                foreach ($lista as $g) {
                    foreach ($g['opcoes'] as $o) {
                        $idsOpcoes[] = (int) $o['id'];
                    }
                }
            }
            $extras = [];
            if ($idsOpcoes !== []) {
                $q = $db->prepare('SELECT id_complemento, id_produto, maximo FROM combo_opcoes WHERE id_restaurante = ? AND id_complemento IN (' . self::marcas($idsOpcoes) . ')');
                $q->execute([$idRestaurante, ...$idsOpcoes]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) {
                    $extras[(int) $e['id_complemento']] = [
                        'id_produto' => $e['id_produto'] !== null ? (int) $e['id_produto'] : null,
                        'maximo' => $e['maximo'] !== null ? (int) $e['maximo'] : null,
                    ];
                }
            }
            $componentes = array_merge(array_map(static fn (array $f): int => (int) $f['id_produto'], $fixosRaw),
                array_values(array_filter(array_column($extras, 'id_produto'))));
            $info = [];
            $disp = [];
            if ($componentes !== []) {
                $componentes = array_values(array_unique($componentes));
                $q = $db->prepare('SELECT id, nome, preco FROM produtos WHERE id_restaurante = ? AND id IN (' . self::marcas($componentes) . ')');
                $q->execute([$idRestaurante, ...$componentes]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $info[(int) $p['id']] = ['nome' => (string) $p['nome'], 'centavos' => Numero::centavos((string) $p['preco'])];
                }
                $disp = (new DisponibilidadeProdutos($db))->verificar($idRestaurante, $componentes);
            }
            $saida = [];
            foreach ($combos as $id => $maximo) {
                $fixos = [];
                $faltando = [];
                foreach ($fixosRaw as $f) {
                    if ((int) $f['id_combo'] !== $id || !isset($info[(int) $f['id_produto']])) {
                        continue;
                    }
                    $idp = (int) $f['id_produto'];
                    $ok = (bool) ($disp[$idp]['disponivel'] ?? false);
                    $fixos[] = ['id_produto' => $idp, 'nome' => $info[$idp]['nome'], 'centavos' => $info[$idp]['centavos'], 'quantidade' => (int) $f['quantidade'], 'disponivel' => $ok];
                    if (!$ok) {
                        $faltando[] = $info[$idp]['nome'];
                    }
                }
                $gruposCombo = $grupos[$id] ?? [];
                foreach ($gruposCombo as &$g) {
                    foreach ($g['opcoes'] as &$o) {
                        $e = $extras[(int) $o['id']] ?? ['id_produto' => null, 'maximo' => null];
                        $ligado = $e['id_produto'] !== null && isset($info[$e['id_produto']]) ? $e['id_produto'] : null;
                        $o['id_produto'] = $ligado;
                        $o['maximo'] = $e['maximo'];
                        $o['produto_nome'] = $ligado !== null ? $info[$ligado]['nome'] : null;
                        $o['produto_centavos'] = $ligado !== null ? $info[$ligado]['centavos'] : 0;
                        if ($ligado !== null) {
                            $o['vendavel'] = !empty($o['vendavel']) && (bool) ($disp[$ligado]['disponivel'] ?? false);
                        }
                    }
                    unset($o);
                }
                unset($g);
                foreach ($gruposCombo as $g) {
                    if (Complementos::bloqueia([$g])) {
                        $faltando[] = (string) $g['nome'];
                    }
                }
                $saida[$id] = ['maximo' => $maximo, 'fixos' => $fixos, 'grupos' => $gruposCombo, 'disponivel' => $faltando === [], 'faltando' => $faltando];
            }
            return $saida;
        } finally {
            self::$carregando = false;
        }
    }

    /**
     * Confere as escolhas de UM combo: grupos (minimo/maximo), maximo de cada opcao e limite total do combo.
     * @param array<string,mixed> $combo de carregar()
     * @param list<array{id:int, quantidade:int}> $escolhas de Complementos::formato()
     * @return array{ok:bool, erro:?string, escolhidos:list<array{0:array<string,mixed>,1:int}>, extras:int, texto:string}
     */
    public static function conferir(array $combo, array $escolhas): array
    {
        $falha = static fn (string $m): array => ['ok' => false, 'erro' => $m, 'escolhidos' => [], 'extras' => 0, 'texto' => ''];
        foreach ($combo['fixos'] as $f) {
            if (!$f['disponivel']) {
                return $falha('"' . $f['nome'] . '" acabou. O combo não pode ser vendido agora.');
            }
        }
        if ($combo['fixos'] === [] && $combo['grupos'] === []) {
            return $falha('Este combo ainda não tem itens cadastrados.');
        }
        $conf = Complementos::conferir($combo['grupos'], $escolhas);
        if (!$conf['ok']) {
            return $falha((string) $conf['erro']);
        }
        $porId = [];
        foreach ($combo['grupos'] as $g) {
            foreach ($g['opcoes'] as $o) {
                $porId[(int) $o['id']] = $o;
            }
        }
        $escolhidos = [];
        $soma = 0;
        foreach ($conf['itens'] as $item) {
            $o = $porId[$item['id']];
            if (($o['maximo'] ?? null) !== null && $item['quantidade'] > $o['maximo']) {
                return $falha('No máximo ' . $o['maximo'] . ' de "' . $o['nome'] . '" neste combo.');
            }
            $escolhidos[] = [$o, (int) $item['quantidade']];
            $soma += (int) $item['quantidade'];
        }
        $inclusos = self::inclusos($combo);
        if ($combo['maximo'] !== null && $inclusos + $soma > $combo['maximo']) {
            return $falha('Este combo tem até ' . $combo['maximo'] . ' itens (' . $inclusos . ' já inclusos): escolha no máximo ' . max(0, $combo['maximo'] - $inclusos) . '.');
        }
        return ['ok' => true, 'erro' => null, 'escolhidos' => $escolhidos, 'extras' => (int) $conf['centavos'], 'texto' => (string) $conf['texto']];
    }

    /**
     * Linhas separadas de N combos. O valor (preco do combo x N + adicionais, ou o total que o iFood cobrou) e dividido
     * entre os itens proporcionalmente ao preco de cada um, ao centavo (o que sobra do arredondamento vai para os
     * maiores restos). O valor a mais de cada opcao fica na propria linha.
     * @param array<string,mixed> $combo
     * @param list<array{0:array<string,mixed>,1:int}> $escolhidos
     * @return array{linhas:list<array{id_produto:?int,nome:string,unidades:int,centavos:int}>, produtos:array<int,int>, insumos:array<int,int>, total:int}
     */
    public static function montar(array $combo, array $escolhidos, int $centavosCombo, int $n, ?int $totalForcado = null): array
    {
        $unidades = []; // [id_produto|null, nome, preco cheio, valor a mais]
        $insumos = [];
        for ($k = 0; $k < $n; $k++) {
            foreach ($combo['fixos'] as $f) {
                for ($u = 0; $u < $f['quantidade']; $u++) {
                    $unidades[] = [$f['id_produto'], $f['nome'], $f['centavos'], 0];
                }
            }
            foreach ($escolhidos as [$o, $q]) {
                for ($u = 0; $u < $q; $u++) {
                    $extra = (int) ($o['centavos'] ?? Numero::centavos((string) ($o['preco'] ?? '0')));
                    if (($o['id_produto'] ?? null) !== null) {
                        $unidades[] = [(int) $o['id_produto'], (string) $o['produto_nome'], (int) $o['produto_centavos'], $extra];
                        continue;
                    }
                    $unidades[] = [null, (string) $o['nome'], 0, $extra];
                    foreach ($o['ficha'] ?? [] as $f) {
                        $insumos[(int) $f['id_insumo']] = ($insumos[(int) $f['id_insumo']] ?? 0) + Quantidade::paraMilesimos((string) $f['quantidade']);
                    }
                }
            }
        }
        if ($unidades === []) {
            return ['linhas' => [], 'produtos' => [], 'insumos' => [], 'total' => 0];
        }
        $extras = array_sum(array_column($unidades, 3));
        $total = $totalForcado ?? ($centavosCombo * $n + $extras);
        $repartir = $total - $extras;
        if ($repartir < 0) { // cobrado menos que os adicionais (so iFood): divide tudo pelo preco cheio
            foreach ($unidades as &$u) {
                $u[3] = 0;
            }
            unset($u);
            $repartir = $total;
        }
        $somaBase = array_sum(array_column($unidades, 2));
        $partes = [];
        if ($somaBase > 0) {
            $restos = [];
            $dado = 0;
            foreach ($unidades as $i => $u) {
                $exato = $repartir * $u[2];
                $partes[$i] = intdiv($exato, $somaBase);
                $restos[$i] = $exato % $somaBase;
                $dado += $partes[$i];
            }
            uksort($restos, static fn (int $a, int $b): int => $restos[$b] <=> $restos[$a] ?: $a <=> $b);
            foreach (array_keys($restos) as $i) {
                if ($dado >= $repartir) {
                    break;
                }
                $partes[$i]++;
                $dado++;
            }
        } else {
            $cada = intdiv($repartir, count($unidades));
            $sobra = $repartir - $cada * count($unidades);
            foreach (array_keys($unidades) as $i) {
                $partes[$i] = $cada + ($i < $sobra ? 1 : 0);
            }
        }
        $linhas = [];
        $produtos = [];
        foreach ($unidades as $i => $u) {
            $centavos = $partes[$i] + $u[3];
            $chave = ($u[0] ?? 'opcao') . '|' . $u[1] . '|' . $centavos;
            $linhas[$chave] ??= ['id_produto' => $u[0], 'nome' => $u[1], 'unidades' => 0, 'centavos' => $centavos];
            $linhas[$chave]['unidades']++;
            if ($u[0] !== null) {
                $produtos[$u[0]] = ($produtos[$u[0]] ?? 0) + 1000;
            }
        }
        return ['linhas' => array_values($linhas), 'produtos' => $produtos, 'insumos' => $insumos, 'total' => $total];
    }

    /**
     * Opcoes de um item do iFood reconhecidas neste combo (codigo DZ-C<id> publicado pelo Cardapim ou o codigo da
     * opcao). null = alguma escolha nao foi reconhecida (o pedido entra como antes, numa linha so).
     * @param list<array{codigo:?string, quantidade:?int}> $opcoesIfood
     * @return list<array{0:array<string,mixed>,1:int}>|null
     */
    public static function escolhasIfood(array $combo, array $opcoesIfood): ?array
    {
        $porId = [];
        $porCodigo = [];
        foreach ($combo['grupos'] as $g) {
            foreach ($g['opcoes'] as $o) {
                $porId[(int) $o['id']] = $o;
                if (($o['codigo'] ?? null) !== null && $o['codigo'] !== '') {
                    $porCodigo[mb_strtolower((string) $o['codigo'])] = $o;
                }
            }
        }
        $escolhidos = [];
        foreach ($opcoesIfood as $op) {
            $q = $op['quantidade'] ?? null;
            $codigo = trim((string) ($op['codigo'] ?? ''));
            if (!is_int($q) || $q < 1 || $codigo === '') {
                return null;
            }
            $o = preg_match('/\ADZ-C(\d+)\z/', $codigo, $m) === 1 ? ($porId[(int) $m[1]] ?? null) : null;
            $o ??= $porCodigo[mb_strtolower($codigo)] ?? null;
            if ($o === null) {
                return null;
            }
            $escolhidos[] = [$o, $q];
        }
        return $escolhidos;
    }

    /** Quantos itens ja vem no combo (obrigatorios). @param array<string,mixed> $combo */
    public static function inclusos(array $combo): int
    {
        return array_sum(array_map(static fn (array $f): int => (int) $f['quantidade'], $combo['fixos']));
    }

    /**
     * Dados para o navegador: inclusos, limite de escolhas e o maximo de cada opcao (sem ficha nem codigo).
     * @param array<string,mixed> $combo
     * @return array{inclusos:list<string>, maximo_escolhas:?int, grupos:list<array<string,mixed>>}
     */
    public static function publico(array $combo): array
    {
        $grupos = Complementos::publico($combo['grupos']);
        foreach ($grupos as $gi => &$g) {
            foreach ($g['opcoes'] as $oi => &$o) {
                $o['maximo'] = $combo['grupos'][$gi]['opcoes'][$oi]['maximo'] ?? null;
            }
            unset($o);
        }
        unset($g);
        return [
            'inclusos' => array_map(static fn (array $f): string => $f['quantidade'] . 'x ' . $f['nome'], $combo['fixos']),
            'maximo_escolhas' => $combo['maximo'] !== null ? max(0, $combo['maximo'] - self::inclusos($combo)) : null,
            'grupos' => $grupos,
        ];
    }

    /** Identificador das linhas que vieram do mesmo combo (cancela junto, mostra agrupado). */
    public static function novoLote(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** Nome do grupo na comanda/pedido: "Combo Familia" ou "2x Combo Familia". */
    public static function rotulo(string $nome, int $n): string
    {
        return mb_substr(($n > 1 ? $n . 'x ' : '') . $nome, 0, 150);
    }

    /** @param list<int> $ids */
    private static function marcas(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
