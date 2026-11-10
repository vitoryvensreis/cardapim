<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia\Integracoes;

use Cardapim\Core\Config;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use PDO;

/** Base dos servicos de integracao: repositorio, autor das acoes e log de auditoria. */
abstract class BaseIntegracao
{
    protected readonly IntegracaoRepository $integracoes;

    public function __construct(
        protected readonly PDO $db,
        protected readonly UsuarioLogado $autor,
        protected readonly int $idRestaurante,
    ) {
        $this->integracoes = new IntegracaoRepository($db);
    }

    protected function log(string $titulo, string $descricao): void
    {
        Auditoria::registrar($this->autor, $titulo, $descricao);
    }

    /** Outras telas abertas do restaurante ficam sabendo da mudanca (tempo real). Nunca falha a acao. */
    protected function avisarTela(string $canal, string $tipo, array $dados = []): void
    {
        \Cardapim\Realtime\TempoReal::publicar($this->idRestaurante, $canal, $tipo, [...$dados, 'autor' => $this->autor->id]);
    }

    protected static function agora(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Endereco publico do Cardapim (APP_URL), usado nos webhooks. */
    public static function urlPublica(string $caminho): string
    {
        $base = rtrim(Config::get('APP_URL'), '/');
        if ($base === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = (preg_match('/\A[a-z0-9.:-]+\z/i', $host) === 1 ? 'https://' . $host : 'https://localhost');
        }
        return $base . '/' . ltrim($caminho, '/');
    }

    /** "$aact_prod_000...abcd" -> "...abcd" (para mostrar qual chave esta salva sem revela-la). */
    protected static function finalDaChave(string $chave): string
    {
        return '...' . substr($chave, -4);
    }
}
