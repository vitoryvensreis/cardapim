<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\Response;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Services\Caixa\MesaService;

/** Mapa das mesas (livre/ocupada), abrir comanda e cadastro das mesas. */
final class MesasController extends BaseCaixa
{
    public function mapa(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $mesas = (new MesaService($this->db, $this->restaurante['id'], $this->usuario))->listar();
        return $this->tela('caixa/mesas', 'mesas', 'Mesas e comandas', [
            'mesas' => array_values(array_filter($mesas, static fn (array $m): bool => (int) $m['ativo'] === 1 || $m['comanda_id'] !== null)),
            'totalMesas' => count($mesas),
            'balcoes' => (new ComandaService($this->db, $this->restaurante['id'], $this->usuario))->balcoesAbertos(),
        ]);
    }

    public function abrir(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null
            ? (new ComandaService($this->db, $this->restaurante['id'], $this->usuario))->abrirMesa((int) $id)
            : \Cardapim\Services\Gerencia\Resultado::falha('Mesa não encontrada.');
        return $this->responder($res, $res->id !== null ? $this->url('comandas/' . $res->id) : $this->url('mesas'), $this->url('mesas'));
    }

    public function gerenciar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->tela('caixa/mesas-gerenciar', 'gerenciar-mesas', 'Cadastro de mesas', [
            'mesas' => (new MesaService($this->db, $this->restaurante['id'], $this->usuario))->listar(),
            'editando' => \Cardapim\Core\Session::flash('caixa_mesa_editando'),
        ]);
    }

    public function criar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = (new MesaService($this->db, $this->restaurante['id'], $this->usuario))
            ->criar(['numero' => $this->post('numero'), 'apelido' => $this->post('apelido'), 'lugares' => $this->post('lugares')]);
        return $this->responder($res, $this->url('mesas/gerenciar'));
    }

    public function criarVarias(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = (new MesaService($this->db, $this->restaurante['id'], $this->usuario))->criarVarias($this->post('de'), $this->post('ate'));
        return $this->responder($res, $this->url('mesas/gerenciar'));
    }

    public function atualizar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        if (self::id($id) === null) {
            return $this->voltar($this->url('mesas/gerenciar'), 'erro', 'Mesa não encontrada.');
        }
        $res = (new MesaService($this->db, $this->restaurante['id'], $this->usuario))->atualizar((int) $id, [
            'numero' => $this->post('numero'), 'apelido' => $this->post('apelido'), 'lugares' => $this->post('lugares'),
            'ativo' => ($_POST['ativo'] ?? '') === '1' ? '1' : '0',
        ]);
        if (!$res->ok && $res->erros !== []) {
            \Cardapim\Core\Session::set('caixa_mesa_editando', (int) $id);
        }
        return $this->responder($res, $this->url('mesas/gerenciar'));
    }

    public function excluir(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null
            ? (new MesaService($this->db, $this->restaurante['id'], $this->usuario))->excluir((int) $id)
            : \Cardapim\Services\Gerencia\Resultado::falha('Mesa não encontrada.');
        return $this->responder($res, $this->url('mesas/gerenciar'));
    }
}
