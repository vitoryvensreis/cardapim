<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Services\Caixa\EntregaBalcaoService;

/**
 * Aba "Entrega" da venda no balcao (caixa): buscar pelo telefone, buscar o bairro pela rua, calcular a taxa
 * (JSON, sem gravar nada) e "Preparar pedido" (formulario do popup, grava e vai para o pedido no delivery).
 * Acesso: o mesmo do caixa (BaseCaixa); todo POST com CSRF; o restaurante vem sempre da sessao.
 */
final class EntregaBalcaoController extends BaseCaixa
{
    public function telefone(string $slug, string $id): Response
    {
        if ($r = $this->entrarJson($slug)) {
            return $r;
        }
        if (!$this->limite('telefone')) {
            return self::json(['ok' => false, 'mensagem' => 'Muitas buscas seguidas. Aguarde um minuto.'], 429);
        }
        return self::json($this->servico()->porTelefone($this->post('telefone')));
    }

    public function bairro(string $slug, string $id): Response
    {
        if ($r = $this->entrarJson($slug)) {
            return $r;
        }
        if (!$this->limite('bairro')) {
            return self::json(['ok' => false, 'bairros' => [], 'mensagem' => 'Muitas buscas seguidas. Aguarde um minuto e digite o bairro.'], 429);
        }
        return self::json($this->servico()->bairros(mb_substr($this->post('logradouro'), 0, 150)));
    }

    public function calcular(string $slug, string $id): Response
    {
        if ($r = $this->entrarJson($slug)) {
            return $r;
        }
        if (self::id($id) === null) {
            return self::json(['ok' => false, 'entrega' => false, 'mensagem' => 'Venda não encontrada.'], 404);
        }
        if (!$this->limite('calcular')) {
            return self::json(['ok' => false, 'entrega' => false, 'mensagem' => 'Muitos cálculos seguidos. Aguarde um minuto.'], 429);
        }
        $res = $this->servico()->calcular((int) $id, $this->endereco());
        return self::json($res, $res['ok'] ? 200 : 422);
    }

    public function preparar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $volta = $this->url('comandas/' . (int) $id);
        if (self::id($id) === null) {
            return $this->voltar($this->url('mesas'), 'erro', 'Venda não encontrada.');
        }
        $res = $this->servico()->preparar((int) $id, $this->endereco() + [
            'nome' => $this->post('nome'), 'telefone' => $this->post('telefone'),
            'quando' => $this->post('quando'), 'forma' => $this->post('forma'), 'valor' => mb_substr($this->post('valor'), 0, 20),
            'taxa_vista' => mb_substr($this->post('taxa_vista'), 0, 12),
        ]);
        if (!$res->ok) {
            return $this->voltar($volta, 'erro', $res->mensagem);
        }
        return $this->voltar($this->url('delivery/' . (int) $res->id), 'sucesso', $res->mensagem);
    }

    private function servico(): EntregaBalcaoService
    {
        return new EntregaBalcaoService($this->db, $this->restaurante['id'], $this->usuario);
    }

    /** @return array<string,string> */
    private function endereco(): array
    {
        $saida = [];
        foreach (['cep' => 9, 'logradouro' => 150, 'numero' => 20, 'complemento' => 100, 'bairro' => 100] as $campo => $max) {
            $saida[$campo] = mb_substr($this->post($campo), 0, $max);
        }
        return $saida;
    }

    /** Acesso do caixa + CSRF, respondendo em JSON (a aba chama por fetch). */
    private function entrarJson(string $slug): ?Response
    {
        $r = $this->entrar($slug);
        if ($r !== null) {
            return self::json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Atualize a página.'], 401);
        }
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            return self::json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Atualize a página e tente de novo.'], 401);
        }
        return null;
    }

    private function limite(string $acao): bool
    {
        return RateLimiter::padrao(60, 60)->permitir('entrega-balcao-' . $acao . '|' . $this->usuario->id);
    }

    /** @param array<string,mixed> $dados */
    private static function json(array $dados, int $status = 200): Response
    {
        return Response::json($dados, $status)->comCabecalhos(['Cache-Control' => 'no-store']);
    }
}
