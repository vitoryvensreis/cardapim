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
        while (openssl_error_string() !== false) { /* limpa a fila de erros do OpenSSL */ }
        if (!@openssl_pkcs12_read($bytes, $certs, $senha)) {
            $erros = '';
            while (($m = openssl_error_string()) !== false) { $erros .= $m . "\n"; }
            // OpenSSL 3 não abre PKCS#12 antigo (RC2/3DES) sem o provedor legacy: converte só para validar.
            if (!self::formatoLegado($erros) || ($convertido = self::converterLegado($bytes, $senha)) === null || !@openssl_pkcs12_read($convertido, $certs, $senha)) {
                throw new RuntimeException(self::formatoLegado($erros)
                    ? 'Este certificado usa um formato antigo que o servidor não consegue abrir. Exporte novamente o A1 (no Windows: "Exportar" com criptografia AES-256) ou peça à certificadora um arquivo .pfx atualizado.'
                    : 'Não foi possível abrir o certificado A1. Confira se a senha está correta e se o arquivo é o .pfx ou .p12 do certificado.');
            }
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

    public static function formatoLegado(string $erros): bool
    {
        return str_contains($erros, '0308010C') || stripos($erros, 'unsupported') !== false;
    }

    /**
     * Reexporta o PKCS#12 com algoritmos atuais usando o binário openssl com o provedor legacy.
     * Arquivos temporários 0600 apagados em seguida; a senha passa por variável de ambiente, nunca por argumento.
     */
    public static function converterLegado(#[\SensitiveParameter] string $bytes, #[\SensitiveParameter] string $senha): ?string
    {
        if (!function_exists('proc_open')) { return null; }
        $dir = sys_get_temp_dir();
        $entrada = tempnam($dir, 'a1in');
        $saida = tempnam($dir, 'a1out');
        $pem = tempnam($dir, 'a1pem');
        if ($entrada === false || $saida === false || $pem === false) { return null; }
        try {
            foreach ([$entrada, $saida, $pem] as $f) { @chmod($f, 0600); }
            file_put_contents($entrada, $bytes);
            $env = ['CARDAPIM_A1' => $senha, 'PATH' => '/usr/bin:/bin:/usr/local/bin'];
            $ok = self::executar(['openssl', 'pkcs12', '-legacy', '-in', $entrada, '-nodes', '-passin', 'env:CARDAPIM_A1', '-out', $pem], $env)
                && self::executar(['openssl', 'pkcs12', '-export', '-in', $pem, '-passout', 'env:CARDAPIM_A1', '-out', $saida], $env);
            $r = $ok ? file_get_contents($saida) : false;
            return is_string($r) && $r !== '' ? $r : null;
        } finally {
            foreach ([$entrada, $saida, $pem] as $f) { if (is_file($f)) { @file_put_contents($f, str_repeat("\0", (int) @filesize($f))); @unlink($f); } }
        }
    }

    /** @param list<string> $cmd */
    private static function executar(array $cmd, array $env): bool
    {
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($p)) { return false; }
        fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return proc_close($p) === 0;
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
