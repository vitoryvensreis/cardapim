<?php

declare(strict_types=1);

namespace Cardapim\Services\Cliente;

/** Um meio de entregar o codigo de login ao cliente final (WhatsApp da plataforma, SMS). */
interface CanalCodigo
{
    /** whatsapp | sms */
    public function canal(): string;

    public function disponivel(): bool;

    /**
     * @param string $telefone DDD + numero (10 ou 11 digitos)
     * @throws \Cardapim\Modules\Integracoes\IntegracaoErro quando o envio falha
     */
    public function enviarCodigo(string $telefone, string $codigo): void;
}
