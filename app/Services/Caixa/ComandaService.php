<?php

declare(strict_types=1);

namespace Cardapim\Services\Caixa;

use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Modules\Inventory\EstoqueInsuficiente;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\Caixa\MesaRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Pedidos\CancelarPedido;
use Cardapim\Services\Pedidos\TaxaPagamento;
use Cardapim\Repositories\Gerencia\TaxaPagamentoRepository;
use Cardapim\Modules\Catalog\Combo;
use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;
use PDO;
use Cardapim\Services\Fiscal\FiscalEmissao;
use Throwable;

/**
 * Comanda = pedido aberto (status 'aberto') de uma MESA (tipo 'mesa', a mesa e o "cliente") ou do BALCAO.
 * Uma mesa pode ter VARIAS comandas abertas (uma por pessoa, ex.: quem chegou depois), cada uma com nome opcional
 * (comanda_nome; sem nome aparece "Comanda 1", "Comanda 2"... na ordem de abertura). Cada uma e paga separada.
 *
 * - Cada item lancado baixa o estoque NA HORA (ficha tecnica) e guarda o que baixou no proprio item.
 *   Item cancelado devolve exatamente esse consumo. Comanda cancelada devolve tudo.
 * - Total = itens + taxa de servico (percentual) + taxas de pagamento - desconto (com motivo). Recalculado no servidor a cada mudanca.
 * - Pagamento em uma ou varias formas, sempre no caixa aberto. Pagou tudo: comanda concluida e mesa livre.
 * - Taxa por forma de pagamento (Gerencia > Entrega e pagamento): cobrada em CADA pagamento, sobre a parte da conta paga
 *   naquela forma (dinheiro nunca). O pagamento guarda valor = parte da conta + taxa; o pedido soma a taxa no total.
 *   Assim o "falta pagar" (total - pago) continua sendo so a conta, e o fechamento do caixa bate com a maquininha.
 */
final class ComandaService
{
    public const SERVICO_PADRAO = '10.00';
    public const MAX_COMANDAS_POR_MESA = 20;
    public const MAX_NOME = 40;
    public const FORMAS_PRESENCIAIS = ['dinheiro' => 'Dinheiro', 'debito' => 'Débito', 'credito' => 'Crédito', 'pix' => 'Pix'];

    private readonly PedidoRepository $pedidos;

    /** @var array<string,array{tipo:string,valor:string}>|null */
    private ?array $regrasTaxa = null;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
        $this->pedidos = new PedidoRepository($db);
    }

    /** Toque na mesa: devolve a comanda aberta mais antiga ou, se a mesa esta livre, abre a primeira. */
    public function abrirMesa(int $idMesa): Resultado
    {
        $mesa = (new MesaRepository($this->db))->buscar($this->idRestaurante, $idMesa);
        if ($mesa === null) {
            return Resultado::falha('Mesa não encontrada. Ela pode ter sido excluída.');
        }
        $aberta = $this->abertaDaMesa($idMesa);
        if ($aberta !== null) {
            return Resultado::sucesso('', $aberta);
        }
        if ((int) $mesa['ativo'] !== 1) {
            return Resultado::falha('Esta mesa está pausada. Ative em "Gerenciar mesas" para usar.');
        }
        $this->db->beginTransaction();
        try {
            // Dois aparelhos tocando na mesma mesa livre ao mesmo tempo: o segundo espera e recebe a mesma comanda.
            $this->travarMesa($idMesa);
            $aberta = $this->abertaDaMesa($idMesa);
            $id = $aberta ?? $this->criar('mesa', $idMesa, MesaService::rotulo($mesa), self::SERVICO_PADRAO);
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
        return Resultado::sucesso($aberta !== null ? '' : MesaService::rotulo($mesa) . ' aberta.', $id);
    }

    /** Mais uma comanda na mesma mesa (ex.: alguem chegou depois), ja com o nome da pessoa se informado. */
    public function novaComanda(int $idMesa, string $nome): Resultado
    {
        $mesa = (new MesaRepository($this->db))->buscar($this->idRestaurante, $idMesa);
        if ($mesa === null) {
            return Resultado::falha('Mesa não encontrada. Ela pode ter sido excluída.');
        }
        if ((int) $mesa['ativo'] !== 1) {
            return Resultado::falha('Esta mesa está pausada. Ative em "Gerenciar mesas" para usar.');
        }
        $nome = self::limparNome($nome);
        if (mb_strlen($nome) > self::MAX_NOME) {
            return Resultado::falha('Use até ' . self::MAX_NOME . ' letras no nome.', ['nome' => 'Use até ' . self::MAX_NOME . ' letras.']);
        }
        $this->db->beginTransaction();
        try {
            $this->travarMesa($idMesa);
            $abertas = count((new MesaRepository($this->db))->comandasAbertas($this->idRestaurante, $idMesa));
            if ($abertas >= self::MAX_COMANDAS_POR_MESA) {
                $this->db->rollBack();
                return Resultado::falha('Esta mesa já tem ' . self::MAX_COMANDAS_POR_MESA . ' comandas abertas. Feche alguma antes.');
            }
            $id = $this->criar('mesa', $idMesa, MesaService::rotulo($mesa), self::SERVICO_PADRAO, $nome !== '' ? $nome : null);
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
        $rotulo = $nome !== '' ? $nome : 'Comanda ' . ($abertas + 1);
        Auditoria::registrar($this->autor, 'Comanda aberta', MesaService::rotulo($mesa) . ': nova comanda "' . $rotulo . '" (' . ($abertas + 1) . 'ª da mesa).');
        return Resultado::sucesso('Comanda de ' . $rotulo . ' aberta na ' . MesaService::rotulo($mesa) . '.', $id);
    }

    /** Da (ou troca) o nome da comanda. Vazio volta para "Comanda N". Balcao: nome do cliente. */
    public function renomear(int $id, string $nome): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $nome = self::limparNome($nome);
        if (mb_strlen($nome) > self::MAX_NOME) {
            return Resultado::falha('Use até ' . self::MAX_NOME . ' letras no nome.', ['nome' => 'Use até ' . self::MAX_NOME . ' letras.']);
        }
        $coluna = $comanda['tipo'] === 'mesa' ? 'comanda_nome' : 'cliente_nome';
        $antes = (string) ($comanda[$coluna] ?? '');
        if ($antes === $nome) {
            return Resultado::sucesso('Nada mudou.');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE pedidos SET {$coluna} = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ? AND status = 'aberto'")
                ->execute([$nome !== '' ? $nome : null, date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
            $this->pedidos->registrarEvento($this->idRestaurante, $id, 'aberto', $nome !== '' ? 'Comanda agora se chama "' . $nome . '".' : 'Nome da comanda removido.', $this->autor->id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        Auditoria::registrar($this->autor, 'Comanda renomeada', 'Pedido #' . $comanda['numero'] . ': "' . ($antes !== '' ? $antes : 'sem nome') . '" -> "' . ($nome !== '' ? $nome : 'sem nome') . '".');
        return Resultado::sucesso($nome !== '' ? 'Comanda agora é de ' . $nome . '.' : 'Nome removido da comanda.');
    }

    public const MAX_OBSERVACAO = 300;

    /** Observacao do pedido de balcao (ex.: "para viagem", "embalar separado"). Sai na impressao. Vazio apaga. */
    public function observar(int $id, string $observacao): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        if ($comanda['tipo'] !== 'balcao') {
            return Resultado::falha('A observação do pedido é usada nas vendas de balcão.');
        }
        $observacao = trim((string) preg_replace('/[ \t]+/u', ' ', str_replace(["\r\n", "\r"], "\n", $observacao)));
        $observacao = (string) preg_replace("/\n{3,}/", "\n\n", $observacao);
        if (mb_strlen($observacao) > self::MAX_OBSERVACAO) {
            return Resultado::falha('Observação com até ' . self::MAX_OBSERVACAO . ' caracteres.', ['observacao' => 'Use até ' . self::MAX_OBSERVACAO . ' caracteres.']);
        }
        if ((string) ($comanda['observacao'] ?? '') === $observacao) {
            return Resultado::sucesso('Nada mudou.');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE pedidos SET observacao = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ? AND status = 'aberto'")
                ->execute([$observacao !== '' ? $observacao : null, date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
            $this->pedidos->registrarEvento($this->idRestaurante, $id, 'aberto', $observacao !== '' ? 'Observação do pedido atualizada.' : 'Observação do pedido removida.', $this->autor->id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        return Resultado::sucesso($observacao !== '' ? 'Observação salva. Ela sai na impressão.' : 'Observação removida.');
    }

    /**
     * Comandas abertas da mesma mesa, com o nome que aparece na tela ("Joao" ou "Comanda 2").
     * @return list<array{id:int, numero:int, nome:string, tem_nome:bool, total:int, itens:int}>
     */
    public function comandasDaMesa(int $idMesa): array
    {
        $lista = [];
        foreach ((new MesaRepository($this->db))->comandasAbertas($this->idRestaurante, $idMesa) as $i => $c) {
            $lista[] = [
                'id' => (int) $c['id'],
                'numero' => (int) $c['numero'],
                'nome' => self::nomeComanda($c['comanda_nome'] ?? null, $i + 1),
                'tem_nome' => trim((string) ($c['comanda_nome'] ?? '')) !== '',
                'total' => Numero::centavos((string) $c['total']),
                'itens' => (int) $c['itens'],
            ];
        }
        return $lista;
    }

    /** Nome mostrado da comanda: o que foi digitado ou "Comanda N" (posicao na mesa). */
    public static function nomeComanda(?string $nome, int $posicao): string
    {
        $nome = trim((string) $nome);
        return $nome !== '' ? $nome : 'Comanda ' . $posicao;
    }

    public function abrirBalcao(string $nome): Resultado
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        if (mb_strlen($nome) > 60) {
            return Resultado::falha('Use até 60 caracteres no nome.');
        }
        $id = $this->criar('balcao', null, $nome !== '' ? $nome : null, '0.00');
        return Resultado::sucesso('Venda no balcão aberta.', $id);
    }

    /** Comanda com itens, pagamentos e contas prontas (centavos). @return array<string,mixed>|null */
    public function detalhes(int $id): ?array
    {
        $p = $this->pedidos->buscar($this->idRestaurante, $id);
        if ($p === null || !in_array($p['tipo'], ['mesa', 'balcao'], true)) {
            return null;
        }
        $comp = (Esquema::tem($this->db, 'complementos') ? ', i.complementos_texto' : '') . (Esquema::tem($this->db, 'combos') ? ', i.combo_lote, i.combo_nome' : '');
        $q = $this->db->prepare(
            "SELECT i.id, i.id_produto, i.nome, i.preco_unitario, i.quantidade, i.unidade, i.observacao$comp, i.total, i.criado_em, i.cancelado_em, i.motivo_cancelamento,
                    u.nome AS autor
             FROM pedido_itens i LEFT JOIN `user` u ON u.id = i.id_user
             WHERE i.id_pedido = ? AND i.id_restaurante = ? ORDER BY i.id"
        );
        $q->execute([$id, $this->idRestaurante]);
        $itens = $q->fetchAll(PDO::FETCH_ASSOC);
        $pagamentos = $this->pagamentos($id);
        $pago = array_sum(array_map(static fn (array $pg): int => Numero::centavos((string) $pg['valor']), $pagamentos));
        $mesa = $p['id_mesa'] !== null ? (new MesaRepository($this->db))->buscarQualquer($this->idRestaurante, (int) $p['id_mesa']) : null;
        // Outras comandas abertas da mesma mesa (inclui esta, se aberta): viram as abas "Joao | Comanda 2 | + Nova".
        $irmas = $p['tipo'] === 'mesa' && $p['id_mesa'] !== null ? $this->comandasDaMesa((int) $p['id_mesa']) : [];
        $nome = trim((string) ($p['comanda_nome'] ?? ''));
        if ($nome === '' && $p['tipo'] === 'mesa' && count($irmas) > 1) {
            foreach ($irmas as $irma) {
                if ($irma['id'] === (int) $p['id']) {
                    $nome = $irma['nome'];
                }
            }
        }
        $rotuloMesa = $mesa !== null ? MesaService::rotulo($mesa) : 'Mesa';
        return [
            'pedido' => $p,
            'mesa' => $mesa,
            'rotulo' => $p['tipo'] === 'mesa' ? $rotuloMesa . ($nome !== '' ? ' · ' . $nome : '') : 'Balcão' . ($p['cliente_nome'] ? ' · ' . $p['cliente_nome'] : ''),
            'rotulo_mesa' => $p['tipo'] === 'mesa' ? $rotuloMesa : 'Balcão',
            'nome' => (string) ($p['tipo'] === 'mesa' ? ($p['comanda_nome'] ?? '') : ($p['cliente_nome'] ?? '')),
            'irmas' => $irmas,
            'itens' => $itens,
            'itens_agrupados' => AgrupadorItensComanda::agrupar($itens),
            'pagamentos' => $pagamentos,
            'subtotal' => Numero::centavos((string) $p['subtotal']),
            'servico' => Numero::centavos((string) $p['taxa_servico']),
            'desconto' => Numero::centavos((string) $p['desconto']),
            'total' => Numero::centavos((string) $p['total']),
            'pago' => $pago,
            'restante' => max(0, Numero::centavos((string) $p['total']) - $pago),
            'aberta' => $p['status'] === 'aberto',
            // Taxas por forma de pagamento: ja cobradas nesta conta e as regras de cada forma (a tela so mostra; o servidor confere).
            'taxa_pagamento' => Numero::centavos((string) ($p['taxa_pagamento'] ?? '0')),
            'taxa_pagamento_rotulo' => (string) ($p['taxa_pagamento_rotulo'] ?? ''),
            'taxas' => TaxaPagamento::paraConta($this->regrasTaxa()),
        ];
    }

    /** Comandas de balcao abertas (para a tela de mesas). @return list<array<string,mixed>> */
    public function balcoesAbertos(): array
    {
        $q = $this->db->prepare("SELECT id, numero, cliente_nome, total, criado_em FROM pedidos WHERE id_restaurante = ? AND tipo = 'balcao' AND status = 'aberto' ORDER BY id");
        $q->execute([$this->idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lanca um produto na comanda. $quantidade: unidades (1 a 99) ou, no produto vendido por quilo/litro,
     * o peso/volume digitado ("0,846"). $complementos: ids escolhidos (ou id => quantidade), conferidos pela regra unica.
     * @param array<mixed> $complementos
     */
    public function lancar(int $id, int $idProduto, string|int $quantidade, string $observacao, array $complementos = []): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $observacao = trim((string) preg_replace('/\s+/u', ' ', $observacao));
        if (mb_strlen($observacao) > 140) {
            return Resultado::falha('Observação com até 140 caracteres.');
        }
        $q = $this->db->prepare('SELECT id, nome, preco, disponivel, unidade_venda, venda_minima FROM produtos WHERE id = ? AND id_restaurante = ? AND ativo = 1');
        $q->execute([$idProduto, $this->idRestaurante]);
        $produto = $q->fetch(PDO::FETCH_ASSOC);
        if ($produto === false) {
            return Resultado::falha('Produto não encontrado no cardápio.');
        }
        if ((int) $produto['disponivel'] !== 1) {
            return Resultado::falha('"' . $produto['nome'] . '" está pausado no cardápio.');
        }
        $unidade = QuantidadeVenda::unidade($produto['unidade_venda'] ?? 'un');
        $minimo = $produto['venda_minima'] !== null ? QuantidadeVenda::doBanco($produto['venda_minima']) : null;
        try {
            $milesimos = QuantidadeVenda::ler($quantidade, $unidade, $minimo);
        } catch (\InvalidArgumentException $erro) {
            return Resultado::falha(QuantidadeVenda::porPeso($unidade) ? '"' . $produto['nome'] . '": ' . $erro->getMessage() : $erro->getMessage());
        }
        // Complementos (adicionais, borda...): mesma regra do cardapio online; o preco unitario inclui os escolhidos.
        $escolhas = Complementos::formato($complementos);
        if ($escolhas === null) {
            return Resultado::falha('Complementos inválidos. Escolha de novo.');
        }
        // Combo: vira os itens separados, com o preco do combo dividido entre eles.
        $combo = $unidade === 'un' ? (Combo::carregar($this->db, $this->idRestaurante, [$idProduto])[$idProduto] ?? null) : null;
        if ($combo !== null) {
            return $this->lancarCombo($id, $produto, $combo, intdiv($milesimos, 1000), $observacao, $escolhas);
        }
        $grupos = $unidade === 'un' ? (Complementos::carregar($this->db, $this->idRestaurante, [$idProduto])[$idProduto] ?? []) : [];
        $conf = ['centavos' => 0, 'itens' => [], 'texto' => '', 'insumos' => [], 'produtos' => []];
        if ($grupos !== [] || $escolhas !== []) {
            $conf = Complementos::conferir($grupos, $escolhas);
            if (!$conf['ok']) {
                return Resultado::falha('"' . $produto['nome'] . '": ' . $conf['erro']);
            }
        }
        $preco = Numero::centavos((string) $produto['preco']) + $conf['centavos'];
        $extras = Complementos::consumoDaLinha($conf['insumos'], intdiv($milesimos, 1000));
        // Meio a Meio: alem do proprio produto, cada sabor baixa a fracao dele (2 sabores = 0,5 de cada).
        $baixar = [$idProduto => $milesimos];
        foreach (Complementos::consumoDaLinha($conf['produtos'] ?? [], intdiv($milesimos, 1000)) as $idSabor => $m) {
            $baixar[$idSabor] = ($baixar[$idSabor] ?? 0) + $m;
        }
        $comComplementos = Esquema::tem($this->db, 'complementos');
        $agora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            // Baixa primeiro: se faltar insumo, nada e gravado.
            $consumo = (new BaixaEstoque($this->db))->baixarMilesimos($this->idRestaurante, $baixar, $extras);
            $valores = [$id, $this->idRestaurante, $idProduto, mb_substr((string) $produto['nome'], 0, 180), CaixaService::dec($preco), QuantidadeVenda::paraBanco($milesimos), $unidade,
                $observacao !== '' ? $observacao : null, CaixaService::dec(QuantidadeVenda::totalCentavos($preco, $milesimos)), $this->autor->id,
                $consumo !== [] ? json_encode($consumo, JSON_THROW_ON_ERROR) : null, $agora];
            if ($comComplementos) {
                $valores[] = $conf['texto'] !== '' ? $conf['texto'] : null;
            }
            $this->db->prepare('INSERT INTO pedido_itens (id_pedido, id_restaurante, id_produto, nome, preco_unitario, quantidade, unidade, observacao, total, id_user, consumo_estoque, criado_em'
                . ($comComplementos ? ', complementos_texto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' : ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'))
                ->execute($valores);
            if ($comComplementos && $conf['itens'] !== []) {
                Complementos::gravarNoItem($this->db, $this->idRestaurante, (int) $this->db->lastInsertId(), $conf['itens']);
            }
            $this->recalcular($id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (EstoqueInsuficiente $falta) {
            $this->db->rollBack();
            $nomes = implode(', ', array_map(static fn (array $f): string => $f['nome'] . ' (tem ' . self::quantidade((string) $f['disponivel']) . ')', $falta->faltas));
            return Resultado::falha('Não deu para lançar "' . $produto['nome'] . '": falta ' . $nomes . '.');
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        return Resultado::sucesso(QuantidadeVenda::comNome($milesimos, $unidade, (string) $produto['nome']) . ' lançado.');
    }

    /**
     * Lanca N combos: as linhas separadas (mesmo combo_lote) e a baixa de estoque de todos os itens de uma vez.
     * @param array<string,mixed> $produto
     * @param array<string,mixed> $combo de Combo::carregar()
     * @param list<array{id:int, quantidade:int}> $escolhas
     */
    private function lancarCombo(int $id, array $produto, array $combo, int $n, string $observacao, array $escolhas): Resultado
    {
        $conf = Combo::conferir($combo, $escolhas);
        if (!$conf['ok']) {
            return Resultado::falha('"' . $produto['nome'] . '": ' . $conf['erro']);
        }
        $m = Combo::montar($combo, $conf['escolhidos'], Numero::centavos((string) $produto['preco']), $n);
        $nome = Combo::rotulo((string) $produto['nome'], $n);
        $lote = Combo::novoLote();
        $agora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $consumo = (new BaixaEstoque($this->db))->baixarMilesimos($this->idRestaurante, $m['produtos'], $m['insumos']);
            $ins = $this->db->prepare('INSERT INTO pedido_itens (id_pedido, id_restaurante, id_produto, nome, preco_unitario, quantidade, unidade, observacao, total, id_user,
                    consumo_estoque, criado_em, complementos_texto, combo_lote, combo_nome) VALUES (?, ?, ?, ?, ?, ?, \'un\', ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($m['linhas'] as $i => $l) {
                // O consumo do combo inteiro fica na primeira linha (cancelar devolve tudo de uma vez).
                $ins->execute([$id, $this->idRestaurante, $l['id_produto'], mb_substr($l['nome'], 0, 180), CaixaService::dec($l['centavos']),
                    QuantidadeVenda::paraBanco($l['unidades'] * 1000), $i === 0 && $observacao !== '' ? $observacao : null,
                    CaixaService::dec($l['centavos'] * $l['unidades']), $this->autor->id,
                    $i === 0 && $consumo !== [] ? json_encode($consumo, JSON_THROW_ON_ERROR) : null, $agora,
                    'Combo: ' . $nome, $lote, $nome]);
            }
            $this->recalcular($id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (EstoqueInsuficiente $falta) {
            $this->db->rollBack();
            $nomes = implode(', ', array_map(static fn (array $f): string => $f['nome'] . ' (tem ' . self::quantidade((string) $f['disponivel']) . ')', $falta->faltas));
            return Resultado::falha('Não deu para lançar "' . $produto['nome'] . '": falta ' . $nomes . '.');
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        return Resultado::sucesso($nome . ' lançado.');
    }

    public function cancelarItem(int $id, int $idItem, string $motivo): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $motivo = trim((string) preg_replace('/\s+/u', ' ', $motivo));
        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 140) {
            return Resultado::falha('Conte o motivo do cancelamento (ex.: cliente desistiu, lançado errado).');
        }
        $this->db->beginTransaction();
        try {
            $comCombo = Esquema::tem($this->db, 'combos');
            $q = $this->db->prepare('SELECT id, nome, quantidade, unidade, total, consumo_estoque' . ($comCombo ? ', combo_lote, combo_nome' : '')
                . ' FROM pedido_itens WHERE id = ? AND id_pedido = ? AND id_restaurante = ? AND cancelado_em IS NULL');
            $q->execute([$idItem, $id, $this->idRestaurante]);
            $item = $q->fetch(PDO::FETCH_ASSOC);
            if ($item === false) {
                $this->db->rollBack();
                return Resultado::falha('Item não encontrado. Ele pode já ter sido cancelado.');
            }
            // Item de combo: o combo nao pode ser alterado, sai inteiro (todas as linhas do mesmo lote).
            $linhas = [$item];
            if (($item['combo_lote'] ?? null) !== null) {
                $q = $this->db->prepare('SELECT id, nome, quantidade, unidade, total, consumo_estoque FROM pedido_itens
                    WHERE id_pedido = ? AND id_restaurante = ? AND combo_lote = ? AND cancelado_em IS NULL');
                $q->execute([$id, $this->idRestaurante, $item['combo_lote']]);
                $linhas = $q->fetchAll(PDO::FETCH_ASSOC);
                $item = ['nome' => (string) $item['combo_nome'], 'quantidade' => '1.000', 'unidade' => 'un',
                    'total' => CaixaService::dec(array_sum(array_map(static fn (array $l): int => Numero::centavos((string) $l['total']), $linhas)))];
            }
            $agora = date('Y-m-d H:i:s');
            $cancela = $this->db->prepare('UPDATE pedido_itens SET cancelado_em = ?, motivo_cancelamento = ?, id_user_cancelou = ?, consumo_estoque = NULL WHERE id = ? AND id_restaurante = ?');
            foreach ($linhas as $linha) {
                $cancela->execute([$agora, $motivo, $this->autor->id, (int) $linha['id'], $this->idRestaurante]);
                $consumo = json_decode((string) ($linha['consumo_estoque'] ?? ''), true);
                if (is_array($consumo) && $consumo !== []) {
                    (new BaixaEstoque($this->db))->devolver($this->idRestaurante, array_map('strval', $consumo));
                }
            }
            $this->recalcular($id);
            if (Numero::centavos((string) $this->pedidos->buscar($this->idRestaurante, $id)['total']) < $this->somaPagamentos($id)) {
                $this->db->rollBack();
                return Resultado::falha('A conta ficaria menor que o que já foi pago. Remova um pagamento antes de cancelar este item.');
            }
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        // Aviso de cancelamento na impressora do item (se ele ja tinha saido na producao).
        (new \Cardapim\Services\Impressao\FilaImpressao($this->db, $this->idRestaurante, '', $this->autor))
            ->cancelamento($id, array_map(static fn (array $l): int => (int) $l['id'], $linhas), $motivo);
        $itemTexto = QuantidadeVenda::comNome(QuantidadeVenda::doBanco($item['quantidade']), (string) ($item['unidade'] ?? 'un'), (string) $item['nome']);
        Auditoria::registrar($this->autor, 'Item cancelado', sprintf('%s: %s (%s). Motivo: %s. Estoque devolvido.',
            $this->rotulo($comanda), $itemTexto, Numero::reais((string) $item['total']), $motivo));
        return Resultado::sucesso($itemTexto . ' cancelado. O estoque foi devolvido.');
    }

    /** Taxa de servico (liga/desliga), desconto com motivo e quantas pessoas vao dividir. */
    public function ajustar(int $id, bool $servico, string $descontoTexto, string $motivo, string $pessoasTexto): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $erros = [];
        $desconto = trim($descontoTexto) === '' ? '0.00' : Numero::dinheiroParaDecimal($descontoTexto);
        $motivo = trim((string) preg_replace('/\s+/u', ' ', $motivo));
        if ($desconto === null) {
            $erros['desconto'] = 'Valor inválido.';
        } elseif (Numero::centavos($desconto) > 0 && mb_strlen($motivo) < 3) {
            $erros['motivo_desconto'] = 'Conte o motivo do desconto.';
        }
        if (mb_strlen($motivo) > 140) {
            $erros['motivo_desconto'] = 'Use até 140 caracteres.';
        }
        $pessoas = null;
        if (trim($pessoasTexto) !== '') {
            $pessoas = ctype_digit(trim($pessoasTexto)) ? (int) trim($pessoasTexto) : 0;
            if ($pessoas < 1 || $pessoas > 50) {
                $erros['pessoas'] = 'De 1 a 50 pessoas.';
            }
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $antes = $comanda;
        $percentual = $servico ? self::SERVICO_PADRAO : '0.00';
        $subtotal = Numero::centavos((string) $comanda['subtotal']);
        $taxa = self::servico($subtotal, $percentual);
        // Taxas de pagamento ja cobradas ficam fora do desconto e da comparacao com o que foi pago (os dois lados sem taxa).
        $taxasPagamento = Numero::centavos((string) ($comanda['taxa_pagamento'] ?? '0'));
        $entrega = Numero::centavos((string) ($comanda['taxa_entrega'] ?? '0'));
        if (Numero::centavos((string) $desconto) > $subtotal + $taxa) {
            return Resultado::falha('O desconto não pode ser maior que a conta.', ['desconto' => 'Máximo ' . Numero::reais(CaixaService::dec($subtotal + $taxa)) . '.']);
        }
        if ($subtotal + $taxa - Numero::centavos((string) $desconto) < $this->somaPagamentos($id) - $taxasPagamento) {
            return Resultado::falha('A conta ficaria menor que o que já foi pago. Remova um pagamento antes.');
        }
        $this->db->beginTransaction();
        try {
            // Tudo num UPDATE so: o banco confere "total = itens + entrega + servico + taxas de pagamento - desconto" (CHECK) a cada comando.
            $descontoCent = Numero::centavos((string) $desconto);
            $this->db->prepare('UPDATE pedidos SET servico_percentual = ?, taxa_servico = ?, desconto = ?, total = ?, motivo_desconto = ?, pessoas = ?, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ?')
                ->execute([$percentual, CaixaService::dec($taxa), CaixaService::dec($descontoCent), CaixaService::dec($subtotal + $entrega + $taxa + $taxasPagamento - $descontoCent),
                    $descontoCent > 0 ? $motivo : null, $pessoas, date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
            $this->recalcular($id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        if (Numero::centavos((string) $desconto) !== Numero::centavos((string) $antes['desconto'])) {
            Auditoria::registrar($this->autor, 'Desconto na conta', sprintf('%s: desconto %s -> %s. Motivo: %s.', $this->rotulo($comanda),
                Numero::reais((string) $antes['desconto']), Numero::reais((string) $desconto), $motivo !== '' ? $motivo : '-'));
        }
        return Resultado::sucesso('Conta atualizada.');
    }

    public function transferir(int $id, int $idMesaDestino): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $mesas = new MesaRepository($this->db);
        $destino = $mesas->buscar($this->idRestaurante, $idMesaDestino);
        if ($destino === null || (int) $destino['ativo'] !== 1) {
            return Resultado::falha('Escolha uma mesa.');
        }
        if ($comanda['tipo'] === 'mesa' && (int) $comanda['id_mesa'] === $idMesaDestino) {
            return Resultado::falha('A comanda já está nesta mesa.');
        }
        // Mesa ocupada tambem serve: a comanda entra como mais uma comanda dela (cada um continua com a sua conta).
        $juntou = $mesas->temComandaAberta($this->idRestaurante, $idMesaDestino);
        $origem = $this->rotulo($comanda);
        try {
            $this->db->beginTransaction();
            // Do balcao para a mesa: o nome do cliente do balcao vira o nome da comanda.
            $nomeComanda = $comanda['tipo'] === 'balcao' && trim((string) $comanda['cliente_nome']) !== ''
                ? mb_substr(trim((string) $comanda['cliente_nome']), 0, self::MAX_NOME)
                : ($comanda['comanda_nome'] ?? null);
            $this->db->prepare("UPDATE pedidos SET tipo = 'mesa', origem = 'mesa', id_mesa = ?, cliente_nome = ?, comanda_nome = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ? AND status = 'aberto'")
                ->execute([$idMesaDestino, MesaService::rotulo($destino), $nomeComanda, date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
            $this->pedidos->registrarEvento($this->idRestaurante, $id, 'aberto', 'Transferida de ' . $origem . ' para ' . MesaService::rotulo($destino) . '.', $this->autor->id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.atualizada', ['id' => $id]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        Auditoria::registrar($this->autor, 'Comanda transferida', 'Pedido #' . $comanda['numero'] . ': ' . $origem . ' -> ' . MesaService::rotulo($destino) . '.');
        return Resultado::sucesso('Comanda levada para a ' . MesaService::rotulo($destino) . ($juntou ? ', junto com as outras comandas da mesa.' : '.'));
    }

    /**
     * Recebe um pagamento. Dinheiro: "recebido" pode ser maior que o valor e a diferenca vira troco.
     * Cartao e Pix: a taxa da forma (se o restaurante cadastrou) e somada ao valor desta parte da conta.
     * $taxaVistaTexto: taxa em centavos que a tela mostrou. Se nao bate com a do servidor (tela sem JavaScript ou regra
     * trocada no meio), nada e gravado e a mensagem mostra a taxa certa para o caixa cobrar e confirmar.
     * Quando a conta fica paga, a comanda e concluida (a mesa fica livre se nao houver outra comanda nela).
     */
    public function pagar(int $id, string $forma, string $valorTexto, string $recebidoTexto, string $taxaVistaTexto = ''): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        if (!isset(self::FORMAS_PRESENCIAIS[$forma])) {
            return Resultado::falha('Escolha a forma de pagamento.', ['forma' => 'Escolha a forma de pagamento.']);
        }
        $caixa = (new CaixaRepository($this->db))->aberto($this->idRestaurante);
        if ($caixa === null) {
            return Resultado::falha('Abra o caixa para receber pagamentos.');
        }
        $total = Numero::centavos((string) $comanda['total']);
        $restante = $total - $this->somaPagamentos($id);
        if ($total <= 0) {
            return Resultado::falha('A conta está zerada. Lance itens ou cancele a comanda.');
        }
        $valor = trim($valorTexto) === '' ? CaixaService::dec($restante) : Numero::dinheiroParaDecimal($valorTexto);
        if ($valor === null || Numero::centavos($valor) <= 0) {
            return Resultado::falha('Digite o valor a pagar.', ['valor' => 'Digite um valor maior que zero.']);
        }
        $centavos = Numero::centavos($valor);
        $troco = 0;
        if ($forma === 'dinheiro' && trim($recebidoTexto) !== '') {
            $recebido = Numero::dinheiroParaDecimal($recebidoTexto);
            if ($recebido === null || Numero::centavos($recebido) < min($centavos, $restante)) {
                return Resultado::falha('O valor recebido é menor que o valor a pagar.', ['recebido' => 'Recebido precisa ser pelo menos ' . Numero::reais(CaixaService::dec(min($centavos, $restante))) . '.']);
            }
            // Recebeu mais que a conta: so o que falta abate da conta, o resto e troco.
            $centavos = min($centavos, $restante);
            $troco = Numero::centavos($recebido) - $centavos;
        } elseif ($forma === 'dinheiro' && $centavos > $restante) {
            $troco = $centavos - $restante;
            $centavos = $restante;
        }
        if ($centavos > $restante) {
            return Resultado::falha('Valor maior que o que falta pagar (' . Numero::reais(CaixaService::dec($restante)) . ').', ['valor' => 'Falta pagar ' . Numero::reais(CaixaService::dec($restante)) . '.']);
        }
        if ($troco > 100_000) {
            return Resultado::falha('Troco muito alto. Confira o valor recebido.', ['recebido' => 'Confira o valor recebido.']);
        }
        $taxa = $forma === 'dinheiro' ? ['centavos' => 0, 'rotulo' => null] : TaxaPagamento::daConta($this->regrasTaxa(), $forma, $centavos);
        $aviso = self::conferirTaxa($forma, $centavos, $taxa['centavos'], $taxaVistaTexto);
        if ($aviso !== null) {
            return $aviso;
        }
        $agora = date('Y-m-d H:i:s');
        $concluiu = false;
        $this->db->beginTransaction();
        try {
            // Trava a conta: dois aparelhos recebendo ao mesmo tempo nao passam do total.
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->db->prepare('SELECT id FROM pedidos WHERE id = ? AND id_restaurante = ? FOR UPDATE')->execute([$id, $this->idRestaurante]);
            }
            $atual = $this->pedidos->buscar($this->idRestaurante, $id);
            if ($atual === null || $atual['status'] !== 'aberto') {
                $this->db->rollBack();
                return Resultado::falha('Esta comanda já foi fechada.');
            }
            $falta = Numero::centavos((string) $atual['total']) - $this->somaPagamentos($id);
            if ($centavos > $falta) {
                $this->db->rollBack();
                return Resultado::falha('A conta mudou enquanto você recebia. Confira o valor que falta pagar.');
            }
            self::registrarPagamento($this->db, $this->idRestaurante, $id, (int) $caixa['id'], $forma, $centavos, $taxa['centavos'], $taxa['rotulo'],
                $troco, $this->autor->id, $agora);
            if ($falta - $centavos <= 0) {
                $concluiu = $this->concluir($id, (int) $caixa['id'], $agora);
            }
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', $concluiu ? 'comanda.fechada' : 'comanda.atualizada', ['id' => $id]);
            TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.pagamento', ['caixa' => (int) $caixa['id']]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        if ($concluiu) {
            // Nota fiscal: só entra na fila se o restaurante usa o módulo fiscal. Nunca interfere na venda.
            FiscalEmissao::solicitar($this->db, $this->idRestaurante, $id);
        }
        $msg = self::FORMAS_PRESENCIAIS[$forma] . ' ' . Numero::reais(CaixaService::dec($centavos + $taxa['centavos'])) . ' recebido'
            . ($taxa['centavos'] > 0 ? ' (inclui taxa de ' . Numero::reais(CaixaService::dec($taxa['centavos'])) . ').' : '.')
            . ($troco > 0 ? ' Troco: ' . Numero::reais(CaixaService::dec($troco)) . '.' : '');
        if ($concluiu) {
            $fechada = $this->pedidos->buscar($this->idRestaurante, $id) ?? $comanda;
            Auditoria::registrar($this->autor, 'Conta fechada', sprintf('%s: pedido #%d, total %s%s.', $this->rotulo($comanda), (int) $comanda['numero'],
                Numero::reais((string) $fechada['total']), Numero::centavos((string) ($fechada['taxa_pagamento'] ?? '0')) > 0
                    ? ' (com ' . Numero::reais((string) $fechada['taxa_pagamento']) . ' de taxas de pagamento)' : ''));
            $outras = $comanda['tipo'] === 'mesa' ? count((new MesaRepository($this->db))->comandasAbertas($this->idRestaurante, (int) $comanda['id_mesa'])) : 0;
            $fim = $comanda['tipo'] !== 'mesa' ? '.' : ($outras > 0 ? '. A mesa continua com ' . $outras . ($outras === 1 ? ' comanda aberta.' : ' comandas abertas.') : ' e mesa liberada.');
            return Resultado::sucesso($msg . ' Conta fechada' . $fim, $id);
        }
        return Resultado::sucesso($msg);
    }

    public function removerPagamento(int $id, int $idPagamento): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        $taxaCol = Esquema::tem($this->db, 'taxas_contas') ? 'taxa' : "'0.00' AS taxa";
        $q = $this->db->prepare("SELECT forma, valor, $taxaCol FROM pedido_pagamentos WHERE id = ? AND id_pedido = ? AND id_restaurante = ?");
        $q->execute([$idPagamento, $id, $this->idRestaurante]);
        $pg = $q->fetch(PDO::FETCH_ASSOC);
        if ($pg === false) {
            return Resultado::falha('Pagamento não encontrado.');
        }
        // Pagamento e taxa saem juntos: a taxa daquele pagamento deixa a conta no mesmo comando.
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM pedido_pagamentos WHERE id = ? AND id_pedido = ? AND id_restaurante = ?')->execute([$idPagamento, $id, $this->idRestaurante]);
            self::atualizarTaxaDaConta($this->db, $this->idRestaurante, $id, date('Y-m-d H:i:s'));
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        TempoReal::publicar($this->idRestaurante, 'caixa', 'caixa.pagamento', ['pedido' => $id]);
        $taxaPg = Numero::centavos((string) $pg['taxa']);
        Auditoria::registrar($this->autor, 'Pagamento removido', sprintf('%s: %s %s%s.', $this->rotulo($comanda), self::FORMAS_PRESENCIAIS[$pg['forma']] ?? $pg['forma'],
            Numero::reais((string) $pg['valor']), $taxaPg > 0 ? ' (taxa de ' . Numero::reais(CaixaService::dec($taxaPg)) . ' devolvida)' : ''));
        return Resultado::sucesso($taxaPg > 0 ? 'Pagamento removido. A taxa de ' . Numero::reais(CaixaService::dec($taxaPg)) . ' saiu da conta.' : 'Pagamento removido.');
    }

    public function cancelar(int $id, string $motivo): Resultado
    {
        $comanda = $this->aberta($id);
        if ($comanda instanceof Resultado) {
            return $comanda;
        }
        // Motivo opcional (se escrever, fica no historico e no log).
        $motivo = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $motivo)), 0, 140);
        if ($motivo === '') {
            $motivo = 'Cancelada no caixa';
        }
        if ($this->somaPagamentos($id) > 0) {
            return Resultado::falha('Esta conta já tem pagamento. Remova os pagamentos antes de cancelar.');
        }
        (new CancelarPedido($this->db))->cancelar($this->idRestaurante, $id, $motivo, $this->autor->id);
        TempoReal::publicar($this->idRestaurante, 'mesas', 'comanda.fechada', ['id' => $id]);
        Auditoria::registrar($this->autor, 'Comanda cancelada', sprintf('%s: pedido #%d (%s). Motivo: %s. Estoque devolvido.',
            $this->rotulo($comanda), (int) $comanda['numero'], Numero::reais((string) $comanda['total']), $motivo));
        // Ainda tem gente na mesa? Devolve a proxima comanda aberta dela para a tela ir direto para la.
        $proxima = $comanda['tipo'] === 'mesa' ? $this->abertaDaMesa((int) $comanda['id_mesa']) : null;
        return Resultado::sucesso('Comanda cancelada. O estoque dos itens foi devolvido.', $proxima);
    }

    /** @return list<array<string,mixed>> */
    public function pagamentos(int $id): array
    {
        $taxa = Esquema::tem($this->db, 'taxas_contas') ? 'pg.taxa, pg.taxa_rotulo' : "'0.00' AS taxa, NULL AS taxa_rotulo";
        $q = $this->db->prepare(
            "SELECT pg.id, pg.forma, pg.valor, $taxa, pg.troco, pg.criado_em, u.nome AS autor
             FROM pedido_pagamentos pg LEFT JOIN `user` u ON u.id = pg.id_user
             WHERE pg.id_pedido = ? AND pg.id_restaurante = ? ORDER BY pg.id"
        );
        $q->execute([$id, $this->idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** "2.0" / "0.250 kg" -> "2" / "0,25 kg" (quantidade de insumo legivel na mensagem). */
    private static function quantidade(string $valor): string
    {
        return (string) preg_replace_callback('/\d+(?:\.\d+)?/', static fn (array $m): string => rtrim(rtrim(number_format((float) $m[0], 3, ',', '.'), '0'), ','), $valor);
    }

    /** Taxa de servico em centavos (arredonda meio centavo para cima). */
    public static function servico(int $subtotal, string $percentual): int
    {
        $pct = Numero::centavos($percentual); // 10.00 -> 1000
        return intdiv($subtotal * $pct + 5000, 10000);
    }

    /** Cria a comanda. Pode rodar dentro de uma transacao ja aberta (abrirMesa/novaComanda travam a mesa antes). */
    private function criar(string $tipo, ?int $idMesa, ?string $nome, string $servico, ?string $comandaNome = null): int
    {
        $agora = date('Y-m-d H:i:s');
        $chave = bin2hex(random_bytes(16));
        $propria = !$this->db->inTransaction();
        if ($propria) {
            $this->db->beginTransaction();
        }
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->db->prepare('SELECT id FROM restaurantes WHERE id = ? FOR UPDATE')->execute([$this->idRestaurante]);
            }
            $n = $this->db->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM pedidos WHERE id_restaurante = ?');
            $n->execute([$this->idRestaurante]);
            $numero = (int) $n->fetchColumn();
            $this->db->prepare("INSERT INTO pedidos (id_restaurante, numero, id_cliente, id_user, id_mesa, origem, tipo, status, forma_pagamento, pagamento_status,
                    subtotal, taxa_entrega, servico_percentual, taxa_servico, desconto, total, cliente_nome, comanda_nome, chave_idempotencia, criado_em, atualizado_em)
                VALUES (?, ?, NULL, ?, ?, ?, ?, 'aberto', NULL, 'pendente', '0.00', '0.00', ?, '0.00', '0.00', '0.00', ?, ?, ?, ?, ?)")
                ->execute([$this->idRestaurante, $numero, $this->autor->id, $idMesa, $tipo, $tipo, $servico, $nome, $comandaNome, $chave, $agora, $agora]);
            $id = (int) $this->pedidos->porChave($this->idRestaurante, $chave)['id'];
            $this->pedidos->registrarEvento($this->idRestaurante, $id, 'aberto', ($tipo === 'mesa' ? 'Comanda aberta' . ($comandaNome !== null ? ' para ' . $comandaNome : '') : 'Venda no balcão aberta') . ' por ' . $this->autor->nome . '.', $this->autor->id);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.aberta', ['id' => $id]);
            if ($propria) {
                $this->db->commit();
            }
            return $id;
        } catch (Throwable $erro) {
            if ($propria && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
    }

    /** Trava a linha da mesa ate o fim da transacao (MySQL), para duas abas nao abrirem comandas ao mesmo tempo. */
    private function travarMesa(int $idMesa): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->db->prepare('SELECT id FROM mesas WHERE id = ? AND id_restaurante = ? FOR UPDATE')->execute([$idMesa, $this->idRestaurante]);
        }
    }

    private static function limparNome(string $nome): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', $nome));
    }

    /** Fecha a comanda paga: forma (unica ou 'misto'), caixa, datas e linha do tempo. */
    private function concluir(int $id, int $idCaixa, string $agora): bool
    {
        return self::fecharPaga($this->db, $this->idRestaurante, $id, $idCaixa, $agora, $this->autor->id, 'Conta paga e fechada por ' . $this->autor->nome . '.');
    }

    /**
     * Fecha a comanda que ficou paga (usado aqui e pelo Pix da mesa, que pode ser confirmado sem ninguem logado).
     * Deve rodar dentro da transacao do pagamento. @return bool false se ela ja estava fechada
     */
    public static function fecharPaga(PDO $db, int $idRestaurante, int $id, int $idCaixa, string $agora, ?int $idUser, string $descricao): bool
    {
        $q = $db->prepare('SELECT DISTINCT forma FROM pedido_pagamentos WHERE id_pedido = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        $formas = $q->fetchAll(PDO::FETCH_COLUMN);
        $forma = count($formas) === 1 ? ($formas[0] === 'pix' ? 'pix_entrega' : $formas[0]) : 'misto';
        $u = $db->prepare("UPDATE pedidos SET status = 'concluido', pagamento_status = 'pago', forma_pagamento = ?, id_caixa = ?, pago_em = ?, concluido_em = ?, atualizado_em = ?
            WHERE id = ? AND id_restaurante = ? AND status = 'aberto'");
        $u->execute([$forma, $idCaixa, $agora, $agora, $agora, $id, $idRestaurante]);
        if ($u->rowCount() !== 1) {
            return false;
        }
        (new PedidoRepository($db))->registrarEvento($idRestaurante, $id, 'concluido', $descricao, $idUser);
        return true;
    }

    /**
     * Grava um pagamento da conta com a taxa (quando houver) e soma a taxa no pedido. Roda DENTRO da transacao de quem chama.
     * $base = parte da conta paga nesta forma; o pagamento fica com valor = base + taxa (o que passou na maquininha / no Pix).
     * Usado pelo caixa, pelo garcom e pela confirmacao do Pix da mesa.
     */
    public static function registrarPagamento(PDO $db, int $idRestaurante, int $idPedido, ?int $idCaixa, string $forma, int $base, int $taxa, ?string $rotulo,
        int $troco, ?int $idUser, string $agora): void
    {
        $comTaxa = Esquema::tem($db, 'taxas_contas');
        if (!$comTaxa || $forma === 'dinheiro') {
            $taxa = 0;
        }
        if ($comTaxa) {
            $db->prepare('INSERT INTO pedido_pagamentos (id_pedido, id_restaurante, id_caixa, forma, valor, taxa, taxa_rotulo, troco, id_user, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$idPedido, $idRestaurante, $idCaixa, $forma, CaixaService::dec($base + $taxa), CaixaService::dec($taxa), $taxa > 0 ? $rotulo : null,
                    CaixaService::dec($troco), $idUser, $agora]);
        } else {
            $db->prepare('INSERT INTO pedido_pagamentos (id_pedido, id_restaurante, id_caixa, forma, valor, troco, id_user, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$idPedido, $idRestaurante, $idCaixa, $forma, CaixaService::dec($base), CaixaService::dec($troco), $idUser, $agora]);
        }
        if ($taxa > 0) {
            self::atualizarTaxaDaConta($db, $idRestaurante, $idPedido, $agora);
        }
    }

    /**
     * Taxa de pagamento da comanda = soma das taxas dos pagamentos; total refeito no mesmo UPDATE (o CHECK do banco confere).
     * Rotulo: o da unica taxa, ou "Taxas de pagamento" quando ha formas diferentes.
     */
    public static function atualizarTaxaDaConta(PDO $db, int $idRestaurante, int $idPedido, string $agora): void
    {
        if (!Esquema::tem($db, 'taxas_contas')) {
            return;
        }
        $q = $db->prepare('SELECT taxa, taxa_rotulo FROM pedido_pagamentos WHERE id_pedido = ? AND id_restaurante = ?');
        $q->execute([$idPedido, $idRestaurante]);
        $soma = 0;
        $rotulos = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $pg) {
            $t = Numero::centavos((string) $pg['taxa']);
            if ($t > 0) {
                $soma += $t;
                $rotulos[] = $pg['taxa_rotulo'] !== null ? (string) $pg['taxa_rotulo'] : null;
            }
        }
        $dec = CaixaService::dec($soma);
        $db->prepare('UPDATE pedidos SET taxa_pagamento = ?, taxa_pagamento_rotulo = ?, total = subtotal + taxa_entrega + taxa_servico + ? - desconto, atualizado_em = ?
            WHERE id = ? AND id_restaurante = ?')
            ->execute([$dec, TaxaPagamento::rotuloDaConta($rotulos), $dec, $agora, $idPedido, $idRestaurante]);
    }

    /**
     * Regras de taxa usadas nas contas do restaurante (vazio antes da migracao: tudo como antes, sem taxa).
     * @return array<string,array{tipo:string,valor:string}>
     */
    public static function regrasDasContas(PDO $db, int $idRestaurante): array
    {
        return Esquema::tem($db, 'taxas_contas') ? (new TaxaPagamentoRepository($db))->regras($idRestaurante) : [];
    }

    /**
     * Confere a taxa que a tela mostrou. null = pode gravar. Senao, a falha traz a taxa certa em erros['taxa_vista']
     * (a tela reenvia esse valor ao tocar em Receber de novo).
     */
    public static function conferirTaxa(string $forma, int $base, int $taxa, string $vistaTexto): ?Resultado
    {
        $vista = trim($vistaTexto);
        if ($taxa === 0 && ($vista === '' || $vista === '0')) {
            return null;
        }
        if ($vista !== '' && ctype_digit($vista) && strlen($vista) <= 9 && (int) $vista === $taxa) {
            return null;
        }
        if ($taxa === 0) {
            return Resultado::falha('A taxa desta forma de pagamento mudou. Confira o valor e toque em Receber de novo.', ['taxa_vista' => '0']);
        }
        $nome = self::FORMAS_PRESENCIAIS[$forma] ?? 'Esta forma';
        return Resultado::falha(sprintf('%s tem taxa de %s. Cobre %s do cliente e toque em Receber de novo.', $nome,
            Numero::reais(CaixaService::dec($taxa)), Numero::reais(CaixaService::dec($base + $taxa))), ['taxa_vista' => (string) $taxa]);
    }

    /** @return array<string,array{tipo:string,valor:string}> */
    private function regrasTaxa(): array
    {
        return $this->regrasTaxa ??= self::regrasDasContas($this->db, $this->idRestaurante);
    }

    /** Soma do que ja foi pago na comanda, em centavos. */
    public static function totalPago(PDO $db, int $idRestaurante, int $id): int
    {
        $q = $db->prepare('SELECT valor FROM pedido_pagamentos WHERE id_pedido = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        return array_sum(array_map(static fn ($v): int => Numero::centavos((string) $v), $q->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function recalcular(int $id): void
    {
        $q = $this->db->prepare('SELECT total FROM pedido_itens WHERE id_pedido = ? AND id_restaurante = ? AND cancelado_em IS NULL');
        $q->execute([$id, $this->idRestaurante]);
        $subtotal = 0;
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $subtotal += Numero::centavos((string) $t);
        }
        $p = $this->pedidos->buscar($this->idRestaurante, $id);
        $taxa = self::servico($subtotal, (string) $p['servico_percentual']);
        $desconto = min(Numero::centavos((string) $p['desconto']), $subtotal + $taxa);
        // Taxas de pagamento ja cobradas continuam na conta (o CHECK do banco exige o total com elas).
        $extras = Numero::centavos((string) ($p['taxa_entrega'] ?? '0')) + Numero::centavos((string) ($p['taxa_pagamento'] ?? '0'));
        $this->db->prepare('UPDATE pedidos SET subtotal = ?, taxa_servico = ?, desconto = ?, total = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ?')
            ->execute([CaixaService::dec($subtotal), CaixaService::dec($taxa), CaixaService::dec($desconto), CaixaService::dec($subtotal + $taxa + $extras - $desconto),
                date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
    }

    private function somaPagamentos(int $id): int
    {
        return self::totalPago($this->db, $this->idRestaurante, $id);
    }

    private function abertaDaMesa(int $idMesa): ?int
    {
        $q = $this->db->prepare("SELECT id FROM pedidos WHERE id_mesa = ? AND id_restaurante = ? AND status = 'aberto' ORDER BY id LIMIT 1");
        $q->execute([$idMesa, $this->idRestaurante]);
        $id = $q->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<string,mixed>|Resultado */
    private function aberta(int $id): array|Resultado
    {
        $p = $this->pedidos->buscar($this->idRestaurante, $id);
        if ($p === null || !in_array($p['tipo'], ['mesa', 'balcao'], true)) {
            return Resultado::falha('Comanda não encontrada.');
        }
        if ($p['status'] !== 'aberto') {
            return Resultado::falha('Esta comanda já foi fechada.');
        }
        return $p;
    }

    /** @param array<string,mixed> $p */
    private function rotulo(array $p): string
    {
        return $p['tipo'] === 'mesa'
            ? (string) ($p['cliente_nome'] ?: 'Mesa') . (($p['comanda_nome'] ?? '') !== '' ? ' (' . $p['comanda_nome'] . ')' : '')
            : 'Balcão' . ($p['cliente_nome'] ? ' (' . $p['cliente_nome'] . ')' : '');
    }
}
