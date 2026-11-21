<?php

declare(strict_types=1);

namespace Cardapim\Services\Cardapio;

use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Shared\Numero;
use DateTimeImmutable;
use PDO;

/**
 * Cardapio que o cliente final ve em /{slug}: categorias com produtos ativos, preco, disponibilidade
 * (pausado ou sem insumo = "Indisponivel") e situacao do restaurante (aberto agora, horarios, endereco).
 * Nunca expoe custo, ficha tecnica, codigo interno ou quantidade em estoque.
 */
final class CardapioPublico
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Produtos por categoria. Cada produto traz a unidade de venda ('un' | 'kg' | 'l'), o minimo (milesimos) e as
     * porcoes do cardapio online ('opcoes'). $online = true (cardapio do cliente): produto por peso SEM porcao
     * cadastrada fica de fora (vende so no caixa e no garcom).
     * @return array{categorias: list<array{id:string,nome:string,produtos:list<array<string,mixed>>}>, total:int}
     */
    public function categorias(int $idRestaurante, bool $online = false): array
    {
        $q = $this->db->prepare(
            'SELECT p.id, p.nome, p.descricao, p.preco, p.unidade_venda, p.venda_minima, p.imagem_path, p.id_categoria, c.nome AS categoria
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.id_categoria AND c.id_restaurante = p.id_restaurante
             WHERE p.id_restaurante = ? AND p.ativo = 1 AND (c.id IS NULL OR c.ativo = 1)
             ORDER BY c.id IS NULL, c.ordem, c.nome, p.ordem, p.nome'
        );
        $q->execute([$idRestaurante]);
        $linhas = $q->fetchAll(PDO::FETCH_ASSOC);
        $disponibilidade = (new DisponibilidadeProdutos($this->db))->verificar($idRestaurante);
        $porcoes = $this->porcoes($idRestaurante);
        $grupos = Complementos::carregar($this->db, $idRestaurante, array_map(static fn (array $p): int => (int) $p['id'], $linhas));
        $combos = \Cardapim\Modules\Catalog\Combo::carregar($this->db, $idRestaurante, array_map(static fn (array $p): int => (int) $p['id'], $linhas));

        $categorias = [];
        $total = 0;
        foreach ($linhas as $p) {
            $unidade = QuantidadeVenda::unidade($p['unidade_venda'] ?? 'un');
            $centavos = self::centavos((string) $p['preco']);
            $opcoes = QuantidadeVenda::porPeso($unidade) ? self::opcoes($centavos, $unidade, $porcoes[(int) $p['id']] ?? []) : [];
            if ($online && QuantidadeVenda::porPeso($unidade) && $opcoes === []) {
                continue;
            }
            $total++;
            $chave = $p['id_categoria'] !== null ? 'c' . (int) $p['id_categoria'] : 'outros';
            $categorias[$chave] ??= ['id' => $chave, 'nome' => $p['categoria'] !== null ? (string) $p['categoria'] : 'Outros', 'produtos' => []];
            $d = $disponibilidade[(int) $p['id']] ?? ['disponivel' => false];
            $categorias[$chave]['produtos'][] = array_replace([
                'id' => (int) $p['id'],
                'nome' => (string) $p['nome'],
                'descricao' => trim((string) ($p['descricao'] ?? '')),
                'centavos' => $centavos,
                'preco' => Numero::reais((string) $p['preco']) . QuantidadeVenda::sufixoPreco($unidade),
                'disponivel' => (bool) $d['disponivel'],
                'unidade' => $unidade,
                'minimo' => $p['venda_minima'] !== null ? QuantidadeVenda::doBanco($p['venda_minima']) : null,
                'opcoes' => $opcoes,
                'imagem' => $p['imagem_path'] !== null ? \Cardapim\Shared\MidiaUrl::publica((string) $p['imagem_path']) : null,
                // Complementos (adicionais, bordas...): so produto por unidade.
                'grupos' => $unidade === 'un' ? Complementos::publico($grupos[(int) $p['id']] ?? []) : [],
            ], self::combo($combos[(int) $p['id']] ?? null));
        }
        // Disponiveis primeiro dentro de cada categoria (os indisponiveis continuam visiveis, no fim).
        foreach ($categorias as &$c) {
            usort($c['produtos'], static fn (array $a, array $b): int => (int) $b['disponivel'] <=> (int) $a['disponivel']);
        }
        unset($c);
        return ['categorias' => array_values($categorias), 'total' => $total];
    }

    /**
     * Porcoes do cardapio online por produto (milesimos, em ordem).
     * @param list<int> $ids vazio = todos os produtos do restaurante
     * @return array<int, list<int>>
     */
    public function porcoes(int $idRestaurante, array $ids = []): array
    {
        $sql = 'SELECT id_produto, quantidade FROM produto_porcoes WHERE id_restaurante = ?';
        $params = [$idRestaurante];
        if ($ids !== []) {
            $sql .= ' AND id_produto IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        $q = $this->db->prepare($sql . ' ORDER BY id_produto, ordem, quantidade');
        $q->execute($params);
        $por = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $por[(int) $r['id_produto']][] = QuantidadeVenda::doBanco($r['quantidade']);
        }
        return $por;
    }

    /**
     * Porcoes prontas para a tela: [{milesimos, texto "500 g", centavos, preco "R$ 29,95"}].
     * @param list<int> $porcoes
     * @return list<array{milesimos:int, texto:string, centavos:int, preco:string}>
     */
    public static function opcoes(int $centavosPorUnidade, string $unidade, array $porcoes): array
    {
        return array_map(static function (int $m) use ($centavosPorUnidade, $unidade): array {
            $c = QuantidadeVenda::totalCentavos($centavosPorUnidade, $m);
            return ['milesimos' => $m, 'texto' => QuantidadeVenda::texto($m, $unidade), 'centavos' => $c, 'preco' => Numero::reais(sprintf('%d.%02d', intdiv($c, 100), $c % 100))];
        }, $porcoes);
    }

    /**
     * Confere a sacola guardada no navegador com os precos e a disponibilidade de AGORA.
     * @param list<mixed> $itens [{id, quantidade}]
     * @return array{itens: list<array{id:int,nome:string,centavos:int,disponivel:bool}>, removidos: list<int>}
     */
    public function conferirSacola(int $idRestaurante, array $itens): array
    {
        $ids = [];
        foreach (array_slice($itens, 0, 50) as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if (is_int($id) && $id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return ['itens' => [], 'removidos' => []];
        }
        $lista = array_keys($ids);
        $q = $this->db->prepare('SELECT id, nome, preco, unidade_venda FROM produtos WHERE id_restaurante = ? AND ativo = 1 AND id IN ('
            . implode(',', array_fill(0, count($lista), '?')) . ')');
        $q->execute([$idRestaurante, ...$lista]);
        $disponibilidade = (new DisponibilidadeProdutos($this->db))->verificar($idRestaurante, $lista);
        $porcoes = $this->porcoes($idRestaurante, $lista);
        $grupos = Complementos::carregar($this->db, $idRestaurante, $lista);
        $combos = \Cardapim\Modules\Catalog\Combo::carregar($this->db, $idRestaurante, $lista);
        $saida = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $unidade = QuantidadeVenda::unidade($p['unidade_venda'] ?? 'un');
            $saida[(int) $p['id']] = array_replace([
                'id' => (int) $p['id'],
                'nome' => (string) $p['nome'],
                'centavos' => self::centavos((string) $p['preco']),
                'disponivel' => (bool) ($disponibilidade[(int) $p['id']]['disponivel'] ?? false),
                // Produto por peso: preco do quilo/litro + porcoes validas (a sacola tira a porcao que sumiu).
                'unidade' => $unidade,
                'porcoes' => QuantidadeVenda::porPeso($unidade) ? ($porcoes[(int) $p['id']] ?? []) : [],
                // Complementos de agora: a sacola refaz o preco da linha e tira a escolha que sumiu ou acabou.
                'grupos' => $unidade === 'un' ? Complementos::publico($grupos[(int) $p['id']] ?? []) : [],
            ], self::combo($combos[(int) $p['id']] ?? null));
        }
        return ['itens' => array_values($saida), 'removidos' => array_values(array_diff($lista, array_keys($saida)))];
    }

    /**
     * @return array{aberto:bool, sempre_aberto:bool, horarios: list<array{dia:string,texto:string,hoje:bool}>,
     *   endereco:string, bairro_cidade:string, telefone:string}
     */
    public function informacoes(int $idRestaurante, ?DateTimeImmutable $agora = null): array
    {
        $agora ??= new DateTimeImmutable('now');
        $perfil = new PerfilRepository($this->db);
        $r = $perfil->buscar($idRestaurante) ?? [];
        $turnos = $perfil->horarios($idRestaurante);
        $sempre = (int) ($r['sempre_aberto'] ?? 0) === 1;
        $nomes = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        $hoje = (int) $agora->format('w');
        $horarios = [];
        if (!$sempre && $turnos !== []) {
            foreach (HorarioFuncionamento::porDia($turnos) as $d => $doDia) {
                $horarios[] = [
                    'dia' => $nomes[$d],
                    'texto' => $doDia === [] ? 'Fechado' : implode(' e ', array_map(static fn (array $t): string => $t['abre'] . ' às ' . $t['fecha'], $doDia)),
                    'hoje' => $d === $hoje,
                ];
            }
            // Comeca pelo dia de hoje: e o que o cliente quer ver primeiro.
            $horarios = [...array_slice($horarios, $hoje), ...array_slice($horarios, 0, $hoje)];
        }
        $rua = trim(implode(', ', array_filter([(string) ($r['logradouro'] ?? ''), (string) ($r['numero'] ?? '')])));
        $complemento = trim((string) ($r['complemento'] ?? ''));
        $bairroCidade = trim(implode(' · ', array_filter([
            (string) ($r['bairro'] ?? ''),
            trim(implode('/', array_filter([(string) ($r['cidade'] ?? ''), (string) ($r['estado'] ?? '')]))),
        ])));
        return [
            // O cardápio próprio segue exclusivamente os horários do Perfil, nunca o status do iFood.
            'aberto' => ($sempre || $turnos !== []) && HorarioFuncionamento::abertoEm($sempre, $turnos, $agora),
            'horario_informado' => $sempre || $turnos !== [],
            'sempre_aberto' => $sempre,
            'horarios' => $horarios,
            'endereco' => trim($rua . ($complemento !== '' ? ' - ' . $complemento : '')),
            'bairro_cidade' => $bairroCidade,
            'telefone' => (string) ($r['telefone'] ?? ''),
            // Partes do endereco para o Google (dados estruturados do restaurante).
            'local' => [
                'rua' => $rua,
                'bairro' => (string) ($r['bairro'] ?? ''),
                'cidade' => (string) ($r['cidade'] ?? ''),
                'estado' => (string) ($r['estado'] ?? ''),
                'cep' => (string) ($r['cep'] ?? ''),
                'latitude' => isset($r['latitude']) && is_numeric($r['latitude']) ? (float) $r['latitude'] : null,
                'longitude' => isset($r['longitude']) && is_numeric($r['longitude']) ? (float) $r['longitude'] : null,
            ],
        ];
    }

    public static function centavos(string $decimal): int
    {
        if (preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', trim($decimal), $m) !== 1) {
            return 0;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * Combo: "combo" (inclusos e limite de escolhas) e os grupos com o maximo de cada opcao (substitui "grupos").
     * @param array<string,mixed>|null $combo
     * @return array<string,mixed>
     */
    private static function combo(?array $combo): array
    {
        if ($combo === null) {
            return [];
        }
        $publico = \Cardapim\Modules\Catalog\Combo::publico($combo);
        return ['combo' => ['inclusos' => $publico['inclusos'], 'maximo_escolhas' => $publico['maximo_escolhas']], 'grupos' => $publico['grupos']];
    }
}
