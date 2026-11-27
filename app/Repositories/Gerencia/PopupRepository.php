<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/** Popup do cardapio online de cada restaurante (sem linha = sem popup). */
final class PopupRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{ativo:bool,tipo:string,imagem_path:?string,titulo:string,texto:string,inicio:?string,fim:?string,versao:int}|null */
    public function buscar(int $idRestaurante): ?array
    {
        $q = $this->db->prepare('SELECT ativo, tipo, imagem_path, titulo, texto, inicio, fim, versao FROM restaurante_popup WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'ativo' => (int) $r['ativo'] === 1,
            'tipo' => $r['tipo'] === 'texto' ? 'texto' : 'imagem',
            'imagem_path' => $r['imagem_path'] !== null && $r['imagem_path'] !== '' ? (string) $r['imagem_path'] : null,
            'titulo' => (string) ($r['titulo'] ?? ''),
            'texto' => (string) ($r['texto'] ?? ''),
            'inicio' => $r['inicio'] !== null ? substr((string) $r['inicio'], 0, 10) : null,
            'fim' => $r['fim'] !== null ? substr((string) $r['fim'], 0, 10) : null,
            'versao' => (int) $r['versao'],
        ];
    }

    /**
     * Grava a configuracao inteira e sobe a versao (o cliente ve o popup de novo).
     * @param array{ativo:bool,tipo:string,imagem_path:?string,titulo:string,texto:string,inicio:?string,fim:?string} $p
     */
    public function salvar(int $idRestaurante, array $p): void
    {
        $this->db->prepare('INSERT INTO restaurante_popup (id_restaurante, ativo, tipo, imagem_path, titulo, texto, inicio, fim, versao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), tipo = VALUES(tipo), imagem_path = VALUES(imagem_path), titulo = VALUES(titulo),
                texto = VALUES(texto), inicio = VALUES(inicio), fim = VALUES(fim), versao = versao + 1')
            ->execute([$idRestaurante, $p['ativo'] ? 1 : 0, $p['tipo'], $p['imagem_path'], $p['titulo'] !== '' ? $p['titulo'] : null,
                $p['texto'] !== '' ? $p['texto'] : null, $p['inicio'], $p['fim']]);
    }
}
