<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

use Cardapim\Core\RateLimiter;
use Cardapim\Repositories\ContaRepository;
use Cardapim\Services\Email\Mailer;
use Cardapim\Shared\Seguranca\CodigosRecuperacao;
use Cardapim\Shared\Seguranca\Cripto;
use Cardapim\Shared\Seguranca\Totp;
use PDO;
use Throwable;

/**
 * Verificacao em duas etapas: aplicativo autenticador (TOTP) ou codigo por e-mail,
 * sempre com codigos de recuperacao. Regra de ouro: so ativa depois que a pessoa digita
 * um codigo valido, assim ninguem fica trancado por um aplicativo mal configurado ou e-mail que nao chega.
 */
final class DoisFatores
{
    public const APP = 'app';
    public const EMAIL = 'email';
    public const DESATIVADO = 'desativado';
    public const VALIDADE_CODIGO_EMAIL = 600; // 10 minutos
    private const ENVIOS_POR_JANELA = 5;
    private const JANELA_ENVIOS = 900;

    private readonly ContaRepository $contas;

    public function __construct(private readonly PDO $db)
    {
        $this->contas = new ContaRepository($db);
    }

    public static function rotulo(string $metodo): string
    {
        return match ($metodo) {
            self::APP => 'Aplicativo autenticador',
            self::EMAIL => 'Código por e-mail',
            default => 'Desativada',
        };
    }

    /** "ana.souza@gmail.com" -> "an***@gmail.com" */
    public static function emailMascarado(string $email): string
    {
        [$nome, $dominio] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($nome, 0, 2) . str_repeat('*', max(3, mb_strlen($nome) - 2)) . '@' . $dominio;
    }

    /* ---------- Codigo por e-mail ---------- */

    /**
     * Gera e envia um codigo de 6 digitos. $finalidade aparece no e-mail ("ativar" ou "entrar").
     * @param array<string,mixed> $conta linha de ContaRepository::buscar
     * @return array{ok:bool, mensagem:string}
     */
    public function enviarCodigoEmail(array $conta, string $finalidade): array
    {
        $limite = RateLimiter::padrao(self::ENVIOS_POR_JANELA, self::JANELA_ENVIOS);
        $chave = 'email-2fa|' . (int) $conta['id'];
        if ($limite->excedido($chave)) {
            return ['ok' => false, 'mensagem' => 'Muitos códigos enviados. Aguarde 15 minutos e tente de novo.'];
        }
        $limite->permitir($chave);
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->contas->salvarCodigoEmail((int) $conta['id'], password_hash($codigo, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + self::VALIDADE_CODIGO_EMAIL));
        $texto = sprintf(
            "Olá, %s!\n\nSeu código do " . marca() . " para %s é:\n\n    %s\n\nEle vale por 10 minutos. Se não foi você quem pediu, ignore este e-mail e troque sua senha.\n\nEquipe " . marca(),
            $conta['nome'],
            $finalidade === 'ativar' ? 'ativar a verificação em duas etapas' : 'entrar na sua conta',
            $codigo,
        );
        $enviado = Mailer::enviar((string) $conta['email'], 'Seu código do ' . marca() . ': ' . $codigo, $texto);
        if (!$enviado) {
            $this->contas->limparCodigoEmail((int) $conta['id']);
            return ['ok' => false, 'mensagem' => 'Não conseguimos enviar o e-mail agora. Tente de novo em alguns minutos ou use o aplicativo autenticador.'];
        }
        return ['ok' => true, 'mensagem' => 'Enviamos um código para ' . self::emailMascarado((string) $conta['email']) . '. Ele vale por 10 minutos.'];
    }

    /** @param array<string,mixed> $conta */
    public function conferirCodigoEmail(array $conta, string $codigo): bool
    {
        $codigo = preg_replace('/\D+/', '', $codigo) ?? '';
        $hash = (string) ($conta['ultimo_codigo_hash'] ?? '');
        $expira = strtotime((string) ($conta['ultimo_codigo_expira_em'] ?? ''));
        if (strlen($codigo) !== 6 || $hash === '' || $expira === false || $expira < time() || !password_verify($codigo, $hash)) {
            return false;
        }
        $this->contas->limparCodigoEmail((int) $conta['id']); // uso unico
        return true;
    }

    /* ---------- Ativar / desativar ---------- */

    /**
     * @param array<string,mixed> $conta
     * @return list<string>|null codigos de recuperacao (mostrar uma unica vez) ou null se o codigo nao confere
     */
    public function ativarApp(array $conta, string $segredo, string $codigo): ?array
    {
        if (!Cripto::disponivel() || Totp::verificar($segredo, $codigo) === null) {
            return null;
        }
        return $this->ativar((int) $conta['id'], self::APP, Cripto::cifrar($segredo));
    }

    /** @param array<string,mixed> $conta @return list<string>|null */
    public function ativarEmail(array $conta, string $codigo): ?array
    {
        if (!$this->conferirCodigoEmail($conta, $codigo)) {
            return null;
        }
        return $this->ativar((int) $conta['id'], self::EMAIL, null);
    }

    public function desativar(int $idUser): void
    {
        $this->contas->desativarDoisFatores($idUser);
    }

    /** @return list<string> novos codigos (os antigos deixam de valer) */
    public function novosCodigos(int $idUser): array
    {
        $codigos = CodigosRecuperacao::gerar();
        $this->contas->substituirCodigos($idUser, array_map([CodigosRecuperacao::class, 'hash'], $codigos));
        return $codigos;
    }

    /* ---------- Login ---------- */

    /**
     * Confere o segundo passo do login.
     * @param array<string,mixed> $conta
     * @return string|null como foi verificado ('app', 'email', 'recuperacao') ou null
     */
    public function verificarLogin(array $conta, string $entrada): ?string
    {
        $entrada = trim($entrada);
        if (CodigosRecuperacao::pareceCodigo($entrada)) {
            return $this->contas->usarCodigo((int) $conta['id'], CodigosRecuperacao::hash($entrada), date('Y-m-d H:i:s')) ? 'recuperacao' : null;
        }
        if ($conta['dois_fatores'] === self::APP) {
            try {
                $segredo = Cripto::decifrar((string) $conta['dois_fatores_segredo']);
            } catch (Throwable $exception) {
                error_log('[Cardapim] Segredo do autenticador ilegivel: ' . get_class($exception));
                return null;
            }
            return Totp::verificar($segredo, $entrada) !== null ? self::APP : null;
        }
        if ($conta['dois_fatores'] === self::EMAIL) {
            return $this->conferirCodigoEmail($conta, $entrada) ? self::EMAIL : null;
        }
        return null;
    }

    /** @return list<string> */
    private function ativar(int $idUser, string $metodo, ?string $segredoCifrado): array
    {
        $this->db->beginTransaction();
        try {
            $this->contas->ativarDoisFatores($idUser, $metodo, $segredoCifrado, date('Y-m-d H:i:s'));
            $codigos = $this->novosCodigos($idUser);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        return $codigos;
    }
}
