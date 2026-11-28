<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;

/**
 * Servidores de WhatsApp do Deliveryzim (VPS de mensageria):
 * - WAHA, motor GOWS (docs/deploy/waha.md): padrao para conexoes novas quando WHATSAPP_PROVEDOR_PADRAO=waha.
 * - Evolution API (docs/deploy/evolution.md): conexoes antigas, ate cada loja reconectar.
 * Cada loja guarda o seu em integracoes.dados.provedor; sem o campo, e da Evolution (conexoes de antes do WAHA).
 * Os servicos dependem do contrato ProvedorWhatsApp, nao da classe concreta.
 *
 * Nomes das sessoes (instancias): deliveryzim_r{id}_{8 hex} (lojas) e deliveryzim_plataforma_{8 hex}.
 */
final class WhatsAppProvedores
{
    public const EVOLUTION = 'evolution';
    public const WAHA = 'waha';

    /** Sessoes do Deliveryzim. Grupo 1 = r{id} ou "plataforma". */
    public const PADRAO_SESSAO = '/\Adeliveryzim_(r[1-9][0-9]{0,17}|plataforma)_[a-f0-9]{8}\z/';

    public static function criar(string $id = self::EVOLUTION): ProvedorWhatsApp
    {
        return $id === self::WAHA ? new WahaApi() : new EvolutionApi();
    }

    /** @param array<string,mixed> $dados integracoes.dados (provedor gravado na conexao; sem ele, Evolution) */
    public static function daIntegracao(array $dados): ProvedorWhatsApp
    {
        return self::criar(($dados['provedor'] ?? null) === self::WAHA ? self::WAHA : self::EVOLUTION);
    }

    /** Servidor para uma conexao nova (WHATSAPP_PROVEDOR_PADRAO=waha ou evolution; sem valor, Evolution). */
    public static function padrao(): ProvedorWhatsApp
    {
        return self::criar(Config::get('WHATSAPP_PROVEDOR_PADRAO') === self::WAHA ? self::WAHA : self::EVOLUTION);
    }

    /** @return list<ProvedorWhatsApp> servidores configurados no .env */
    public static function configurados(): array
    {
        return array_values(array_filter([self::criar(self::EVOLUTION), self::criar(self::WAHA)], static fn (ProvedorWhatsApp $p): bool => $p->configurado()));
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
