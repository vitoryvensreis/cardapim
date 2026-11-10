<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

use PDO;

/**
 * "Manter conectado" com tokens divididos (seletor + validador):
 * - Cookie: seletor:validador (HttpOnly, SameSite=Lax, Secure em HTTPS), valido por 30 dias.
 * - Banco: seletor e SHA-256 do validador. Comparacao com hash_equals.
 * - A cada uso o validador e trocado (rotacao) e o prazo renovado.
 * - Validador errado para um seletor existente indica cookie copiado: todos os acessos
 *   lembrados da conta sao encerrados.
 */
final class LoginLembrado
{
    public const COOKIE = 'cardapim_lembrar';
    public const DIAS = 30;
    private const MAX_APARELHOS = 10;

    public function __construct(private readonly PDO $db)
    {
    }

    public function criar(int $idUser, string $ip, string $navegador): void
    {
        $seletor = bin2hex(random_bytes(12));
        $validador = bin2hex(random_bytes(32));
        $expira = time() + self::DIAS * 86400;
        $this->db->prepare(
            'INSERT INTO user_sessoes_lembradas (id_user, seletor, validador_hash, expira_em, ip, navegador)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$idUser, $seletor, hash('sha256', $validador), date('Y-m-d H:i:s', $expira), substr($ip, 0, 45), mb_substr($navegador, 0, 120)]);

        // Mantem so os aparelhos mais recentes e limpa os vencidos desta conta.
        $antigos = $this->db->prepare(
            'SELECT id FROM user_sessoes_lembradas WHERE id_user = ? ORDER BY id DESC LIMIT 1000 OFFSET ' . self::MAX_APARELHOS
        );
        $antigos->execute([$idUser]);
        $ids = array_map('intval', $antigos->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $this->db->prepare('DELETE FROM user_sessoes_lembradas WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                ->execute($ids);
        }
        $this->db->prepare('DELETE FROM user_sessoes_lembradas WHERE id_user = ? AND expira_em < ?')
            ->execute([$idUser, date('Y-m-d H:i:s')]);

        self::enviarCookie($seletor . ':' . $validador, $expira);
    }

    /**
     * @return array{status: 'ok'|'ausente'|'suspeito', id_user?: int}
     *         ok = valido (cookie ja rotacionado); ausente = vencido/removido/malformado;
     *         suspeito = validador errado (acessos lembrados da conta foram encerrados)
     */
    public function validar(string $cookie): array
    {
        if (preg_match('/\A([a-f0-9]{24}):([a-f0-9]{64})\z/', $cookie, $m) !== 1) {
            return ['status' => 'ausente'];
        }
        [, $seletor, $validador] = $m;
        $busca = $this->db->prepare('SELECT id, id_user, validador_hash, expira_em FROM user_sessoes_lembradas WHERE seletor = ?');
        $busca->execute([$seletor]);
        $row = $busca->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'ausente'];
        }
        if ((string) $row['expira_em'] < date('Y-m-d H:i:s')) {
            $this->db->prepare('DELETE FROM user_sessoes_lembradas WHERE id = ?')->execute([(int) $row['id']]);
            return ['status' => 'ausente'];
        }
        if (!hash_equals((string) $row['validador_hash'], hash('sha256', $validador))) {
            $this->revogarTodos((int) $row['id_user']);
            return ['status' => 'suspeito', 'id_user' => (int) $row['id_user']];
        }

        $novo = bin2hex(random_bytes(32));
        $expira = time() + self::DIAS * 86400;
        $this->db->prepare(
            'UPDATE user_sessoes_lembradas SET validador_hash = ?, expira_em = ?, ultimo_uso_em = ? WHERE id = ?'
        )->execute([hash('sha256', $novo), date('Y-m-d H:i:s', $expira), date('Y-m-d H:i:s'), (int) $row['id']]);
        self::enviarCookie($seletor . ':' . $novo, $expira);
        return ['status' => 'ok', 'id_user' => (int) $row['id_user']];
    }

    /** Remove o acesso lembrado deste aparelho (logout). */
    public function remover(string $cookie): void
    {
        if (preg_match('/\A([a-f0-9]{24}):/', $cookie, $m) === 1) {
            $this->db->prepare('DELETE FROM user_sessoes_lembradas WHERE seletor = ?')->execute([$m[1]]);
        }
        self::limparCookie();
    }

    public function revogarTodos(int $idUser): void
    {
        $this->db->prepare('DELETE FROM user_sessoes_lembradas WHERE id_user = ?')->execute([$idUser]);
    }

    public static function limparCookie(): void
    {
        self::enviarCookie('', time() - 3600);
    }

    private static function enviarCookie(string $valor, int $expira): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        if (!headers_sent()) {
            setcookie(self::COOKIE, $valor, [
                'expires' => $expira,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $valor; // mesma requisicao enxerga o valor novo
    }
}
