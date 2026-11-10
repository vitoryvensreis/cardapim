<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

/** Clientes finais (conta da plataforma, login pelo WhatsApp). */
final class ClienteRepository
{
    private const CAMPOS = 'id, nome, whatsapp, cpf, cep, logradouro, numero, complemento, bairro, cidade, estado, ponto_referencia, ativo, ultimo_acesso';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $q = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM clientes WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function buscarPorWhatsapp(string $whatsapp): ?array
    {
        $q = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM clientes WHERE whatsapp = ?');
        $q->execute([$whatsapp]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function criar(string $nome, string $whatsapp, string $agora): int
    {
        $this->db->prepare('INSERT INTO clientes (nome, whatsapp, whatsapp_verificado_em, ultimo_acesso) VALUES (?, ?, ?, ?)')
            ->execute([$nome, $whatsapp, $agora, $agora]);
        $id = (int) $this->db->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $cliente = $this->buscarPorWhatsapp($whatsapp); // lastInsertId pode voltar 0 em alguns drivers
        return (int) ($cliente['id'] ?? 0);
    }

    public function registrarAcesso(int $id, string $agora): void
    {
        $this->db->prepare('UPDATE clientes SET ultimo_acesso = ?, whatsapp_verificado_em = COALESCE(whatsapp_verificado_em, ?) WHERE id = ?')
            ->execute([$agora, $agora, $id]);
    }
}
