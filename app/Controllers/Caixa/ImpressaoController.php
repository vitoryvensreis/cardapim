<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\Response;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Impressao\FilaImpressao;

/**
 * Botoes "Imprimir" do caixa: mandam o cupom para o Deliveryzim Agent instalado no computador
 * (nunca abrem a janela de impressao do navegador). Respostas em JSON para o aviso na tela;
 * sem JavaScript, volta para a pagina com o aviso.
 */
final class ImpressaoController extends BaseCaixa
{
    public function comanda(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $this->negado($r);
        }
        $idPedido = self::id($id);
        $res = $idPedido !== null ? $this->fila()->comanda($idPedido, 'conta', 'caixa_conta', $this->impressora()) : Resultado::falha('Comanda não encontrada.');
        return $this->resposta($res, $this->url('comandas/' . (int) $idPedido));
    }

    public function pedido(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $this->negado($r);
        }
        $idPedido = self::id($id);
        $res = $idPedido !== null ? $this->fila()->pedido($idPedido, $this->impressora()) : Resultado::falha('Pedido não encontrado.');
        return $this->resposta($res, $this->url('delivery/' . (int) $idPedido));
    }

    public function caixa(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $this->negado($r);
        }
        $idCaixa = self::id($id);
        $res = $idCaixa !== null ? $this->fila()->fechamento($idCaixa, $this->impressora()) : Resultado::falha('Caixa não encontrado.');
        return $this->resposta($res, $this->url('historico/' . (int) $idCaixa));
    }

    /** GET: situacao do cupom (o navegador pergunta ate imprimir ou falhar). */
    public function situacao(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $this->negado($r);
        }
        $idImpressao = self::id($id);
        $s = $idImpressao !== null ? $this->fila()->situacao($idImpressao) : null;
        if ($s === null) {
            return Response::json(['erro' => 'Impressão não encontrada.'], 404)->comCabecalhos(['Cache-Control' => 'no-store']);
        }
        return Response::json($s)->comCabecalhos(['Cache-Control' => 'no-store']);
    }

    /** Impressora escolhida no botao (so quando o ponto tem mais de uma); o servico confere se e permitida. */
    private function impressora(): ?int
    {
        return self::id($this->post('impressora'));
    }

    private function fila(): FilaImpressao
    {
        return new FilaImpressao($this->db, $this->restaurante['id'], $this->restaurante['nome'], $this->usuario);
    }

    private function resposta(Resultado $res, string $volta): Response
    {
        if (self::querJson()) {
            return Response::json(
                $res->ok ? ['ok' => true, 'mensagem' => $res->mensagem, 'situacao' => $this->url('imprimir/' . (int) $res->id)] : ['ok' => false, 'erro' => $res->mensagem],
                $res->ok ? 202 : 409,
            )->comCabecalhos(['Cache-Control' => 'no-store']);
        }
        return $this->voltar($this->origem($volta), $res->ok ? 'sucesso' : 'erro', $res->mensagem);
    }

    /** Sessao vencida/sem acesso: no JSON vira erro legivel em vez de redirecionamento. */
    private function negado(Response $r): Response
    {
        return self::querJson()
            ? Response::json(['ok' => false, 'erro' => 'Sua sessão expirou. Atualize a página e tente de novo.'], 401)
            : $r;
    }

    private static function querJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
}
