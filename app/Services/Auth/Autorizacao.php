<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

/**
 * Regras de acesso por cargo (RBAC). Fonte unica: alterar aqui muda login e rotas.
 * Sempre validado no servidor; esconder botoes na tela nao e protecao.
 */
final class Autorizacao
{
    /** Areas de um restaurante (/{slug}/area) e os cargos que podem entrar. */
    public const AREAS_RESTAURANTE = [
        'gerencia' => ['gerente'],
        'caixa' => ['caixa', 'gerente'],
        'entregas' => ['motoqueiro', 'gerente'],
    ];

    /** Cargo com acesso global (administracao da plataforma). */
    public const CARGO_MASTER = 'adm_master';

    /** Restaurantes com estes status podem ser acessados pela equipe. */
    public const STATUS_COM_ACESSO = ['ativo', 'pendente'];

    /** Para onde cada cargo vai depois do login. */
    public static function destino(UsuarioLogado $usuario): string
    {
        return match ($usuario->cargo) {
            self::CARGO_MASTER => '/painel',
            'gerente' => $usuario->urlRestaurante('gerencia'),
            'caixa' => $usuario->urlRestaurante('caixa'),
            'motoqueiro' => $usuario->urlRestaurante('entregas'),
            default => '/minha-conta', // cliente e garcom: areas ainda nao criadas
        };
    }

    public static function podeAcessarPainel(UsuarioLogado $usuario): bool
    {
        return $usuario->cargo === self::CARGO_MASTER;
    }

    /**
     * Area de restaurante: cargo permitido E vinculo com o mesmo restaurante.
     * O adm_master acessa qualquer restaurante para suporte.
     */
    public static function podeAcessarRestaurante(UsuarioLogado $usuario, string $area, int $idRestaurante): bool
    {
        if (!isset(self::AREAS_RESTAURANTE[$area])) {
            return false;
        }
        if ($usuario->cargo === self::CARGO_MASTER) {
            return true;
        }
        return in_array($usuario->cargo, self::AREAS_RESTAURANTE[$area], true)
            && $usuario->idRestaurante === $idRestaurante
            && in_array($usuario->restauranteStatus, self::STATUS_COM_ACESSO, true);
    }

    /** Equipe de restaurante suspenso/inativo nao entra (o master sempre entra). */
    public static function contaLiberada(UsuarioLogado $usuario): bool
    {
        if (!$usuario->ativo) {
            return false;
        }
        if ($usuario->cargo === self::CARGO_MASTER || $usuario->cargo === 'cliente') {
            return true;
        }
        return in_array($usuario->restauranteStatus, self::STATUS_COM_ACESSO, true);
    }

    /** Caminho de retorno salvo antes do login: apenas caminhos locais. */
    public static function caminhoLocalSeguro(mixed $caminho): ?string
    {
        if (!is_string($caminho) || $caminho === '' || strlen($caminho) > 300
            || !str_starts_with($caminho, '/') || str_starts_with($caminho, '//')
            || preg_match('~[\x00-\x1f\\\\]~', $caminho) === 1) {
            return null;
        }
        return $caminho;
    }

    public static function rotuloCargo(string $cargo): string
    {
        return match ($cargo) {
            'adm_master' => 'Administrador da plataforma',
            'gerente' => 'Gerente',
            'caixa' => 'Caixa',
            'motoqueiro' => 'Entregador',
            'garcom' => 'Garçom',
            'cliente' => 'Cliente',
            default => 'Usuário',
        };
    }
}
