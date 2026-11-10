<?php

declare(strict_types=1);

namespace Cardapim\Core;

final class View
{
    public static function render(string $template, array $data = [], string $layout = 'layouts/marketing'): string
    {
        $__pasta = dirname(__DIR__) . '/Views/';
        $__pagina = $__pasta . $template . '.php';
        $__layout = $__pasta . $layout . '.php';
        if (!is_file($__pagina) || !is_file($__layout)) {
            throw new \RuntimeException('Template nao encontrado.');
        }
        unset($__pasta, $template, $layout);
        // Somente controllers escolhem templates; nunca entrada direta do usuario.
        // Variaveis internas com prefixo "__" para nao esconder dados com o mesmo nome (ex.: $base).
        extract($data, EXTR_SKIP);
        ob_start();
        require $__pagina;
        $content = (string) ob_get_clean();
        ob_start();
        require $__layout;
        return (string) ob_get_clean();
    }
}
