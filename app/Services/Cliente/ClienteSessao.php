<?php

declare(strict_types=1);

namespace Cardapim\Services\Cliente;

use Cardapim\Core\Database;
use Cardapim\Core\Session;
use Cardapim\Repositories\ClienteRepository;
use Cardapim\Repositories\LogUserRepository;
use Cardapim\Shared\Navegador;
use PDO;
use Throwable;

/**
 * Login do cliente final. Separado do login da equipe: usa a chave "cliente_id" na sessao e o cookie
 * "cardapim_cliente" (continua conectado por 60 dias no aparelho, com troca do token a cada uso).
 * Logs em log_user sem id_user (eventos de cliente), com o numero mascarado.
 */
final class ClienteSessao
{
    public const COOKIE = 'cardapim_cliente';
    public const DIAS = 60;
    private const CHAVE = 'cliente_id';
    private const MAX_APARELHOS = 10;

    /** @var array<string,mixed>|null */
    private static ?array $atual = null;
    private static bool $carregado = false;

    /** Cliente conectado (null = ninguem). Visitante sem cookies nao cria sessao. */
    public static function atual(): ?array
    {
        if (self::$carregado) {
            return self::$atual;
        }
        self::$carregado = true;
        try {
            $db = Database::connect();
            if (isset($_COOKIE[Session::NOME])) {
                $id = Session::get(self::CHAVE);
                if (is_int($id)) {
                    $cliente = (new ClienteRepository($db))->buscar($id);
                    if ($cliente !== null && (int) $cliente['ativo'] === 1) {
                        return self::$atual = $cliente;
                    }
                    Session::remover(self::CHAVE);
                }
            }
            $cookie = $_COOKIE[self::COOKIE] ?? null;
            if (is_string($cookie) && $cookie !== '') {
                $id = self::validarLembrado($db, $cookie);
                $cliente = $id !== null ? (new ClienteRepository($db))->buscar($id) : null;
                if ($cliente !== null && (int) $cliente['ativo'] === 1) {
                    Session::set(self::CHAVE, (int) $cliente['id']);
                    (new ClienteRepository($db))->registrarAcesso((int) $cliente['id'], date('Y-m-d H:i:s'));
                    return self::$atual = $cliente;
                }
                self::limparCookie();
            }
        } catch (Throwable $erro) {
            error_log('[Cardapim] Sessao do cliente indisponivel: ' . get_class($erro));
        }
        return self::$atual = null;
    }

    /** Depois do codigo confirmado: entra, lembra o aparelho e registra o acesso. */
    public static function entrar(int $idCliente, string $ip, bool $novo): void
    {
        $db = Database::connect();
        Session::renovarId();
        Session::set(self::CHAVE, $idCliente);
        $repo = new ClienteRepository($db);
        $repo->registrarAcesso($idCliente, date('Y-m-d H:i:s'));
        self::criarLembrado($db, $idCliente, $ip);
        $cliente = $repo->buscar($idCliente);
        self::$atual = $cliente;
        self::$carregado = true;
        self::log($db, $novo ? 'Cliente: cadastro' : 'Cliente: login', sprintf(
            'Cliente #%d (WhatsApp final %s) %s pelo código do WhatsApp. %s.',
            $idCliente,
            substr((string) ($cliente['whatsapp'] ?? ''), -4),
            $novo ? 'se cadastrou' : 'entrou',
            Navegador::resumo($_SERVER['HTTP_USER_AGENT'] ?? null),
        ), $ip);
    }

    public static function sair(string $ip): void
    {
        $cliente = self::atual();
        try {
            $db = Database::connect();
            $cookie = $_COOKIE[self::COOKIE] ?? null;
            if (is_string($cookie) && preg_match('/\A([a-f0-9]{24}):/', $cookie, $m) === 1) {
                $db->prepare('DELETE FROM cliente_sessoes WHERE seletor = ?')->execute([$m[1]]);
            }
            if ($cliente !== null) {
                self::log($db, 'Cliente: saiu', sprintf('Cliente #%d saiu neste aparelho.', (int) $cliente['id']), $ip);
            }
        } catch (Throwable $erro) {
            error_log('[Cardapim] Falha ao encerrar sessao do cliente: ' . get_class($erro));
        }
        self::limparCookie();
        if (isset($_COOKIE[Session::NOME])) {
            Session::remover(self::CHAVE);
        }
        self::$atual = null;
    }

    /** Dados seguros para o navegador (nunca CPF nem endereco completo aqui). @return array<string,string>|null */
    public static function publico(?array $cliente): ?array
    {
        if ($cliente === null) {
            return null;
        }
        $nome = trim((string) $cliente['nome']);
        return [
            'nome' => $nome,
            'primeiro_nome' => explode(' ', $nome)[0],
            'whatsapp' => \Cardapim\Shared\Validation\Documento::formatarTelefone((string) $cliente['whatsapp']),
        ];
    }

    /** Para testes. */
    public static function esquecer(): void
    {
        self::$atual = null;
        self::$carregado = false;
    }

    private static function criarLembrado(PDO $db, int $idCliente, string $ip): void
    {
        $seletor = bin2hex(random_bytes(12));
        $validador = bin2hex(random_bytes(32));
        $expira = time() + self::DIAS * 86400;
        $db->prepare('INSERT INTO cliente_sessoes (id_cliente, seletor, validador_hash, expira_em, ip, navegador) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$idCliente, $seletor, hash('sha256', $validador), date('Y-m-d H:i:s', $expira), substr($ip, 0, 45),
                mb_substr(Navegador::resumo($_SERVER['HTTP_USER_AGENT'] ?? null), 0, 120)]);
        $antigos = $db->prepare('SELECT id FROM cliente_sessoes WHERE id_cliente = ? ORDER BY id DESC LIMIT 1000 OFFSET ' . self::MAX_APARELHOS);
        $antigos->execute([$idCliente]);
        $ids = array_map('intval', $antigos->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $db->prepare('DELETE FROM cliente_sessoes WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
        }
        $db->prepare('DELETE FROM cliente_sessoes WHERE id_cliente = ? AND expira_em < ?')->execute([$idCliente, date('Y-m-d H:i:s')]);
        self::enviarCookie($seletor . ':' . $validador, $expira);
    }

    /** id do cliente se o cookie for valido (ja com o token trocado). Token errado = cookie copiado: encerra todos. */
    private static function validarLembrado(PDO $db, string $cookie): ?int
    {
        if (preg_match('/\A([a-f0-9]{24}):([a-f0-9]{64})\z/', $cookie, $m) !== 1) {
            return null;
        }
        $q = $db->prepare('SELECT id, id_cliente, validador_hash, expira_em FROM cliente_sessoes WHERE seletor = ?');
        $q->execute([$m[1]]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ((string) $row['expira_em'] < date('Y-m-d H:i:s')) {
            $db->prepare('DELETE FROM cliente_sessoes WHERE id = ?')->execute([(int) $row['id']]);
            return null;
        }
        if (!hash_equals((string) $row['validador_hash'], hash('sha256', $m[2]))) {
            $db->prepare('DELETE FROM cliente_sessoes WHERE id_cliente = ?')->execute([(int) $row['id_cliente']]);
            self::log($db, 'Cliente: alerta de segurança', sprintf('Cookie do cliente #%d inválido (possível cópia). Todos os aparelhos foram desconectados.', (int) $row['id_cliente']), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            return null;
        }
        $novo = bin2hex(random_bytes(32));
        $expira = time() + self::DIAS * 86400;
        $db->prepare('UPDATE cliente_sessoes SET validador_hash = ?, expira_em = ?, ultimo_uso_em = ? WHERE id = ?')
            ->execute([hash('sha256', $novo), date('Y-m-d H:i:s', $expira), date('Y-m-d H:i:s'), (int) $row['id']]);
        self::enviarCookie($m[1] . ':' . $novo, $expira);
        return (int) $row['id_cliente'];
    }

    private static function log(PDO $db, string $titulo, string $descricao, string $ip): void
    {
        try {
            (new LogUserRepository($db))->registrar($titulo, $descricao, null, $ip);
        } catch (Throwable $erro) {
            error_log('[Cardapim] Falha ao registrar log do cliente: ' . get_class($erro));
        }
    }

    private static function limparCookie(): void
    {
        self::enviarCookie('', time() - 3600);
    }

    private static function enviarCookie(string $valor, int $expira): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        if (!headers_sent()) {
            setcookie(self::COOKIE, $valor, ['expires' => $expira, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        }
        $_COOKIE[self::COOKIE] = $valor;
    }
}
