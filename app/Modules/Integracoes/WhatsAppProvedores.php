<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;

/**
 * Servidor de WhatsApp do Deliveryzim: WAHA, motor GOWS (docs/deploy/waha.md), na VPS de mensageria.
 * A Evolution API foi removida: conexao gravada sem dados.provedor = "waha" e de antes da troca (legado) e precisa
 * ser conectada de novo (a tela e o vigia avisam a loja).
 * Os servicos dependem do contrato ProvedorWhatsApp (testes injetam um falso), nao da classe concreta.
 *
 * Nomes das sessoes (instancias): deliveryzim_r{id}_{8 hex} (lojas) e deliveryzim_plataforma_{8 hex}.
 */
final class WhatsAppProvedores
{
    public const WAHA = 'waha';

    /** Sessoes do Deliveryzim. Grupo 1 = r{id} ou "plataforma". */
    public const PADRAO_SESSAO = '/\Adeliveryzim_(r[1-9][0-9]{0,17}|plataforma)_[a-f0-9]{8}\z/';

    public static function criar(): ProvedorWhatsApp
    {
        return new WahaApi();
    }

    /** @param array<string,mixed> $dados integracoes.dados */
    public static function daIntegracao(array $dados): ProvedorWhatsApp
    {
        return self::criar();
    }

    /** Servidor para uma conexao nova. */
    public static function padrao(): ProvedorWhatsApp
    {
        return self::criar();
    }

    /** Conexao feita na Evolution (antes da troca para o WAHA): a sessao nao existe mais, precisa conectar de novo. */
    public static function legado(array $dados): bool
    {
        return is_string($dados['sessao'] ?? null) && ($dados['provedor'] ?? null) !== self::WAHA;
    }

    /** @return list<ProvedorWhatsApp> servidores configurados no .env */
    public static function configurados(): array
    {
        $api = self::criar();
        return $api->configurado() ? [$api] : [];
    }

    public static function algumDisponivel(): bool
    {
        return self::configurados() !== [];
    }

    /** Algum servidor configurado avisa o Deliveryzim quando chega mensagem (boas-vindas)? */
    public static function recebimentoConfigurado(): bool
    {
        foreach (self::configurados() as $api) {
            if ($api instanceof WebhookDeMensagens && $api->webhookConfigurado()) {
                return true;
            }
        }
        return false;
    }

    /** Nome para uma sessao nova. $idRestaurante null = WhatsApp da plataforma. */
    public static function novaSessao(?int $idRestaurante): string
    {
        return 'deliveryzim_' . ($idRestaurante === null ? 'plataforma' : 'r' . $idRestaurante) . '_' . bin2hex(random_bytes(4));
    }

    /** Sessao com nome do Deliveryzim? */
    public static function doDeliveryzim(string $sessao): bool
    {
        return preg_match(self::PADRAO_SESSAO, $sessao) === 1;
    }

    /** Restaurante dono do nome da sessao (null = plataforma ou nome invalido). */
    public static function restauranteDaSessao(string $sessao): ?int
    {
        if (preg_match(self::PADRAO_SESSAO, $sessao, $m) !== 1 || $m[1] === 'plataforma') {
            return null;
        }
        return (int) substr($m[1], 1);
    }

    /** Valida o nome antes de usa-lo em uma URL do servidor. */
    public static function sessao(string $sessao): string
    {
        if (preg_match('/\A[a-zA-Z0-9_-]{3,60}\z/', $sessao) !== 1) {
            throw new IntegracaoErro('Sessão do WhatsApp inválida.');
        }
        return $sessao;
    }
}
