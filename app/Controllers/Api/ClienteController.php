<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Api;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Services\Cliente\CadastroCliente;
use Cardapim\Services\Cliente\ClienteSessao;
use Cardapim\Services\Cliente\CodigoVerificacao;
use Cardapim\Services\Cliente\WhatsAppPlataforma;
use Throwable;

/**
 * Login/cadastro do cliente final pelo WhatsApp (JSON, usado pelo cardapio /{slug}).
 * GET  /api/v1/cliente            sessao: token CSRF + cliente conectado
 * POST /api/v1/cliente/codigo     {telefone}  envia o codigo pelo WhatsApp da plataforma
 * POST /api/v1/cliente/entrar     {codigo}    confirma; etapa "pronto" ou "nome" (cadastro)
 * POST /api/v1/cliente/cadastro   {nome}      conclui o cadastro de um numero ja confirmado
 * POST /api/v1/cliente/sair
 * Todo POST exige o cabecalho X-CSRF-Token.
 */
final class ClienteController
{
    public function sessao(): Response
    {
        try {
            $cliente = ClienteSessao::atual();
            $csrf = Session::tokenCsrf();
            $whatsapp = (new WhatsAppPlataforma(Database::connect()))->disponivel();
        } catch (Throwable $erro) {
            error_log('[Cardapim] Sessao do cliente indisponivel: ' . get_class($erro));
            return Response::json(['erro' => 'Não foi possível carregar sua conta agora.'], 503);
        }
        return Response::json(['csrf' => $csrf, 'cliente' => ClienteSessao::publico($cliente), 'login_disponivel' => $whatsapp]);
    }

    public function codigo(): Response
    {
        return $this->acao(function (array $dados, string $ip): array {
            return $this->codigos()->solicitar(is_string($dados['telefone'] ?? null) ? $dados['telefone'] : '', $ip);
        });
    }

    public function entrar(): Response
    {
        return $this->acao(function (array $dados, string $ip): array {
            $tentativas = RateLimiter::padrao(30, 900);
            if (!$tentativas->permitir('cliente-entrar-ip|' . $ip)) {
                return ['ok' => false, 'status' => 429, 'mensagem' => 'Muitas tentativas. Aguarde alguns minutos.'];
            }
            return (new CadastroCliente(Database::connect(), $this->codigos()))
                ->confirmarCodigo(is_string($dados['codigo'] ?? null) ? $dados['codigo'] : '', $ip);
        });
    }

    public function cadastro(): Response
    {
        return $this->acao(function (array $dados, string $ip): array {
            return (new CadastroCliente(Database::connect(), $this->codigos()))
                ->concluir(is_string($dados['nome'] ?? null) ? $dados['nome'] : '', $ip);
        });
    }

    public function sair(): Response
    {
        return $this->acao(static function (array $dados, string $ip): array {
            ClienteSessao::sair($ip);
            return ['ok' => true, 'mensagem' => 'Você saiu da sua conta neste aparelho.'];
        });
    }

    /** CSRF + corpo JSON + resposta padrao (com o cliente e um CSRF novo, pois o login troca a sessao). */
    private function acao(callable $executar): Response
    {
        if (!Session::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            return Response::json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página e tente de novo.'], 419);
        }
        $corpo = (string) file_get_contents('php://input', false, null, 0, 4097);
        $dados = strlen($corpo) <= 4096 ? json_decode($corpo, true) : null;
        if (!is_array($dados)) {
            return Response::json(['ok' => false, 'mensagem' => 'Pedido inválido.'], 400);
        }
        try {
            $r = $executar($dados, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $status = (int) ($r['status'] ?? ($r['ok'] ? 200 : 422));
            unset($r['status']);
            return Response::json([...$r, 'cliente' => ClienteSessao::publico(ClienteSessao::atual()), 'csrf' => Session::tokenCsrf()], $status);
        } catch (Throwable $erro) {
            error_log('[Cardapim] Login do cliente falhou: ' . get_class($erro));
            return Response::json(['ok' => false, 'mensagem' => 'Algo deu errado do nosso lado. Tente de novo em instantes.'], 503);
        }
    }

    private function codigos(): CodigoVerificacao
    {
        return new CodigoVerificacao(new WhatsAppPlataforma(Database::connect()));
    }
}
