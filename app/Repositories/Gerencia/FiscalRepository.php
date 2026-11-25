<?php
declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use Cardapim\Shared\Seguranca\Cripto;
use PDO;
use RuntimeException;

/** Configuração fiscal separada dos pontos de impressão e dos dados públicos. */
final class FiscalRepository
{
    public function __construct(private readonly PDO $db) {}

    public function buscar(int $id): ?array
    {
        $q = $this->db->prepare('SELECT id_restaurante, cnpj, habilitado, aprovado, id_impressora, dados FROM restaurante_fiscal WHERE id_restaurante = ?');
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $d = json_decode(Cripto::decifrar($r['dados']), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($d)) {
            throw new RuntimeException('Configuração fiscal inválida.');
        }
        return [
            'cnpj' => $r['cnpj'], 'habilitado' => (bool) $r['habilitado'],
            'aprovado' => (bool) $r['aprovado'],
            'id_impressora' => $r['id_impressora'] === null ? null : (int) $r['id_impressora'],
            'dados' => $d,
        ];
    }

    /** Uma operação de configuração por restaurante de cada vez, inclusive chamadas externas. */
    public function serializar(int $id, callable $acao): mixed
    {
        $chave = 'cardapim:fiscal:' . $id;
        $q = $this->db->prepare('SELECT GET_LOCK(?, 2)');
        $q->execute([$chave]);
        if ((int) $q->fetchColumn() !== 1) {
            throw new RuntimeException('Configuração fiscal ocupada.');
        }
        try {
            return $acao();
        } finally {
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$chave]);
        }
    }

    /** Somente chamado dentro de serializar. CNPJ imutável nesta fase. */
    public function salvar(int $id, array $r): void
    {
        $cifrado = Cripto::cifrar(json_encode($r['dados'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $q = $this->db->prepare('SELECT cnpj FROM restaurante_fiscal WHERE id_restaurante = ?');
        $q->execute([$id]);
        $atual = $q->fetchColumn();
        if ($atual === false) {
            $this->db->prepare('INSERT INTO restaurante_fiscal (id_restaurante, cnpj, habilitado, aprovado, id_impressora, dados) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $r['cnpj'], (int) $r['habilitado'], (int) $r['aprovado'], $r['id_impressora'], $cifrado]);
        } else {
            if ($atual !== $r['cnpj']) {
                throw new RuntimeException('CNPJ fiscal não pode ser substituído.');
            }
            $this->db->prepare('UPDATE restaurante_fiscal SET habilitado = ?, aprovado = ?, id_impressora = ?, dados = ? WHERE id_restaurante = ?')
                ->execute([(int) $r['habilitado'], (int) $r['aprovado'], $r['id_impressora'], $cifrado, $id]);
        }
    }

    /** Não retorna tokens, CSC nem payload cifrado. */
    public function resumoPlataforma(): array
    {
        $lista = [];
        $q = $this->db->query('SELECT f.id_restaurante, f.cnpj, f.habilitado, f.aprovado, r.nome FROM restaurante_fiscal f JOIN restaurantes r ON r.id = f.id_restaurante ORDER BY r.nome, f.id_restaurante');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id_restaurante'];
            $f = $this->buscar($id);
            $d = $f['dados'] ?? [];
            $lista[] = [
                'id' => $id, 'nome' => $r['nome'], 'cnpj' => $r['cnpj'],
                'habilitado' => (bool) $r['habilitado'], 'aprovado' => (bool) $r['aprovado'],
                'registrado' => isset($d['token']), 'validade' => $d['certificado']['validade'] ?? null,
                'status' => $d['status'][2] ?? 'Aguardando homologação',
            ];
        }
        return $lista;
    }
}
