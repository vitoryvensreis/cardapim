<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Api;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Services\Cliente\ClienteSessao;
use Cardapim\Services\Geo\Geocodificador;
use Cardapim\Services\Pedidos\CheckoutPublico;
use Cardapim\Services\Pedidos\CriarPedido;
use Cardapim\Services\Pedidos\Endereco;
use Cardapim\Services\Pedidos\PixPedido;
use Cardapim\Services\Pedidos\VerificadorEntrega;
use Cardapim\Shared\Numero;
use Cardapim\Shared\Slug;
use Throwable;

/**
 * Finalizacao do pedido pelo cliente (JSON):
 * GET  /api/v1/cep/{cep}                          endereco do CEP (BrasilAPI/ViaCEP, com cache)
 * GET  /api/v1/cliente/endereco                   ultimo endereco usado pelo cliente conectado
 * POST /api/v1/restaurantes/{slug}/entrega        entrega neste endereco? taxa e pedido minimo
 * POST /api/v1/restaurantes/{slug}/pedidos        cria o pedido (cliente conectado)
 * GET  /api/v1/pedidos/{id}                       acompanhamento (so o dono do pedido)
 * GET  /api/v1/cliente/pedidos?restaurante=&antes= meus pedidos (historico + em andamento no restaurante)
 * POST exige X-CSRF-Token.
 */
final class PedidoClienteController
{
    public function cep(string $cep): Response
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!RateLimiter::padrao(40, 600)->permitir('cep|' . $ip)) {
            return Response::json(['erro' => 'Muitas consultas. Aguarde um pouco ou digite o endereço.'], 429);
        }
        $achado = (new Geocodificador())->cep($cep);
        if ($achado === null) {
            return Response::json(['erro' => 'CEP não encontrado. Confira os números ou digite o endereço.'], 404);
        }
        unset($achado['latitude'], $achado['longitude']);
        return Response::json($achado)->comCabecalhos(['Cache-Control' => 'private, max-age=86400']);
    }

    public function endereco(): Response
    {
        $cliente = ClienteSessao::atual();
        if ($cliente === null) {
            return Response::json(['erro' => 'Entre na sua conta.'], 401);
        }
        $tem = !empty($cliente['logradouro']) && !empty($cliente['bairro']);
        return Response::json([
            'endereco' => $tem ? [
                'cep' => (string) ($cliente['cep'] ?? ''),
                'logradouro' => (string) $cliente['logradouro'],
                'numero' => (string) ($cliente['numero'] ?? ''),
                'complemento' => (string) ($cliente['complemento'] ?? ''),
                'bairro' => (string) $cliente['bairro'],
                'cidade' => (string) ($cliente['cidade'] ?? ''),
                'estado' => (string) ($cliente['estado'] ?? ''),
                'referencia' => (string) ($cliente['ponto_referencia'] ?? ''),
            ] : null,
            'tem_cpf' => !empty($cliente['cpf']),
        ])->comCabecalhos(['Cache-Control' => 'no-store']);
    }

    public function entrega(string $slug): Response
    {
        return $this->comRestaurante($slug, 4096, function (array $restaurante, array $dados, string $ip): Response {
            if (!RateLimiter::padrao(60, 600)->permitir('entrega-verificar|' . $ip)) {
                return Response::json(['ok' => false, 'mensagem' => 'Muitas consultas. Aguarde um pouco.'], 429);
            }
            $norm = Endereco::normalizar($dados['endereco'] ?? null);
            if ($norm['erros'] !== []) {
                return Response::json(['ok' => false, 'entrega' => false, 'mensagem' => 'Complete o endereço.', 'erros' => $norm['erros']], 422);
            }
            $r = (new VerificadorEntrega(Database::connect()))->verificar($restaurante['id'], $norm['dados']);
            return Response::json([
                'ok' => true,
                'entrega' => $r['entrega'],
                'mensagem' => $r['mensagem'],
                'area' => $r['area'],
                'taxa_centavos' => Numero::centavos($r['taxa']),
                'pedido_minimo_centavos' => $r['pedido_minimo'] !== null ? Numero::centavos($r['pedido_minimo']) : null,
                'distancia_km' => $r['distancia_km'],
            ]);
        });
    }

    public function criar(string $slug): Response
    {
        return $this->comRestaurante($slug, 16384, function (array $restaurante, array $dados, string $ip): Response {
            $cliente = ClienteSessao::atual();
            if ($cliente === null) {
                return Response::json(['ok' => false, 'mensagem' => 'Entre na sua conta para fazer o pedido.', 'login' => true], 401);
            }
            $db = Database::connect();
            $r = (new CriarPedido($db, new VerificadorEntrega($db), new PixPedido($db)))->criar($restaurante, $cliente, $dados, $ip);
            $status = $r['status'];
            unset($r['status']);
            return Response::json($r, $status);
        });
    }

    /**
     * Meus pedidos: historico do cliente (10 por pagina, ?antes=id para a proxima) e, com ?restaurante=slug,
     * so os desse restaurante + os que ainda estao em andamento (para o aviso "Acompanhar" no cardapio).
     */
    public function meus(): Response
    {
        $cliente = ClienteSessao::atual();
        if ($cliente === null) {
            return Response::json(['erro' => 'Entre na sua conta para ver seus pedidos.', 'login' => true], 401);
        }
        if (!RateLimiter::padrao(60, 60)->permitir('meus-pedidos|' . (int) $cliente['id'])) {
            return Response::json(['erro' => 'Muitas consultas. Aguarde um pouco.'], 429);
        }
        $slug = (string) ($_GET['restaurante'] ?? '');
        $antes = (string) ($_GET['antes'] ?? '');
        if (($slug !== '' && (!Slug::formatoValido($slug) || Slug::reservado($slug))) || ($antes !== '' && (!ctype_digit($antes) || strlen($antes) > 18))) {
            return Response::json(['erro' => 'Consulta inválida.'], 422);
        }
        try {
            $db = Database::connect();
            $idRestaurante = null;
            if ($slug !== '') {
                $restaurante = (new RestauranteRepository($db))->buscarPublicoPorSlug($slug);
                if ($restaurante === null) {
                    return Response::json(['erro' => 'Restaurante não encontrado.'], 404);
                }
                $idRestaurante = (int) $restaurante['id'];
            }
            $historico = new \Cardapim\Services\Pedidos\HistoricoCliente($db);
            $pagina = $historico->pagina((int) $cliente['id'], $idRestaurante, $antes === '' ? null : (int) $antes);
            if ($idRestaurante !== null && $antes === '') {
                $pagina['em_andamento'] = $historico->emAndamento((int) $cliente['id'], $idRestaurante);
            }
            return Response::json($pagina)->comCabecalhos(['Cache-Control' => 'no-store']);
        } catch (Throwable $erro) {
            error_log('[Cardapim] Meus pedidos indisponivel: ' . get_class($erro));
            return Response::json(['erro' => 'Não foi possível carregar seus pedidos agora.'], 503);
        }
    }

    /** Onde esta o entregador do pedido (so o dono do pedido; so enquanto saiu para entrega). */
    public function rastreio(string $id): Response
    {
        $cliente = ClienteSessao::atual();
        if ($cliente === null) {
            return Response::json(['erro' => 'Entre na sua conta para ver o pedido.', 'login' => true], 401);
        }
        if (!ctype_digit($id) || strlen($id) > 18) {
            return Response::json(['erro' => 'Pedido não encontrado.'], 404);
        }
        try {
            $db = Database::connect();
            $pedido = (new PedidoRepository($db))->doCliente((int) $cliente['id'], (int) $id);
            if ($pedido === null) {
                return Response::json(['erro' => 'Pedido não encontrado.'], 404);
            }
            return Response::json(\Cardapim\Services\Entregas\Rastreio::paraCliente($db, $pedido))->comCabecalhos(['Cache-Control' => 'no-store']);
        } catch (Throwable $erro) {
            error_log('[Cardapim] Rastreio indisponivel: ' . get_class($erro));
            return Response::json(['erro' => 'Não foi possível localizar o entregador agora.'], 503);
        }
    }

    public function mostrar(string $id): Response
    {
        $cliente = ClienteSessao::atual();
        if ($cliente === null) {
            return Response::json(['erro' => 'Entre na sua conta para ver o pedido.', 'login' => true], 401);
        }
        if (!ctype_digit($id) || strlen($id) > 18) {
            return Response::json(['erro' => 'Pedido não encontrado.'], 404);
        }
        try {
            $db = Database::connect();
            $repo = new PedidoRepository($db);
            $pedido = $repo->doCliente((int) $cliente['id'], (int) $id);
            if ($pedido === null) {
                return Response::json(['erro' => 'Pedido não encontrado.'], 404);
            }
            $pedido = (new PixPedido($db))->acompanhar($pedido);
            $idRest = (int) $pedido['id_restaurante'];
            $q = $db->prepare('SELECT slug FROM restaurantes WHERE id = ?');
            $q->execute([$idRest]);
            return Response::json(CheckoutPublico::pedidoParaCliente($pedido, $repo->itens($idRest, (int) $pedido['id']), $repo->eventos($idRest, (int) $pedido['id']), (string) $q->fetchColumn()))
                ->comCabecalhos(['Cache-Control' => 'no-store']);
        } catch (Throwable $erro) {
            error_log('[Cardapim] Pedido do cliente indisponivel: ' . get_class($erro));
            return Response::json(['erro' => 'Não foi possível carregar o pedido agora.'], 503);
        }
    }

    /** CSRF + corpo JSON + restaurante publico do slug. @param callable(array,array,string):Response $executar */
    private function comRestaurante(string $slug, int $max, callable $executar): Response
    {
        if (!Session::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            return Response::json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página e tente de novo.'], 419);
        }
        if (!Slug::formatoValido($slug) || Slug::reservado($slug)) {
            return Response::json(['ok' => false, 'mensagem' => 'Restaurante não encontrado.'], 404);
        }
        $corpo = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        $dados = strlen($corpo) <= $max ? json_decode($corpo, true) : null;
        if (!is_array($dados)) {
            return Response::json(['ok' => false, 'mensagem' => 'Pedido inválido.'], 400);
        }
        try {
            $restaurante = (new RestauranteRepository(Database::connect()))->buscarPublicoPorSlug($slug);
            if ($restaurante === null) {
                return Response::json(['ok' => false, 'mensagem' => 'Restaurante não encontrado.'], 404);
            }
            return $executar($restaurante, $dados, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        } catch (Throwable $erro) {
            error_log('[Cardapim] Finalizacao do pedido falhou: ' . get_class($erro) . ' ' . $erro->getMessage());
            return Response::json(['ok' => false, 'mensagem' => 'Algo deu errado do nosso lado. Tente de novo em instantes.'], 503);
        }
    }
}
