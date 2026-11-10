<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/** Formas de pagamento, modos de pedido (entrega/retirada), areas de entrega e localizacao do restaurante. */
final class EntregaPagamentoRepository
{
    public const PAGAMENTOS = ['pag_pix_online', 'pag_pix_entrega', 'pag_debito', 'pag_credito', 'pag_dinheiro'];
    private const CAMPOS = ['aceita_entrega', 'aceita_retirada', 'entrega_minutos_min', 'entrega_minutos_max', 'retirada_minutos', ...self::PAGAMENTOS];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed> configuracao (padrao tudo desligado se ainda nao salvou) + 'salvo' */
    public function config(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT ' . implode(', ', self::CAMPOS) . ' FROM restaurante_entrega_pagamento WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $config = ['salvo' => $row !== false];
        foreach (self::CAMPOS as $campo) {
            $v = $row[$campo] ?? null;
            $config[$campo] = str_contains($campo, 'minutos') ? ($v !== null ? (int) $v : null) : (int) ($v ?? 0);
        }
        return $config;
    }

    /** @param array<string,int|null> $c */
    public function salvarConfig(int $idRestaurante, array $c): void
    {
        $valores = array_map(static fn (string $campo) => $c[$campo] ?? null, self::CAMPOS);
        $existe = $this->db->prepare('SELECT 1 FROM restaurante_entrega_pagamento WHERE id_restaurante = ?');
        $existe->execute([$idRestaurante]);
        if ($existe->fetchColumn() !== false) {
            $this->db->prepare('UPDATE restaurante_entrega_pagamento SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', self::CAMPOS))
                . ', atualizado_em = CURRENT_TIMESTAMP WHERE id_restaurante = ?')->execute([...$valores, $idRestaurante]);
            return;
        }
        $this->db->prepare('INSERT INTO restaurante_entrega_pagamento (id_restaurante, ' . implode(', ', self::CAMPOS) . ') VALUES (?'
            . str_repeat(', ?', count(self::CAMPOS)) . ')')->execute([$idRestaurante, ...$valores]);
    }

    /** @return list<array<string,mixed>> bairros por nome, depois faixas de km em ordem crescente */
    public function areas(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT id, tipo, bairro, cidade, raio_km, taxa, pedido_minimo, ativo FROM restaurante_areas_entrega
            WHERE id_restaurante = ? ORDER BY tipo, raio_km, bairro, id');
        $q->execute([$idRestaurante]);
        return array_map(static fn (array $a): array => ['id' => (int) $a['id'], 'ativo' => (int) $a['ativo']] + $a, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function area(int $idRestaurante, int $idArea): ?array
    {
        foreach ($this->areas($idRestaurante) as $a) {
            if ($a['id'] === $idArea) {
                return $a;
            }
        }
        return null;
    }

    public function chaveExiste(int $idRestaurante, string $tipo, string $chave, int $ignorarId = 0): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM restaurante_areas_entrega WHERE id_restaurante = ? AND tipo = ? AND chave = ? AND id <> ?');
        $q->execute([$idRestaurante, $tipo, $chave, $ignorarId]);
        return $q->fetchColumn() !== false;
    }

    /** @param array{tipo:string,bairro:?string,cidade:?string,raio_km:?string,chave:string,taxa:string,pedido_minimo:?string} $a */
    public function criarArea(int $idRestaurante, array $a): int
    {
        $this->db->prepare('INSERT INTO restaurante_areas_entrega (id_restaurante, tipo, bairro, cidade, raio_km, chave, taxa, pedido_minimo, ativo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)')->execute([$idRestaurante, $a['tipo'], $a['bairro'], $a['cidade'], $a['raio_km'], $a['chave'], $a['taxa'], $a['pedido_minimo']]);
        $q = $this->db->prepare('SELECT id FROM restaurante_areas_entrega WHERE id_restaurante = ? AND tipo = ? AND chave = ?');
        $q->execute([$idRestaurante, $a['tipo'], $a['chave']]);
        return (int) $q->fetchColumn();
    }

    /** @param array{bairro:?string,cidade:?string,raio_km:?string,chave:string,taxa:string,pedido_minimo:?string} $a */
    public function atualizarArea(int $idRestaurante, int $idArea, array $a): void
    {
        $this->db->prepare('UPDATE restaurante_areas_entrega SET bairro = ?, cidade = ?, raio_km = ?, chave = ?, taxa = ?, pedido_minimo = ?, atualizado_em = CURRENT_TIMESTAMP
            WHERE id = ? AND id_restaurante = ?')->execute([$a['bairro'], $a['cidade'], $a['raio_km'], $a['chave'], $a['taxa'], $a['pedido_minimo'], $idArea, $idRestaurante]);
    }

    public function ativarArea(int $idRestaurante, int $idArea, bool $ativo): void
    {
        $this->db->prepare('UPDATE restaurante_areas_entrega SET ativo = ?, atualizado_em = CURRENT_TIMESTAMP WHERE id = ? AND id_restaurante = ?')
            ->execute([$ativo ? 1 : 0, $idArea, $idRestaurante]);
    }

    public function excluirArea(int $idRestaurante, int $idArea): void
    {
        $this->db->prepare('DELETE FROM restaurante_areas_entrega WHERE id = ? AND id_restaurante = ?')->execute([$idArea, $idRestaurante]);
    }

    /** @return array{latitude:?float,longitude:?float,logradouro:string,numero:string,bairro:string,cidade:string,estado:string,cep:string} */
    public function local(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT latitude, longitude, logradouro, numero, bairro, cidade, estado, cep FROM restaurantes WHERE id = ?');
        $q->execute([$idRestaurante]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $temCoord = isset($r['latitude'], $r['longitude']) && $r['latitude'] !== '' && $r['longitude'] !== '';
        return [
            'latitude' => $temCoord ? (float) $r['latitude'] : null,
            'longitude' => $temCoord ? (float) $r['longitude'] : null,
        ] + array_map(static fn ($v): string => (string) ($v ?? ''), array_intersect_key($r, array_flip(['logradouro', 'numero', 'bairro', 'cidade', 'estado', 'cep'])))
          + ['logradouro' => '', 'numero' => '', 'bairro' => '', 'cidade' => '', 'estado' => '', 'cep' => ''];
    }

    public function salvarLocal(int $idRestaurante, ?float $lat, ?float $lon): void
    {
        $this->db->prepare('UPDATE restaurantes SET latitude = ?, longitude = ? WHERE id = ?')->execute([
            $lat !== null ? number_format($lat, 7, '.', '') : null,
            $lon !== null ? number_format($lon, 7, '.', '') : null,
            $idRestaurante,
        ]);
    }
}
