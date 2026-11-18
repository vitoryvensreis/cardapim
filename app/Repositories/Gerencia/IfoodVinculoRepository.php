<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/**
 * O que deste restaurante ja existe no cardapio do iFood (categoria, item, grupo, complemento) e o ultimo envio aceito.
 * Tabela ifood_catalogo_vinculos; toda consulta filtra por id_restaurante.
 */
final class IfoodVinculoRepository
{
    public function __construct(private readonly PDO $db, private readonly int $idRestaurante)
    {
    }

    /** @return array<int, array<string,mixed>> id_local => vinculo */
    public function todos(string $tipo): array
    {
        $q = $this->db->prepare('SELECT * FROM ifood_catalogo_vinculos WHERE id_restaurante = ? AND tipo = ?');
        $q->execute([$this->idRestaurante, $tipo]);
        $saida = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $saida[(int) $v['id_local']] = $v;
        }
        return $saida;
    }

    /** @return array<string,mixed>|null */
    public function buscar(string $tipo, int $idLocal): ?array
    {
        $q = $this->db->prepare('SELECT * FROM ifood_catalogo_vinculos WHERE id_restaurante = ? AND tipo = ? AND id_local = ?');
        $q->execute([$this->idRestaurante, $tipo, $idLocal]);
        $v = $q->fetch(PDO::FETCH_ASSOC);
        return $v === false ? null : $v;
    }

    /**
     * Cria ou atualiza o vinculo. Campos ausentes ficam como estao.
     * @param array<string,mixed> $campos ifood_id, ifood_produto_id, ifood_imagem, imagem_enviada, hash_cadastro, enviado_preco, enviado_status, status, ultimo_erro
     */
    public function salvar(string $tipo, int $idLocal, array $campos): void
    {
        $permitidos = ['ifood_id', 'ifood_produto_id', 'ifood_imagem', 'imagem_enviada', 'hash_cadastro', 'enviado_preco', 'enviado_status', 'status', 'ultimo_erro', 'tentativas', 'proxima_em', 'publicado_em'];
        $campos = array_intersect_key($campos, array_flip($permitidos));
        if ($this->buscar($tipo, $idLocal) === null) {
            $this->db->prepare('INSERT INTO ifood_catalogo_vinculos (id_restaurante, tipo, id_local, ifood_id) VALUES (?, ?, ?, ?)')
                ->execute([$this->idRestaurante, $tipo, $idLocal, (string) ($campos['ifood_id'] ?? '')]);
        }
        if ($campos === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($campos)));
        $this->db->prepare('UPDATE ifood_catalogo_vinculos SET ' . $sets . ' WHERE id_restaurante = ? AND tipo = ? AND id_local = ?')
            ->execute([...array_values($campos), $this->idRestaurante, $tipo, $idLocal]);
    }

    /** Remove a vinculacao local depois que a exclusao remota for aceita (idempotente e restrita ao restaurante). */
    public function remover(string $tipo, int $idLocal): void
    {
        $this->db->prepare('DELETE FROM ifood_catalogo_vinculos WHERE id_restaurante = ? AND tipo = ? AND id_local = ?')
            ->execute([$this->idRestaurante, $tipo, $idLocal]);
    }

    /** Falha ao enviar: guarda o motivo e agenda nova tentativa (1, 2, 4... ate 60 minutos). */
    public function falhou(string $tipo, int $idLocal, string $erro): void
    {
        $v = $this->buscar($tipo, $idLocal);
        if ($v === null) {
            return; // so registra falha de quem ja tem vinculo (os ids do item sao criados antes do envio)
        }
        $tentativas = (int) $v['tentativas'] + 1;
        $espera = min(60, 2 ** min(6, $tentativas - 1));
        $this->salvar($tipo, $idLocal, ['status' => 'erro', 'ultimo_erro' => mb_substr($erro, 0, 255), 'tentativas' => $tentativas,
            'proxima_em' => date('Y-m-d H:i:s', time() + $espera * 60)]);
    }

    /** Itens publicados (ou com erro ja vencido para tentar de novo). @return list<int> ids dos produtos */
    public function itensParaConferir(): array
    {
        $q = $this->db->prepare("SELECT id_local FROM ifood_catalogo_vinculos WHERE id_restaurante = ? AND tipo = 'item' AND ifood_id <> ''
            AND (status = 'publicado' OR (status = 'erro' AND (proxima_em IS NULL OR proxima_em <= ?))) ORDER BY id");
        $q->execute([$this->idRestaurante, date('Y-m-d H:i:s')]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Restaurantes com algo publicado no iFood (para a rotina). @return list<int> */
    public static function restaurantesComPublicacao(PDO $db): array
    {
        return array_map('intval', $db->query("SELECT DISTINCT id_restaurante FROM ifood_catalogo_vinculos WHERE tipo = 'item' ORDER BY id_restaurante")->fetchAll(PDO::FETCH_COLUMN));
    }
}
