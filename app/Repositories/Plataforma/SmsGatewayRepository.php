<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Plataforma;

use Cardapim\Shared\Seguranca\Cripto;
use PDO;

/**
 * Configuracao do servidor de SMS (SMSGate) da plataforma, cadastrada em /painel/integracoes/sms.
 * Fica na tabela "configuracoes" (chave sms_gateway) com o valor inteiro CIFRADO (Cripto, AES-256-GCM).
 * Configuracoes::get nunca le esta chave (lista fechada de chaves publicas).
 */
final class SmsGatewayRepository
{
    private const CHAVE = 'sms_gateway';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{url:string, usuario:string, senha:string, salvo_em:string, salvo_por:string, ultimo_teste:string}|null */
    public function buscar(): ?array
    {
        $q = $this->db->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $q->execute([self::CHAVE]);
        $cifrado = $q->fetchColumn();
        if (!is_string($cifrado) || $cifrado === '') {
            return null;
        }
        try {
            $d = json_decode(Cripto::decifrar($cifrado), true);
        } catch (\Throwable) {
            return null; // chave do servidor trocada: trata como nao configurado
        }
        if (!is_array($d) || !is_string($d['url'] ?? null) || !is_string($d['usuario'] ?? null) || !is_string($d['senha'] ?? null)) {
            return null;
        }
        return [
            'url' => $d['url'],
            'usuario' => $d['usuario'],
            'senha' => $d['senha'],
            'salvo_em' => (string) ($d['salvo_em'] ?? ''),
            'salvo_por' => (string) ($d['salvo_por'] ?? ''),
            'ultimo_teste' => (string) ($d['ultimo_teste'] ?? ''),
        ];
    }

    /** @param array{url:string, usuario:string, senha:string, salvo_em?:string, salvo_por?:string, ultimo_teste?:string} $c */
    public function salvar(array $c): void
    {
        $valor = Cripto::cifrar(json_encode([
            'url' => $c['url'],
            'usuario' => $c['usuario'],
            'senha' => $c['senha'],
            'salvo_em' => $c['salvo_em'] ?? date('Y-m-d H:i:s'),
            'salvo_por' => mb_substr((string) ($c['salvo_por'] ?? ''), 0, 120),
            'ultimo_teste' => (string) ($c['ultimo_teste'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $existe = $this->db->prepare('SELECT 1 FROM configuracoes WHERE chave = ?');
        $existe->execute([self::CHAVE]);
        if ($existe->fetchColumn() !== false) {
            $this->db->prepare('UPDATE configuracoes SET valor = ? WHERE chave = ?')->execute([$valor, self::CHAVE]);
            return;
        }
        $this->db->prepare("INSERT INTO configuracoes (chave, valor, tipo, descricao) VALUES (?, ?, 'texto', ?)")
            ->execute([self::CHAVE, $valor, 'Servidor de SMS da plataforma (cifrado)']);
    }

    public function remover(): void
    {
        $this->db->prepare('DELETE FROM configuracoes WHERE chave = ?')->execute([self::CHAVE]);
    }
}
