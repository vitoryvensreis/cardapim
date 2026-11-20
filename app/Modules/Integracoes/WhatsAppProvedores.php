<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;

/**
 * Escolhe o servidor de WhatsApp de cada restaurante.
 *
 * - Sessao ja gravada: usa o servidor gravado em dados.provedor (sem o campo = WPPConnect,
 *   porque todas as sessoes anteriores a Evolution foram criadas no WPPConnect).
 * - Sessao nova: usa WHATSAPP_PROVEDOR_PADRAO (evolution | wppconnect). Se o escolhido nao estiver
 *   configurado no .env, usa o outro que estiver.
 * - Nomes novos: deliveryzim_r{id}_{8 hex} e deliveryzim_plataforma_{8 hex}. Os nomes antigos
 *   (cardapim_...) continuam aceitos ate a ultima loja reconectar.
 */
final class WhatsAppProvedores
{
    public const WPPCONNECT = 'wppconnect';
    public const EVOLUTION = 'evolution';

    /** Sessoes do Deliveryzim (novas e antigas). Grupo 2 = id do restaurante ou "plataforma". */
    public const PADRAO_SESSAO = '/\A(deliveryzim|cardapim)_(r[1-9][0-9]{0,17}|plataforma)_[a-f0-9]{8}\z/';

    public static function criar(string $id): ProvedorWhatsApp
    {
        return $id === self::EVOLUTION ? new EvolutionApi() : new WppConnectApi();
    }

    /** @param array<string,mixed> $dados integracoes.dados */
    public static function daIntegracao(array $dados): ProvedorWhatsApp
    {
        return self::criar(($dados['provedor'] ?? null) === self::EVOLUTION ? self::EVOLUTION : self::WPPCONNECT);
    }

    /** Servidor para uma conexao nova. */
    public static function padrao(): ProvedorWhatsApp
    {
        $preferido = Config::get('WHATSAPP_PROVEDOR_PADRAO') === self::WPPCONNECT ? self::WPPCONNECT : self::EVOLUTION;
        $outro = $preferido === self::EVOLUTION ? self::WPPCONNECT : self::EVOLUTION;
        $api = self::criar($preferido);
        return $api->configurado() ? $api : self::criar($outro);
    }

    /** @return list<ProvedorWhatsApp> servidores configurados no .env */
    public static function configurados(): array
    {
        return array_values(array_filter([new WppConnectApi(), new EvolutionApi()], static fn (ProvedorWhatsApp $p): bool => $p->configurado()));
    }

    public static function algumDisponivel(): bool
    {
        return self::configurados() !== [];
    }

    /** Nome para uma sessao nova. $idRestaurante null = WhatsApp da plataforma. */
    public static function novaSessao(?int $idRestaurante): string
    {
        return 'deliveryzim_' . ($idRestaurante === null ? 'plataforma' : 'r' . $idRestaurante) . '_' . bin2hex(random_bytes(4));
    }

    /** Sessao com nome do Deliveryzim (atual ou antigo)? */
    public static function doDeliveryzim(string $sessao): bool
    {
        return preg_match(self::PADRAO_SESSAO, $sessao) === 1;
    }

    /** Restaurante dono do nome da sessao (null = plataforma ou nome invalido). */
    public static function restauranteDaSessao(string $sessao): ?int
    {
        if (preg_match(self::PADRAO_SESSAO, $sessao, $m) !== 1 || $m[2] === 'plataforma') {
            return null;
        }
        return (int) substr($m[2], 1);
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
