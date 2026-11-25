<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Plataforma;

use Cardapim\Shared\Seguranca\Cripto;
use PDO;
use RuntimeException;

/** Registro global privado; usa a tabela configuracoes ja existente, como Google Routes. */
final class FiscalNextRepository
{
    private const CHAVE = 'fiscal_next_global';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{versao:?string,dados:array} */
    public function ler(): array
    {
        $q = $this->db->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $q->execute([self::CHAVE]);
        $valor = $q->fetchColumn();
        if ($valor === false) {
            return ['versao' => null, 'dados' => []];
        }
        $dados = json_decode(Cripto::decifrar((string) $valor), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($dados) || !is_string($dados['token'] ?? null) || $dados['token'] === '') {
            throw new RuntimeException('Configuração fiscal inválida.');
        }
        return ['versao' => (string) $valor, 'dados' => $dados];
    }

    /** Comparacao otimista: um teste atrasado nao sobrescreve credenciais novas. */
    public function salvar(array $dados, ?string $versao): void
    {
        $valor = Cripto::cifrar(json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (strlen($valor) > 60000) {
            throw new RuntimeException('Configuração fiscal excede o tamanho permitido.');
        }
        if ($versao === null) {
            $this->db->prepare("INSERT INTO configuracoes (chave, valor, tipo, descricao) VALUES (?, ?, 'texto', ?)")
                ->execute([self::CHAVE, $valor, 'Integração fiscal NeXT da plataforma (cifrada)']);
            return;
        }
        $q = $this->db->prepare('UPDATE configuracoes SET valor = ? WHERE chave = ? AND BINARY valor = ?');
        $q->execute([$valor, self::CHAVE, $versao]);
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('Configuração fiscal alterada em outra sessão.');
        }
    }
}
