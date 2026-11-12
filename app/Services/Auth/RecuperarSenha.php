<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

use Cardapim\Core\Config;
use Cardapim\Core\RateLimiter;
use Cardapim\Repositories\LogUserRepository;
use Cardapim\Services\Email\Mailer;
use Cardapim\Services\Gerencia\EquipeService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Seguranca\Cripto;
use PDO;
use Throwable;

/**
 * "Esqueci minha senha" da equipe e da administracao (contas da tabela user com e-mail de verdade).
 *
 * Link assinado, sem tabela: {id}.{expira}.{assinatura}, onde a assinatura e um HMAC-SHA256 (chave derivada da APP_KEY)
 * de id + expira + senha_hash + e-mail. Vale 1 hora e para de valer sozinho quando a senha muda (uso unico) ou o e-mail muda.
 * A resposta do pedido e sempre a mesma: nunca revela se a conta existe.
 */
final class RecuperarSenha
{
    public const VALIDADE = 3600;
    private const PROPOSITO = 'recuperar-senha-v1';

    public function __construct(private readonly PDO $db, private readonly ?int $agora = null)
    {
    }

    /** Pede o link. Nao diz se achou a conta; limites por IP e por conta. */
    public function solicitar(string $identificacao, string $ip): void
    {
        $identificacao = trim($identificacao);
        if ($identificacao === '' || mb_strlen($identificacao) > 190) {
            return;
        }
        if (!RateLimiter::padrao(10, 3600)->permitir('recuperar-ip|' . $ip)
            || !RateLimiter::padrao(3, 3600)->permitir('recuperar-conta|' . hash('sha256', mb_strtolower($identificacao)))) {
            return;
        }
        $conta = $this->contaPorIdentificacao($identificacao);
        if ($conta === null || !self::emailReal((string) $conta['email'])) {
            return;
        }
        $link = rtrim(Config::get('APP_URL', 'http://localhost'), '/') . '/recuperar-senha/' . $this->gerarToken($conta);
        Mailer::agendar((string) $conta['email'], 'Redefinir sua senha · ' . marca(),
            'Olá, ' . $conta['nome'] . "!\n\n"
            . 'Recebemos um pedido para redefinir a senha da sua conta ' . marca() . ' (usuário: ' . $conta['usuario'] . ").\n\n"
            . "Para criar uma senha nova, abra este link (vale por 1 hora e só funciona uma vez):\n" . $link . "\n\n"
            . "Se não foi você, ignore este e-mail: sua senha continua a mesma.\n");
        $this->log('Recuperar senha: link enviado', 'Link para redefinir a senha enviado por e-mail.', (int) $conta['id'], $ip);
    }

    /** @return array<string,mixed>|null conta do link, se o link ainda vale */
    public function validar(string $token): ?array
    {
        if (preg_match('/\A([1-9][0-9]{0,17})\.([0-9]{10})\.([A-Za-z0-9_-]{43})\z/', $token, $m) !== 1) {
            return null;
        }
        if ((int) $m[2] < $this->agora() || (int) $m[2] > $this->agora() + self::VALIDADE) {
            return null;
        }
        $conta = $this->contaPorId((int) $m[1]);
        if ($conta === null || !self::emailReal((string) $conta['email'])) {
            return null;
        }
        return hash_equals($this->assinatura($conta, (int) $m[2]), $m[3]) ? $conta : null;
    }

    /** Grava a senha nova, desconecta os aparelhos lembrados e avisa por e-mail. */
    public function redefinir(string $token, string $nova, string $confirmacao, string $ip): Resultado
    {
        $conta = $this->validar($token);
        if ($conta === null) {
            return Resultado::falha('Este link venceu ou já foi usado. Peça um novo.');
        }
        $erro = EquipeService::validarSenha($nova);
        if ($erro !== null) {
            return Resultado::falha('Confira os campos destacados.', ['senha' => $erro]);
        }
        if (!hash_equals($nova, $confirmacao)) {
            return Resultado::falha('Confira os campos destacados.', ['senha_confirmacao' => 'As duas senhas não são iguais. Digite de novo.']);
        }
        if (password_verify($nova, (string) $conta['senha_hash'])) {
            return Resultado::falha('Confira os campos destacados.', ['senha' => 'Use uma senha diferente da anterior.']);
        }
        $this->db->beginTransaction();
        try {
            // Confere de novo dentro da transacao: dois envios do mesmo link nao gravam duas vezes.
            $trava = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $q = $this->db->prepare('SELECT senha_hash FROM `user` WHERE id = ?' . $trava);
            $q->execute([(int) $conta['id']]);
            if (!hash_equals((string) $conta['senha_hash'], (string) $q->fetchColumn())) {
                $this->db->rollBack();
                return Resultado::falha('Este link venceu ou já foi usado. Peça um novo.');
            }
            $this->db->prepare('UPDATE `user` SET senha_hash = ?, ultimo_codigo_hash = NULL, ultimo_codigo_expira_em = NULL WHERE id = ?')
                ->execute([password_hash($nova, PASSWORD_DEFAULT), (int) $conta['id']]);
            $this->db->prepare('DELETE FROM user_sessoes_lembradas WHERE id_user = ?')->execute([(int) $conta['id']]);
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
        $this->log('Recuperar senha: senha alterada', 'Senha redefinida pelo link do e-mail. Aparelhos lembrados desconectados.', (int) $conta['id'], $ip);
        Mailer::agendar((string) $conta['email'], 'Sua senha foi alterada · ' . marca(),
            'Olá, ' . $conta['nome'] . "!\n\nA senha da sua conta " . marca() . ' foi alterada em ' . date('d/m/Y \à\s H:i', $this->agora()) . ".\n"
            . "Os aparelhos onde você estava conectado vão pedir a senha nova.\n\n"
            . "Se não foi você, avise o gerente do restaurante ou o suporte agora mesmo.\n");
        return Resultado::sucesso('Senha alterada. Entre com a senha nova.');
    }

    /** Gera o link (publico para testes). @param array<string,mixed> $conta */
    public function gerarToken(array $conta): string
    {
        $expira = $this->agora() + self::VALIDADE;
        return (int) $conta['id'] . '.' . $expira . '.' . $this->assinatura($conta, $expira);
    }

    public static function emailReal(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && !str_ends_with(strtolower($email), '.invalid');
    }

    /** @param array<string,mixed> $conta */
    private function assinatura(array $conta, int $expira): string
    {
        $dados = (int) $conta['id'] . '|' . $expira . '|' . $conta['senha_hash'] . '|' . strtolower((string) $conta['email']);
        $bruto = hash_hmac('sha256', $dados, Cripto::derivarChave(self::PROPOSITO), true);
        return rtrim(strtr(base64_encode($bruto), '+/', '-_'), '=');
    }

    /** @return array<string,mixed>|null */
    private function contaPorIdentificacao(string $identificacao): ?array
    {
        $coluna = str_contains($identificacao, '@') ? 'email' : 'usuario';
        $q = $this->db->prepare("SELECT id, usuario, email, nome, senha_hash FROM `user`
            WHERE $coluna = ? AND ativo = 1 AND excluido_em IS NULL LIMIT 1");
        $q->execute([$identificacao]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed>|null */
    private function contaPorId(int $id): ?array
    {
        $q = $this->db->prepare('SELECT id, usuario, email, nome, senha_hash FROM `user` WHERE id = ? AND ativo = 1 AND excluido_em IS NULL');
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function log(string $titulo, string $descricao, int $idUser, string $ip): void
    {
        try {
            (new LogUserRepository($this->db))->registrar($titulo, $descricao, $idUser, $ip);
        } catch (Throwable) {
            error_log('[Cardapim] Log da recuperacao de senha nao gravado.');
        }
    }

    private function agora(): int
    {
        return $this->agora ?? time();
    }
}
