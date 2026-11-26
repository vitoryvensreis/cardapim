<?php

declare(strict_types=1);

namespace Cardapim\Services\Impressao;

use Cardapim\Modules\Catalog\Combo;
use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Repositories\Desktop\ImpressaoRepository;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Gerencia > Impressoras > Testar impressao: monta, sem gravar nada no pedido, os mesmos cupons da impressao real
 * (mesma divisao por produto e mesmo CupomImpressao) para mostrar o que sai em cada impressora e por que.
 * "Imprimir de verdade" grava os cupons na fila do Agent com TESTE no topo (sem pedido ligado).
 * Complementos e combos seguem a mesma regra do caixa (Complementos::conferir, Combo::conferir/montar); so o estoque
 * e ignorado (e um teste: nada e baixado, entao opcao "acabou" tambem pode ser escolhida).
 */
final class TesteImpressao
{
    public const SITUACOES = ['delivery', 'garcom', 'caixa', 'cancelamento', 'conta'];
    public const MAX_ITENS = 20;

    private readonly FilaImpressao $fila;

    public function __construct(
        private readonly PDO $db,
        private readonly int $idRestaurante,
        private readonly string $restaurante,
        private readonly UsuarioLogado $autor,
    ) {
        $this->fila = new FilaImpressao($db, $idRestaurante, $restaurante, $autor);
    }

    /**
     * @param list<array{id_produto:int,quantidade:int,observacao:string,complementos?:list<array{id:int,quantidade:int}>}> $pedidos
     * @return array{cupons:list<array<string,mixed>>,avisos:list<string>,mensagem?:string}|Resultado
     */
    public function executar(string $situacao, array $pedidos, ?int $idImpressora, bool $imprimir): array|Resultado
    {
        if (!in_array($situacao, self::SITUACOES, true)) {
            return Resultado::falha('Escolha a situação do teste.');
        }
        if (!$this->fila->disponivel()) {
            return Resultado::falha('As impressoras ainda estão sendo ativadas.');
        }
        // A mesma regra da impressao real: com o aviso de cancelamento desligado, nada sai.
        if ($situacao === 'cancelamento' && !(new ImpressoraRepository($this->db))->cancelamentoAtivo($this->idRestaurante)) {
            return Resultado::falha('O aviso de cancelamento está desligado (acima, em "Aviso de cancelamento"): ao cancelar um item, nada sai na impressora.');
        }
        // Delivery: produto marcado "só no local" nunca chega num pedido do cardápio online.
        $soNoLocal = $situacao === 'delivery' ? $this->soNoLocal(array_column($pedidos, 'id_produto')) : [];
        $itens = $this->itens(array_values(array_filter($pedidos, static fn (array $p): bool => !isset($soNoLocal[$p['id_produto']]))));
        if ($itens instanceof Resultado) {
            return $itens;
        }
        if ($itens === []) {
            return Resultado::falha($soNoLocal !== []
                ? 'Os produtos do teste são vendidos só no local e não entram em pedido de delivery: ' . implode(', ', $soNoLocal) . '.'
                : 'Adicione pelo menos um produto ao teste.');
        }
        $saida = $situacao === 'conta' ? $this->conta($itens, $idImpressora) : $this->producao($situacao, $itens);
        if (is_array($saida) && $soNoLocal !== []) {
            $saida['avisos'][] = 'Vendido só no local, não entra em pedido de delivery: ' . implode(', ', $soNoLocal) . '.';
        }
        if ($saida instanceof Resultado || !$imprimir) {
            return $saida;
        }
        return $this->imprimir($saida, $situacao);
    }

    /**
     * Produtos marcados "so no local" (vende_delivery = 0), por id => nome.
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function soNoLocal(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !Esquema::tem($this->db, 'produto_opcoes')) {
            return [];
        }
        $q = $this->db->prepare('SELECT id, nome FROM produtos WHERE id_restaurante = ? AND vende_delivery = 0 AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute([$this->idRestaurante, ...$ids]);
        $saida = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $saida[(int) $r['id']] = (string) $r['nome'];
        }
        return $saida;
    }

    /**
     * Grupos de complementos e combo de cada produto, para a tela do teste escolher as opcoes como no caixa.
     * Tudo aparece disponivel (o teste nao baixa estoque).
     * @param list<int> $idsProdutos
     * @return array<int, array{grupos:list<array<string,mixed>>, combo:?array<string,mixed>}> so produtos com escolhas
     */
    public static function escolhasDosProdutos(PDO $db, int $idRestaurante, array $idsProdutos): array
    {
        [$grupos, $combos] = self::regras($db, $idRestaurante, $idsProdutos);
        $saida = [];
        foreach ($combos as $id => $combo) {
            $pub = Combo::publico($combo);
            $saida[$id] = ['grupos' => $pub['grupos'], 'combo' => ['inclusos' => $pub['inclusos'], 'maximo_escolhas' => $pub['maximo_escolhas']]];
        }
        foreach ($grupos as $id => $lista) {
            if (!isset($saida[$id]) && $lista !== []) {
                $saida[$id] = ['grupos' => Complementos::publico($lista), 'combo' => null];
            }
        }
        return $saida;
    }

    /**
     * Regras de complementos e combos dos produtos, com todas as opcoes e itens vendaveis (teste sem estoque).
     * @param list<int> $ids
     * @return array{0:array<int,list<array<string,mixed>>>, 1:array<int,array<string,mixed>>}
     */
    private static function regras(PDO $db, int $idRestaurante, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [[], []];
        }
        $liberar = static function (array $lista): array {
            foreach ($lista as &$g) {
                foreach ($g['opcoes'] as &$o) {
                    $o['vendavel'] = true;
                }
                unset($o);
            }
            unset($g);
            return $lista;
        };
        $grupos = array_map($liberar, Complementos::carregar($db, $idRestaurante, $ids));
        $combos = Combo::carregar($db, $idRestaurante, $ids);
        foreach ($combos as &$c) {
            $c['grupos'] = $liberar($c['grupos']);
            foreach ($c['fixos'] as &$f) {
                $f['disponivel'] = true;
            }
            unset($f);
        }
        unset($c);
        return [$grupos, $combos];
    }

    /**
     * Itens no mesmo formato do banco (pedido_itens), so com produtos ativos deste restaurante. Complementos viram o
     * texto do item (e somam no preco); combo vira os itens separados com o mesmo combo_lote, como no caixa.
     * @param list<array{id_produto:int,quantidade:int,observacao:string,complementos?:list<array{id:int,quantidade:int}>}> $pedidos
     * @return list<array<string,mixed>>|Resultado
     */
    private function itens(array $pedidos): array|Resultado
    {
        $repo = new ProdutoRepository($this->db);
        $pedidos = array_slice($pedidos, 0, self::MAX_ITENS);
        [$grupos, $combos] = self::regras($this->db, $this->idRestaurante, array_column($pedidos, 'id_produto'));
        $itens = [];
        $n = 0;
        $linha = static function (array $base) use (&$n): array {
            $n++;
            return $base + ['id' => $n, 'unidade' => 'un', 'cancelado_em' => null, 'combo_lote' => null, 'combo_nome' => null, 'complementos_texto' => ''];
        };
        foreach ($pedidos as $pedido) {
            $p = $repo->buscar($this->idRestaurante, $pedido['id_produto']);
            if ($p === null || (int) $p['ativo'] !== 1) {
                continue;
            }
            $qtd = max(1, min(99, $pedido['quantidade']));
            $escolhas = $pedido['complementos'] ?? [];
            $porUnidade = ($p['unidade_venda'] ?? 'un') === 'un';
            $combo = $porUnidade ? ($combos[(int) $p['id']] ?? null) : null;
            if ($combo !== null) {
                $conf = Combo::conferir($combo, $escolhas);
                if (!$conf['ok']) {
                    return Resultado::falha('"' . $p['nome'] . '": ' . $conf['erro']);
                }
                $m = Combo::montar($combo, $conf['escolhidos'], Numero::centavos((string) $p['preco']), $qtd);
                $nome = Combo::rotulo((string) $p['nome'], $qtd);
                $lote = Combo::novoLote();
                foreach ($m['linhas'] as $i => $l) {
                    $itens[] = $linha([
                        'id_produto' => $l['id_produto'], 'nome' => $l['nome'], 'preco_unitario' => CaixaService::dec($l['centavos']),
                        'quantidade' => $l['unidades'] . '.000', 'observacao' => $i === 0 ? $pedido['observacao'] : '',
                        'total' => CaixaService::dec($l['centavos'] * $l['unidades']),
                        'complementos_texto' => 'Combo: ' . $nome, 'combo_lote' => $lote, 'combo_nome' => $nome,
                    ]);
                }
                continue;
            }
            $lista = $porUnidade ? ($grupos[(int) $p['id']] ?? []) : [];
            $conf = ['centavos' => 0, 'texto' => ''];
            if ($lista !== [] || $escolhas !== []) {
                $conf = Complementos::conferir($lista, $escolhas);
                if (!$conf['ok']) {
                    return Resultado::falha('"' . $p['nome'] . '": ' . $conf['erro']);
                }
            }
            $preco = Numero::centavos((string) $p['preco']) + (int) $conf['centavos'];
            $itens[] = $linha([
                'id_produto' => (int) $p['id'], 'nome' => (string) $p['nome'], 'preco_unitario' => CaixaService::dec($preco),
                'quantidade' => $qtd . '.000', 'observacao' => $pedido['observacao'], 'complementos_texto' => (string) $conf['texto'],
                'total' => CaixaService::dec($preco * $qtd),
            ]);
        }
        return $itens;
    }

    /**
     * Delivery aceito, Lancar do garcom, Novos itens do caixa: um cupom por impressora, cada item na impressora do
     * produto e o sem marcacao na padrao. Impressora sem item aparece como "nao sai nada".
     * @param list<array<string,mixed>> $itens
     */
    private function producao(string $situacao, array $itens): array|Resultado
    {
        if (!$this->fila->producaoAtiva()) {
            return Resultado::falha('A impressão por produto está desligada. Escolha a impressora padrão acima e salve.');
        }
        $padrao = $this->fila->opcoes('producao')[0];
        // A MESMA regra da impressao real (RotaProducao): produto, padrao e "Nao imprime".
        $rota = RotaProducao::dividir($this->db, $this->idRestaurante, $itens, $padrao['id']);
        $porProduto = $rota['porProduto'];
        $grupos = $rota['grupos'];
        $cabeca = match ($situacao) {
            'delivery' => CupomImpressao::cabecaPedido([
                'numero' => 1, 'tipo' => 'entrega', 'origem' => 'cardapio', 'cliente_nome' => 'Cliente de teste', 'observacao' => '',
            ]),
            'garcom', 'cancelamento' => ['titulo' => 'MESA 18', 'linha' => 'Comanda: Teste', 'detalhe' => 'Pedido #1', 'observacao' => ''],
            default => ['titulo' => 'BALCÃO', 'linha' => 'Cliente: Teste', 'detalhe' => 'Pedido #1', 'observacao' => ''],
        };
        $avisos = [];
        if ($rota['fora'] !== []) {
            $avisos[] = 'Não sai em nenhuma impressora (marcado "Não imprime" no produto): '
                . implode(', ', array_unique(array_map(static fn (array $i): string => (string) $i['nome'], $rota['fora']))) . '.';
        }
        $semMarcacao = [];
        foreach ($itens as $i) {
            if (!isset($rota['naoImprime'][(int) $i['id_produto']]) && ($porProduto[(int) $i['id_produto']] ?? []) === []) {
                $semMarcacao[] = $i['nome'];
            }
        }
        if ($semMarcacao !== []) {
            $avisos[] = 'Sem impressora marcada no produto (sai na padrão ' . $padrao['apelido'] . '): ' . implode(', ', array_unique($semMarcacao)) . '.';
        }
        // Delivery com "Mostrar o total" ligado: o resumo sai so na via da padrao (ou na primeira com itens).
        $resumo = [];
        $comResumo = null;
        if ($situacao === 'delivery' && $this->fila->opcoesCupom()['delivery_com_preco'] && $grupos !== []) {
            $subtotal = array_sum(array_map(static fn (array $i): int => Numero::centavos((string) $i['total']), $itens));
            $resumo = CupomImpressao::resumoPedido([
                'subtotal' => CaixaService::dec($subtotal), 'taxa_entrega' => '5.00', 'desconto' => '0.00',
                'total' => CaixaService::dec($subtotal + 500), 'forma_pagamento' => 'pix', 'tipo' => 'entrega', 'troco_para' => null,
            ]);
            $comResumo = isset($grupos[$padrao['id']]) ? $padrao['id'] : array_key_first($grupos);
        }
        $cupons = [];
        foreach ((new ImpressoraRepository($this->db))->listar($this->idRestaurante) as $imp) {
            $lista = $grupos[$imp['id']] ?? [];
            $motivos = array_map(static fn (array $i): array => [
                'nome' => QuantidadeVenda::textoDoBanco($i['quantidade'], 'un') . ' ' . $i['nome'],
                'motivo' => ($i['id_produto'] === null ? 'opção do combo sem produto ligado: foi para a padrão'
                    : (($porProduto[(int) $i['id_produto']] ?? []) === [] ? 'sem impressora no produto: foi para a padrão' : 'marcado nesta impressora no produto'))
                    . ($i['combo_nome'] !== null ? ' (veio do ' . $i['combo_nome'] . ')' : ''),
            ], $lista);
            if ($lista !== [] && !$imp['ligada']) {
                $avisos[] = $imp['apelido'] . ' está desligada: abra o Deliveryzim Agent no computador "' . $imp['computador'] . '" (na hora real, o envio seria recusado).';
            }
            $cupons[] = [
                'id' => $imp['id'], 'apelido' => $imp['apelido'], 'ligada' => $imp['ligada'], 'padrao' => $imp['id'] === $padrao['id'],
                'blocos' => $lista === [] ? null : ($situacao === 'cancelamento'
                    ? CupomImpressao::cancelamento($cabeca, $imp['apelido'], $lista, 'Cliente desistiu (teste)', false, $this->fila->quem())
                    : CupomImpressao::producao($cabeca, $imp['apelido'], $lista, $this->fila->quem(), $imp['id'] === $comResumo ? $resumo : [])),
                'motivos' => $motivos,
            ];
        }
        return ['cupons' => $cupons, 'avisos' => $avisos];
    }

    /**
     * Conta / cupom completo: um cupom so, com todos os itens, na impressora escolhida (ou a padrao do ponto).
     * @param list<array<string,mixed>> $itens
     */
    private function conta(array $itens, ?int $idImpressora): array|Resultado
    {
        $opcoes = $this->fila->opcoes('caixa_conta');
        $escolhida = null;
        if ($opcoes !== []) {
            $escolhida = $opcoes[0];
            foreach ($opcoes as $o) {
                if ($o['id'] === $idImpressora) {
                    $escolhida = $o;
                }
            }
        }
        $subtotal = array_sum(array_map(static fn (array $i): int => Numero::centavos((string) $i['total']), $itens));
        $c = [
            'pedido' => ['id' => 0, 'numero' => 1, 'tipo' => 'balcao', 'servico_percentual' => '0.00', 'pessoas' => null, 'observacao' => ''],
            'rotulo' => 'Balcão · Teste', 'rotulo_mesa' => 'Balcão', 'nome' => 'Teste', 'itens' => $itens,
            'subtotal' => $subtotal, 'servico' => 0, 'desconto' => 0, 'total' => $subtotal, 'pago' => 0, 'restante' => $subtotal,
        ];
        $cupom = CupomImpressao::comanda($c, $this->restaurante, 'conta', 0, $this->fila->quem());
        $avisos = [];
        if ($escolhida !== null && !$escolhida['ligada']) {
            $avisos[] = $escolhida['apelido'] . ' está desligada: abra o Deliveryzim Agent no computador "' . $escolhida['computador'] . '".';
        }
        return ['cupons' => [[
            'id' => $escolhida['id'] ?? null, 'apelido' => $escolhida['apelido'] ?? 'Impressora principal do Agent',
            'ligada' => $escolhida['ligada'] ?? true, 'padrao' => true, 'blocos' => $cupom['blocos'],
            'motivos' => [['nome' => count($itens) === 1 ? '1 item' : count($itens) . ' itens', 'motivo' => 'cupom completo: sai tudo, sem olhar a impressora do produto']],
        ]], 'avisos' => $avisos];
    }

    /** Grava os cupons na fila do Agent com TESTE no topo (sem pedido ligado). */
    private function imprimir(array $saida, string $situacao): array
    {
        $repo = new ImpressaoRepository($this->db);
        $lote = bin2hex(random_bytes(8));
        $enviados = [];
        $this->db->beginTransaction();
        try {
            foreach ($saida['cupons'] as $c) {
                if ($c['blocos'] === null) {
                    continue;
                }
                $blocos = [['t' => 'titulo', 'texto' => 'TESTE'], ['t' => 'linha'], ...$c['blocos']];
                $repo->criar($this->idRestaurante, null, $situacao === 'conta' ? 'conta' : 'producao', 'TESTE · ' . $c['apelido'],
                    $blocos, null, $this->autor->id > 0 ? $this->autor->id : null, $c['id'], $situacao === 'conta' ? null : $lote);
                $enviados[] = $c['apelido'];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $saida['mensagem'] = 'Teste enviado para ' . implode(', ', $enviados) . '. Confira se saiu no papel.';
        return $saida;
    }
}
