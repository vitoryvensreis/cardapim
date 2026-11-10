<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

/** A propria conta do usuario logado: dados, senha, verificacao em duas etapas e aparelhos. */
final class ContaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $query = $this->db->prepare('SELECT id, cargo, usuario, email, senha_hash, nome, sobrenome, whatsapp, dois_fatores, dois_fatores_segredo,
                dois_fatores_ativado_em, ultimo_codigo_hash, ultimo_codigo_expira_em
            FROM `user` WHERE id = ? AND excluido_em IS NULL');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function atualizarDados(int $id, string $nome, string $sobrenome, ?string $whatsapp): void
    {
        $this->db->prepare('UPDATE `user` SET nome = ?, sobrenome = ?, whatsapp = ? WHERE id = ?')->execute([$nome, $sobrenome, $whatsapp, $id]);
    }

    public function definirSenha(int $id, string $hash): void
    {
        $this->db->prepare('UPDATE `user` SET senha_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function ativarDoisFatores(int $id, string $metodo, ?string $segredoCifrado, string $agora): void
    {
        $this->db->prepare('UPDATE `user` SET dois_fatores = ?, dois_fatores_segredo = ?, dois_fatores_ativado_em = ?,
                ultimo_codigo_hash = NULL, ultimo_codigo_expira_em = NULL WHERE id = ?')
            ->execute([$metodo, $segredoCifrado, $agora, $id]);
    }

    public function desativarDoisFatores(int $id): void
    {
        $this->db->prepare("UPDATE `user` SET dois_fatores = 'desativado', dois_fatores_segredo = NULL, dois_fatores_ativado_em = NULL,
                ultimo_codigo_hash = NULL, ultimo_codigo_expira_em = NULL WHERE id = ?")->execute([$id]);
        $this->db->prepare('DELETE FROM user_codigos_recuperacao WHERE id_user = ?')->execute([$id]);
    }

    public function salvarCodigoEmail(int $id, string $hash, string $expiraEm): void
    {
        $this->db->prepare('UPDATE `user` SET ultimo_codigo_hash = ?, ultimo_codigo_expira_em = ? WHERE id = ?')->execute([$hash, $expiraEm, $id]);
    }

    public function limparCodigoEmail(int $id): void
    {
        $this->db->prepare('UPDATE `user` SET ultimo_codigo_hash = NULL, ultimo_codigo_expira_em = NULL WHERE id = ?')->execute([$id]);
    }

    /** @param list<string> $hashes substitui todos os codigos de recuperacao */
    public function substituirCodigos(int $id, array $hashes): void
    {
        $this->db->prepare('DELETE FROM user_codigos_recuperacao WHERE id_user = ?')->execute([$id]);
        $insert = $this->db->prepare('INSERT INTO user_codigos_recuperacao (id_user, codigo_hash) VALUES (?, ?)');
        foreach ($hashes as $hash) {
            $insert->execute([$id, $hash]);
        }
    }

    /** Marca o codigo como usado. true = era valido e ainda nao tinha sido usado. */
    public function usarCodigo(int $id, string $hash, string $agora): bool
    {
        $query = $this->db->prepare('UPDATE user_codigos_recuperacao SET usado_em = ? WHERE id_user = ? AND codigo_hash = ? AND usado_em IS NULL');
        $query->execute([$agora, $id, $hash]);
        return $query->rowCount() === 1;
    }

    public function codigosRestantes(int $id): int
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM user_codigos_recuperacao WHERE id_user = ? AND usado_em IS NULL');
        $query->execute([$id]);
        return (int) $query->fetchColumn();
    }

    /** @return list<array<string,mixed>> aparelhos com "manter conectado" ativo */
    public function aparelhos(int $id, string $agora): array
    {
        $query = $this->db->prepare('SELECT id, seletor, navegador, ip, criado_em, ultimo_uso_em, expira_em FROM user_sessoes_lembradas
            WHERE id_user = ? AND expira_em > ? ORDER BY COALESCE(ultimo_uso_em, criado_em) DESC');
        $query->execute([$id, $agora]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function aparelho(int $idUser, int $id): ?array
    {
        $query = $this->db->prepare('SELECT id, navegador FROM user_sessoes_lembradas WHERE id = ? AND id_user = ?');
        $query->execute([$id, $idUser]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function removerAparelho(int $idUser, int $id): void
    {
        $this->db->prepare('DELETE FROM user_sessoes_lembradas WHERE id = ? AND id_user = ?')->execute([$id, $idUser]);
    }

    /** Remove todos os aparelhos lembrados, menos o atual (seletor do cookie deste navegador). */
    public function removerOutrosAparelhos(int $idUser, ?string $seletorAtual): int
    {
        $query = $this->db->prepare('DELETE FROM user_sessoes_lembradas WHERE id_user = ? AND seletor <> ?');
        $query->execute([$idUser, $seletorAtual ?? '']);
        return $query->rowCount();
    }

    /** @return list<array<string,mixed>> ultimos acessos (logins, recusas e alertas) */
    public function ultimosAcessos(int $id, int $limite = 8): array
    {
        $query = $this->db->prepare("SELECT titulo, descricao, ip, data_hora FROM log_user
            WHERE id_user = ? AND titulo IN ('Login', 'Login automático', 'Login recusado', 'Alerta de segurança', 'Verificação recusada')
            ORDER BY id DESC LIMIT " . max(1, min(30, $limite)));
        $query->execute([$id]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
