<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

/**
 * Recursos extras do servidor de WhatsApp para manter a loja conectada sem ninguem ir ate o celular
 * (hoje so o WAHA). Quem nao implementa fica sem o vigia automatico e sem o codigo de conexao.
 */
interface RecuperacaoDeSessao
{
    /** Reinicia a sessao no servidor. O aparelho continua vinculado (nao precisa de QR Code). */
    public function reiniciar(string $sessao, string $token): void;

    /**
     * Codigo de 8 caracteres (ex.: ABCD-EFGH) para conectar digitando no celular, no lugar do QR Code.
     * So funciona enquanto a sessao esta aguardando conexao.
     * @param string $telefone digitos com DDI (5585...) do celular que vai conectar
     */
    public function pedirCodigo(string $sessao, string $token, string $telefone): string;
}
