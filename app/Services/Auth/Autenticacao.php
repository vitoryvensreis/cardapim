<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Session;
use Cardapim\Repositories\ContaRepository;
use Cardapim\Repositories\LogUserRepository;
use Cardapim\Repositories\UsuarioRepository;
use Cardapim\Shared\Navegador;
use Throwable;

/** Login por e-mail ou usuario (com verificacao em duas etapas opcional), sessao e logout. */
final class Autenticacao
{
    private const LIMITE_POR_CONTA = 5;   // tentativas por conta+IP
    private const LIMITE_POR_IP = 30;     // tentativas por IP
    private const JANELA_SEGUNDOS = 900;  // 15 minutos
    /** Hash de uma senha aleatoria descartada: so iguala o tempo de resposta. */
    private const HASH_FICTICIO = '$2y$10$c9CgVuOYfJGs3Gs1NwWBmOiks6hVDIeYh.P8PbWwFOyT0VblFNNka';
    private const MSG_INVALIDO = 'E-mail, usuário ou senha incorretos.';
    /** Login aguardando o codigo da verificacao em duas etapas. */
    private const PENDENTE = 'login_pendente';
    private const PENDENTE_VALIDADE = 600;    // 10 minutos para digitar o codigo
    private const PENDENTE_TENTATIVAS = 5;

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
        $limiteConta->limpar($chaveConta);
        $porEmail = str_contains($identificacao, '@');

        // Verificacao em duas etapas: a senha confere, mas a sessao so e criada depois do codigo.
        $metodo = (string) ($row['dois_fatores'] ?? DoisFatores::DESATIVADO);
        if ($metodo !== DoisFatores::DESATIVADO) {
            Session::renovarId();
            Session::remover('usuario_id');
            Session::set(self::PENDENTE, [
                'id' => $usuario->id,
                'lembrar' => $lembrar,
                'por_email' => $porEmail,
                'expira' => time() + self::PENDENTE_VALIDADE,
                'tentativas' => 0,
            ]);
            if ($metodo === DoisFatores::EMAIL) {
                $conta = (new ContaRepository($db))->buscar($usuario->id);
                if ($conta !== null) {
                    $envio = (new DoisFatores($db))->enviarCodigoEmail($conta, 'entrar');
                    Session::set('flash_verificacao', $envio['mensagem']);
                }
            }
            return ['ok' => false, 'verificacao' => true, 'metodo' => $metodo];
        }

        return ['ok' => true, 'usuario' => self::concluir($usuario, $ip, $lembrar, $porEmail)];
    }

    /** Dados do login aguardando o codigo da segunda etapa (ou null se nao ha / expirou). @return array<string,mixed>|null */
    public static function pendente(): ?array
    {
        $pendente = Session::get(self::PENDENTE);
        if (!is_array($pendente) || !is_int($pendente['id'] ?? null) || (int) ($pendente['expira'] ?? 0) < time()) {
            Session::remover(self::PENDENTE);
            return null;
        }
        return $pendente;
    }

    /**
     * Segunda etapa do login: codigo do aplicativo, do e-mail ou de recuperacao.
     * @return array{ok:bool, usuario?:UsuarioLogado, erro?:string, status?:int, expirou?:bool}
     */
    public static function verificarSegundaEtapa(string $codigo, string $ip): array
    {
        $pendente = self::pendente();
        if ($pendente === null) {
            return ['ok' => false, 'erro' => 'O tempo para digitar o código acabou. Entre de novo com sua senha.', 'status' => 401, 'expirou' => true];
        }
        $limiteIp = RateLimiter::padrao(self::LIMITE_POR_IP, self::JANELA_SEGUNDOS);
        if ($limiteIp->excedido('verificacao-ip|' . $ip)) {
            return ['ok' => false, 'erro' => 'Muitas tentativas. Aguarde 15 minutos e tente novamente.', 'status' => 429];
        }
        $db = Database::connect();
        $logs = new LogUserRepository($db);
        $conta = (new ContaRepository($db))->buscar($pendente['id']);
        $row = (new UsuarioRepository($db))->buscarParaSessao($pendente['id']);
        $usuario = $row !== null ? UsuarioLogado::deLinha($row) : null;
        if ($conta === null || $usuario === null || !Autorizacao::contaLiberada($usuario)) {
            Session::remover(self::PENDENTE);
            return ['ok' => false, 'erro' => 'Não foi possível entrar com esta conta.', 'status' => 403, 'expirou' => true];
        }
        $metodo = (new DoisFatores($db))->verificarLogin($conta, $codigo);
        if ($metodo === null) {
            $limiteIp->permitir('verificacao-ip|' . $ip);
            $pendente['tentativas'] = (int) $pendente['tentativas'] + 1;
            self::log($logs, 'Verificação recusada', 'Código da verificação em duas etapas incorreto (tentativa ' . $pendente['tentativas'] . ').', $usuario->id, $ip);
            if ($pendente['tentativas'] >= self::PENDENTE_TENTATIVAS) {
                Session::remover(self::PENDENTE);
                return ['ok' => false, 'erro' => 'Código errado muitas vezes. Por segurança, entre de novo com sua senha.', 'status' => 401, 'expirou' => true];
            }
            Session::set(self::PENDENTE, $pendente);
            return ['ok' => false, 'erro' => 'Código incorreto ou vencido. Confira e tente de novo.', 'status' => 401];
        }
        Session::remover(self::PENDENTE);
        $extra = match ($metodo) {
            DoisFatores::APP => 'Verificado pelo aplicativo autenticador.',
            DoisFatores::EMAIL => 'Verificado por código no e-mail.',
            default => 'Verificado com código de recuperação (restam ' . (new ContaRepository($db))->codigosRestantes($usuario->id) . ').',
        };
        return ['ok' => true, 'usuario' => self::concluir($usuario, $ip, (bool) $pendente['lembrar'], (bool) $pendente['por_email'], $extra)];
    }

    /** Reenvia o codigo por e-mail do login pendente. @return array{ok:bool, mensagem:string} */
    public static function reenviarCodigo(): array
    {
        $pendente = self::pendente();
        if ($pendente === null) {
            return ['ok' => false, 'mensagem' => 'O tempo para digitar o código acabou. Entre de novo com sua senha.'];
        }
        $db = Database::connect();
        $conta = (new ContaRepository($db))->buscar($pendente['id']);
        if ($conta === null || $conta['dois_fatores'] !== DoisFatores::EMAIL) {
            return ['ok' => false, 'mensagem' => 'Esta conta usa o aplicativo autenticador. Abra o aplicativo para ver o código.'];
        }
        return (new DoisFatores($db))->enviarCodigoEmail($conta, 'entrar');
    }

    /** Cria a sessao, o "manter conectado" (se pedido) e registra o login. */
    private static function concluir(UsuarioLogado $usuario, string $ip, bool $lembrar, bool $porEmail, string $extra = ''): UsuarioLogado
    {
        $db = Database::connect();
        $usuarios = new UsuarioRepository($db);
        $logs = new LogUserRepository($db);
        $usuarios->registrarAcesso($usuario->id, date('Y-m-d H:i:s'));

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
        self::log($logs, 'Login', trim(sprintf(
            'Acesso realizado como %s, por %s. Manter conectado: %s. %s. %s',
            Autorizacao::rotuloCargo($usuario->cargo),
            $porEmail ? 'e-mail' : 'nome de usuário',
            $lembrado ? 'sim (30 dias)' : 'não',
            $navegador,
            $extra,
        )), $usuario->id, $ip);
        self::$atual = $usuario;
        self::$carregado = true;

        return $usuario;
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
