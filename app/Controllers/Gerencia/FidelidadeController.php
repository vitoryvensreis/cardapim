<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Services\Gerencia\FidelidadeService;

/** Aba Clientes > Fidelidade: liga/desliga e configura o programa do restaurante. */
final class FidelidadeController extends BaseGerencia
{
    public function mostrar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->pagina($this->servico()->valoresForm(), []);
    }

    public function salvar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = $this->servico()->salvar($_POST);
        if ($res->ok) {
            return $this->voltar($this->url('fidelidade'), 'sucesso', $res->mensagem);
        }
        $enviado = [];
        foreach (['ativo', 'compras_necessarias', 'tipo', 'valor', 'quais', 'valor_minimo'] as $campo) {
            $enviado[$campo] = mb_substr($this->post($campo), 0, 20);
        }
        return $this->pagina($enviado, $res->erros, 422);
    }

    /** @param array<string,string> $valores @param array<string,string> $erros */
    private function pagina(array $valores, array $erros, int $status = 200): Response
    {
        $s = $this->servico();
        return $this->tela('gerencia/fidelidade', 'fidelidade', 'Fidelidade', [
            'valores' => $valores,
            'erros' => $erros,
            'config' => $s->config(),
            'numeros' => $s->numeros(),
            'pageScripts' => ['js/forms.js'],
            'pageStyles' => ['css/clientes.css'],
        ], $status);
    }

    private function servico(): FidelidadeService
    {
        return new FidelidadeService($this->db, $this->restaurante['id'], $this->usuario);
    }
}
