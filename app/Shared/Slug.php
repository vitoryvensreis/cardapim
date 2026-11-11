<?php

declare(strict_types=1);

namespace Cardapim\Shared;

/**
 * Regras do endereco publico do restaurante (/{slug}).
 * O cadastro de restaurantes deve recusar slugs reservados para nao sombrear rotas do sistema.
 */
final class Slug
{
    public const RESERVADOS = [
        'admin', 'api', 'app', 'assets', 'cadastro', 'cardapim', 'catalogo', 'conta', 'cookies',
        'dashboard', 'entrar', 'exclusao-de-dados', 'login', 'logout', 'midia', 'minha-conta', 'painel', 'planos',
        'politica-de-privacidade', 'privacidade', 'pwa', 'restaurantes', 'sair', 'status', 'storage',
        'suporte', 'termos-de-uso', 'tratamento-de-dados', 'uploads', 'webhooks', 'ws', 'www',
    ];

    public static function formatoValido(string $slug): bool
    {
        return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1 && strlen($slug) <= 160;
    }

    public static function reservado(string $slug): bool
    {
        return in_array($slug, self::RESERVADOS, true);
    }

    /** Gera slug a partir do nome (ex.: "Cantina do Zé" -> "cantina-do-ze"). */
    public static function gerar(string $nome): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii === false ? $nome : $ascii));
        return trim(substr(trim($slug, '-'), 0, 160), '-');
    }
}
