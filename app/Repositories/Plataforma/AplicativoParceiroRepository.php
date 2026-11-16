<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Plataforma;

use Cardapim\Shared\Seguranca\Cripto;
use PDO;

/**
 * Credenciais dos APLICATIVOS da plataforma nos marketplaces (iFood, 99Food...): o Client ID e o
 * Client Secret do app que o Cardapim tem no portal de desenvolvedor de cada marketplace.
 * Nao sao contas dos restaurantes: cada restaurante so autoriza esse app no portal dele.
 *
 * Ficam na tabela "configuracoes" (chave app_parceiro_<provedor>), com o valor inteiro CIFRADO
 * (Cripto, AES-256-GCM, chave fora do banco). Configuracoes::get nunca le estas chaves (lista fechada de publicas).
 */
final class AplicativoParceiroRepository
{
    private const PREFIXO = 'app_parceiro_';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{client_id:string, client_secret:string, salvo_em:string, salvo_por:string}|null */
    public function buscar(string $provedor): ?array
    {
        $query = $this->db->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $query->execute([self::chave($provedor)]);
        $cifrado = $query->fetchColumn();
        if (!is_string($cifrado) || $cifrado === '') {
            return null;
        }
        $dados = json_decode(Cripto::decifrar($cifrado), true);
        if (!is_array($dados) || !is_string($dados['client_id'] ?? null) || !is_string($dados['client_secret'] ?? null)) {
            return null;
        }
        return [
            'client_id' => $dados['client_id'],
            'client_secret' => $dados['client_secret'],
            'salvo_em' => (string) ($dados['salvo_em'] ?? ''),
            'salvo_por' => (string) ($dados['salvo_por'] ?? ''),
        ];
    }

    public function salvar(string $provedor, string $clientId, string $clientSecret, string $autor): void
    {
        $valor = Cripto::cifrar(json_encode([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'salvo_em' => date('Y-m-d H:i:s'),
            'salvo_por' => mb_substr($autor, 0, 120),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $chave = self::chave($provedor);
        $existe = $this->db->prepare('SELECT 1 FROM configuracoes WHERE chave = ?');
        $existe->execute([$chave]);
        if ($existe->fetchColumn() !== false) {
            $this->db->prepare('UPDATE configuracoes SET valor = ? WHERE chave = ?')->execute([$valor, $chave]);
            return;
        }
        $this->db->prepare("INSERT INTO configuracoes (chave, valor, tipo, descricao) VALUES (?, ?, 'texto', ?)")
            ->execute([$chave, $valor, 'Credenciais do aplicativo ' . $provedor . ' (cifradas)']);
    }

    public function remover(string $provedor): void
    {
        $this->db->prepare('DELETE FROM configuracoes WHERE chave = ?')->execute([self::chave($provedor)]);
    }

    /** Restaurantes com a integracao deste marketplace ativa (dependem do app atual). */
    public function lojasVinculadas(string $provedor): int
    {
        $query = $this->db->prepare("SELECT COUNT(*) FROM integracoes WHERE provedor = ? AND id_restaurante IS NOT NULL AND status <> 'desconectado'");
        try {
            $query->execute([$provedor]);
        } catch (\PDOException) {
            return 0; // provedor ainda fora do ENUM da tabela (ex.: 99food antes da integracao existir)
        }
        return (int) $query->fetchColumn();
    }

    private static function chave(string $provedor): string
    {
        if (preg_match('/\A[a-z0-9]{2,20}\z/', $provedor) !== 1) {
            throw new \InvalidArgumentException('Provedor invalido.');
        }
        return self::PREFIXO . $provedor;
    }
}
