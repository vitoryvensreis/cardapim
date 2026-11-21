<?php

declare(strict_types=1);

namespace Cardapim\Services\Impressao;

use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Repositories\Desktop\ImpressaoRepository;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Repositories\Desktop\ProdutoImpressoraRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Gerencia > Impressoras > Testar impressao: monta, sem gravar nada no pedido, os mesmos cupons da impressao real
 * (mesma divisao por produto e mesmo CupomImpressao) para mostrar o que sai em cada impressora e por que.
 * "Imprimir de verdade" grava os cupons na fila do Agent com TESTE no topo (sem pedido ligado).
 */
final class TesteImpressao
{
    public const SITUACOES = ['delivery', 'garcom', 'caixa', 'conta'];
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
     * @param list<array{id_produto:int,quantidade:int,observacao:string}> $pedidos
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
        $itens = $this->itens($pedidos);
        if ($itens === []) {
            return Resultado::falha('Adicione pelo menos um produto ao teste.');
        }
        $saida = $situacao === 'conta' ? $this->conta($itens, $idImpressora) : $this->producao($situacao, $itens);
        if ($saida instanceof Resultado || !$imprimir) {
            return $saida;
        }
        return $this->imprimir($saida, $situacao);
    }

    /**
     * Itens no mesmo formato do banco (pedido_itens), so com produtos ativos deste restaurante.
     * @param list<array{id_produto:int,quantidade:int,observacao:string}> $pedidos
     * @return list<array<string,mixed>>
     */
    private function itens(array $pedidos): array
    {
        $repo = new ProdutoRepository($this->db);
        $itens = [];
        foreach (array_slice($pedidos, 0, self::MAX_ITENS) as $n => $pedido) {
            $p = $repo->buscar($this->idRestaurante, $pedido['id_produto']);
            if ($p === null || (int) $p['ativo'] !== 1) {
                continue;
            }
            $qtd = max(1, min(99, $pedido['quantidade']));
            $itens[] = [
                'id' => $n + 1, 'id_produto' => (int) $p['id'], 'nome' => (string) $p['nome'],
                'preco_unitario' => (string) $p['preco'], 'quantidade' => $qtd . '.000', 'unidade' => 'un',
                'observacao' => $pedido['observacao'], 'complementos_texto' => '',
                'total' => CaixaService::dec(Numero::centavos((string) $p['preco']) * $qtd),
                'cancelado_em' => null, 'combo_lote' => null,
            ];
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
        $porProduto = (new ProdutoImpressoraRepository($this->db))->dosProdutos($this->idRestaurante, array_column($itens, 'id_produto'));
        $grupos = CupomImpressao::dividir($itens, $porProduto, $padrao['id']);
        $cabeca = match ($situacao) {
            'delivery' => CupomImpressao::cabecaPedido([
                'numero' => 1, 'tipo' => 'entrega', 'origem' => 'cardapio', 'cliente_nome' => 'Cliente de teste', 'observacao' => '',
            ]),
            'garcom' => ['titulo' => 'MESA 18', 'linha' => 'Comanda: Teste', 'detalhe' => 'Pedido #1', 'observacao' => ''],
            default => ['titulo' => 'BALCÃO', 'linha' => 'Cliente: Teste', 'detalhe' => 'Pedido #1', 'observacao' => ''],
        };
        $avisos = [];
        $semMarcacao = [];
        foreach ($itens as $i) {
            if (($porProduto[$i['id_produto']] ?? []) === []) {
                $semMarcacao[] = $i['nome'];
            }
        }
        if ($semMarcacao !== []) {
            $avisos[] = 'Sem impressora marcada no produto (sai na padrão ' . $padrao['apelido'] . '): ' . implode(', ', array_unique($semMarcacao)) . '.';
        }
        $cupons = [];
        foreach ((new ImpressoraRepository($this->db))->listar($this->idRestaurante) as $imp) {
            $lista = $grupos[$imp['id']] ?? [];
            $motivos = array_map(static fn (array $i): array => [
                'nome' => QuantidadeVenda::textoDoBanco($i['quantidade'], 'un') . ' ' . $i['nome'],
                'motivo' => ($porProduto[$i['id_produto']] ?? []) === [] ? 'sem impressora no produto: foi para a padrão' : 'marcado nesta impressora no produto',
            ], $lista);
            if ($lista !== [] && !$imp['ligada']) {
                $avisos[] = $imp['apelido'] . ' está desligada: abra o Deliveryzim Agent no computador "' . $imp['computador'] . '" (na hora real, o envio seria recusado).';
            }
            $cupons[] = [
                'id' => $imp['id'], 'apelido' => $imp['apelido'], 'ligada' => $imp['ligada'], 'padrao' => $imp['id'] === $padrao['id'],
                'blocos' => $lista === [] ? null : CupomImpressao::producao($cabeca, $imp['apelido'], $lista, $this->autor->nome),
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
        $cupom = CupomImpressao::comanda($c, $this->restaurante, 'conta', 0, $this->autor->nome);
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
