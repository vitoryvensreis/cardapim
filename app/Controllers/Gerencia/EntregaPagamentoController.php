<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Modules\Entrega\AreaEntrega;
use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Services\Gerencia\EntregaPagamentoService;
use Cardapim\Services\Geo\Geocodificador;
use Cardapim\Services\Pedidos\Endereco;
use Cardapim\Services\Pedidos\VerificadorEntrega;

/** Aba Entrega e pagamento: formas de pagamento, entrega/retirada, areas de entrega e localizacao do restaurante. */
final class EntregaPagamentoController extends BaseGerencia
{
    public function mostrar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->pagina();
    }

    public function salvar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $resultado = $this->servico()->salvar($_POST);
        if ($resultado->ok) {
            return $this->voltar($this->url('entrega-pagamento'), 'sucesso', $resultado->mensagem);
        }
        return $this->pagina(['config' => $resultado->erros], ['config' => $_POST], 422);
    }

    public function criarArea(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $tipo = ($_POST['tipo'] ?? '') === 'raio' ? 'raio' : 'bairro';
        $resultado = $this->servico()->salvarArea($_POST);
        if ($resultado->ok) {
            return $this->voltar($this->url('entrega-pagamento') . '#areas-' . $tipo, 'sucesso', $resultado->mensagem);
        }
        return $this->pagina([$tipo => $resultado->erros], [$tipo => $_POST], 422);
    }

    public function atualizarArea(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $idArea = self::id($id);
        if ($idArea === null) {
            return $this->naoEncontrado('Área de entrega', $this->url('entrega-pagamento'));
        }
        $resultado = $this->servico()->salvarArea($_POST, $idArea);
        if ($resultado->ok) {
            return $this->voltar($this->url('entrega-pagamento') . '#area-' . $idArea, 'sucesso', $resultado->mensagem);
        }
        return $this->pagina(['area' . $idArea => $resultado->erros], ['area' . $idArea => $_POST], 422);
    }

    public function alternarArea(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $idArea = self::id($id);
        return $idArea === null
            ? $this->naoEncontrado('Área de entrega', $this->url('entrega-pagamento'))
            : $this->responder($this->servico()->alternarArea($idArea), $this->url('entrega-pagamento') . '#area-' . $idArea);
    }

    public function excluirArea(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $idArea = self::id($id);
        return $idArea === null
            ? $this->naoEncontrado('Área de entrega', $this->url('entrega-pagamento'))
            : $this->responder($this->servico()->excluirArea($idArea), $this->url('entrega-pagamento') . '#areas');
    }

    public function local(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->responder($this->servico()->salvarLocal($_POST), $this->url('entrega-pagamento') . '#localizacao');
    }

    public function removerLocal(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->responder($this->servico()->removerLocal(), $this->url('entrega-pagamento') . '#localizacao');
    }

    /** "Testar um endereco": o gerente confere a regra como o cliente vai ver (sem gravar nada). */
    public function testar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $entrada = array_map(static fn ($v): string => is_string($v) ? $v : '', array_intersect_key($_GET, array_flip(['cep', 'logradouro', 'numero', 'bairro', 'cidade', 'estado'])));
        if (($entrada['cep'] ?? '') !== '' && (($entrada['bairro'] ?? '') === '' || ($entrada['logradouro'] ?? '') === '')) {
            $achado = (new Geocodificador())->cep($entrada['cep']);
            if ($achado !== null) {
                foreach (['logradouro', 'bairro', 'cidade', 'estado'] as $c) {
                    if (($entrada[$c] ?? '') === '') {
                        $entrada[$c] = (string) $achado[$c];
                    }
                }
            }
        }
        $norm = Endereco::normalizar($entrada, false);
        $teste = ['entrada' => $norm['dados']];
        if (($norm['dados']['bairro'] === '' && $norm['dados']['logradouro'] === '')) {
            $teste['resultado'] = null;
            $teste['aviso'] = 'Digite um CEP ou o bairro e a cidade para testar.';
        } else {
            $teste['resultado'] = (new VerificadorEntrega($this->db))->verificar($this->restaurante['id'], $norm['dados']);
        }
        return $this->pagina([], [], 200, $teste);
    }

    /**
     * @param array<string,array<string,string>> $erros por formulario
     * @param array<string,array<string,mixed>> $enviado
     * @param array<string,mixed>|null $teste
     */
    private function pagina(array $erros = [], array $enviado = [], int $status = 200, ?array $teste = null): Response
    {
        $repo = new EntregaPagamentoRepository($this->db);
        $config = $repo->config($this->restaurante['id']);
        $areas = $repo->areas($this->restaurante['id']);
        $local = $repo->local($this->restaurante['id']);
        $servico = $this->servico();
        return $this->tela('gerencia/entrega-pagamento', 'entrega-pagamento', 'Entrega e pagamento', [
            'config' => $config,
            'enviado' => $enviado,
            'bairros' => array_values(array_filter($areas, static fn (array $a): bool => $a['tipo'] === 'bairro')),
            'faixas' => array_values(array_filter($areas, static fn (array $a): bool => $a['tipo'] === 'raio')),
            'local' => $local,
            'precisaLocal' => AreaEntrega::precisaDeLocalizacao($areas),
            'asaasConectado' => $servico->asaasConectado(),
            'teste' => $teste,
            'erros' => $erros,
            'pageScripts' => ['js/forms.js', 'js/entrega-pagamento.js'],
            'pageStyles' => ['css/entrega-pagamento.css'],
        ], $status);
    }

    private function servico(): EntregaPagamentoService
    {
        return new EntregaPagamentoService($this->db, $this->usuario, $this->restaurante['id']);
    }
}
