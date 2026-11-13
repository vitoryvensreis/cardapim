<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Services\Seo\Seo;
use Throwable;

final class HomeController
{
    public function index(): Response
    {
        $planos = [];
        $planosIndisponiveis = false;
        try {
            $planos = (new PlanoRepository(Database::connect()))->listarPublicos();
        } catch (Throwable $exception) {
            // A landing continua no ar; somente a secao de planos mostra aviso.
            $planosIndisponiveis = true;
            error_log('[Cardapim] Planos indisponiveis na landing: ' . get_class($exception));
        }

        return Response::html(View::render('marketing/home', [
            // Titulo e descricao editaveis em Configuracoes > Google e redes sociais (painel do adm_master).
            'pageTitle' => Seo::tituloInicio(),
            'pageDescription' => Seo::descricaoInicio(),
            'seo' => ['jsonLd' => Seo::jsonLdInicio()],
            'planos' => $planos,
            'planosIndisponiveis' => $planosIndisponiveis,
        ]));
    }
}
