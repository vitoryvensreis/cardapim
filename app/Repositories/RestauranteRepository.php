<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

final class RestauranteRepository
{
    /** Somente restaurantes ativos aparecem publicamente. */
    private const STATUS_PUBLICO = 'ativo';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param string $cnpj somente digitos */
    public function cnpjExiste(string $cnpj): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM restaurantes WHERE cnpj = ? LIMIT 1');
        $query->execute([$cnpj]);
        return $query->fetchColumn() !== false;
    }

    /**
     * Pagina do catalogo publico com paginacao por cursor (nome, id): estavel mesmo com
     * novos cadastros entre uma pagina e outra. Retorna apenas dados publicos.
     *
     * @param array{0:string,1:int}|null $depoisDe ultimo (nome, id) ja exibido
     * @return array{itens: list<array{id:int,nome:string,slug:string,logo_path:?string}>, proximo: array{0:string,1:int}|null}
     */
    public function listarCatalogo(?array $depoisDe, int $limite, string $busca = ''): array
    {
        $limite = max(1, min($limite, 48));
        $sql = 'SELECT id, nome, slug, logo_path FROM restaurantes WHERE status_rest = ?';
        $params = [self::STATUS_PUBLICO];

        if ($busca !== '') {
            $sql .= " AND nome LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($busca, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($depoisDe !== null) {
            $sql .= ' AND (nome > ? OR (nome = ? AND id > ?))';
            array_push($params, $depoisDe[0], $depoisDe[0], $depoisDe[1]);
        }
        // Busca um item a mais para saber se existe proxima pagina.
        $sql .= ' ORDER BY nome ASC, id ASC LIMIT ' . ($limite + 1);

        $query = $this->db->prepare($sql);
        $query->execute($params);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        $temMais = count($rows) > $limite;
        $itens = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nome' => (string) $row['nome'],
            'slug' => (string) $row['slug'],
            'logo_path' => $row['logo_path'] !== null ? (string) $row['logo_path'] : null,
        ], array_slice($rows, 0, $limite));
        $ultimo = $itens[count($itens) - 1] ?? null;

        return [
            'itens' => $itens,
            'proximo' => $temMais && $ultimo !== null ? [$ultimo['nome'], $ultimo['id']] : null,
        ];
    }

    /** Area da equipe: inclui status para a regra de acesso. */
    public function buscarPorSlugParaEquipe(string $slug): ?array
    {
        $query = $this->db->prepare('SELECT id, nome, slug, status_rest FROM restaurantes WHERE slug = ? LIMIT 1');
        $query->execute([$slug]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => (int) $row['id'], 'nome' => (string) $row['nome'], 'slug' => (string) $row['slug'], 'status' => (string) $row['status_rest']] : null;
    }

    /** @return array{id:int,nome:string,slug:string,logo_path:?string}|null */
    /**
     * Restaurantes ativos para o mapa do site (/sitemap.xml), no maximo 50 mil (limite de um sitemap).
     * @return list<array{slug:string, atualizado:?string}> atualizado no formato AAAA-MM-DD
     */
    public function listarParaMapa(): array
    {
        $query = $this->db->prepare('SELECT slug, updated_at FROM restaurantes WHERE status_rest = ? AND slug IS NOT NULL ORDER BY id ASC LIMIT 50000');
        $query->execute([self::STATUS_PUBLICO]);
        $lista = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data = is_string($row['updated_at'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}/', $row['updated_at']) === 1 ? substr($row['updated_at'], 0, 10) : null;
            $lista[] = ['slug' => (string) $row['slug'], 'atualizado' => $data];
        }
        return $lista;
    }

    public function buscarPublicoPorSlug(string $slug): ?array
    {
        $query = $this->db->prepare(
            'SELECT id, nome, slug, logo_path FROM restaurantes WHERE slug = ? AND status_rest = ? LIMIT 1'
        );
        $query->execute([$slug, self::STATUS_PUBLICO]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'nome' => (string) $row['nome'],
            'slug' => (string) $row['slug'],
            'logo_path' => $row['logo_path'] !== null ? (string) $row['logo_path'] : null,
        ];
    }
}
