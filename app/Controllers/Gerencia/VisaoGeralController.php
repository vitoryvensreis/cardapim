<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Services\Gerencia\VisaoGeralService;

/** Primeira tela do gerente: o dia em numeros, o que acontece agora, alertas e vendas recentes. */
final class VisaoGeralController extends BaseGerencia
{
    public function mostrar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->tela('gerencia/visao-geral/index', 'visao-geral', 'Visão geral', [
            'vg' => (new VisaoGeralService($this->db, $this->restaurante['id'], $this->usuario))->painel(),
            'pageStyles' => ['css/visao-geral.css'],
            'pageScripts' => ['js/visao-geral.js'],
        ]);
    }
}
