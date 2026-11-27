<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Services\Auth\AcessoRestaurante;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Entregas\EntregadorService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Pedidos\PixPedido;
use Cardapim\Services\Pedidos\TrocarFormaPagamento;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Shared\Validation\Documento;

/**
 * Painel do entregador (/{slug}/entregas): entregas com ele, confirmar entrega, historico e localizacao em tempo real.
 * Gerente que abre /entregas vai para o Delivery do modo caixa (la ele despacha).
 */
final class EntregadorController
{
    public function painel(string $slug): Response
    {
        $acesso = AcessoRestaurante::verificar($slug, 'entregas');
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        if ($usuario->cargo !== 'motoqueiro') {
            return Response::redirect('/' . rawurlencode($restaurante['slug']) . '/caixa/delivery', 303);
        }
        $db = Database::connect();
        $s = new EntregadorService($db, $restaurante['id'], $usuario);
        $flash = Session::flash('flash');
        $emRota = $s->emRota();
        // "Trocar forma de pagamento": opcoes com a taxa de cada forma (Pix por QR Code so com o Asaas conectado).
        $troca = new TrocarFormaPagamento($db, $restaurante['id'], $usuario);
        $comQr = (new PixPedido($db))->disponivel($restaurante['id']);
        foreach ($emRota as &$p) {
            $p['troca'] = TrocarFormaPagamento::motivoBloqueio($p, $usuario->id) === null ? $troca->opcoes($p, $comQr) + ['pede_cpf' => $comQr && !$this->temCpf($p)] : null;
        }
        unset($p);
        return Guard::semCache(Response::html(View::render('entregador/painel', [
            'pageTitle' => 'Minhas entregas · ' . $restaurante['nome'] . ' | ' . marca(),
            'pageDescription' => 'Painel do entregador.',
            'noIndex' => true,
            'usuario' => $usuario,
            'csrf' => Session::tokenCsrf(),
            'restaurante' => $restaurante,
            'flash' => is_array($flash) ? $flash : null,
            'emRota' => $emRota,
            'hoje' => $s->feitas(date('Y-m-d 00:00:00')),
            'resumo' => $s->resumo(),
            'mostrarValores' => (new EntregaPagamentoRepository(Database::connect()))->entregadorVeValores($restaurante['id']),
            'posicao' => $s->posicao(),
            'tempoReal' => TempoReal::ativo() ? '/api/v1/tempo-real/ticket?restaurante=' . rawurlencode($restaurante['slug']) : null,
        ], 'layouts/entregador')));
    }

    public function entregue(string $slug, string $id): Response
    {
        $acesso = $this->motoqueiro($slug);
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        $volta = '/' . rawurlencode($restaurante['slug']) . '/entregas';
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            Session::set('flash', ['tipo' => 'erro', 'mensagem' => 'Sua sessão expirou. Tente de novo.']);
            return Response::redirect($volta, 303);
        }
        $r = ctype_digit($id) && strlen($id) <= 18
            ? (new EntregadorService(Database::connect(), $restaurante['id'], $usuario))->entregar((int) $id)
            : \Cardapim\Services\Gerencia\Resultado::falha('Entrega não encontrada.');
        Session::set('flash', ['tipo' => $r->ok ? 'sucesso' : 'erro', 'mensagem' => $r->mensagem]);
        return Response::redirect($volta, 303);
    }

    /**
     * "Trocar forma de pagamento" (popup): escolha = "forma|taxa vista". Pix por QR Code: troca para Pix pelo app,
     * gera o QR do Asaas e abre a tela do QR. Sem nenhuma pergunta no "Entreguei".
     */
    public function trocarForma(string $slug, string $id): Response
    {
        $acesso = $this->motoqueiro($slug);
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        $volta = '/' . rawurlencode($restaurante['slug']) . '/entregas';
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            Session::set('flash', ['tipo' => 'erro', 'mensagem' => 'Sua sessão expirou. Tente de novo.']);
            return Response::redirect($volta, 303);
        }
        $idPedido = ctype_digit($id) && strlen($id) <= 18 ? (int) $id : 0;
        [$forma, $taxaVista] = array_pad(explode('|', is_string($_POST['escolha'] ?? null) ? $_POST['escolha'] : '', 2), 2, '');
        $db = Database::connect();
        if ($forma === 'pix_online') {
            $r = $this->pixNaEntrega($db, $restaurante, $usuario, $idPedido, $taxaVista);
            if ($r->ok) {
                return Response::redirect($volta . '/' . $idPedido . '/pix', 303);
            }
        } else {
            $r = (new TrocarFormaPagamento($db, $restaurante['id'], $usuario))->trocar($idPedido, $forma, $taxaVista,
                is_string($_POST['troco_para'] ?? null) ? $_POST['troco_para'] : '', $usuario->id);
        }
        Session::set('flash', ['tipo' => $r->ok ? 'sucesso' : 'erro', 'mensagem' => $r->mensagem]);
        return Response::redirect($volta, 303);
    }

    /** Tela do QR Code do Pix da entrega. Com Accept: application/json devolve so a situacao (a tela consulta a cada 4 s). */
    public function pix(string $slug, string $id): Response
    {
        $acesso = $this->motoqueiro($slug);
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        $volta = '/' . rawurlencode($restaurante['slug']) . '/entregas';
        $json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $db = Database::connect();
        $p = ctype_digit($id) && strlen($id) <= 18 ? (new PedidoRepository($db))->buscar($restaurante['id'], (int) $id) : null;
        if ($p === null || (int) ($p['id_entregador'] ?? 0) !== $usuario->id) {
            return $json ? Response::json(['erro' => 'Entrega não encontrada.'], 404) : Response::redirect($volta, 303);
        }
        $situacao = (new PixPedido($db))->situacaoEntrega($p);
        if ($json) {
            return Response::json(['status' => $situacao, 'url' => $volta], 200, ['Cache-Control' => 'no-store']);
        }
        if ($situacao !== 'pendente') {
            Session::set('flash', ['tipo' => $situacao === 'pago' ? 'sucesso' : 'erro', 'mensagem' => $situacao === 'pago'
                ? 'Pix do pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . ' recebido. Toque em "Entreguei" quando entregar.' : 'Este Pix não vale mais. Troque a forma de pagamento.']);
            return Response::redirect($volta, 303);
        }
        return Guard::semCache(Response::html(View::render('entregador/pix', [
            'pageTitle' => 'Pix do pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($p) . ' | ' . marca(),
            'noIndex' => true,
            'usuario' => $usuario,
            'csrf' => Session::tokenCsrf(),
            'restaurante' => $restaurante,
            'pedido' => $p,
            'tempoReal' => null,
        ], 'layouts/entregador')));
    }

    /** Troca para Pix pelo app e gera o QR; se o Asaas falhar, volta a forma de antes. @param array<string,mixed> $restaurante */
    private function pixNaEntrega(\PDO $db, array $restaurante, \Cardapim\Services\Auth\UsuarioLogado $usuario, int $idPedido, string $taxaVista): Resultado
    {
        $pix = new PixPedido($db);
        if (!$pix->disponivel($restaurante['id'])) {
            return Resultado::falha('O Pix por QR Code não está disponível. Escolha Pix para só registrar.');
        }
        $repo = new PedidoRepository($db);
        $p = $repo->buscar($restaurante['id'], $idPedido);
        if ($p === null) {
            return Resultado::falha('Esta entrega não está com você.');
        }
        $cpf = is_string($_POST['cpf'] ?? null) ? $_POST['cpf'] : '';
        if (!$this->temCpf($p) && !Documento::cpfValido((string) Documento::normalizarCpf($cpf))) {
            return Resultado::falha('Digite o CPF do cliente para gerar o Pix (o banco pede).');
        }
        $troca = new TrocarFormaPagamento($db, $restaurante['id'], $usuario);
        $formaAntes = (string) $p['forma_pagamento'];
        if ($formaAntes !== 'pix_online') {
            $r = $troca->trocar($idPedido, 'pix_online', $taxaVista, '', $usuario->id, true);
            if (!$r->ok) {
                return $r;
            }
        }
        try {
            $pix->gerarNaEntrega($repo->buscar($restaurante['id'], $idPedido) ?? $p, $cpf);
            return Resultado::sucesso('');
        } catch (IntegracaoErro|\Throwable $erro) {
            error_log('[Cardapim] Pix da entrega ' . $idPedido . ' nao gerado: ' . get_class($erro));
            if ($formaAntes !== 'pix_online' && isset(TrocarFormaPagamento::FORMAS[$formaAntes])) {
                $atual = $repo->buscar($restaurante['id'], $idPedido) ?? $p;
                $volta = array_values(array_filter($troca->opcoes($atual)['opcoes'], static fn (array $o): bool => $o['forma'] === $formaAntes))[0] ?? null;
                if ($volta !== null) {
                    $troca->trocar($idPedido, $formaAntes, (string) $volta['taxa'], (string) ($p['troco_para'] ?? ''), $usuario->id);
                }
            }
            return Resultado::falha('Não conseguimos gerar o Pix agora. A forma de pagamento continua a de antes.');
        }
    }

    /** O cliente do pedido ja tem CPF salvo (pedido do cardapio). @param array<string,mixed> $p */
    private function temCpf(array $p): bool
    {
        if (empty($p['id_cliente'])) {
            return false;
        }
        $q = Database::connect()->prepare('SELECT cpf FROM clientes WHERE id = ?');
        $q->execute([(int) $p['id_cliente']]);
        return (string) ($q->fetchColumn() ?: '') !== '';
    }

    /** POST JSON {latitude, longitude, precisao, direcao, velocidade} com X-CSRF-Token. */
    public function localizacao(string $slug): Response
    {
        $acesso = $this->motoqueiro($slug);
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        if (!Session::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            return Response::json(['ok' => false, 'mensagem' => 'Sessão expirada. Recarregue a página.'], 419);
        }
        if (!RateLimiter::padrao(40, 60)->permitir('posicao|' . $usuario->id)) {
            return Response::json(['ok' => false, 'mensagem' => 'Muitas atualizações seguidas.'], 429, ['Retry-After' => '30']);
        }
        $corpo = json_decode((string) file_get_contents('php://input', false, null, 0, 2048), true);
        if (!is_array($corpo)) {
            return Response::json(['ok' => false, 'mensagem' => 'Dados inválidos.'], 400);
        }
        $r = (new EntregadorService(Database::connect(), $restaurante['id'], $usuario))
            ->salvarPosicao($corpo['latitude'] ?? null, $corpo['longitude'] ?? null, $corpo['precisao'] ?? null, $corpo['direcao'] ?? null, $corpo['velocidade'] ?? null);
        return Response::json(['ok' => $r->ok, 'mensagem' => $r->mensagem], $r->ok ? 200 : 422, ['Cache-Control' => 'no-store']);
    }

    public function pararLocalizacao(string $slug): Response
    {
        $acesso = $this->motoqueiro($slug);
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        if (!Session::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null))) {
            return Response::json(['ok' => false], 419);
        }
        (new EntregadorService(Database::connect(), $restaurante['id'], $usuario))->pararPosicao();
        return Response::json(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array{0:\Cardapim\Services\Auth\UsuarioLogado,1:array<string,mixed>}|Response */
    private function motoqueiro(string $slug): array|Response
    {
        $acesso = AcessoRestaurante::verificar($slug, 'entregas');
        if ($acesso instanceof Response) {
            return $acesso;
        }
        if ($acesso[0]->cargo !== 'motoqueiro') {
            return Guard::proibido($acesso[0], 'Só o entregador usa esta ação.');
        }
        return $acesso;
    }
}
