<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Repositories\ContaRepository;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Services\Auth\Autenticacao;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Auth\DoisFatores;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Cadastro\CadastroRestaurante;
use Throwable;

final class AuthController
{
    public function login(): Response
    {
        $usuario = Autenticacao::usuario();
        if ($usuario !== null) {
            return Response::redirect(Autorizacao::destino($usuario));
        }
        return $this->formulario();
    }

    public function entrar(): Response
    {
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->formulario('Sua sessão expirou. Confira os dados e tente novamente.', 419);
        }
        $identificacao = is_string($_POST['identificacao'] ?? null) ? $_POST['identificacao'] : '';
        $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        try {
            $resultado = Autenticacao::entrar($identificacao, $senha, $ip, ($_POST['lembrar'] ?? '') === '1');
        } catch (Throwable $exception) {
            error_log('[Cardapim] Login indisponivel: ' . get_class($exception));
            return $this->formulario('Não foi possível entrar agora. Tente novamente em instantes.', 503, $identificacao);
        }
        if (!empty($resultado['verificacao'])) {
            return Response::redirect('/login/verificacao', 303);
        }
        if (!$resultado['ok']) {
            return $this->formulario($resultado['erro'], $resultado['status'], $identificacao, ($_POST['lembrar'] ?? '') === '1');
        }
        return $this->irParaArea($resultado['usuario']);
    }

    /** Tela do codigo da verificacao em duas etapas. */
    public function verificacao(): Response
    {
        if (Autenticacao::usuario() !== null) {
            return Response::redirect(Autorizacao::destino(Autenticacao::usuario()));
        }
        if (Autenticacao::pendente() === null) {
            return Response::redirect('/login', 303);
        }
        return $this->telaVerificacao();
    }

    public function verificar(): Response
    {
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->telaVerificacao('Sua sessão expirou. Tente de novo.', 419);
        }
        $codigo = is_string($_POST['codigo'] ?? null) ? mb_substr($_POST['codigo'], 0, 40) : '';
        try {
            $resultado = Autenticacao::verificarSegundaEtapa($codigo, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        } catch (Throwable $exception) {
            error_log('[Cardapim] Verificacao indisponivel: ' . get_class($exception));
            return $this->telaVerificacao('Não foi possível conferir o código agora. Tente novamente em instantes.', 503);
        }
        if ($resultado['ok']) {
            return $this->irParaArea($resultado['usuario']);
        }
        if (!empty($resultado['expirou'])) {
            return $this->formulario($resultado['erro'], $resultado['status']);
        }
        return $this->telaVerificacao($resultado['erro'], $resultado['status']);
    }

    public function reenviar(): Response
    {
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->telaVerificacao('Sua sessão expirou. Tente de novo.', 419);
        }
        $envio = Autenticacao::reenviarCodigo();
        if (Autenticacao::pendente() === null) {
            return $this->formulario($envio['mensagem'], 401);
        }
        return $this->telaVerificacao($envio['ok'] ? null : $envio['mensagem'], $envio['ok'] ? 200 : 429, $envio['ok'] ? $envio['mensagem'] : null);
    }

    private function irParaArea(\Cardapim\Services\Auth\UsuarioLogado $usuario): Response
    {
        // Volta para a pagina pedida antes do login, se o cargo tiver permissao; senao, area do cargo.
        $pedido = Autorizacao::caminhoLocalSeguro(Session::flash('login_destino'));
        $destino = Autorizacao::destino($usuario);
        if ($pedido !== null && $this->permitido($usuario, $pedido)) {
            $destino = $pedido;
        }
        return Response::redirect($destino, 303);
    }

    public function sair(): Response
    {
        if (Session::csrfValido($_POST['_csrf'] ?? null)) {
            Autenticacao::sair((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        }
        return Response::redirect('/login?saiu=1', 303);
    }

    public function register(): Response
    {
        $id = filter_var($_GET['plano_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $convite = is_string($_GET['convite'] ?? null) ? trim($_GET['convite']) : '';
        return $this->telaCadastro([
            'plano_id' => $id !== false && $id !== null ? (string) $id : '',
            'convite' => $convite,
        ], [], 200, $convite !== '' || isset($_GET['plano_id']));
    }

    /** POST /cadastro: cria o restaurante, a conta do gerente e a assinatura; ja entra no painel. */
    public function cadastrar(): Response
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $valores = array_map(static fn (mixed $v): string => is_string($v) ? mb_substr($v, 0, 200) : '', array_diff_key($_POST, ['senha' => 1, '_csrf' => 1]));
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->telaCadastro($valores, ['geral' => 'Sua sessão expirou. Confira os dados e envie de novo.'], 419);
        }
        $limite = RateLimiter::padrao(10, 3600);
        if ($limite->excedido('cadastro|' . $ip)) {
            return $this->telaCadastro($valores, ['geral' => 'Muitas tentativas de cadastro. Aguarde uma hora e tente de novo.'], 429);
        }
        $limite->permitir('cadastro|' . $ip);
        try {
            $r = (new CadastroRestaurante(Database::connect()))->cadastrar($_POST);
        } catch (Throwable $exception) {
            error_log('[Cardapim] Cadastro falhou: ' . get_class($exception) . ': ' . $exception->getMessage());
            return $this->telaCadastro($valores, ['geral' => 'Não foi possível concluir o cadastro agora. Tente de novo em instantes.'], 503);
        }
        if (!$r['ok']) {
            return $this->telaCadastro($valores, $r['erros'] ?? [], 422);
        }
        Autenticacao::entrarAposCadastro((int) $r['id_user'], $ip);
        $base = '/' . $r['slug'] . '/gerencia';
        $cobranca = $r['cobranca'] ?? ['ok' => true, 'mensagem' => ''];
        if (!empty($r['gratis'])) {
            Session::set('flash', ['tipo' => 'sucesso', 'mensagem' => 'Conta criada! Bem-vindo. Comece cadastrando o cardápio em Estoque.']);
            return Response::redirect($base, 303);
        }
        if (!$cobranca['ok']) {
            Session::set('flash', ['tipo' => 'erro', 'mensagem' => 'Conta criada! ' . $cobranca['mensagem'] . ' Toque em "Gerar cobrança" daqui a pouco.']);
            return Response::redirect($base . '/assinatura', 303);
        }
        Session::set('flash', ['tipo' => 'sucesso', 'mensagem' => !empty($r['teste'])
            ? 'Conta criada! Aproveite os dias grátis. A primeira fatura já está aqui embaixo, com vencimento no fim do teste.'
            : 'Conta criada! Pague a primeira fatura para o seu cardápio aparecer para os clientes. Enquanto isso, já dá para montar tudo.']);
        return Response::redirect($base . '/assinatura', 303);
    }

    /**
     * @param array<string,string> $valores
     * @param array<string,string> $erros
     */
    private function telaCadastro(array $valores, array $erros, int $status = 200, bool $planoNaUrl = false): Response
    {
        Session::iniciar();
        $planos = [];
        $plano = null;
        $pagamento = false;
        $teste = 0;
        try {
            $db = Database::connect();
            $servico = new CadastroRestaurante($db);
            $convite = $valores['convite'] ?? '';
            $id = ctype_digit($valores['plano_id'] ?? '') ? (int) $valores['plano_id'] : null;
            if ($convite !== '') {
                $plano = $servico->plano($convite, null);
            } else {
                $planos = (new PlanoRepository($db))->listarPublicos();
                $plano = $id !== null ? $servico->plano(null, $id) : null;
            }
            $pagamento = $servico->pagamentoDisponivel();
            $teste = (new \Cardapim\Services\Plataforma\CobrancaAssinaturas($db))->regras()['teste_dias'];
        } catch (Throwable $exception) {
            error_log('[Cardapim] Cadastro indisponivel: ' . get_class($exception));
        }
        $html = View::render('auth/register', [
            'pwa' => true,
            'pageTitle' => 'Criar conta | ' . marca(),
            'pageDescription' => 'Cadastre seu restaurante no ' . marca() . '.',
            'noIndex' => true,
            'pageScripts' => ['js/forms.js'],
            'csrf' => Session::tokenCsrf(),
            'planos' => $planos,
            'plano' => $plano,
            'convite' => ($valores['convite'] ?? '') !== '' ? (string) $valores['convite'] : null,
            'planoInvalido' => $planoNaUrl && $plano === null,
            'pagamentoDisponivel' => $pagamento,
            'testeDias' => $teste,
            'valores' => $valores,
            'erros' => $erros,
        ], 'layouts/public');
        return Guard::semCache(Response::html($html, $status));
    }

    private function telaVerificacao(?string $erro = null, int $status = 200, ?string $aviso = null): Response
    {
        $pendente = Autenticacao::pendente();
        $metodo = DoisFatores::DESATIVADO;
        if ($pendente !== null) {
            $conta = (new ContaRepository(Database::connect()))->buscar((int) $pendente['id']);
            $metodo = (string) ($conta['dois_fatores'] ?? DoisFatores::DESATIVADO);
        }
        $flash = Session::flash('flash_verificacao');
        $html = View::render('auth/verificacao', [
            'pwa' => true,
            'pageTitle' => 'Confirme que é você | ' . marca(),
            'pageDescription' => 'Verificação em duas etapas.',
            'noIndex' => true,
            'pageScripts' => ['js/forms.js'],
            'csrf' => Session::tokenCsrf(),
            'erro' => $erro,
            'aviso' => $aviso ?? (is_string($flash) ? $flash : null),
            'metodo' => $metodo,
        ], 'layouts/public');
        return Guard::semCache(Response::html($html, $status));
    }

    private function formulario(?string $erro = null, int $status = 200, string $identificacao = '', bool $lembrar = false): Response
    {
        Session::iniciar();
        $html = View::render('auth/login', [
            'pwa' => true,
            'pageTitle' => 'Entrar | ' . marca(),
            'pageDescription' => 'Acesse sua conta ' . marca() . '.',
            'noIndex' => true,
            'pageScripts' => ['js/forms.js'],
            'csrf' => Session::tokenCsrf(),
            'erro' => $erro,
            'saiu' => $erro === null && isset($_GET['saiu']),
            'senhaAlterada' => $erro === null && ($_GET['senha'] ?? '') === 'alterada',
            'identificacao' => mb_substr($identificacao, 0, 190),
            'lembrar' => $lembrar,
        ], 'layouts/public');
        return Guard::semCache(Response::html($html, $status));
    }

    /** Confere se o caminho pedido pertence a uma area liberada para o usuario. */
    private function permitido(\Cardapim\Services\Auth\UsuarioLogado $usuario, string $caminho): bool
    {
        if ($caminho === '/painel') {
            return Autorizacao::podeAcessarPainel($usuario);
        }
        if (preg_match('~\A/([a-z0-9-]+)/(gerencia|caixa|entregas|garcom)(?:/[a-z-]{1,40})?\z~', $caminho, $m) === 1) {
            return $usuario->cargo === Autorizacao::CARGO_MASTER
                || ($usuario->restauranteSlug === $m[1] && in_array($usuario->cargo, Autorizacao::AREAS_RESTAURANTE[$m[2]], true));
        }
        return $caminho === '/minha-conta';
    }
}
