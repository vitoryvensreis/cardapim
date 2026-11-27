<?php

declare(strict_types=1);

namespace Cardapim\Modules\Catalog;

use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Repositories\Estoque\MeioAMeioRepository;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Regra unica do produto especial "Meio a Meio" (cardapio online, caixa, garcom e pedidos do iFood).
 *
 * O cliente escolhe de minimo a maximo sabores (1 a 4, sem repetir). Os sabores aparecem como um grupo de complementos
 * "Sabores" (o mesmo desenho de sempre), montado aqui e juntado em Complementos::carregar(), com:
 * - id do grupo e das opcoes = OFFSET + id (nao colide com os complementos de verdade e nao vai para
 *   pedido_item_complementos);
 * - 'meio' => [regra, base]: o preco do item e base + delta, onde delta = preco pela regra - base:
 *   fracionado = soma dos sabores / quantidade; maior = o sabor mais caro; personalizado = preco do proprio produto;
 * - estoque: cada sabor baixa a fracao do proprio produto (2 sabores = 500 milesimos de cada; 3 = 333/333/334).
 * O item continua uma linha so (o nome do Meio a Meio com "Sabores: 1/2 Calabresa, 1/2 Mista").
 * Meio a Meio nao e publicado no iFood por aqui (IfoodPublicacao recusa); o pedido que chega de la e reconhecido.
 */
final class MeioAMeio
{
    /** Ids das opcoes "sabor" = OFFSET + id do produto sabor (o grupo usa o proprio OFFSET). */
    public const OFFSET = 900000000000;
    public const MAX_SABORES = 4;
    public const REGRAS = [
        'fracionado' => 'Fracionado: soma dos sabores dividida pela quantidade',
        'maior' => 'Pelo sabor mais caro',
        'personalizado' => 'Preço do próprio produto',
    ];

    private static bool $carregando = false;

    public static function ativo(PDO $db): bool
    {
        return Esquema::tem($db, 'meio_a_meio');
    }

    public static function ehSabor(int $idOpcao): bool
    {
        return $idOpcao > self::OFFSET;
    }

    /**
     * Grupo "Sabores" de cada produto Meio a Meio pedido (no formato de Complementos::carregar).
     * @param list<int> $idsProdutos
     * @return array<int, array<string,mixed>> id do produto => grupo
     */
    public static function carregar(PDO $db, int $idRestaurante, array $idsProdutos): array
    {
        if ($idsProdutos === [] || self::$carregando || !self::ativo($db)) {
            return [];
        }
        $repo = new MeioAMeioRepository($db);
        $configs = $repo->configuracoes($idRestaurante, $idsProdutos);
        if ($configs === []) {
            return [];
        }
        $sabores = $repo->sabores($idRestaurante, $configs);
        $bases = self::precos($db, $idRestaurante, array_keys($configs));
        $todos = [];
        foreach ($sabores as $lista) {
            foreach ($lista as $s) {
                $todos[$s['id']] = true;
            }
        }
        self::$carregando = true; // a disponibilidade dos sabores nao volta a montar Meio a Meio
        try {
            $disp = $todos !== [] ? (new DisponibilidadeProdutos($db))->verificar($idRestaurante, array_keys($todos)) : [];
        } finally {
            self::$carregando = false;
        }
        $saida = [];
        foreach ($configs as $idMeio => $c) {
            $saida[$idMeio] = [
                'id' => self::OFFSET,
                'nome' => $c['maximo'] === 1 ? 'Sabor' : 'Sabores',
                'minimo' => $c['minimo'],
                'maximo' => $c['maximo'],
                'repetir' => false,
                'meio' => ['regra' => $c['regra'], 'base' => $bases[$idMeio] ?? 0],
                'opcoes' => array_map(static fn (array $s): array => [
                    'id' => self::OFFSET + $s['id'], 'id_produto' => $s['id'], 'nome' => $s['nome'], 'descricao' => $s['descricao'],
                    'preco' => '0.00', 'centavos' => 0, 'sabor_centavos' => Numero::centavos($s['preco']), 'codigo' => $s['codigo'],
                    'imagem_path' => $s['imagem_path'], 'ficha' => [], 'disponivel' => true,
                    'vendavel' => (bool) ($disp[$s['id']]['disponivel'] ?? false),
                ], $sabores[$idMeio] ?? []),
            ];
        }
        return $saida;
    }

    /**
     * Fracao de cada sabor em milesimos (soma 1000): 2 = [500, 500]; 3 = [333, 333, 334]; 4 = [250 x 4].
     * @return list<int>
     */
    public static function fracoes(int $n): array
    {
        $n = max(1, $n);
        $cada = intdiv(1000, $n);
        $lista = array_fill(0, $n, $cada);
        $lista[$n - 1] += 1000 - $cada * $n;
        return $lista;
    }

    /** "1/2", "1/3", "1/4" (um sabor so: inteiro, sem fracao). */
    public static function fracaoTexto(int $n): string
    {
        return $n <= 1 ? '' : '1/' . $n;
    }

    /**
     * Preco de uma unidade pela regra (centavos).
     * @param list<int> $saboresCentavos
     */
    public static function preco(string $regra, int $base, array $saboresCentavos): int
    {
        $n = count($saboresCentavos);
        if ($n === 0 || $regra === 'personalizado') {
            return $base;
        }
        if ($regra === 'maior') {
            return max($saboresCentavos);
        }
        return intdiv(array_sum($saboresCentavos) * 2 + $n, $n * 2); // media arredondada ao centavo
    }

    /**
     * Menor preco possivel (para "A partir de" no cardapio), com os sabores que podem ser vendidos agora.
     * Fracionado: media dos "minimo" sabores mais baratos; maior: o "minimo"-esimo mais barato; personalizado: null.
     * @param array<string,mixed> $grupo de carregar()
     */
    public static function aPartirDe(array $grupo): ?int
    {
        $regra = (string) ($grupo['meio']['regra'] ?? 'personalizado');
        if ($regra === 'personalizado') {
            return null;
        }
        $precos = array_map(static fn (array $o): int => (int) $o['sabor_centavos'], array_values(array_filter($grupo['opcoes'], static fn (array $o): bool => !empty($o['vendavel']))));
        $k = max(1, (int) $grupo['minimo']);
        if (count($precos) < $k) {
            return null;
        }
        sort($precos);
        $mais = array_slice($precos, 0, $k);
        return $regra === 'maior' ? max($mais) : self::preco('fracionado', 0, $mais);
    }

    /**
     * Sabores de um item do iFood reconhecidos neste Meio a Meio: pelo codigo (do produto ou DZ-P<id>) ou pelo nome.
     * null = algum sabor nao reconhecido (o pedido entra como antes, sem baixar os sabores).
     * @param array<string,mixed> $grupo de carregar()
     * @param list<array{codigo:?string, nome?:?string, quantidade:?int}> $opcoesIfood
     * @return array<int,int>|null id do produto sabor => milesimos por unidade
     */
    public static function saboresIfood(array $grupo, array $opcoesIfood): ?array
    {
        $porCodigo = [];
        $porNome = [];
        foreach ($grupo['opcoes'] as $o) {
            $porCodigo['dz-p' . $o['id_produto']] = $o;
            if (($o['codigo'] ?? null) !== null) {
                $porCodigo[mb_strtolower((string) $o['codigo'])] = $o;
            }
            $porNome[TextoLocal::chave((string) $o['nome'])] = $o;
        }
        $achados = [];
        foreach ($opcoesIfood as $op) {
            $codigo = mb_strtolower(trim((string) ($op['codigo'] ?? '')));
            $o = $codigo !== '' ? ($porCodigo[$codigo] ?? null) : null;
            if ($o === null) {
                $nome = TextoLocal::chave((string) preg_replace('~^\s*\d+\s*/\s*\d+\s*~u', '', (string) ($op['nome'] ?? '')));
                $o = $nome !== '' ? ($porNome[$nome] ?? null) : null;
            }
            if ($o === null) {
                return null;
            }
            $achados[] = (int) $o['id_produto'];
        }
        if ($achados === [] || count($achados) > self::MAX_SABORES) {
            return null;
        }
        $saida = [];
        foreach (self::fracoes(count($achados)) as $i => $mil) {
            $saida[$achados[$i]] = ($saida[$achados[$i]] ?? 0) + $mil;
        }
        return $saida;
    }

    /** @param list<int> $ids @return array<int,int> id => preco em centavos */
    private static function precos(PDO $db, int $idRestaurante, array $ids): array
    {
        $q = $db->prepare('SELECT id, preco FROM produtos WHERE id_restaurante = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute([$idRestaurante, ...$ids]);
        $saida = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $saida[(int) $r['id']] = Numero::centavos((string) $r['preco']);
        }
        return $saida;
    }
}
