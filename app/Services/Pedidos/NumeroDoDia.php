<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Shared\Esquema;
use DateTimeImmutable;
use PDO;

/**
 * Numero do dia do pedido (so visual): #1, #2, #3... e zera quando a loja fecha (fim do expediente do horario
 * de funcionamento; sem horario ou "sempre aberto", a meia-noite). Uma sequencia so para delivery, retirada,
 * balcao, mesas e iFood. O numero corrido (pedidos.numero) continua por tras para buscas, links e relatorios.
 * Pedido antigo (antes da migracao) ou banco sem a coluna: mostra o numero corrido.
 */
final class NumeroDoDia
{
    /**
     * Da o numero do dia ao pedido recem-criado e devolve o numero que aparece nas telas.
     * Chamar na mesma transacao do INSERT, com a linha do restaurante travada (SELECT ... FOR UPDATE).
     */
    public static function atribuir(PDO $db, int $idRestaurante, int $idPedido, int $numeroCorrido, ?DateTimeImmutable $agora = null): int
    {
        if (!Esquema::tem($db, 'numero_dia')) {
            return $numeroCorrido;
        }
        $dia = self::diaOperacional($db, $idRestaurante, $agora ?? new DateTimeImmutable('now'));
        $q = $db->prepare('SELECT COALESCE(MAX(numero_dia), 0) + 1 FROM pedidos WHERE id_restaurante = ? AND dia_operacional = ?');
        $q->execute([$idRestaurante, $dia]);
        $numero = (int) $q->fetchColumn();
        $db->prepare('UPDATE pedidos SET dia_operacional = ?, numero_dia = ? WHERE id = ? AND id_restaurante = ?')
            ->execute([$dia, $numero, $idPedido, $idRestaurante]);
        return $numero;
    }

    /** Dia do expediente do restaurante neste momento (Y-m-d). */
    public static function diaOperacional(PDO $db, int $idRestaurante, DateTimeImmutable $agora): string
    {
        $q = $db->prepare('SELECT sempre_aberto FROM restaurantes WHERE id = ?');
        $q->execute([$idRestaurante]);
        $sempre = (int) $q->fetchColumn() === 1;
        return HorarioFuncionamento::diaOperacional($sempre, (new PerfilRepository($db))->horarios($idRestaurante), $agora);
    }

    /** Numero que aparece para a equipe e o cliente. @param array<string,mixed> $p linha de pedidos */
    public static function de(array $p): int
    {
        $dia = $p['numero_dia'] ?? null;
        return $dia !== null && (int) $dia > 0 ? (int) $dia : (int) ($p['numero'] ?? 0);
    }

    /** "#5". @param array<string,mixed> $p */
    public static function texto(array $p): string
    {
        return '#' . self::de($p);
    }

    /**
     * Expressao SQL do numero mostrado, para consultas com colunas escolhidas (ex.: "COALESCE(p.numero_dia, p.numero)").
     * Sem a migracao, so o numero corrido.
     */
    public static function sql(PDO $db, string $tabela = 'p'): string
    {
        if (preg_match('/\A[a-z_]*\z/', $tabela) !== 1) {
            throw new \InvalidArgumentException('Apelido de tabela invalido.');
        }
        $prefixo = $tabela !== '' ? $tabela . '.' : '';
        return Esquema::tem($db, 'numero_dia') ? "COALESCE({$prefixo}numero_dia, {$prefixo}numero)" : $prefixo . 'numero';
    }
}
