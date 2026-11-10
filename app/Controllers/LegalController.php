<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Response;
use Cardapim\Core\View;

final class LegalController
{
    public function privacidade(): Response
    {
        return $this->page('privacidade', 'Política de Privacidade');
    }

    public function termos(): Response
    {
        return $this->page('termos', 'Termos de Uso');
    }

    public function cookies(): Response
    {
        return $this->page('cookies', 'Política de Cookies');
    }

    public function exclusao(): Response
    {
        return $this->page('exclusao', 'Exclusão de Dados');
    }

    public function tratamento(): Response
    {
        return $this->page('tratamento', 'Tratamento de Dados');
    }

    private function page(string $template, string $title): Response
    {
        return Response::html(View::render('legal/' . $template, [
            'pageTitle' => $title . ' | Cardapim',
            'pageDescription' => $title . ' e informações sobre o uso do Cardapim.',
            'activeLegalPage' => $template,
        ], 'layouts/public'));
    }
}
