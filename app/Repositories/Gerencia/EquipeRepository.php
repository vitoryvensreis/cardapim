<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/**
 * Contas da equipe de um restaurante (caixa, garcom, motoqueiro).
 * Toda consulta filtra id_restaurante + cargos da equipe + contas nao excluidas.
 */
final class EquipeRepository
{
    public const CARGOS = ['caixa', 'garcom', 'motoqueiro'];
    private const CAMPOS = 'id, cargo, usuario, email, nome, sobrenome, cpf, whatsapp, ativo, ultimo_acesso, criado_em';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listar(int $idRestaurante): array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM `user`
            WHERE id_restaurante = ? AND cargo IN (' . self::marcadores() . ') AND excluido_em IS NULL
            ORDER BY ativo DESC, nome, sobrenome');
        $query->execute([$idRestaurante, ...self::CARGOS]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $idRestaurante, int $id): ?array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM `user`
            WHERE id = ? AND id_restaurante = ? AND cargo IN (' . self::marcadores() . ') AND excluido_em IS NULL');
        $query->execute([$id, $idRestaurante, ...self::CARGOS]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Usuario/e-mail/CPF sao unicos no sistema todo (nao so no restaurante). */
    public function emUso(string $coluna, string $valor, ?int $ignorarId = null): bool
    {
        if (!in_array($coluna, ['usuario', 'email', 'cpf'], true)) {
            throw new \InvalidArgumentException('Coluna invalida.');
        }
        $query = $this->db->prepare('SELECT id FROM `user` WHERE LOWER(' . $coluna . ') = LOWER(?)');
        $query->execute([$valor]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ((int) $id !== $ignorarId) {
                return true;
            }
        }
        return false;
    }

    /** @param array{cargo:string,usuario:string,email:string,nome:string,sobrenome:string,cpf:?string,whatsapp:?string} $d */
    public function criar(int $idRestaurante, array $d, string $senhaHash): int
    {
        $this->db->prepare('INSERT INTO `user` (id_restaurante, cargo, usuario, email, senha_hash, nome, sobrenome, cpf, whatsapp, ativo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
            ->execute([$idRestaurante, $d['cargo'], $d['usuario'], $d['email'], $senhaHash, $d['nome'], $d['sobrenome'], $d['cpf'], $d['whatsapp']]);
        $id = $this->db->prepare('SELECT id FROM `user` WHERE usuario = ?');
        $id->execute([$d['usuario']]);
        return (int) $id->fetchColumn();
    }

    /** @param array{cargo:string,usuario:string,email:string,nome:string,sobrenome:string,cpf:?string,whatsapp:?string} $d */
    public function atualizar(int $idRestaurante, int $id, array $d): void
    {
        $this->db->prepare('UPDATE `user` SET cargo = ?, usuario = ?, email = ?, nome = ?, sobrenome = ?, cpf = ?, whatsapp = ?
            WHERE id = ? AND id_restaurante = ? AND cargo IN (' . self::marcadores() . ') AND excluido_em IS NULL')
            ->execute([$d['cargo'], $d['usuario'], $d['email'], $d['nome'], $d['sobrenome'], $d['cpf'], $d['whatsapp'], $id, $idRestaurante, ...self::CARGOS]);
    }

    public function definirSenha(int $idRestaurante, int $id, string $hash): void
    {
        $this->db->prepare('UPDATE `user` SET senha_hash = ? WHERE id = ? AND id_restaurante = ? AND cargo IN (' . self::marcadores() . ')')
            ->execute([$hash, $id, $idRestaurante, ...self::CARGOS]);
    }

    public function definirAtivo(int $idRestaurante, int $id, bool $ativo): void
    {
        $this->db->prepare('UPDATE `user` SET ativo = ? WHERE id = ? AND id_restaurante = ? AND cargo IN (' . self::marcadores() . ')')
            ->execute([$ativo ? 1 : 0, $id, $idRestaurante, ...self::CARGOS]);
    }

    public function temHistorico(int $id): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM log_user WHERE id_user = ? LIMIT 1');
        $query->execute([$id]);
        return $query->fetchColumn() !== false;
    }

    /** Conta sem historico: apaga de vez. */
    public function apagar(int $idRestaurante, int $id): void
    {
        $this->db->prepare('DELETE FROM `user` WHERE id = ? AND id_restaurante = ? AND cargo IN (' . self::marcadores() . ')')
            ->execute([$id, $idRestaurante, ...self::CARGOS]);
    }

    /**
     * Conta com historico: some do sistema, mas o id fica para a auditoria (log_user aponta para ele).
     * Dados pessoais sao removidos (LGPD) e o acesso e bloqueado para sempre.
     */
    public function anonimizar(int $idRestaurante, int $id, string $quando): void
    {
        $this->db->prepare("UPDATE `user` SET usuario = ?, email = ?, senha_hash = ?, cpf = NULL, whatsapp = NULL,
                nome = 'Ex-funcionário', sobrenome = ?, ativo = 0, excluido_em = ?, ultimo_codigo_hash = NULL, ultimo_codigo_expira_em = NULL
            WHERE id = ? AND id_restaurante = ? AND cargo IN (" . self::marcadores() . ')')
            ->execute([
                'removido_' . $id,
                'removido+' . $id . '@cardapim.invalid',
                password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                '#' . $id,
                $quando,
                $id,
                $idRestaurante,
                ...self::CARGOS,
            ]);
    }

    private static function marcadores(): string
    {
        return implode(', ', array_fill(0, count(self::CARGOS), '?'));
    }
}
