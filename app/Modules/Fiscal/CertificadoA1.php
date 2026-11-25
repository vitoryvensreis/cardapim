<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use RuntimeException;

/** Validação local do PKCS#12 e identidade declarada. A cadeia ICP e a autorização
 * são verificadas em homologação; a aprovação administrativa não depende do CN. */
final class CertificadoA1
{
    public static function validar(#[\SensitiveParameter] string $bytes, #[\SensitiveParameter] string $senha, string $cnpj): array
    {
        if ($bytes === '' || strlen($bytes) > 1048576 || strlen($senha) > 512 || !function_exists('openssl_pkcs12_read')) {
            throw new RuntimeException('Certificado inválido ou maior que 1 MB.');
        }
        $certs = [];
        if (!@openssl_pkcs12_read($bytes, $certs, $senha)) {
            throw new RuntimeException('Não foi possível abrir o certificado A1. Confira o arquivo e a senha.');
        }
        $cert = $certs['cert'] ?? '';
        $chave = $certs['pkey'] ?? '';
        if (!is_string($cert) || !is_string($chave) || !@openssl_x509_check_private_key($cert, $chave)) {
            throw new RuntimeException('O arquivo não contém certificado e chave privada correspondentes.');
        }
        $p = @openssl_x509_parse($cert);
        if (!is_array($p) || ($p['validFrom_time_t'] ?? PHP_INT_MAX) > time() || ($p['validTo_time_t'] ?? 0) <= time()) {
            throw new RuntimeException('Certificado vencido ou ainda não válido.');
        }
        $cn = $p['subject']['CN'] ?? '';
        if (!is_string($cn) || preg_match('/(?:^|:)\s*([0-9]{14})\s*\z/', $cn, $m) !== 1 || $m[1] !== $cnpj) {
            throw new RuntimeException('O CNPJ declarado no certificado não corresponde ao cadastro fiscal. Solicite suporte se o formato do titular for diferente.');
        }
        return ['titular' => mb_substr($cn, 0, 200), 'validade' => gmdate('Y-m-d', (int) $p['validTo_time_t']), 'expira_em' => (int) $p['validTo_time_t']];
    }

    public static function alerta(?int $expira): string
    {
        if ($expira === null) {
            return 'Aguardando certificado';
        }
        $dias = (int) ceil(($expira - time()) / 86400);
        return $expira <= time() ? 'Certificado vencido' : ($dias <= 7 ? 'Vence em até 7 dias' : ($dias <= 15 ? 'Vence em até 15 dias' : ($dias <= 30 ? 'Vence em até 30 dias' : 'Dentro da validade local')));
    }
}
