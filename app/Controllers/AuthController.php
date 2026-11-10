<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Repositories\PlanoRepository;

final class AuthController
{
    public function login(): Response
    {
        return Response::html(View::render('auth/login', [
            'pageTitle' => 'Entrar | Cardapim',
            'pageDescription' => 'Acesse sua conta Cardapim quando a autenticação estiver disponível.',
            'noIndex' => true,
            'pageScripts' => ['js/forms.js'],
        ], 'layouts/public'));
    }

    public function register(): Response
    {
        $id = filter_var($_GET['plano_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $plan = null;
        if ($id !== false && $id !== null) {
            $plan = (new PlanoRepository(Database::connect()))->buscarPublicoPorId($id);
        }

        return Response::html(View::render('auth/register', [
            'pageTitle' => 'Criar conta | Cardapim',
            'pageDescription' => 'Prepare seu cadastro no Cardapim.',
            'noIndex' => true,
            'pageScripts' => ['js/forms.js'],
            'plano' => $plan,
            'planoInvalido' => $id !== null && $id !== false && $plan === null
                || isset($_GET['plano_id']) && ($id === false || $id === null),
        ], 'layouts/public'));
    }
}
