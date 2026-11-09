<?php

declare(strict_types=1);

namespace Cardapim\Core;

final class View
{
    public static function render(string $template, array $data = [], string $layout = 'layouts/marketing'): string
    {
        $base = dirname(__DIR__) . '/Views/';
        $page = $base . $template . '.php';
        $layoutFile = $base . $layout . '.php';
        if (!is_file($page) || !is_file($layoutFile)) {
            throw new \RuntimeException('Template nao encontrado.');
        }
        // Somente controllers escolhem templates; nunca entrada direta do usuario.
        extract($data, EXTR_SKIP);
        ob_start();
        require $page;
        $content = (string) ob_get_clean();
        ob_start();
        require $layoutFile;
        return (string) ob_get_clean();
    }
}
