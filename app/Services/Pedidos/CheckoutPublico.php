<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Shared\Numero;
use PDO;

/** O que a tela "Finalizar pedido" precisa saber do restaurante (sem segredos). */
final class CheckoutPublico
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed> */
    public function config(int $idRestaurante): array
    {
        $c = (new EntregaPagamentoRepository($this->db))->config($idRestaurante);
        $info = (new CardapioPublico($this->db))->informacoes($idRestaurante);
        $pixOnline = $c['pag_pix_online'] === 1 && (new PixPedido($this->db))->disponivel($idRestaurante);
        $formas = [];
        if ($pixOnline) {
            $formas[] = 'pix_online';
        }
        foreach (['pix_entrega' => 'pag_pix_entrega', 'debito' => 'pag_debito', 'credito' => 'pag_credito', 'dinheiro' => 'pag_dinheiro'] as $forma => $campo) {
            if ($c[$campo] === 1) {
                $formas[] = $forma;
            }
        }
        $modos = [];
        if ($c['aceita_entrega'] === 1) {
            $modos['entrega'] = self::tempo($c['entrega_minutos_min'], $c['entrega_minutos_max']);
        }
        if ($c['aceita_retirada'] === 1) {
            $modos['retirada'] = $c['retirada_minutos'] !== null ? 'Pronto em ' . $c['retirada_minutos'] . ' min' : '';
        }
        return [
            'modos' => $modos,
            'formas' => $formas,
            'aberto' => !$info['horario_informado'] || $info['aberto'],
            'endereco_restaurante' => trim($info['endereco'] . ($info['bairro_cidade'] !== '' ? ' · ' . $info['bairro_cidade'] : '')),
            'telefone' => $info['telefone'],
            'pix_prazo_minutos' => PixPedido::PRAZO_MINUTOS,
            'recebe_pedidos' => $modos !== [] && $formas !== [],
        ];
    }

    private static function tempo(?int $min, ?int $max): string
    {
        if ($min === null) {
            return '';
        }
        return $max !== null && $max !== $min ? $min . '–' . $max . ' min' : $min . ' min';
    }

    /** Pedido para a tela do cliente (sem dados internos). @param array<string,mixed> $p @param list<array<string,mixed>> $itens @param list<array<string,mixed>> $eventos @return array<string,mixed> */
    public static function pedidoParaCliente(array $p, array $itens, array $eventos, string $slug): array
    {
        $tipo = (string) $p['tipo'];
        $endereco = $tipo === 'entrega' ? trim(implode(', ', array_filter([
            (string) $p['entrega_logradouro'], (string) $p['entrega_numero'], (string) ($p['entrega_complemento'] ?? ''),
        ]))) . ' · ' . trim(implode(' · ', array_filter([(string) $p['entrega_bairro'], (string) $p['entrega_cidade']]))) : '';
        $alcancados = array_column($eventos, 'criado_em', 'status');
        return [
            'id' => (int) $p['id'],
            'numero' => (int) $p['numero'],
            'slug' => $slug,
            'tipo' => $tipo,
            'status' => (string) $p['status'],
            'status_rotulo' => StatusPedido::rotulo((string) $p['status'], $tipo),
            'etapas' => array_map(static fn (string $s): array => [
                'status' => $s,
                'rotulo' => StatusPedido::rotulo($s, $tipo),
                'em' => isset($alcancados[$s]) ? substr((string) $alcancados[$s], 11, 5) : null,
            ], StatusPedido::etapas($tipo)),
            'forma_pagamento' => StatusPedido::forma((string) $p['forma_pagamento'], $tipo),
            'forma' => (string) $p['forma_pagamento'],
            'pagamento_status' => (string) $p['pagamento_status'],
            'troco_para_centavos' => $p['troco_para'] !== null ? Numero::centavos((string) $p['troco_para']) : null,
            'subtotal_centavos' => Numero::centavos((string) $p['subtotal']),
            'taxa_centavos' => Numero::centavos((string) $p['taxa_entrega']),
            'total_centavos' => Numero::centavos((string) $p['total']),
            'observacao' => (string) ($p['observacao'] ?? ''),
            'endereco' => $endereco,
            'referencia' => (string) ($p['entrega_referencia'] ?? ''),
            'previsao' => self::tempo($p['previsao_minutos_min'] !== null ? (int) $p['previsao_minutos_min'] : null, $p['previsao_minutos_max'] !== null ? (int) $p['previsao_minutos_max'] : null),
            'criado_em' => substr((string) $p['criado_em'], 11, 5),
            'criado_data' => substr((string) $p['criado_em'], 0, 10),
            'cancelado_motivo' => $p['status'] === 'cancelado' ? (string) ($p['motivo_cancelamento'] ?? '') : '',
            'pix' => $p['status'] === 'aguardando_pagamento' && !empty($p['pix_copia_cola']) ? [
                'copia_cola' => (string) $p['pix_copia_cola'],
                'expira_em_segundos' => max(0, strtotime((string) $p['pix_expira_em']) - time()),
            ] : null,
            'itens' => array_map(static fn (array $i): array => [
                'nome' => (string) $i['nome'],
                'quantidade' => (int) $i['quantidade'],
                'observacao' => (string) ($i['observacao'] ?? ''),
                'total_centavos' => Numero::centavos((string) $i['total']),
            ], $itens),
            'final' => in_array($p['status'], ['concluido', 'cancelado'], true),
        ];
    }
}
