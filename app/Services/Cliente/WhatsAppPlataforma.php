<?php

declare(strict_types=1);

namespace Cardapim\Services\Cliente;

use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use PDO;

/**
 * Envio de mensagens pelo WhatsApp DA PLATAFORMA (conectado pelo adm_master em /painel/integracoes/whatsapp).
 * Usado para os codigos de verificacao do cliente final. Nunca usa o WhatsApp de um restaurante.
 */
final class WhatsAppPlataforma
{
    public function __construct(private readonly PDO $db, private readonly WppConnectApi $api = new WppConnectApi())
    {
    }

    public function disponivel(): bool
    {
        if (!WppConnectApi::disponivel()) {
            return false;
        }
        $integracao = (new IntegracaoRepository($this->db))->buscar(null, 'whatsapp');
        return $integracao['status'] === 'conectado' && is_string($integracao['dados']['sessao'] ?? null);
    }

    /** @param string $whatsapp DDD + numero (10 ou 11 digitos) */
    public function enviar(string $whatsapp, string $mensagem): void
    {
        $repo = new IntegracaoRepository($this->db);
        $integracao = $repo->buscar(null, 'whatsapp');
        $sessao = $integracao['dados']['sessao'] ?? null;
        $token = $repo->credenciais(null, 'whatsapp')['token'] ?? null;
        if (!WppConnectApi::disponivel() || $integracao['status'] !== 'conectado' || !is_string($sessao) || !is_string($token) || $token === '') {
            throw new IntegracaoErro('O envio de códigos pelo WhatsApp está indisponível no momento. Tente de novo em alguns minutos.');
        }
        $this->api->enviarTexto($sessao, $token, '55' . $whatsapp, $mensagem);
    }
}
