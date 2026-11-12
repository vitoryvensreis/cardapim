<?php

declare(strict_types=1);

namespace Cardapim\Services\Email;

use RuntimeException;

/**
 * Cliente SMTP minimo (sem dependencias) para enviar e-mail por um servidor de e-mail (ex.: mailcow):
 * STARTTLS (porta 587) ou SSL direto (465), autenticacao LOGIN, texto UTF-8 em base64.
 * O servidor de e-mail assina com DKIM. Nunca registra a senha.
 */
final class SmtpCliente
{
    /** @var resource|null */
    private $conexao = null;

    public function __construct(
        private readonly string $host,
        private readonly int $porta = 587,
        private readonly string $seguranca = 'tls',
        private readonly string $usuario = '',
        private readonly string $senha = '',
        private readonly int $tempoLimite = 10,
    ) {
    }

    /** @throws RuntimeException com mensagem sem segredos */
    public function enviar(string $de, string $nomeDe, string $para, string $assunto, string $texto, ?string $responderPara = null): void
    {
        try {
            $this->conectar();
            $this->esperar(220);
            $this->ehlo();
            if ($this->seguranca === 'tls') {
                $this->comando('STARTTLS', 220);
                if (!@stream_socket_enable_crypto($this->conexao, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('Não foi possível iniciar a conexão segura (TLS) com o servidor de e-mail.');
                }
                $this->ehlo();
            }
            if ($this->usuario !== '') {
                $this->comando('AUTH LOGIN', 334);
                $this->comando(base64_encode($this->usuario), 334);
                $this->comando(base64_encode($this->senha), 235, 'O servidor de e-mail recusou o usuário ou a senha.');
            }
            $this->comando('MAIL FROM:<' . self::endereco($de) . '>', 250);
            $this->comando('RCPT TO:<' . self::endereco($para) . '>', [250, 251], 'O servidor de e-mail recusou o destinatário.');
            $this->comando('DATA', 354);
            $this->escrever(self::mensagem($de, $nomeDe, $para, $assunto, $texto, $responderPara) . "\r\n.");
            $this->esperar(250, 'O servidor de e-mail não aceitou a mensagem.');
            $this->comando('QUIT', 221);
        } finally {
            if (is_resource($this->conexao)) {
                fclose($this->conexao);
            }
            $this->conexao = null;
        }
    }

    /** Mensagem completa (cabecalhos + corpo base64). Publica para os testes. */
    public static function mensagem(string $de, string $nomeDe, string $para, string $assunto, string $texto, ?string $responderPara = null): string
    {
        $dominio = substr((string) strrchr($de, '@'), 1) ?: 'localhost';
        $cab = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . mb_encode_mimeheader($nomeDe, 'UTF-8', 'B') . ' <' . self::endereco($de) . '>',
            'To: <' . self::endereco($para) . '>',
            'Subject: ' . mb_encode_mimeheader($assunto, 'UTF-8', 'B'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($responderPara !== null && filter_var($responderPara, FILTER_VALIDATE_EMAIL) !== false) {
            $cab[] = 'Reply-To: <' . self::endereco($responderPara) . '>';
        }
        $corpo = rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $texto)), 76, "\r\n"));
        return implode("\r\n", $cab) . "\r\n\r\n" . $corpo;
    }

    private function conectar(): void
    {
        $esquema = $this->seguranca === 'ssl' ? 'ssl://' : 'tcp://';
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'SNI_enabled' => true]]);
        $conexao = @stream_socket_client($esquema . $this->host . ':' . $this->porta, $codigo, $erro, $this->tempoLimite, STREAM_CLIENT_CONNECT, $contexto);
        if ($conexao === false) {
            throw new RuntimeException('Não foi possível conectar ao servidor de e-mail (' . $this->host . ':' . $this->porta . ').');
        }
        stream_set_timeout($conexao, $this->tempoLimite);
        $this->conexao = $conexao;
    }

    private function ehlo(): void
    {
        $nome = (string) (gethostname() ?: 'localhost');
        $this->comando('EHLO ' . (preg_match('/\A[A-Za-z0-9.-]+\z/', $nome) === 1 ? $nome : 'localhost'), 250);
    }

    /** @param int|list<int> $esperado */
    private function comando(string $linha, int|array $esperado, ?string $erro = null): void
    {
        $this->escrever($linha);
        $this->esperar($esperado, $erro);
    }

    private function escrever(string $linha): void
    {
        if (@fwrite($this->conexao, $linha . "\r\n") === false) {
            throw new RuntimeException('A conexão com o servidor de e-mail caiu.');
        }
    }

    /** @param int|list<int> $esperado */
    private function esperar(int|array $esperado, ?string $erro = null): void
    {
        $resposta = '';
        while (($linha = fgets($this->conexao, 1024)) !== false) {
            $resposta .= $linha;
            if (strlen($linha) < 4 || $linha[3] !== '-') {
                break;
            }
        }
        $codigo = (int) substr($resposta, 0, 3);
        if (!in_array($codigo, (array) $esperado, true)) {
            $detalhe = trim((string) preg_replace('/\s+/', ' ', substr($resposta, 0, 160)));
            throw new RuntimeException(($erro ?? 'O servidor de e-mail respondeu um erro.') . ($detalhe !== '' ? ' (' . $detalhe . ')' : ' (sem resposta)'));
        }
    }

    private static function endereco(string $email): string
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n<>]/', $email) === 1) {
            throw new RuntimeException('Endereço de e-mail inválido.');
        }
        return $email;
    }
}
