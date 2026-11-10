<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Session;
use Cardapim\Repositories\LogUserRepository;
use Cardapim\Repositories\UsuarioRepository;
use Cardapim\Shared\Navegador;
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
    public static function entrar(string $identificacao, string $senha, string $ip, bool $lembrar = false): array
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
        $limiteConta->limpar($chaveConta);

        Session::renovarId();
        Session::set('usuario_id', $usuario->id);

        $navegador = Navegador::resumo($_SERVER['HTTP_USER_AGENT'] ?? null);
        $lembrado = false;
        if ($lembrar) {
            try {
                (new LoginLembrado($db))->criar($usuario->id, $ip, $navegador);
                $lembrado = true;
            } catch (Throwable $exception) {
                // O login continua valido; so o "manter conectado" deixa de valer neste acesso.
                error_log('[Cardapim] Manter conectado indisponivel: ' . get_class($exception));
            }
        }
        self::log($logs, 'Login', sprintf(
            'Acesso realizado como %s, por %s. Manter conectado: %s. %s.',
            Autorizacao::rotuloCargo($usuario->cargo),
            str_contains($identificacao, '@') ? 'e-mail' : 'nome de usuário',
            $lembrado ? 'sim (30 dias)' : 'não',
            $navegador,
        ), $usuario->id, $ip);
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
        if (isset($_COOKIE[Session::NOME])) {
            $id = Session::get('usuario_id');
            if (is_int($id)) {
                $row = (new UsuarioRepository(Database::connect()))->buscarParaSessao($id);
                $usuario = $row !== null ? UsuarioLogado::deLinha($row) : null;
                if ($usuario !== null && Autorizacao::contaLiberada($usuario)) {
                    return self::$atual = $usuario;
                }
                Session::remover('usuario_id');
            }
        }
        // Sem sessao valida: tenta o "manter conectado". Visitante sem cookies nao cria sessao.
        $cookie = $_COOKIE[LoginLembrado::COOKIE] ?? null;
        return is_string($cookie) && $cookie !== '' ? self::restaurarLembrado($cookie) : null;
    }

    /** Recria a sessao a partir do cookie "manter conectado" (com rotacao do token). */
    private static function restaurarLembrado(string $cookie): ?UsuarioLogado
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        try {
            $db = Database::connect();
            $lembrado = new LoginLembrado($db);
            $resultado = $lembrado->validar($cookie);
            $logs = new LogUserRepository($db);
            if ($resultado['status'] === 'suspeito') {
                LoginLembrado::limparCookie();
                self::log($logs, 'Alerta de segurança', 'Cookie de "manter conectado" inválido (possível cópia). Todos os acessos lembrados desta conta foram encerrados.', $resultado['id_user'], $ip);
                return null;
            }
            if ($resultado['status'] !== 'ok') {
                LoginLembrado::limparCookie();
                return null;
            }
            $usuarios = new UsuarioRepository($db);
            $row = $usuarios->buscarParaSessao($resultado['id_user']);
            $usuario = $row !== null ? UsuarioLogado::deLinha($row) : null;
            if ($usuario === null || !Autorizacao::contaLiberada($usuario)) {
                $lembrado->revogarTodos($resultado['id_user']);
                LoginLembrado::limparCookie();
                return null;
            }
            Session::renovarId();
            Session::set('usuario_id', $usuario->id);
            $usuarios->registrarAcesso($usuario->id, date('Y-m-d H:i:s'));
            self::log($logs, 'Login automático', sprintf(
                'Acesso restaurado pelo "manter conectado" como %s. %s.',
                Autorizacao::rotuloCargo($usuario->cargo),
                Navegador::resumo($_SERVER['HTTP_USER_AGENT'] ?? null),
            ), $usuario->id, $ip);
            return self::$atual = $usuario;
        } catch (Throwable $exception) {
            error_log('[Cardapim] Falha ao restaurar "manter conectado": ' . get_class($exception));
            return null;
        }
    }

    public static function sair(string $ip): void
    {
        $usuario = self::usuario();
        if ($usuario !== null) {
            try {
                self::log(new LogUserRepository(Database::connect()), 'Logout', 'Saiu da conta neste aparelho.', $usuario->id, $ip);
            } catch (Throwable) {
            }
        }
        $cookie = $_COOKIE[LoginLembrado::COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            try {
                (new LoginLembrado(Database::connect()))->remover($cookie);
            } catch (Throwable) {
                LoginLembrado::limparCookie();
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
