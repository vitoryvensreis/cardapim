<?php

declare(strict_types=1);

namespace Cardapim\Services;

use Cardapim\Core\Database;
use Cardapim\Repositories\LogUserRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Throwable;

/**
 * Registro de auditoria em log_user para cadastros, edicoes e exclusoes.
 * Nunca registrar senhas, tokens, codigos ou documentos completos.
 */
final class Auditoria
{
    /** Restaurante em que a acao acontece (o adm_master nao tem restaurante proprio). */
    private static ?string $restaurante = null;

    public static function noRestaurante(?string $nome): void
    {
        self::$restaurante = $nome;
    }

    public static function registrar(UsuarioLogado $autor, string $titulo, string $descricao): void
    {
        try {
            $nome = self::$restaurante ?? $autor->restauranteNome;
            $contexto = $nome !== null ? ' [' . $nome . ']' : '';
            (new LogUserRepository(Database::connect()))->registrar(
                $titulo,
                $descricao . $contexto,
                $autor->id,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            );
        } catch (Throwable $exception) {
            error_log('[Cardapim] Falha ao registrar auditoria: ' . get_class($exception));
        }
    }

    /**
     * Texto com o que mudou: "Preço: 24,90 -> 26,90; Estoque: 5 -> 4,5".
     * @param array<string,string> $rotulos campo => rotulo legivel (so esses campos sao comparados)
     */
    public static function diferencas(array $antes, array $depois, array $rotulos): string
    {
        $partes = [];
        foreach ($rotulos as $campo => $rotulo) {
            $de = self::texto($antes[$campo] ?? null);
            $para = self::texto($depois[$campo] ?? null);
            if ($de !== $para) {
                $partes[] = sprintf('%s: %s -> %s', $rotulo, $de, $para);
            }
        }
        return $partes === [] ? 'Nenhuma alteração.' : implode('; ', $partes) . '.';
    }

    private static function texto(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '(vazio)';
        }
        if (is_bool($valor)) {
            return $valor ? 'sim' : 'não';
        }
        $texto = (string) $valor;
        // Decimais vindos do banco: "24.900" -> "24,9"
        if (preg_match('/\A-?\d+\.\d+\z/', $texto) === 1) {
            $texto = str_replace('.', ',', rtrim(rtrim($texto, '0'), '.'));
        }
        return mb_strimwidth($texto, 0, 80, '...');
    }
}
