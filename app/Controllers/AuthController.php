<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Response;
use Cardapim\Core\View;

final class AuthController
{
    public function login(): Response
    {
        return Response::html(View::render('auth/coming-soon', [
            'pageTitle' => 'Login | Cardapim',
            'pageDescription' => 'Area de acesso do Cardapim em preparacao.',
            'flowTitle' => 'Seu painel esta chegando.',
            'flowDescription' => 'Estamos preparando a area de login segura para os restaurantes. Esta rota ja esta pronta, mas a autenticacao sera desenvolvida na proxima etapa.',
        ]));
    }

    public function register(): Response
    {
        return Response::html(View::render('auth/coming-soon', [
            'pageTitle' => 'Comecar | Cardapim',
            'pageDescription' => 'Cadastro de estabelecimentos Cardapim em preparacao.',
            'flowTitle' => 'O proximo capitulo comeca aqui.',
            'flowDescription' => 'O cadastro e os planos ainda estao em desenvolvimento. Em breve voce podera criar seu restaurante no Cardapim.',
        ]));
    }
}
