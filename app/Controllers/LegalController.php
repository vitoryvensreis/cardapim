<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Shared\Slug;

final class LegalController
{
    private const PAGINAS = ['/privacidade', '/termos-de-uso', '/cookies', '/exclusao-de-dados', '/tratamento-de-dados'];

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
        $cardapio = $this->cardapioDeOrigem();
        $html = View::render('legal/' . $template, [
            'pageTitle' => $title . ' | ' . marca(),
            'pageDescription' => $title . ' e informações sobre o uso do ' . marca() . '.',
            'activeLegalPage' => $template,
            'voltarCardapio' => $cardapio,
            'noIndex' => $cardapio !== null,
        ], 'layouts/public');
        if ($cardapio !== null) {
            // Links entre as politicas continuam presos ao cardapio de origem.
            $sufixo = '?r=' . rawurlencode(ltrim($cardapio[0], '/'));
            $trocas = [];
            foreach (self::PAGINAS as $caminho) {
                $trocas['href="' . $caminho . '"'] = 'href="' . $caminho . $sufixo . '"';
            }
            $html = strtr($html, $trocas);
        }
        return Response::html($html);
    }

    /**
     * Aberta pelo cardapio (?r=slug): o cliente so ve "Voltar ao cardapio", sem caminho para outros restaurantes.
     * Slug invalido, restaurante inativo ou banco fora do ar: pagina normal.
     *
     * @return array{0: string, 1: string}|null
     */
    private function cardapioDeOrigem(): ?array
    {
        $slug = $_GET['r'] ?? null;
        if (!is_string($slug) || !Slug::formatoValido($slug)) {
            return null;
        }
        try {
            $restaurante = (new RestauranteRepository(Database::connect()))->buscarPublicoPorSlug($slug);
        } catch (\Throwable) {
            return null;
        }
        return $restaurante === null ? null : ['/' . $restaurante['slug'], (string) $restaurante['nome']];
    }
}
