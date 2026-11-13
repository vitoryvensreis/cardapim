<?php

declare(strict_types=1);

namespace Cardapim\Shared;

/**
 * Escolha de cookies do visitante (LGPD), gravada pelo navegador no cookie "cardapim_consentimento"
 * (valor "todos.AAAAMMDD" ou "necessarios.AAAAMMDD", validade de 12 meses; ver public/assets/js/consentimento.js).
 * Sem escolha valida, nada opcional e carregado: as fontes do Google so entram com "todos".
 */
final class Consentimento
{
    public const COOKIE = 'cardapim_consentimento';

    /** @return 'todos'|'necessarios'|null */
    public static function escolha(): ?string
    {
        $valor = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($valor) || preg_match('/\A(todos|necessarios)\.\d{8}\z/', $valor, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    public static function aceitouTodos(): bool
    {
        return self::escolha() === 'todos';
    }
}
