<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Plataforma;

use PDO;

/**
 * Logs de auditoria (tabela log_user) vistos pela plataforma: filtros por tipo, restaurante, pessoa, texto e datas.
 * So leitura: nenhum registro e editado ou apagado.
 */
final class LogAdminRepository
{
    public const POR_PAGINA = 50;
    /** Limite da planilha (para nao pesar no servidor). */
    public const LIMITE_PLANILHA = 20000;

    public const TIPOS = [
        'acessos' => 'Entradas e saídas',
        'seguranca' => 'Segurança',
        'alteracoes' => 'Cadastros e alterações',
        'clientes' => 'Clientes do cardápio',
    ];

    private const ACESSOS = "('Login', 'Login automático', 'Logout')";
    private const SEGURANCA = "(l.titulo IN ('Login recusado', 'Verificação recusada', 'Alerta de segurança', 'Códigos de recuperação trocados', 'Aparelho desconectado', 'Aparelhos desconectados')
        OR l.titulo LIKE '%senha%' OR l.titulo LIKE '%duas etapas%')";

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{tipo:?string, restaurante:?int, pessoa:string, busca:string, de:?string, ate:?string} $f
     * @return array{itens:list<array<string,mixed>>, total:int}
     */
    public function listar(array $f, int $pagina): array
    {
        [$onde, $args] = $this->filtros($f);
        $total = $this->db->prepare('SELECT COUNT(*) FROM log_user l LEFT JOIN `user` u ON u.id = l.id_user WHERE ' . $onde);
        $total->execute($args);
        $q = $this->db->prepare($this->select() . ' WHERE ' . $onde . ' ORDER BY l.id DESC LIMIT ' . self::POR_PAGINA . ' OFFSET ' . (($pagina - 1) * self::POR_PAGINA));
        $q->execute($args);
        return ['itens' => $q->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $total->fetchColumn()];
    }

    /** @return iterable<array<string,mixed>> registros para a planilha (mais novos primeiro) */
    public function paraPlanilha(array $f): iterable
    {
        [$onde, $args] = $this->filtros($f);
        $q = $this->db->prepare($this->select() . ' WHERE ' . $onde . ' ORDER BY l.id DESC LIMIT ' . self::LIMITE_PLANILHA);
        $q->execute($args);
        while (($r = $q->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $r;
        }
    }

    /** @return list<array{id:int, nome:string}> restaurantes para o filtro */
    public function restaurantes(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['nome']],
            $this->db->query('SELECT id, nome FROM restaurantes ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC));
    }

    private function select(): string
    {
        return "SELECT l.id, l.titulo, l.descricao, l.data_hora, l.ip, l.id_user, u.usuario, u.nome, u.sobrenome, u.cargo,
                u.id_restaurante, r.nome AS restaurante_nome
            FROM log_user l LEFT JOIN `user` u ON u.id = l.id_user LEFT JOIN restaurantes r ON r.id = u.id_restaurante";
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function filtros(array $f): array
    {
        $onde = ['1 = 1'];
        $args = [];
        switch ($f['tipo'] ?? null) {
            case 'acessos':
                $onde[] = 'l.titulo IN ' . self::ACESSOS;
                break;
            case 'seguranca':
                $onde[] = self::SEGURANCA;
                break;
            case 'clientes':
                $onde[] = "l.titulo LIKE 'Cliente:%'";
                break;
            case 'alteracoes':
                $onde[] = 'l.titulo NOT IN ' . self::ACESSOS . " AND l.titulo NOT LIKE 'Cliente:%' AND NOT " . self::SEGURANCA;
                break;
        }
        if (($f['restaurante'] ?? null) === 0) {
            $onde[] = "u.cargo = 'adm_master'";
        } elseif (($f['restaurante'] ?? null) !== null) {
            $onde[] = 'u.id_restaurante = ?';
            $args[] = $f['restaurante'];
        }
        $pessoa = trim((string) ($f['pessoa'] ?? ''));
        if ($pessoa !== '') {
            $termo = '%' . self::escapar($pessoa) . '%';
            $onde[] = "(u.usuario LIKE ? ESCAPE '!' OR u.nome LIKE ? ESCAPE '!' OR u.sobrenome LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!')";
            array_push($args, $termo, $termo, $termo, $termo);
        }
        $busca = trim((string) ($f['busca'] ?? ''));
        if ($busca !== '') {
            $termo = '%' . self::escapar($busca) . '%';
            $onde[] = "(l.titulo LIKE ? ESCAPE '!' OR l.descricao LIKE ? ESCAPE '!' OR l.ip LIKE ? ESCAPE '!')";
            array_push($args, $termo, $termo, $termo);
        }
        if (($f['de'] ?? null) !== null) {
            $onde[] = 'l.data_hora >= ?';
            $args[] = $f['de'] . ' 00:00:00';
        }
        if (($f['ate'] ?? null) !== null) {
            $onde[] = 'l.data_hora < ?';
            $args[] = date('Y-m-d', (int) strtotime($f['ate'] . ' +1 day')) . ' 00:00:00';
        }
        return [implode(' AND ', $onde), $args];
    }

    private static function escapar(string $t): string
    {
        return strtr($t, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
