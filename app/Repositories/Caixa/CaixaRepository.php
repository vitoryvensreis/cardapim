<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Caixa;

use PDO;

/** Caixas (turnos), entradas/saidas e pagamentos recebidos. Toda consulta filtra pelo restaurante. */
final class CaixaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Caixa aberto agora (no maximo um por restaurante). @return array<string,mixed>|null */
    public function aberto(int $idRestaurante): ?array
    {
        $q = $this->db->prepare(
            "SELECT c.*, CONCAT(u.nome, ' ', u.sobrenome) AS aberto_por
             FROM caixas c LEFT JOIN `user` u ON u.id = c.id_user_abertura
             WHERE c.id_restaurante = ? AND c.status = 'aberto' ORDER BY c.id DESC LIMIT 1"
        );
        $q->execute([$idRestaurante]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        return $c === false ? null : $c;
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $idRestaurante, int $id): ?array
    {
        $q = $this->db->prepare(
            "SELECT c.*, CONCAT(ua.nome, ' ', ua.sobrenome) AS aberto_por, CONCAT(uf.nome, ' ', uf.sobrenome) AS fechado_por
             FROM caixas c
             LEFT JOIN `user` ua ON ua.id = c.id_user_abertura
             LEFT JOIN `user` uf ON uf.id = c.id_user_fechamento
             WHERE c.id = ? AND c.id_restaurante = ?"
        );
        $q->execute([$id, $idRestaurante]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        return $c === false ? null : $c;
    }

    /** @return list<array<string,mixed>> */
    public function historico(int $idRestaurante, int $limite = 60): array
    {
        $q = $this->db->prepare(
            "SELECT c.id, c.status, c.aberto_em, c.fechado_em, c.valor_abertura, c.dinheiro_esperado, c.dinheiro_contado, c.diferenca, c.resumo,
                    CONCAT(ua.nome, ' ', ua.sobrenome) AS aberto_por, CONCAT(uf.nome, ' ', uf.sobrenome) AS fechado_por
             FROM caixas c
             LEFT JOIN `user` ua ON ua.id = c.id_user_abertura
             LEFT JOIN `user` uf ON uf.id = c.id_user_fechamento
             WHERE c.id_restaurante = ? ORDER BY c.id DESC LIMIT " . max(1, min(200, $limite))
        );
        $q->execute([$idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function abrir(int $idRestaurante, int $idUser, string $valor, string $agora): int
    {
        $this->db->prepare('INSERT INTO caixas (id_restaurante, status, aberto_em, id_user_abertura, valor_abertura) VALUES (?, \'aberto\', ?, ?, ?)')
            ->execute([$idRestaurante, $agora, $idUser, $valor]);
        $q = $this->db->prepare("SELECT id FROM caixas WHERE id_restaurante = ? AND status = 'aberto' ORDER BY id DESC LIMIT 1");
        $q->execute([$idRestaurante]);
        return (int) $q->fetchColumn();
    }

    /** Fecha somente se ainda estiver aberto (dois cliques ao mesmo tempo nao fecham duas vezes). */
    public function fechar(int $idRestaurante, int $id, int $idUser, string $esperado, string $contado, string $diferenca, string $resumo, ?string $observacao, string $agora): bool
    {
        $q = $this->db->prepare(
            "UPDATE caixas SET status = 'fechado', fechado_em = ?, id_user_fechamento = ?, dinheiro_esperado = ?, dinheiro_contado = ?, diferenca = ?, resumo = ?, observacao = ?
             WHERE id = ? AND id_restaurante = ? AND status = 'aberto'"
        );
        $q->execute([$agora, $idUser, $esperado, $contado, $diferenca, $resumo, $observacao, $id, $idRestaurante]);
        return $q->rowCount() === 1;
    }

    public function registrarMovimento(int $idRestaurante, int $idCaixa, string $tipo, string $categoria, ?string $descricao, string $valor, int $idUser, string $agora): void
    {
        $this->db->prepare('INSERT INTO caixa_movimentos (id_caixa, id_restaurante, tipo, categoria, descricao, valor, id_user, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$idCaixa, $idRestaurante, $tipo, $categoria, $descricao, $valor, $idUser, $agora]);
    }

    /** @return list<array<string,mixed>> */
    public function movimentos(int $idRestaurante, int $idCaixa): array
    {
        $q = $this->db->prepare(
            "SELECT m.id, m.tipo, m.categoria, m.descricao, m.valor, m.criado_em, CONCAT(u.nome, ' ', u.sobrenome) AS autor
             FROM caixa_movimentos m LEFT JOIN `user` u ON u.id = m.id_user
             WHERE m.id_caixa = ? AND m.id_restaurante = ? ORDER BY m.id DESC"
        );
        $q->execute([$idCaixa, $idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Soma das entradas e saidas em centavos. @return array{entrada:int, saida:int} */
    public function somaMovimentos(int $idRestaurante, int $idCaixa): array
    {
        $q = $this->db->prepare('SELECT tipo, valor FROM caixa_movimentos WHERE id_caixa = ? AND id_restaurante = ?');
        $q->execute([$idCaixa, $idRestaurante]);
        $soma = ['entrada' => 0, 'saida' => 0];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $soma[$m['tipo']] += \Cardapim\Shared\Numero::centavos((string) $m['valor']);
        }
        return $soma;
    }

    /** Pagamentos recebidos no caixa. @return list<array<string,mixed>> */
    public function pagamentos(int $idRestaurante, int $idCaixa): array
    {
        $q = $this->db->prepare(
            "SELECT pg.id, pg.forma, pg.valor, pg.troco, pg.criado_em, p.id AS id_pedido, p.numero, p.tipo, p.id_mesa, m.numero AS mesa_numero, p.comanda_nome,
                    CONCAT(u.nome, ' ', u.sobrenome) AS autor
             FROM pedido_pagamentos pg
             JOIN pedidos p ON p.id = pg.id_pedido AND p.id_restaurante = pg.id_restaurante
             LEFT JOIN mesas m ON m.id = p.id_mesa AND m.id_restaurante = p.id_restaurante
             LEFT JOIN `user` u ON u.id = pg.id_user
             WHERE pg.id_caixa = ? AND pg.id_restaurante = ? ORDER BY pg.id DESC"
        );
        $q->execute([$idCaixa, $idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
