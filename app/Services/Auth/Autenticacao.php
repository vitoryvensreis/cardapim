<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Session;
use Cardapim\Repositories\LogUserRepository;
use Cardapim\Repositories\UsuarioRepository;
use Throwable;

/** Login por e-mail ou usuario, sessao e logout. */
final class Autenticacao
{
    private const LIMITE_POR_CONTA = 5;   // tentativas por conta+IP
    private const LIMITE_POR_IP = 30;     // tentativas por IP
    private const JANELA_SEGUNDOS = 900;  // 15 minutos
    /** Hash de uma senha aleatoria descartada: so iguala o tempo de resposta. */
    private const HASH_FICTICIO = '$2y$10$c9CgVuOYfJGs3Gs1NwWBmOiks6hVDIeYh.P8PbWwFOyT0VblFNNka';
    private const MSG_INVALIDO = 'E-mail, usuário ou senha incorretos.';

    private static ?UsuarioLogado $atual = null;
    private static bool $carregado = false;

    /**
     * @return array{ok:bool, usuario?:UsuarioLogado, erro?:string, status?:int}
     */
    public static function entrar(string $identificacao, string $senha, string $ip): array
    {
        $identificacao = trim($identificacao);
        if (!str_contains($identificacao, '@')) {
            $identificacao = strtolower($identificacao);
        }
        if ($identificacao === '' || $senha === '' || strlen($identificacao) > 190 || strlen($senha) > 1024) {
            return ['ok' => false, 'erro' => 'Preencha e-mail ou usuário e a senha.', 'status' => 422];
        }

        $limiteIp = RateLimiter::padrao(self::LIMITE_POR_IP, self::JANELA_SEGUNDOS);
        $limiteConta = RateLimiter::padrao(self::LIMITE_POR_CONTA, self::JANELA_SEGUNDOS);
        $chaveConta = 'login-conta|' . strtolower($identificacao) . '|' . $ip;
        $chaveIp = 'login-ip|' . $ip;
        // So tentativas que falham contam: equipe no mesmo Wi-Fi nao e bloqueada por entrar.
        if ($limiteIp->excedido($chaveIp) || $limiteConta->excedido($chaveConta)) {
            return ['ok' => false, 'erro' => 'Muitas tentativas de acesso. Aguarde 15 minutos e tente novamente.', 'status' => 429];
        }

        $db = Database::connect();
        $usuarios = new UsuarioRepository($db);
        $logs = new LogUserRepository($db);
        $row = $usuarios->buscarParaLogin($identificacao);

        // Mesmo sem usuario, roda password_verify para nao revelar contas pelo tempo de resposta.
        $hash = $row['senha_hash'] ?? self::HASH_FICTICIO;
        if (!password_verify($senha, (string) $hash) || $row === null) {
            $limiteIp->permitir($chaveIp);
            $limiteConta->permitir($chaveConta);
            if ($row !== null) {
                self::log($logs, 'Login recusado', 'Senha incorreta.', (int) $row['id'], $ip);
            }
            return ['ok' => false, 'erro' => self::MSG_INVALIDO, 'status' => 401];
        }

        $usuario = UsuarioLogado::deLinha($row);
        if (!$usuario->ativo) {
            self::log($logs, 'Login recusado', 'Conta desativada.', $usuario->id, $ip);
            return ['ok' => false, 'erro' => 'Esta conta está desativada. Fale com o responsável pelo restaurante.', 'status' => 403];
        }
        if (!Autorizacao::contaLiberada($usuario)) {
            self::log($logs, 'Login recusado', 'Restaurante sem acesso liberado.', $usuario->id, $ip);
            return ['ok' => false, 'erro' => 'O acesso deste restaurante está suspenso. Fale com o suporte do Cardapim.', 'status' => 403];
        }

        if (password_needs_rehash((string) $hash, PASSWORD_DEFAULT)) {
            $usuarios->atualizarHash($usuario->id, password_hash($senha, PASSWORD_DEFAULT));
        }
        $usuarios->registrarAcesso($usuario->id, date('Y-m-d H:i:s'));
        self::log($logs, 'Login', 'Acesso realizado (' . $usuario->cargo . ').', $usuario->id, $ip);
        $limiteConta->limpar($chaveConta);

        Session::renovarId();
        Session::set('usuario_id', $usuario->id);
        self::$atual = $usuario;
        self::$carregado = true;

        return ['ok' => true, 'usuario' => $usuario];
    }

    /** Usuario da sessao atual (null se ninguem logado ou conta bloqueada). */
    public static function usuario(): ?UsuarioLogado
    {
        if (self::$carregado) {
            return self::$atual;
        }
        self::$carregado = true;
        if (!isset($_COOKIE[Session::NOME])) {
            return null; // visitante: nao cria sessao nem cookie
        }
        $id = Session::get('usuario_id');
        if (!is_int($id)) {
            return null;
        }
        $row = (new UsuarioRepository(Database::connect()))->buscarParaSessao($id);
        $usuario = $row !== null ? UsuarioLogado::deLinha($row) : null;
        if ($usuario === null || !Autorizacao::contaLiberada($usuario)) {
            Session::remover('usuario_id');
            return null;
        }
        return self::$atual = $usuario;
    }

    public static function sair(string $ip): void
    {
        $usuario = self::usuario();
        if ($usuario !== null) {
            try {
                self::log(new LogUserRepository(Database::connect()), 'Logout', 'Saiu da conta.', $usuario->id, $ip);
            } catch (Throwable) {
            }
        }
        Session::encerrar();
        self::$atual = null;
    }

    private static function log(LogUserRepository $logs, string $titulo, string $descricao, ?int $id, string $ip): void
    {
        try {
            $logs->registrar($titulo, $descricao, $id, $ip);
        } catch (Throwable $exception) {
            error_log('[Cardapim] Falha ao registrar log_user: ' . get_class($exception));
        }
    }
}
