<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Desktop;

use PDO;

/**
 * Impressoras cadastradas nos Agents do restaurante e os pontos de impressao configurados pelo gerente.
 * O dispositivo vem sempre do token autenticado; o restaurante, da sessao ou do dispositivo.
 */
final class ImpressoraRepository
{
    /** ponto => rotulo na tela da gerencia (ordem de exibicao). */
    public const PONTOS = [
        'caixa_conta' => 'Caixa: conta da comanda (mesa e balcão)',
        'caixa_delivery' => 'Caixa: pedido do delivery',
        'caixa_fechamento' => 'Caixa: resumo do caixa',
        'garcom' => 'Garçom: comanda',
        'producao' => 'Impressão por produto (padrão)',
    ];
    public const MAX_POR_AGENT = 50;
    /** Maior margem lateral aceita (mm). */
    public const LATERAL_MAX = 8.0;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * O Agent manda a lista completa dele: cria/atualiza pela chave e desativa o que saiu da lista.
     * Agent 0.9.0+ manda tambem papel, logo e margem (cupom em imagem); $comPapel = migracao aplicada.
     * Agent antigo (sem esses campos) nao apaga o que ja estava gravado.
     * @param list<array{chave:string,apelido:string,papel?:int,logo?:bool,margem?:int}> $lista
     */
    public function sincronizar(int $idRestaurante, int $idDispositivo, array $lista, bool $comPapel = false, bool $comInformado = false): void
    {
        $this->db->beginTransaction();
        try {
            $grava = $this->db->prepare('INSERT INTO impressoras (id_restaurante, id_dispositivo, chave, apelido, ativo)
                VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE apelido = VALUES(apelido), ativo = 1');
            $papel = $comPapel ? $this->db->prepare('UPDATE impressoras SET papel = ?, logo = ?, margem_corte = ?'
                . ($comInformado ? ', papel_informado = 1' : '') . ' WHERE id_dispositivo = ? AND id_restaurante = ? AND chave = ?') : null;
            $chaves = [];
            foreach ($lista as $i) {
                $grava->execute([$idRestaurante, $idDispositivo, $i['chave'], $i['apelido']]);
                if ($papel !== null && isset($i['papel'], $i['logo'], $i['margem'])) {
                    $papel->execute([(int) $i['papel'], $i['logo'] ? 1 : 0, (int) $i['margem'], $idDispositivo, $idRestaurante, $i['chave']]);
                }
                $chaves[] = $i['chave'];
            }
            $sql = 'UPDATE impressoras SET ativo = 0 WHERE id_dispositivo = ? AND id_restaurante = ?';
            $params = [$idDispositivo, $idRestaurante];
            if ($chaves !== []) {
                $sql .= ' AND chave NOT IN (' . implode(',', array_fill(0, count($chaves), '?')) . ')';
                $params = [...$params, ...$chaves];
            }
            $this->db->prepare($sql)->execute($params);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Papel, logo e espaco antes do corte de cada impressora ativa (cupom em imagem), por id.
     * Sem a migracao ou sem informacao do Agent: 80 mm, com logo, espaco normal.
     * informado = o Agent 0.9.0+ ja mandou o papel ($comInformado: migracao da aparencia do cupom aplicada).
     * lateral = margem de cada lado em mm escolhida na tela de Impressoras (null = padrao do Deliveryzim).
     * @return array<int,array{chave:string,id_dispositivo:int,papel:int,logo:bool,margem:int,informado:bool,lateral:?float}>
     */
    public function formatos(int $idRestaurante, bool $comPapel, ?int $idDispositivo = null, bool $comInformado = false): array
    {
        $colunas = $comPapel ? 'papel, logo, margem_corte' : '80 AS papel, 1 AS logo, 4 AS margem_corte';
        $colunas .= $comPapel && $comInformado ? ', papel_informado' : ', 0 AS papel_informado';
        $colunas .= \Cardapim\Shared\Esquema::tem($this->db, 'cupom_opcoes') ? ', margem_lateral_mm' : ', NULL AS margem_lateral_mm';
        $sql = 'SELECT id, chave, id_dispositivo, ' . $colunas . ' FROM impressoras WHERE id_restaurante = ? AND ativo = 1';
        $params = [$idRestaurante];
        if ($idDispositivo !== null) {
            $sql .= ' AND id_dispositivo = ?';
            $params[] = $idDispositivo;
        }
        $q = $this->db->prepare($sql);
        $q->execute($params);
        $saida = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $saida[(int) $r['id']] = [
                'chave' => (string) $r['chave'], 'id_dispositivo' => (int) $r['id_dispositivo'],
                'papel' => (int) $r['papel'] === 58 ? 58 : 80, 'logo' => (int) $r['logo'] === 1,
                'margem' => max(0, min(20, (int) $r['margem_corte'])),
                'informado' => (int) $r['papel_informado'] === 1,
                'lateral' => self::lateral($r['margem_lateral_mm']),
            ];
        }
        return $saida;
    }

    /**
     * Impressoras ativas do restaurante, com o computador e se ele esta ligado agora.
     * @return list<array{id:int,apelido:string,computador:string,ligada:bool}>
     */
    public function listar(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT i.id, i.apelido, d.nome AS computador,
                (d.revogado_em IS NULL AND d.expira_em > NOW() AND d.impressao_em >= NOW() - INTERVAL '
                . ImpressaoRepository::AGENTE_SEGUNDOS . ' SECOND) AS ligada
            FROM impressoras i JOIN desktop_agent_devices d ON d.id = i.id_dispositivo AND d.id_restaurante = i.id_restaurante
            WHERE i.id_restaurante = ? AND i.ativo = 1 AND d.revogado_em IS NULL
            ORDER BY i.apelido, i.id');
        $q->execute([$idRestaurante]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'apelido' => (string) $r['apelido'],
            'computador' => (string) $r['computador'], 'ligada' => (int) $r['ligada'] === 1,
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int,int> id da impressora => quantos produtos estao marcados nela */
    public function produtosPorImpressora(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT id_impressora, COUNT(*) FROM produto_impressoras WHERE id_restaurante = ? GROUP BY id_impressora');
        $q->execute([$idRestaurante]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return array<string,array{ids:list<int>,padrao:?int}> ponto => impressoras marcadas e a padrao */
    public function pontos(int $idRestaurante): array
    {
        $saida = array_fill_keys(array_keys(self::PONTOS), ['ids' => [], 'padrao' => null]);
        $q = $this->db->prepare('SELECT p.ponto, p.id_impressora, p.padrao FROM impressao_pontos p
            JOIN impressoras i ON i.id = p.id_impressora AND i.id_restaurante = p.id_restaurante
            WHERE p.id_restaurante = ? AND i.ativo = 1 ORDER BY i.apelido, i.id');
        $q->execute([$idRestaurante]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($saida[$r['ponto']])) {
                continue;
            }
            $saida[$r['ponto']]['ids'][] = (int) $r['id_impressora'];
            if ((int) $r['padrao'] === 1) {
                $saida[$r['ponto']]['padrao'] = (int) $r['id_impressora'];
            }
        }
        return $saida;
    }

    /**
     * Grava a configuracao inteira (substitui a anterior). Ids de impressora de outro restaurante sao ignorados.
     * @param array<string,array{ids:list<int>,padrao:?int}> $config
     */
    public function salvarPontos(int $idRestaurante, array $config): void
    {
        $validas = array_column($this->listar($idRestaurante), 'id');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM impressao_pontos WHERE id_restaurante = ?')->execute([$idRestaurante]);
            $grava = $this->db->prepare('INSERT INTO impressao_pontos (id_restaurante, ponto, id_impressora, padrao) VALUES (?, ?, ?, ?)');
            foreach ($config as $ponto => $c) {
                if (!isset(self::PONTOS[$ponto])) {
                    continue;
                }
                $ids = array_values(array_intersect(array_unique($c['ids']), $validas));
                $padrao = in_array($c['padrao'], $ids, true) ? $c['padrao'] : ($ids[0] ?? null);
                foreach ($ids as $id) {
                    $grava->execute([$idRestaurante, $ponto, $id, $id === $padrao ? 1 : 0]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Impressoras de um ponto, a padrao primeiro. Lista vazia = ponto sem configuracao (imprime no Agent principal).
     * @return list<array{id:int,apelido:string,computador:string,ligada:bool,padrao:bool}>
     */
    public function opcoes(int $idRestaurante, string $ponto): array
    {
        $cfg = $this->pontos($idRestaurante)[$ponto] ?? ['ids' => [], 'padrao' => null];
        if ($cfg['ids'] === []) {
            return [];
        }
        $lista = [];
        foreach ($this->listar($idRestaurante) as $i) {
            if (in_array($i['id'], $cfg['ids'], true)) {
                $lista[] = $i + ['padrao' => $i['id'] === $cfg['padrao']];
            }
        }
        usort($lista, static fn (array $a, array $b): int => (int) $b['padrao'] <=> (int) $a['padrao']);
        return $lista;
    }

    /** Imprimir o aviso de item/comanda cancelado? Sem configuracao (ou recurso ainda nao liberado) = sim. */
    public function cancelamentoAtivo(int $idRestaurante): bool
    {
        if (!\Cardapim\Shared\Esquema::tem($this->db, 'complemento_qtd')) {
            return true;
        }
        $q = $this->db->prepare('SELECT cancelamento FROM restaurante_impressao_config WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        $v = $q->fetchColumn();
        return $v === false || (int) $v === 1;
    }

    public function salvarCancelamento(int $idRestaurante, bool $ativo): void
    {
        if (!\Cardapim\Shared\Esquema::tem($this->db, 'complemento_qtd')) {
            return;
        }
        $this->db->prepare('INSERT INTO restaurante_impressao_config (id_restaurante, cancelamento) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE cancelamento = VALUES(cancelamento)')->execute([$idRestaurante, $ativo ? 1 : 0]);
    }

    /** Margem lateral valida (0 a 8 mm, de 0,5 em 0,5) ou null (padrao). */
    public static function lateral(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $mm = is_numeric($valor) ? (float) $valor : (is_string($valor) && is_numeric(str_replace(',', '.', $valor)) ? (float) str_replace(',', '.', $valor) : null);
        if ($mm === null || $mm < 0 || $mm > self::LATERAL_MAX || fmod($mm * 2, 1.0) !== 0.0) {
            return null;
        }
        return $mm;
    }

    /**
     * Grava a margem lateral de cada impressora (id => mm ou null = padrao). Ids de outro restaurante sao ignorados.
     * @param array<int,?float> $porImpressora
     */
    public function salvarLaterais(int $idRestaurante, array $porImpressora): void
    {
        if (!\Cardapim\Shared\Esquema::tem($this->db, 'cupom_opcoes') || $porImpressora === []) {
            return;
        }
        $grava = $this->db->prepare('UPDATE impressoras SET margem_lateral_mm = ? WHERE id = ? AND id_restaurante = ?');
        foreach ($porImpressora as $id => $mm) {
            $grava->execute([$mm === null ? null : number_format($mm, 1, '.', ''), (int) $id, $idRestaurante]);
        }
    }

    /** Padrao das opcoes do cupom: nome impresso e via do delivery sem total, endereco e telefone (como ja funcionava). */
    public const OPCOES_CUPOM_PADRAO = [
        'mostrar_atendente' => true,
        'delivery_com_preco' => false,
        'delivery_com_endereco' => false,
        'delivery_com_telefone' => false,
    ];

    /**
     * Opcoes do cupom do restaurante. Sem configuracao (ou recurso ainda nao liberado): OPCOES_CUPOM_PADRAO.
     * @return array{mostrar_atendente:bool,delivery_com_preco:bool,delivery_com_endereco:bool,delivery_com_telefone:bool}
     */
    public function opcoesCupom(int $idRestaurante): array
    {
        $padrao = self::OPCOES_CUPOM_PADRAO;
        if (!\Cardapim\Shared\Esquema::tem($this->db, 'cupom_opcoes')) {
            return $padrao;
        }
        $contato = \Cardapim\Shared\Esquema::tem($this->db, 'cupom_contato');
        $q = $this->db->prepare('SELECT mostrar_atendente, delivery_com_preco'
            . ($contato ? ', delivery_com_endereco, delivery_com_telefone' : '')
            . ' FROM restaurante_impressao_config WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return $padrao;
        }
        $opcoes = $padrao;
        foreach (array_keys($padrao) as $campo) {
            if (array_key_exists($campo, $r)) {
                $opcoes[$campo] = (int) $r[$campo] === 1;
            }
        }
        return $opcoes;
    }

    /** @param array{mostrar_atendente:bool,delivery_com_preco:bool,delivery_com_endereco:bool,delivery_com_telefone:bool} $opcoes */
    public function salvarOpcoesCupom(int $idRestaurante, array $opcoes): void
    {
        if (!\Cardapim\Shared\Esquema::tem($this->db, 'cupom_opcoes')) {
            return;
        }
        $campos = ['mostrar_atendente', 'delivery_com_preco'];
        if (\Cardapim\Shared\Esquema::tem($this->db, 'cupom_contato')) {
            $campos = [...$campos, 'delivery_com_endereco', 'delivery_com_telefone'];
        }
        $valores = array_map(static fn (string $c): int => ($opcoes[$c] ?? false) ? 1 : 0, $campos);
        $this->db->prepare('INSERT INTO restaurante_impressao_config (id_restaurante, ' . implode(', ', $campos) . ')
            VALUES (?' . str_repeat(', ?', count($campos)) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => "$c = VALUES($c)", $campos)))
            ->execute([$idRestaurante, ...$valores]);
    }
}
