<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

/**
 * Contrato do servidor de WhatsApp do Deliveryzim (WAHA ou Evolution API).
 * Os servicos (conexao, avisos, saudacao, codigos da plataforma, limpeza) so conhecem este contrato.
 */
interface ProvedorWhatsApp
{
    /** Identificador gravado em integracoes.dados.provedor (waha ou evolution). */
    public function id(): string;

    /** O servidor esta configurado no .env? */
    public function configurado(): bool;

    /** Cria a sessao no servidor e devolve o token que deve ser guardado (cifrado) no banco. */
    public function criarSessao(string $sessao): string;

    /** Pede ao servidor para iniciar a conexao (gerar o QR Code). */
    public function iniciar(string $sessao, string $token): void;

    /**
     * Situacao no formato unico usado pelos servicos.
     * @return array{status:string, qrcode:?string} status: CONNECTED, QRCODE, INITIALIZING, PASSKEY
     *         (o WhatsApp pediu confirmacao no celular) ou CLOSED
     */
    public function estado(string $sessao, string $token): array;

    /** Numero do aparelho conectado (so digitos, com DDI), se o servidor informar. */
    public function numero(string $sessao, string $token): ?string;

    /** @param string $telefone digitos com DDI (5585...) ou identificador de conversa terminado em @lid */
    public function enviarTexto(string $sessao, string $token, string $telefone, string $mensagem): void;

    /** Desconecta o aparelho e encerra a sessao. true = o servidor confirmou. */
    public function sair(string $sessao, string $token): bool;

    /** @return list<string> nomes de todas as sessoes que o servidor conhece */
    public function sessoesNoServidor(): array;

    /** Encerra uma sessao abandonada (sem token salvo no banco), usando a chave do servidor. */
    public function encerrar(string $sessao): bool;
}
