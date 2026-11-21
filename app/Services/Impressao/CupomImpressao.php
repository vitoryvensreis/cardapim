<?php

declare(strict_types=1);

namespace Cardapim\Services\Impressao;

use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Pedidos\StatusPedido;
use Cardapim\Shared\Numero;

/**
 * Monta o cupom que o Deliveryzim Agent imprime, em BLOCOS simples (o Agent ajusta a largura do papel, 58 ou 80 mm):
 *   titulo   texto grande e centralizado (ex.: "MESA 5")
 *   centro   texto centralizado
 *   destaque texto em negrito
 *   texto    texto comum (quebra de linha automatica)
 *   linha    separador
 *   valor    rotulo a esquerda e valor a direita (forte = negrito)
 *   item     quantidade + nome, valor a direita e detalhes abaixo (complementos, observacao)
 * Assim um modelo novo de cupom nao exige atualizar o Agent. Sem regra de negocio aqui: so formatacao.
 */
final class CupomImpressao
{
    public const MODOS_COMANDA = ['conta', 'pre_conta', 'itens_novos'];

    /**
     * Comanda de mesa ou balcao (ComandaService::detalhes).
     * conta = conta completa (caixa); pre_conta = garcom mostra o que ja tem; itens_novos = so o que veio depois de $desdeItem.
     * @param array<string,mixed> $c
     * @return array{titulo:string,blocos:list<array<string,mixed>>,ate_item:int,itens:int}
     */
    public static function comanda(array $c, string $restaurante, string $modo, int $desdeItem = 0, string $quem = ''): array
    {
        if (!in_array($modo, self::MODOS_COMANDA, true)) {
            throw new \InvalidArgumentException('Modo de impressao desconhecido.');
        }
        $p = $c['pedido'];
        $ativos = array_values(array_filter($c['itens'], static fn (array $i): bool => $i['cancelado_em'] === null));
        $ateItem = 0;
        foreach ($ativos as $i) {
            $ateItem = max($ateItem, (int) $i['id']);
        }
        $lista = $modo === 'itens_novos'
            ? array_values(array_filter($ativos, static fn (array $i): bool => (int) $i['id'] > $desdeItem))
            : $ativos;

        $mesa = $p['tipo'] === 'mesa';
        $nome = trim((string) ($c['nome'] ?? ''));
        $cabeca = $mesa ? mb_strtoupper((string) $c['rotulo_mesa']) : 'BALCÃO';
        $titulo = match ($modo) {
            'conta' => 'CONTA',
            'pre_conta' => 'PRÉ-CONTA',
            default => 'ITENS NOVOS',
        };
        $blocos = [
            ['t' => 'centro', 'texto' => $restaurante],
            ['t' => 'linha'],
            ['t' => 'titulo', 'texto' => $cabeca],
        ];
        if ($nome !== '') {
            $blocos[] = ['t' => 'centro', 'texto' => ($mesa ? 'Comanda: ' : 'Cliente: ') . $nome];
        }
        $blocos[] = ['t' => 'destaque', 'texto' => $titulo . ' · Pedido #' . (int) $p['numero']];
        $blocos[] = ['t' => 'texto', 'texto' => date('d/m/Y H:i') . ($quem !== '' ? ' · ' . $quem : '')];
        $blocos[] = ['t' => 'linha'];
        $blocos = [...$blocos, ...($modo === 'itens_novos' ? array_map([self::class, 'item'], $lista) : self::itensAgrupados($lista))];
        if ($lista === []) {
            $blocos[] = ['t' => 'texto', 'texto' => 'Nenhum item.'];
        }
        $blocos[] = ['t' => 'linha'];
        $obs = trim((string) ($p['observacao'] ?? ''));
        if ($obs !== '') {
            $blocos[] = ['t' => 'destaque', 'texto' => 'OBSERVAÇÃO'];
            $blocos[] = ['t' => 'texto', 'texto' => $obs];
            $blocos[] = ['t' => 'linha'];
        }

        if ($modo === 'itens_novos') {
            $blocos[] = ['t' => 'valor', 'rotulo' => 'Total da comanda até agora', 'valor' => self::reais((int) $c['total'])];
        } else {
            $qtd = array_sum(array_map(static fn (array $i): int => QuantidadeVenda::contaItens(QuantidadeVenda::doBanco($i['quantidade']), (string) ($i['unidade'] ?? 'un')), $ativos));
            $blocos[] = ['t' => 'valor', 'rotulo' => 'Itens (' . $qtd . ')', 'valor' => self::reais((int) $c['subtotal'])];
            if ((int) $c['servico'] > 0) {
                $pct = rtrim(rtrim(number_format((float) $p['servico_percentual'], 2, ',', ''), '0'), ',');
                $blocos[] = ['t' => 'valor', 'rotulo' => 'Serviço (' . $pct . '%)', 'valor' => self::reais((int) $c['servico'])];
            }
            if ((int) $c['desconto'] > 0) {
                $blocos[] = ['t' => 'valor', 'rotulo' => 'Desconto', 'valor' => '-' . self::reais((int) $c['desconto'])];
            }
            $blocos[] = ['t' => 'valor', 'rotulo' => 'TOTAL', 'valor' => self::reais((int) $c['total']), 'forte' => true];
            $pessoas = (int) ($p['pessoas'] ?? 0);
            if ($pessoas > 1 && (int) $c['total'] > 0) {
                $blocos[] = ['t' => 'valor', 'rotulo' => 'Por pessoa (' . $pessoas . ')', 'valor' => self::reais(intdiv((int) $c['total'] + $pessoas - 1, $pessoas))];
            }
            if ((int) $c['pago'] > 0) {
                $blocos[] = ['t' => 'valor', 'rotulo' => 'Já pago', 'valor' => self::reais((int) $c['pago'])];
                if ((int) $c['restante'] > 0) {
                    $blocos[] = ['t' => 'valor', 'rotulo' => 'Falta pagar', 'valor' => self::reais((int) $c['restante']), 'forte' => true];
                }
            }
            $blocos[] = ['t' => 'linha'];
            $blocos[] = ['t' => 'centro', 'texto' => 'Não é documento fiscal.'];
        }

        $rotulo = $mesa ? (string) $c['rotulo'] : 'Balcão' . ($nome !== '' ? ' · ' . $nome : '');
        return ['titulo' => $titulo . ' · ' . $rotulo, 'blocos' => $blocos, 'ate_item' => $ateItem, 'itens' => count($lista)];
    }

    /**
     * Pedido de entrega ou retirada (cardapio online, iFood, WhatsApp).
     * @param array<string,mixed> $p
     * @param list<array<string,mixed>> $itens
     * @return array{titulo:string,blocos:list<array<string,mixed>>}
     */
    public static function pedido(array $p, array $itens, string $restaurante): array
    {
        $entrega = $p['tipo'] === 'entrega';
        $blocos = [
            ['t' => 'centro', 'texto' => $restaurante],
            ['t' => 'linha'],
            ['t' => 'titulo', 'texto' => 'PEDIDO #' . (int) $p['numero']],
            ['t' => 'centro', 'texto' => ($entrega ? 'ENTREGA' : 'RETIRADA') . (($p['origem'] ?? '') === 'ifood' ? ' · iFood' . (!empty($p['ifood_display_id']) ? ' #' . $p['ifood_display_id'] : '') : '')],
            ['t' => 'texto', 'texto' => 'Feito em ' . date('d/m/Y H:i', (int) strtotime((string) $p['criado_em']))],
            ['t' => 'linha'],
        ];
        if (trim((string) ($p['cliente_nome'] ?? '')) !== '') {
            $blocos[] = ['t' => 'destaque', 'texto' => 'Cliente: ' . trim((string) $p['cliente_nome'])];
        }
        $fone = preg_replace('/\D+/', '', (string) ($p['cliente_whatsapp'] ?? ''));
        if (is_string($fone) && strlen($fone) >= 10) {
            $blocos[] = ['t' => 'texto', 'texto' => 'Telefone: (' . substr($fone, 0, 2) . ') ' . substr($fone, 2, -4) . '-' . substr($fone, -4)];
        }
        if ($entrega) {
            $rua = trim((string) ($p['entrega_logradouro'] ?? '') . (!empty($p['entrega_numero']) ? ', ' . $p['entrega_numero'] : ''));
            foreach ([$rua, $p['entrega_complemento'] ?? '', $p['entrega_bairro'] ?? '', $p['entrega_cidade'] ?? ''] as $parte) {
                if (trim((string) $parte) !== '') {
                    $blocos[] = ['t' => 'texto', 'texto' => trim((string) $parte)];
                }
            }
            if (trim((string) ($p['entrega_referencia'] ?? '')) !== '') {
                $blocos[] = ['t' => 'texto', 'texto' => 'Referência: ' . trim((string) $p['entrega_referencia'])];
            }
        }
        $blocos[] = ['t' => 'linha'];
        foreach ($itens as $i) {
            if (($i['cancelado_em'] ?? null) === null) {
                $blocos[] = self::item($i);
            }
        }
        $blocos[] = ['t' => 'linha'];
        $obs = trim((string) ($p['observacao'] ?? ''));
        if ($obs !== '') {
            $blocos[] = ['t' => 'destaque', 'texto' => 'OBSERVAÇÃO'];
            $blocos[] = ['t' => 'texto', 'texto' => $obs];
            $blocos[] = ['t' => 'linha'];
        }
        $blocos[] = ['t' => 'valor', 'rotulo' => 'Itens', 'valor' => Numero::reais((string) $p['subtotal'])];
        if (Numero::centavos((string) ($p['taxa_entrega'] ?? '0')) > 0) {
            $blocos[] = ['t' => 'valor', 'rotulo' => 'Entrega', 'valor' => Numero::reais((string) $p['taxa_entrega'])];
        }
        if (Numero::centavos((string) ($p['desconto'] ?? '0')) > 0) {
            $blocos[] = ['t' => 'valor', 'rotulo' => 'Desconto', 'valor' => '-' . Numero::reais((string) $p['desconto'])];
        }
        $blocos[] = ['t' => 'valor', 'rotulo' => 'TOTAL', 'valor' => Numero::reais((string) $p['total']), 'forte' => true];
        $blocos[] = ['t' => 'texto', 'texto' => 'Pagamento: ' . StatusPedido::forma((string) $p['forma_pagamento'], (string) $p['tipo'])];
        if ($p['forma_pagamento'] === 'dinheiro' && $p['troco_para'] !== null) {
            $troco = Numero::centavos((string) $p['troco_para']) - Numero::centavos((string) $p['total']);
            if ($troco > 0) {
                $blocos[] = ['t' => 'valor', 'rotulo' => 'Troco para ' . Numero::reais((string) $p['troco_para']), 'valor' => self::reais($troco), 'forte' => true];
            }
        }
        return ['titulo' => 'Pedido #' . (int) $p['numero'], 'blocos' => $blocos];
    }

    /**
     * Fechamento (ou parcial) do caixa: o mesmo resumo da tela.
     * @param array<string,mixed> $caixa
     * @param array<string,mixed> $resumo CaixaService::resumo
     * @param list<array<string,mixed>> $movimentos
     * @return array{titulo:string,blocos:list<array<string,mixed>>}
     */
    public static function fechamento(array $caixa, array $resumo, array $movimentos, int $recebimentos, string $restaurante): array
    {
        $fechado = $caixa['status'] === 'fechado';
        $gravado = $fechado ? json_decode((string) ($caixa['resumo'] ?? ''), true) : null;
        $formas = is_array($gravado) ? (array) $gravado['formas'] : $resumo['formas'];
        $vendas = is_array($gravado) ? (int) $gravado['vendas'] : (int) $resumo['vendas'];
        $esperado = $fechado ? Numero::centavos((string) $caixa['dinheiro_esperado']) : (int) $resumo['esperado'];
        $blocos = [
            ['t' => 'centro', 'texto' => $restaurante],
            ['t' => 'linha'],
            ['t' => 'titulo', 'texto' => $fechado ? 'FECHAMENTO' : 'CAIXA ABERTO'],
            ['t' => 'texto', 'texto' => 'Aberto: ' . date('d/m/Y H:i', (int) strtotime((string) $caixa['aberto_em'])) . ' · ' . (string) ($caixa['aberto_por'] ?? '')],
        ];
        if ($fechado) {
            $blocos[] = ['t' => 'texto', 'texto' => 'Fechado: ' . date('d/m/Y H:i', (int) strtotime((string) $caixa['fechado_em'])) . ' · ' . (string) ($caixa['fechado_por'] ?? '')];
        } else {
            $blocos[] = ['t' => 'texto', 'texto' => 'Parcial impresso em ' . date('d/m/Y H:i')];
        }
        $blocos[] = ['t' => 'linha'];
        $blocos[] = ['t' => 'valor', 'rotulo' => 'Troco inicial', 'valor' => Numero::reais((string) $caixa['valor_abertura'])];
        foreach (CaixaService::FORMAS as $forma => $rotulo) {
            $blocos[] = ['t' => 'valor', 'rotulo' => $rotulo, 'valor' => self::reais((int) ($formas[$forma] ?? 0))];
        }
        $blocos[] = ['t' => 'valor', 'rotulo' => 'Vendas (' . $recebimentos . ' receb.)', 'valor' => self::reais($vendas), 'forte' => true];
        $blocos[] = ['t' => 'valor', 'rotulo' => 'Entradas', 'valor' => '+' . self::reais((int) $resumo['entradas'])];
        $blocos[] = ['t' => 'valor', 'rotulo' => 'Saídas', 'valor' => '-' . self::reais((int) $resumo['saidas'])];
        $blocos[] = ['t' => 'valor', 'rotulo' => 'Dinheiro esperado', 'valor' => self::reais($esperado), 'forte' => true];
        if ($fechado) {
            $dif = Numero::centavos((string) $caixa['diferenca']);
            $blocos[] = ['t' => 'valor', 'rotulo' => 'Dinheiro contado', 'valor' => Numero::reais((string) $caixa['dinheiro_contado'])];
            $blocos[] = ['t' => 'valor', 'rotulo' => $dif === 0 ? 'Diferença' : ($dif > 0 ? 'Sobrou' : 'Faltou'), 'valor' => self::reais(abs($dif)), 'forte' => $dif !== 0];
            if (trim((string) ($caixa['observacao'] ?? '')) !== '') {
                $blocos[] = ['t' => 'texto', 'texto' => 'Obs.: ' . trim((string) $caixa['observacao'])];
            }
        }
        if ($movimentos !== []) {
            $todas = CaixaService::ENTRADAS + CaixaService::SAIDAS;
            $blocos[] = ['t' => 'linha'];
            $blocos[] = ['t' => 'destaque', 'texto' => 'ENTRADAS E SAÍDAS'];
            foreach ($movimentos as $m) {
                $entrada = $m['tipo'] === 'entrada';
                $blocos[] = ['t' => 'valor',
                    'rotulo' => date('H:i', (int) strtotime((string) $m['criado_em'])) . ' ' . ($todas[$m['categoria']] ?? (string) $m['categoria']),
                    'valor' => ($entrada ? '+' : '-') . Numero::reais((string) $m['valor'])];
            }
        }
        $blocos[] = ['t' => 'linha'];
        return ['titulo' => ($fechado ? 'Fechamento' : 'Caixa') . ' de ' . date('d/m', (int) strtotime((string) $caixa['aberto_em'])), 'blocos' => $blocos];
    }

    /**
     * Divide os itens por estacao: cada item vai para as impressoras marcadas no produto; sem marcacao
     * (ou produto sem ligacao, como item do iFood nao ligado), vai para a impressora padrao da producao.
     * @param list<array<string,mixed>> $itens
     * @param array<int,list<int>> $porProduto id_produto => impressoras
     * @return array<int,list<array<string,mixed>>> id_impressora => itens (na ordem em que foram lancados)
     */
    public static function dividir(array $itens, array $porProduto, int $padrao): array
    {
        $grupos = [];
        foreach ($itens as $i) {
            if (($i['cancelado_em'] ?? null) !== null) {
                continue;
            }
            $destinos = $porProduto[(int) ($i['id_produto'] ?? 0)] ?? [];
            foreach ($destinos !== [] ? $destinos : [$padrao] as $idImpressora) {
                $grupos[(int) $idImpressora][] = $i;
            }
        }
        return $grupos;
    }

    /**
     * Via de producao de uma estacao (Churrasqueira, Cozinha, Bar...): so os itens dela, com a mesa (ou o pedido)
     * em letra grande, hora, quem pediu, complementos e observacoes. Sem o total da conta.
     * @param array{titulo:string,linha:string,detalhe:string,observacao:string} $cabeca
     * @param list<array<string,mixed>> $itens
     * @return list<array<string,mixed>>
     */
    public static function producao(array $cabeca, string $estacao, array $itens, string $quem = ''): array
    {
        $blocos = [
            ['t' => 'centro', 'texto' => 'PRODUÇÃO · ' . mb_strtoupper($estacao)],
            ['t' => 'linha'],
            ['t' => 'titulo', 'texto' => $cabeca['titulo']],
        ];
        if ($cabeca['linha'] !== '') {
            $blocos[] = ['t' => 'centro', 'texto' => $cabeca['linha']];
        }
        $blocos[] = ['t' => 'destaque', 'texto' => $cabeca['detalhe']];
        $blocos[] = ['t' => 'texto', 'texto' => date('d/m/Y H:i') . ($quem !== '' ? ' · ' . $quem : '')];
        $blocos[] = ['t' => 'linha'];
        foreach ($itens as $i) {
            $blocos[] = self::item($i);
        }
        $blocos[] = ['t' => 'linha'];
        if (trim($cabeca['observacao']) !== '') {
            $blocos[] = ['t' => 'destaque', 'texto' => 'OBSERVAÇÃO'];
            $blocos[] = ['t' => 'texto', 'texto' => trim($cabeca['observacao'])];
            $blocos[] = ['t' => 'linha'];
        }
        return $blocos;
    }

    /**
     * Cabecalho da via de producao de uma comanda (mesa ou balcao).
     * @param array<string,mixed> $c ComandaService::detalhes
     * @return array{titulo:string,linha:string,detalhe:string,observacao:string}
     */
    public static function cabecaComanda(array $c): array
    {
        $p = $c['pedido'];
        $mesa = $p['tipo'] === 'mesa';
        $nome = trim((string) ($c['nome'] ?? ''));
        return [
            'titulo' => $mesa ? mb_strtoupper((string) $c['rotulo_mesa']) : 'BALCÃO',
            'linha' => $nome !== '' ? ($mesa ? 'Comanda: ' : 'Cliente: ') . $nome : '',
            'detalhe' => 'Pedido #' . (int) $p['numero'],
            'observacao' => (string) ($p['observacao'] ?? ''),
        ];
    }

    /**
     * Cabecalho da via de producao de um pedido do delivery (aceito).
     * @param array<string,mixed> $p
     * @return array{titulo:string,linha:string,detalhe:string,observacao:string}
     */
    public static function cabecaPedido(array $p): array
    {
        $ifood = ($p['origem'] ?? '') === 'ifood';
        return [
            'titulo' => 'PEDIDO #' . (int) $p['numero'],
            'linha' => ($p['tipo'] === 'entrega' ? 'ENTREGA' : 'RETIRADA') . ($ifood ? ' · iFood' . (!empty($p['ifood_display_id']) ? ' #' . $p['ifood_display_id'] : '') : ''),
            'detalhe' => trim((string) ($p['cliente_nome'] ?? '')) !== '' ? 'Cliente: ' . trim((string) $p['cliente_nome']) : 'Delivery',
            'observacao' => (string) ($p['observacao'] ?? ''),
        ];
    }

    /**
     * Conta: as linhas do mesmo combo viram um item so (nome e total do combo, com os itens embaixo).
     * @param list<array<string,mixed>> $itens
     * @return list<array<string,mixed>>
     */
    private static function itensAgrupados(array $itens): array
    {
        $blocos = [];
        $lotes = [];
        foreach ($itens as $i) {
            $lote = $i['combo_lote'] ?? null;
            if ($lote === null) {
                $blocos[] = self::item($i);
                continue;
            }
            if (!isset($lotes[$lote])) {
                $lotes[$lote] = count($blocos);
                $blocos[] = ['t' => 'item', 'qtd' => '', 'nome' => (string) $i['combo_nome'], 'valor' => '', 'detalhes' => [], 'centavos' => 0];
            }
            $b = &$blocos[$lotes[$lote]];
            $b['centavos'] += Numero::centavos((string) $i['total']);
            $b['detalhes'][] = QuantidadeVenda::textoDoBanco($i['quantidade'], (string) ($i['unidade'] ?? 'un')) . ' ' . $i['nome'];
            unset($b);
        }
        foreach ($blocos as &$b) {
            if (array_key_exists('centavos', $b)) {
                $b['valor'] = self::reais($b['centavos']);
                unset($b['centavos']);
            }
        }
        unset($b);
        return $blocos;
    }

    /** @param array<string,mixed> $i */
    private static function item(array $i): array
    {
        $unidade = (string) ($i['unidade'] ?? 'un');
        $detalhes = [];
        if (QuantidadeVenda::porPeso($unidade)) {
            $detalhes[] = Numero::reais((string) $i['preco_unitario']) . QuantidadeVenda::sufixoPreco($unidade);
        }
        foreach (['complementos_texto', 'observacao'] as $campo) {
            $texto = trim((string) ($i[$campo] ?? ''));
            if ($texto !== '') {
                $detalhes[] = ($campo === 'observacao' ? 'Obs.: ' : '') . $texto;
            }
        }
        return [
            't' => 'item',
            'qtd' => QuantidadeVenda::textoDoBanco($i['quantidade'], $unidade),
            'nome' => (string) $i['nome'],
            'valor' => Numero::reais((string) $i['total']),
            'detalhes' => $detalhes,
        ];
    }

    private static function reais(int $centavos): string
    {
        return Numero::reais(CaixaService::dec($centavos));
    }
}
