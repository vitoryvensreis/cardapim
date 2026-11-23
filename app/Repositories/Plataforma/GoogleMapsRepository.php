<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Plataforma;

use Cardapim\Shared\Seguranca\Cripto;
use PDO;
use RuntimeException;

/**
 * Configuracao unica da Routes API para TODA a plataforma.
 * Segredo criptografado em configuracoes; nunca acessivel por Configuracoes::get().
 */
final class GoogleMapsRepository
{
    private const CHAVE = 'google_routes_global';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{chave:string,ativo:bool,testado_em:string,salvo_em:string,salvo_por:string}|null */
    public function buscar(): ?array
    {
        $q = $this->db->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $q->execute([self::CHAVE]);
        $valor = $q->fetchColumn();
        if (!is_string($valor) || $valor === '') {
            return null;
        }
        $dados = json_decode(Cripto::decifrar($valor), true);
        if (!is_array($dados) || !is_string($dados['chave'] ?? null) || $dados['chave'] === '') {
            throw new RuntimeException('Configuracao do Google Maps invalida.');
        }
        return [
            'chave' => $dados['chave'],
            'ativo' => ($dados['ativo'] ?? false) === true,
            'testado_em' => (string) ($dados['testado_em'] ?? ''),
            'salvo_em' => (string) ($dados['salvo_em'] ?? ''),
            'salvo_por' => (string) ($dados['salvo_por'] ?? ''),
        ];
    }

    /** @param array{chave:string,ativo:bool,testado_em:string,salvo_em:string,salvo_por:string} $dados */
    public function salvar(array $dados): void
    {
        $cifrado = Cripto::cifrar(json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $q = $this->db->prepare('SELECT 1 FROM configuracoes WHERE chave = ?');
        $q->execute([self::CHAVE]);
        if ($q->fetchColumn() !== false) {
            $this->db->prepare('UPDATE configuracoes SET valor = ? WHERE chave = ?')->execute([$cifrado, self::CHAVE]);
            return;
        }
        $this->db->prepare("INSERT INTO configuracoes (chave, valor, tipo, descricao) VALUES (?, ?, 'texto', ?)")
            ->execute([self::CHAVE, $cifrado, 'Google Routes API da plataforma (cifrada)']);
    }

    public function remover(): void
    {
        $this->db->prepare('DELETE FROM configuracoes WHERE chave = ?')->execute([self::CHAVE]);
    }
}
