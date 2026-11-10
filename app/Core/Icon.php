<?php

declare(strict_types=1);

namespace Cardapim\Core;

use InvalidArgumentException;

/**
 * Icones Lucide servidos por sprite local (public/assets/icons/lucide.svg).
 * Novos icones: adicionar em dev/icons/icons.txt e gerar o sprite novamente.
 */
final class Icon
{
    private const SPRITE = 'icons/lucide.svg';

    /**
     * @param string      $name  Nome do icone Lucide (ex.: chef-hat).
     * @param string      $class Classes CSS extras.
     * @param string|null $label Texto acessivel quando o icone carrega significado sozinho.
     */
    public static function svg(string $name, string $class = '', ?string $label = null): string
    {
        if (preg_match('/\A[a-z0-9-]+\z/', $name) !== 1) {
            throw new InvalidArgumentException('Nome de icone invalido.');
        }
        if ($class !== '' && preg_match('/\A[a-zA-Z0-9_ -]+\z/', $class) !== 1) {
            throw new InvalidArgumentException('Classe de icone invalida.');
        }

        $classes = trim('icon ' . $class);
        $a11y = $label === null
            ? 'aria-hidden="true" focusable="false"'
            : 'role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';

        return sprintf(
            '<svg class="%s" %s><use href="%s#%s"></use></svg>',
            $classes,
            $a11y,
            AssetUrl::asset(self::SPRITE),
            $name,
        );
    }
}
