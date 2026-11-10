<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/** Dados publicos do restaurante (perfil, endereco, logo) e horario de funcionamento. */
final class PerfilRepository
{
    public const CAMPOS_ENDERECO = ['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado', 'ponto_referencia'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $query = $this->db->prepare('SELECT id, nome, slug, telefone, email, cnpj, cep, logradouro, numero, complemento, bairro, cidade, estado,
                ponto_referencia, latitude, longitude, logo_path, sempre_aberto, status_rest
            FROM restaurantes WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,?string> $d nome, telefone, email e campos de endereco
     * @param bool $limparCoordenadas endereco mudou: a localizacao antiga deixa de valer
     */
    public function atualizar(int $id, array $d, bool $limparCoordenadas): void
    {
        $sql = 'UPDATE restaurantes SET nome = ?, telefone = ?, email = ?, cep = ?, logradouro = ?, numero = ?, complemento = ?,
                bairro = ?, cidade = ?, estado = ?, ponto_referencia = ?' . ($limparCoordenadas ? ', latitude = NULL, longitude = NULL' : '') . '
            WHERE id = ?';
        $this->db->prepare($sql)->execute([
            $d['nome'], $d['telefone'], $d['email'], $d['cep'], $d['logradouro'], $d['numero'], $d['complemento'],
            $d['bairro'], $d['cidade'], $d['estado'], $d['ponto_referencia'], $id,
        ]);
    }

    public function definirLogo(int $id, ?string $caminho): void
    {
        $this->db->prepare('UPDATE restaurantes SET logo_path = ? WHERE id = ?')->execute([$caminho, $id]);
    }

    /** @return list<array{dia_semana:int,turno:int,abre:string,fecha:string}> */
    public function horarios(int $idRestaurante): array
    {
        $query = $this->db->prepare('SELECT dia_semana, turno, abre, fecha FROM restaurante_horarios WHERE id_restaurante = ? ORDER BY dia_semana, turno');
        $query->execute([$idRestaurante]);
        return array_map(static fn (array $r): array => [
            'dia_semana' => (int) $r['dia_semana'], 'turno' => (int) $r['turno'],
            'abre' => substr((string) $r['abre'], 0, 5), 'fecha' => substr((string) $r['fecha'], 0, 5),
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Grava o modo e, se informado, substitui todos os turnos. Chamar dentro de transacao.
     * @param list<array{dia_semana:int,turno:int,abre:string,fecha:string}>|null $turnos null = manter os atuais
     */
    public function salvarHorarios(int $idRestaurante, bool $sempreAberto, ?array $turnos): void
    {
        $this->db->prepare('UPDATE restaurantes SET sempre_aberto = ? WHERE id = ?')->execute([$sempreAberto ? 1 : 0, $idRestaurante]);
        if ($turnos === null) {
            return;
        }
        $this->db->prepare('DELETE FROM restaurante_horarios WHERE id_restaurante = ?')->execute([$idRestaurante]);
        $insert = $this->db->prepare('INSERT INTO restaurante_horarios (id_restaurante, dia_semana, turno, abre, fecha) VALUES (?, ?, ?, ?, ?)');
        foreach ($turnos as $t) {
            $insert->execute([$idRestaurante, $t['dia_semana'], $t['turno'], $t['abre'] . ':00', $t['fecha'] . ':00']);
        }
    }
}
