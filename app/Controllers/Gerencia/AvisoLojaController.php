<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Plataforma\AvisosLojaService;

/** Clique no botao do aviso da plataforma (o pop-up so registra; a navegacao e feita pelo proprio link). */
final class AvisoLojaController extends BaseGerencia
{
    public function clique(string $slug, string $id): Response
    {
        if ($this->entrar($slug) !== null || !Session::csrfValido($_POST['_csrf'] ?? null)) {
            return Response::json(['ok' => false], 401);
        }
        if (ctype_digit($id) && $this->usuario->cargo === 'gerente') {
            (new AvisosLojaService($this->db))->clicou((int) $id, $this->usuario, (int) $this->restaurante['id']);
        }
        return Guard::semCache(Response::json(['ok' => true]));
    }
}
