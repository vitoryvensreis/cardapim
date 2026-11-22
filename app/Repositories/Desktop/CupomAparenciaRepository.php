<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Desktop;

use PDO;

/** Aparencia do cupom de cada restaurante (sem linha = padrao do Deliveryzim). */
final class CupomAparenciaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{fonte:string,peso_texto:int,peso_destaque:int,tamanho:string}|null */
    public function buscar(int $idRestaurante): ?array
    {
        $q = $this->db->prepare('SELECT fonte, peso_texto, peso_destaque, tamanho FROM restaurante_cupom_aparencia WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : [
            'fonte' => (string) $r['fonte'], 'peso_texto' => (int) $r['peso_texto'],
            'peso_destaque' => (int) $r['peso_destaque'], 'tamanho' => (string) $r['tamanho'],
        ];
    }

    /** @param array{fonte:string,peso_texto:int,peso_destaque:int,tamanho:string} $a */
    public function salvar(int $idRestaurante, array $a): void
    {
        $this->db->prepare('INSERT INTO restaurante_cupom_aparencia (id_restaurante, fonte, peso_texto, peso_destaque, tamanho)
            VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE fonte = VALUES(fonte), peso_texto = VALUES(peso_texto),
            peso_destaque = VALUES(peso_destaque), tamanho = VALUES(tamanho)')
            ->execute([$idRestaurante, $a['fonte'], $a['peso_texto'], $a['peso_destaque'], $a['tamanho']]);
    }

    /** "Voltar ao padrao": apaga a personalizacao. */
    public function apagar(int $idRestaurante): void
    {
        $this->db->prepare('DELETE FROM restaurante_cupom_aparencia WHERE id_restaurante = ?')->execute([$idRestaurante]);
    }
}
