<?php

declare(strict_types=1);

namespace Cardapim\Services\Caixa;

use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;
use PDO;
use PDOException;
use Throwable;

/**
 * Abrir e fechar o caixa e registrar entradas/saidas de dinheiro da gaveta.
 *
 * Dinheiro esperado na gaveta = fundo de troco + vendas em dinheiro (ja sem o troco) + entradas - saidas.
 * Fechamento "cego": a pessoa informa quanto contou ANTES de ver o esperado; a diferenca fica no historico.
 */
final class CaixaService
{
    /** Formas recebidas no caixa (pedido_pagamentos.forma) e seus nomes na tela. */
    public const FORMAS = [
        'dinheiro' => 'Dinheiro',
        'debito' => 'Cartão de débito',
        'credito' => 'Cartão de crédito',
        'pix' => 'Pix na hora',
        'pix_online' => 'Pix pelo app',
    ];

    /** Motivos das entradas e saidas (lista fechada: relatorio claro e sem texto solto). */
    public const ENTRADAS = [
        'reforco' => 'Reforço de troco',
        'outra_entrada' => 'Outra entrada',
    ];
    public const SAIDAS = [
        'sangria' => 'Sangria (levar para o cofre)',
        'despesa' => 'Despesa do dia',
        'fornecedor' => 'Pagamento a fornecedor',
        'entregador' => 'Pagamento de entregador',
        'vale' => 'Vale para funcionário',
        'outra_saida' => 'Outra saída',
    ];

    private readonly CaixaRepository $repo;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
        $this->repo = new CaixaRepository($db);
    }

    /** @return array<string,mixed>|null */
    public function aberto(): ?array
    {
        return $this->repo->aberto($this->idRestaurante);
    }

    public function abrir(string $valorTexto): Resultado
    {
        $valor = trim($valorTexto) === '' ? '0.00' : Numero::dinheiroParaDecimal($valorTexto);
        if ($valor === null || Numero::centavos($valor) > 10_000_000) {
            return Resultado::falha('Confira o valor do troco inicial.', ['valor_abertura' => 'Digite o dinheiro que está na gaveta agora (pode ser 0,00).']);
        }
        if ($this->aberto() !== null) {
            return Resultado::falha('O caixa já está aberto.');
        }
        $this->db->beginTransaction();
        try {
            $id = $this->repo->abrir($this->idRestaurante, $this->autor->id, $valor, date('Y-m-d H:i:s'));
            TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.aberto', ['id' => $id]);
            $this->db->commit();
        } catch (PDOException $erro) {
            $this->db->rollBack();
            // Outro aparelho abriu no mesmo instante: a regra "um caixa aberto" do banco recusou.
            if ($this->aberto() !== null) {
                return Resultado::falha('O caixa já foi aberto em outro aparelho.');
            }
            throw $erro;
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        Auditoria::registrar($this->autor, 'Caixa aberto', 'Troco inicial ' . Numero::reais($valor) . '.');
        return Resultado::sucesso('Caixa aberto com ' . Numero::reais($valor) . ' de troco. Boas vendas!', $id);
    }

    /**
     * Totais do caixa em centavos.
     * @param array<string,mixed> $caixa
     * @return array{formas: array<string,int>, vendas:int, pedidos:int, troco:int, entradas:int, saidas:int, abertura:int, esperado:int, ticket:int}
     */
    public function resumo(array $caixa): array
    {
        $formas = array_fill_keys(array_keys(self::FORMAS), 0);
        $pedidos = [];
        $troco = 0;
        foreach ($this->repo->pagamentos($this->idRestaurante, (int) $caixa['id']) as $pg) {
            $formas[$pg['forma']] = ($formas[$pg['forma']] ?? 0) + Numero::centavos((string) $pg['valor']);
            $troco += Numero::centavos((string) $pg['troco']);
            $pedidos[(int) $pg['id_pedido']] = true;
        }
        $mov = $this->repo->somaMovimentos($this->idRestaurante, (int) $caixa['id']);
        $abertura = Numero::centavos((string) $caixa['valor_abertura']);
        $vendas = array_sum($formas);
        return [
            'formas' => $formas,
            'vendas' => $vendas,
            'pedidos' => count($pedidos),
            'troco' => $troco,
            'entradas' => $mov['entrada'],
            'saidas' => $mov['saida'],
            'abertura' => $abertura,
            'esperado' => $abertura + $formas['dinheiro'] + $mov['entrada'] - $mov['saida'],
            'ticket' => $pedidos === [] ? 0 : intdiv($vendas, count($pedidos)),
        ];
    }

    public function registrarMovimento(string $tipo, string $categoria, string $valorTexto, string $descricao): Resultado
    {
        $caixa = $this->aberto();
        if ($caixa === null) {
            return Resultado::falha('Abra o caixa antes de registrar entradas e saídas.');
        }
        $lista = $tipo === 'entrada' ? self::ENTRADAS : ($tipo === 'saida' ? self::SAIDAS : null);
        $erros = [];
        if ($lista === null) {
            return Resultado::falha('Escolha se é entrada ou saída.');
        }
        if (!isset($lista[$categoria])) {
            $erros['categoria'] = 'Escolha o motivo.';
        }
        $valor = Numero::dinheiroParaDecimal($valorTexto);
        if ($valor === null || Numero::centavos($valor) <= 0 || Numero::centavos($valor) > 10_000_000) {
            $erros['valor'] = 'Digite um valor maior que zero.';
        }
        $descricao = trim((string) preg_replace('/\s+/u', ' ', $descricao));
        if (mb_strlen($descricao) > 200) {
            $erros['descricao'] = 'Use até 200 caracteres.';
        }
        if (in_array($categoria, ['outra_entrada', 'outra_saida', 'despesa', 'fornecedor'], true) && $descricao === '') {
            $erros['descricao'] = 'Conte o que foi (ex.: gás, gelo, nome do fornecedor).';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        if ($tipo === 'saida') {
            $naGaveta = $this->resumo($caixa)['esperado'];
            if (Numero::centavos((string) $valor) > $naGaveta) {
                return Resultado::falha('Saída maior que o dinheiro da gaveta.', ['valor' => 'Pela conta do sistema há ' . Numero::reais(self::dec($naGaveta)) . ' em dinheiro na gaveta.']);
            }
        }
        $this->db->beginTransaction();
        try {
            $this->repo->registrarMovimento($this->idRestaurante, (int) $caixa['id'], $tipo, $categoria, $descricao !== '' ? $descricao : null, (string) $valor, $this->autor->id, date('Y-m-d H:i:s'));
            TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.movimento', ['caixa' => (int) $caixa['id']]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        $rotulo = $lista[$categoria];
        Auditoria::registrar($this->autor, $tipo === 'entrada' ? 'Entrada no caixa' : 'Saída do caixa',
            $rotulo . ': ' . Numero::reais((string) $valor) . ($descricao !== '' ? ' - ' . $descricao : '') . '.');
        return Resultado::sucesso(($tipo === 'entrada' ? 'Entrada' : 'Saída') . ' de ' . Numero::reais((string) $valor) . ' registrada.');
    }

    public function fechar(string $contadoTexto, string $observacao): Resultado
    {
        $caixa = $this->aberto();
        if ($caixa === null) {
            return Resultado::falha('O caixa já está fechado.');
        }
        $contado = Numero::dinheiroParaDecimal($contadoTexto);
        if ($contado === null || Numero::centavos($contado) > 10_000_000) {
            return Resultado::falha('Informe quanto dinheiro você contou na gaveta.', ['dinheiro_contado' => 'Conte as notas e moedas e digite o total (pode ser 0,00).']);
        }
        $observacao = trim((string) preg_replace('/\s+/u', ' ', $observacao));
        if (mb_strlen($observacao) > 300) {
            return Resultado::falha('Confira os campos destacados.', ['observacao' => 'Use até 300 caracteres.']);
        }
        $r = $this->resumo($caixa);
        $diferenca = Numero::centavos($contado) - $r['esperado'];
        $resumo = json_encode([
            'formas' => $r['formas'], 'vendas' => $r['vendas'], 'pedidos' => $r['pedidos'], 'entradas' => $r['entradas'],
            'saidas' => $r['saidas'], 'abertura' => $r['abertura'], 'troco' => $r['troco'],
        ], JSON_THROW_ON_ERROR);
        $this->db->beginTransaction();
        try {
            $ok = $this->repo->fechar($this->idRestaurante, (int) $caixa['id'], $this->autor->id, self::dec($r['esperado']), (string) $contado, self::dec($diferenca),
                $resumo, $observacao !== '' ? $observacao : null, date('Y-m-d H:i:s'));
            if (!$ok) {
                $this->db->rollBack();
                return Resultado::falha('O caixa já foi fechado em outro aparelho.');
            }
            TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.fechado', ['id' => (int) $caixa['id']]);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        $texto = $diferenca === 0 ? 'sem diferença' : ($diferenca > 0 ? 'sobrou ' : 'faltou ') . Numero::reais(self::dec(abs($diferenca)));
        Auditoria::registrar($this->autor, 'Caixa fechado', sprintf('Vendas %s em %d pedido(s). Dinheiro esperado %s, contado %s (%s).',
            Numero::reais(self::dec($r['vendas'])), $r['pedidos'], Numero::reais(self::dec($r['esperado'])), Numero::reais((string) $contado), $texto));
        return Resultado::sucesso('Caixa fechado: ' . $texto . '.', (int) $caixa['id']);
    }

    public static function dec(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);
        return $sinal . intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}
