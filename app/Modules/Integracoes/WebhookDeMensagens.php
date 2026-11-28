<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

/**
 * Servidor de WhatsApp que avisa o Deliveryzim quando chega mensagem (boas-vindas).
 * Usado pela tela de Integracoes > WhatsApp ("Recebendo mensagens" e "Corrigir recebimento").
 */
interface WebhookDeMensagens
{
    /** O endereco do webhook esta configurado no .env? */
    public function webhookConfigurado(): bool;

    /** @return string ok | errado | desconhecido */
    public function situacaoWebhook(string $sessao, string $token): string;

    /** Aponta o recebimento de mensagens da sessao para o Deliveryzim. @throws IntegracaoErro */
    public function configurarWebhook(string $sessao, string $token): void;
}
