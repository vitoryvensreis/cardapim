<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Services\Gerencia\CupomService;

/** Aba Clientes > Cupons: lista, criar, editar, pausar/ativar e excluir. */
final class CuponsController extends BaseGerencia
{
    private const CAMPOS = ['codigo', 'tipo', 'valor', 'pedido_minimo', 'uso', 'limite_usos', 'um_por_cliente', 'valido_ate', 'ativo'];

    public function lista(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->tela('gerencia/cupons/lista', 'cupons', 'Cupons', [
            'cupons' => $this->servico()->listar(),
            'pageStyles' => ['css/clientes.css'],
        ]);
    }

    public function novo(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->formulario(null, ['tipo' => 'percentual', 'uso' => 'varios', 'ativo' => '1'], []);
    }

    public function criar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = $this->servico()->salvar($_POST);
        if ($res->ok) {
            return $this->voltar($this->url('cupons'), 'sucesso', $res->mensagem);
        }
        return $res->erros !== [] ? $this->formulario(null, $this->enviado(), $res->erros, 422) : $this->voltar($this->url('cupons/novo'), 'erro', $res->mensagem);
    }

    public function editar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $cupom = self::id($id) !== null ? $this->servico()->buscar((int) $id) : null;
        if ($cupom === null) {
            return $this->naoEncontrado('Cupom', $this->url('cupons'));
        }
        return $this->formulario($cupom, CupomService::valoresForm($cupom), []);
    }

    public function atualizar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $cupom = self::id($id) !== null ? $this->servico()->buscar((int) $id) : null;
        if ($cupom === null) {
            return $this->naoEncontrado('Cupom', $this->url('cupons'));
        }
        $res = $this->servico()->salvar($_POST, (int) $id);
        if ($res->ok) {
            return $this->voltar($this->url('cupons'), 'sucesso', $res->mensagem);
        }
        return $res->erros !== [] ? $this->formulario($cupom, $this->enviado(), $res->erros, 422) : $this->voltar($this->url('cupons'), 'erro', $res->mensagem);
    }

    public function pausar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return self::id($id) === null
            ? $this->naoEncontrado('Cupom', $this->url('cupons'))
            : $this->responder($this->servico()->alternar((int) $id), $this->url('cupons'));
    }

    public function excluir(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return self::id($id) === null
            ? $this->naoEncontrado('Cupom', $this->url('cupons'))
            : $this->responder($this->servico()->excluir((int) $id), $this->url('cupons'));
    }

    /** @param array<string,mixed>|null $cupom @param array<string,string> $valores @param array<string,string> $erros */
    private function formulario(?array $cupom, array $valores, array $erros, int $status = 200): Response
    {
        return $this->tela('gerencia/cupons/form', 'cupons', $cupom === null ? 'Novo cupom' : 'Cupom ' . $cupom['codigo'], [
            'cupom' => $cupom,
            'valores' => $valores,
            'erros' => $erros,
            'pageScripts' => ['js/forms.js'],
            'pageStyles' => ['css/clientes.css'],
        ], $status);
    }

    /** @return array<string,string> */
    private function enviado(): array
    {
        $v = [];
        foreach (self::CAMPOS as $campo) {
            $v[$campo] = mb_substr($this->post($campo), 0, 40);
        }
        return $v;
    }

    private function servico(): CupomService
    {
        return new CupomService($this->db, $this->restaurante['id'], $this->usuario);
    }
}
