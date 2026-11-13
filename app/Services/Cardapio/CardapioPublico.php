<?php

declare(strict_types=1);

namespace Cardapim\Services\Cardapio;

use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
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
     * @return array{categorias: list<array{id:string,nome:string,produtos:list<array<string,mixed>>}>, total:int}
     */
    public function categorias(int $idRestaurante): array
    {
        $q = $this->db->prepare(
            'SELECT p.id, p.nome, p.descricao, p.preco, p.id_categoria, c.nome AS categoria
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.id_categoria AND c.id_restaurante = p.id_restaurante
             WHERE p.id_restaurante = ? AND p.ativo = 1 AND (c.id IS NULL OR c.ativo = 1)
             ORDER BY c.id IS NULL, c.ordem, c.nome, p.ordem, p.nome'
        );
        $q->execute([$idRestaurante]);
        $linhas = $q->fetchAll(PDO::FETCH_ASSOC);
        $disponibilidade = (new DisponibilidadeProdutos($this->db))->verificar($idRestaurante);

        $categorias = [];
        foreach ($linhas as $p) {
            $chave = $p['id_categoria'] !== null ? 'c' . (int) $p['id_categoria'] : 'outros';
            $categorias[$chave] ??= ['id' => $chave, 'nome' => $p['categoria'] !== null ? (string) $p['categoria'] : 'Outros', 'produtos' => []];
            $d = $disponibilidade[(int) $p['id']] ?? ['disponivel' => false];
            $categorias[$chave]['produtos'][] = [
                'id' => (int) $p['id'],
                'nome' => (string) $p['nome'],
                'descricao' => trim((string) ($p['descricao'] ?? '')),
                'centavos' => self::centavos((string) $p['preco']),
                'preco' => Numero::reais((string) $p['preco']),
                'disponivel' => (bool) $d['disponivel'],
            ];
        }
        // Disponiveis primeiro dentro de cada categoria (os indisponiveis continuam visiveis, no fim).
        foreach ($categorias as &$c) {
            usort($c['produtos'], static fn (array $a, array $b): int => (int) $b['disponivel'] <=> (int) $a['disponivel']);
        }
        unset($c);
        return ['categorias' => array_values($categorias), 'total' => count($linhas)];
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
        $q = $this->db->prepare('SELECT id, nome, preco FROM produtos WHERE id_restaurante = ? AND ativo = 1 AND id IN ('
            . implode(',', array_fill(0, count($lista), '?')) . ')');
        $q->execute([$idRestaurante, ...$lista]);
        $disponibilidade = (new DisponibilidadeProdutos($this->db))->verificar($idRestaurante, $lista);
        $saida = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $saida[(int) $p['id']] = [
                'id' => (int) $p['id'],
                'nome' => (string) $p['nome'],
                'centavos' => self::centavos((string) $p['preco']),
                'disponivel' => (bool) ($disponibilidade[(int) $p['id']]['disponivel'] ?? false),
            ];
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
}
