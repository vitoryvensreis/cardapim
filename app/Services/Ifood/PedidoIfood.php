<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Converte o pedido da API do iFood (GET /order/v1.0/orders/{id}) no formato do pedido do sistema.
 * Funcao pura (sem banco): facil de testar. Valores em centavos; o total sempre fecha com o do iFood
 * (diferencas de arredondamento ou taxas extras entram como taxa ou desconto).
 */
final class PedidoIfood
{
    /** Formas de pagamento do iFood -> forma do sistema (quando o cliente paga na entrega ou na retirada). */
    private const FORMA_OFFLINE = [
        'CASH' => 'dinheiro', 'CREDIT' => 'credito', 'DEBIT' => 'debito', 'PIX' => 'pix_entrega',
        'MEAL_VOUCHER' => 'credito', 'FOOD_VOUCHER' => 'credito', 'DIGITAL_WALLET' => 'credito', 'OTHER' => 'credito',
    ];

    private const NOME_FORMA = [
        'CASH' => 'Dinheiro', 'CREDIT' => 'Cartão de crédito', 'DEBIT' => 'Cartão de débito', 'PIX' => 'Pix',
        'MEAL_VOUCHER' => 'Vale-refeição', 'FOOD_VOUCHER' => 'Vale-alimentação', 'DIGITAL_WALLET' => 'Carteira digital',
        'GIFT_CARD' => 'Cartão-presente', 'OTHER' => 'Outra forma',
    ];

    /**
     * @param array<string,mixed> $o pedido como o iFood devolve
     * @return array<string,mixed>
     */
    public static function converter(array $o): array
    {
        $id = (string) ($o['id'] ?? '');
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Pedido do iFood sem identificador.');
        }
        $tipoIfood = strtoupper((string) ($o['orderType'] ?? 'DELIVERY'));
        $tipo = $tipoIfood === 'DELIVERY' ? 'entrega' : 'retirada';
        $entregue = strtoupper((string) ($o['delivery']['deliveredBy'] ?? ''));
        $entreguePor = $tipo === 'entrega' ? ($entregue === 'MERCHANT' ? 'loja' : 'ifood') : null;

        [$itens, $subtotal] = self::itens(is_array($o['items'] ?? null) ? $o['items'] : []);
        $t = is_array($o['total'] ?? null) ? $o['total'] : [];
        $taxa = self::centavos($t['deliveryFee'] ?? 0) + self::centavos($t['additionalFees'] ?? 0);
        $desconto = self::centavos($t['benefits'] ?? 0);
        $calculado = $subtotal + $taxa - $desconto;
        $total = isset($t['orderAmount']) && is_numeric($t['orderAmount']) ? self::centavos($t['orderAmount']) : $calculado;
        if ($total !== $calculado) {
            $diferenca = $total - $calculado;
            if ($diferenca > 0) {
                $taxa += $diferenca;
            } else {
                $desconto -= $diferenca;
            }
        }
        if ($desconto > $subtotal + $taxa) {
            $desconto = $subtotal + $taxa;
        }
        $total = max(0, $subtotal + $taxa - $desconto);

        $pagamento = self::pagamento(is_array($o['payments'] ?? null) ? $o['payments'] : [], $entreguePor, $tipo, $total);
        $display = mb_substr(trim((string) ($o['displayId'] ?? '')), 0, 20);
        $cliente = is_array($o['customer'] ?? null) ? $o['customer'] : [];
        $telefone = is_array($cliente['phone'] ?? null) ? $cliente['phone'] : [];

        $agendadoPara = strtoupper((string) ($o['orderTiming'] ?? '')) === 'SCHEDULED'
            ? self::data($o['schedule']['deliveryDateTimeStart'] ?? $o['delivery']['deliveryDateTime'] ?? $o['takeout']['takeoutDateTime'] ?? null) : null;
        $observacoes = array_filter([
            trim((string) ($o['delivery']['observations'] ?? '')),
            trim((string) ($o['extraInfo'] ?? '')),
            $tipoIfood === 'DINE_IN' ? 'Comer no local' . (!empty($o['indoor']['table']) ? ', mesa ' . trim((string) $o['indoor']['table']) : '') . '.' : '',
            $agendadoPara !== null ? 'Pedido agendado para ' . date('d/m H:i', (int) strtotime($agendadoPara)) . '.' : '',
        ], static fn (string $v): bool => $v !== '');

        $cupons = [];
        foreach (is_array($o['benefits'] ?? null) ? $o['benefits'] : [] as $b) {
            if (!is_array($b)) {
                continue;
            }
            $quem = [];
            foreach (is_array($b['sponsorshipValues'] ?? null) ? $b['sponsorshipValues'] : [] as $s) {
                if (is_array($s) && self::centavos($s['value'] ?? 0) > 0) {
                    $quem[] = strtoupper((string) ($s['name'] ?? '')) === 'IFOOD' ? 'iFood' : 'loja';
                }
            }
            $cupons[] = [
                'valor' => self::centavos($b['value'] ?? 0),
                'descricao' => mb_substr(trim((string) ($b['campaign']['name'] ?? $b['description'] ?? 'Cupom do iFood')), 0, 80),
                'pago_por' => $quem === [] ? null : implode(' e ', array_values(array_unique($quem))),
            ];
        }

        $dados = [
            'tipo_ifood' => $tipoIfood,
            'agendado_para' => $agendadoPara,
            'telefone' => self::telefone($telefone),
            'documento' => preg_match('/\A[0-9]{11,14}\z/', (string) ($cliente['documentNumber'] ?? '')) === 1 ? (string) $cliente['documentNumber'] : null,
            'pedidos_na_loja' => is_numeric($cliente['ordersCountOnMerchant'] ?? null) ? (int) $cliente['ordersCountOnMerchant'] : null,
            'pagamento' => $pagamento['texto'],
            'cupons' => $cupons,
            'avisos' => [],
            'entregador' => null,
        ];

        return [
            'ifood_id' => $id,
            'display_id' => $display !== '' ? $display : null,
            'tipo' => $tipo,
            'entregue_por' => $entreguePor,
            'codigo_coleta' => self::codigo($o['delivery']['pickupCode'] ?? null),
            'cliente_nome' => mb_substr(trim((string) ($cliente['name'] ?? '')) ?: 'Cliente iFood', 0, 80),
            'endereco' => $tipo === 'entrega' ? self::endereco(is_array($o['delivery']['deliveryAddress'] ?? null) ? $o['delivery']['deliveryAddress'] : []) : null,
            'itens' => $itens,
            'subtotal' => $subtotal,
            'taxa_entrega' => $taxa,
            'desconto' => $desconto,
            'total' => $total,
            'forma_pagamento' => $pagamento['forma'],
            'pagamento_status' => $pagamento['pago'] ? 'pago' : 'pendente',
            'troco_para' => $pagamento['troco'],
            'observacao' => $observacoes === [] ? null : mb_substr(implode(' ', $observacoes), 0, 300),
            'criado_em' => self::data($o['createdAt'] ?? null),
            'dados' => $dados,
        ];
    }

    /**
     * @param list<mixed> $lista
     * @return array{0:list<array<string,mixed>>, 1:int} itens e subtotal em centavos
     */
    private static function itens(array $lista): array
    {
        $itens = [];
        $subtotal = 0;
        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qtdIfood = is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 1.0;
            $qtdIfood = $qtdIfood > 0 ? $qtdIfood : 1.0;
            $unidade = strtoupper(trim((string) ($item['unit'] ?? 'UN')));
            $totalItem = isset($item['totalPrice']) && is_numeric($item['totalPrice'])
                ? self::centavos($item['totalPrice'])
                : (int) round((self::centavos($item['unitPrice'] ?? $item['price'] ?? 0) + self::centavos($item['optionsPrice'] ?? 0)) * $qtdIfood);
            $totalItem = max(0, $totalItem);
            $nome = trim((string) ($item['name'] ?? '')) ?: 'Item do iFood';
            $inteiro = abs($qtdIfood - round($qtdIfood)) < 0.0001;
            $porUnidade = $unidade === '' || $unidade === 'UN' || $unidade === 'UNIT';
            if ($porUnidade && $inteiro && $qtdIfood >= 1 && $qtdIfood <= 99 && $totalItem % (int) round($qtdIfood) === 0) {
                $quantidade = (int) round($qtdIfood);
            } else {
                $quantidade = 1;
                $nome .= $porUnidade ? ' (' . self::numero($qtdIfood) . ' un.)' : ' (' . self::numero($qtdIfood) . ' ' . mb_strtolower($unidade) . ')';
            }
            $complementos = [];
            foreach (is_array($item['options'] ?? null) ? $item['options'] : [] as $op) {
                if (!is_array($op) || trim((string) ($op['name'] ?? '')) === '') {
                    continue;
                }
                $q = is_numeric($op['quantity'] ?? null) ? (float) $op['quantity'] : 1.0;
                $texto = ($q > 1 ? self::numero($q) . 'x ' : '') . trim((string) $op['name']);
                $extras = [];
                foreach (is_array($op['customizations'] ?? null) ? $op['customizations'] : [] as $c) {
                    if (is_array($c) && trim((string) ($c['name'] ?? '')) !== '') {
                        $extras[] = trim((string) $c['name']);
                    }
                }
                $complementos[] = $texto . ($extras !== [] ? ' (' . implode(', ', $extras) . ')' : '');
            }
            $obs = trim((string) ($item['observations'] ?? ''));
            $observacao = trim(implode(' · ', array_filter([implode(', ', $complementos), $obs !== '' ? 'Obs.: ' . $obs : ''])));
            $itens[] = [
                'nome' => mb_substr($nome, 0, 180),
                'quantidade' => $quantidade,
                'preco_unitario' => intdiv($totalItem, $quantidade),
                'total' => $totalItem,
                'observacao' => $observacao !== '' ? mb_substr($observacao, 0, 400) : null,
                'ifood_item_id' => is_string($item['id'] ?? null) ? mb_substr($item['id'], 0, 64) : null,
                'codigo_externo' => is_scalar($item['externalCode'] ?? null) && trim((string) $item['externalCode']) !== '' ? mb_substr(trim((string) $item['externalCode']), 0, 80) : null,
                'nome_ifood' => mb_substr(trim((string) ($item['name'] ?? '')), 0, 180),
                'baixa' => $porUnidade && $inteiro ? max(1, (int) round($qtdIfood)) : 1,
            ];
            $subtotal += $totalItem;
        }
        return [$itens, $subtotal];
    }

    /**
     * @param array<string,mixed> $p
     * @return array{forma:string, pago:bool, troco:?int, texto:string}
     */
    private static function pagamento(array $p, ?string $entreguePor, string $tipo, int $total): array
    {
        $metodos = array_values(array_filter(is_array($p['methods'] ?? null) ? $p['methods'] : [], 'is_array'));
        $offline = array_values(array_filter($metodos, static fn (array $m): bool => strtoupper((string) ($m['type'] ?? '')) === 'OFFLINE' && ($m['prepaid'] ?? false) !== true));
        $descrever = static function (array $m): string {
            $metodo = strtoupper((string) ($m['method'] ?? 'OTHER'));
            $nome = self::NOME_FORMA[$metodo] ?? 'Outra forma';
            $extra = trim((string) ($m['card']['brand'] ?? $m['wallet']['name'] ?? ''));
            return $nome . ($extra !== '' ? ' ' . $extra : '');
        };
        if ($offline === []) {
            $nomes = array_values(array_unique(array_map($descrever, $metodos)));
            return ['forma' => 'ifood', 'pago' => true, 'troco' => null, 'texto' => 'Pago pelo app do iFood' . ($nomes !== [] ? ' (' . implode(', ', $nomes) . ')' : '') . '.'];
        }
        usort($offline, static fn (array $a, array $b): int => self::centavos($b['value'] ?? 0) <=> self::centavos($a['value'] ?? 0));
        $principal = $offline[0];
        $metodo = strtoupper((string) ($principal['method'] ?? 'OTHER'));
        $cobrar = self::centavos($p['pending'] ?? 0) ?: $total;
        $trocoPara = $metodo === 'CASH' && is_numeric($principal['cash']['changeFor'] ?? null) ? self::centavos($principal['cash']['changeFor']) : null;
        $jaPago = self::centavos($p['prepaid'] ?? 0);
        $texto = $descrever($principal) . ($trocoPara !== null && $trocoPara > $cobrar ? ', troco para ' . self::reais($trocoPara) : '') . '.';
        if ($jaPago > 0) {
            $texto .= ' Já pago no app: ' . self::reais($jaPago) . '; cobrar ' . self::reais($cobrar) . '.';
        }
        if ($entreguePor === 'ifood') {
            // O entregador do iFood cobra o cliente; o valor vem no repasse do iFood.
            return ['forma' => 'ifood', 'pago' => true, 'troco' => null, 'texto' => 'O cliente paga ao entregador do iFood: ' . $texto];
        }
        return [
            'forma' => self::FORMA_OFFLINE[$metodo] ?? 'credito',
            'pago' => false,
            'troco' => $trocoPara !== null && $trocoPara >= $total ? $trocoPara : null,
            'texto' => ($tipo === 'entrega' ? 'Cobrar na entrega: ' : 'Cobrar na retirada: ') . $texto,
        ];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private static function endereco(array $a): array
    {
        $texto = static fn (mixed $v, int $max): ?string => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $cep = preg_replace('/\D/', '', (string) ($a['postalCode'] ?? ''));
        $estado = strtoupper(trim((string) ($a['state'] ?? '')));
        $lat = $a['coordinates']['latitude'] ?? null;
        $lng = $a['coordinates']['longitude'] ?? null;
        $temCoordenada = is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180 && ((float) $lat !== 0.0 || (float) $lng !== 0.0);
        return [
            'logradouro' => $texto($a['streetName'] ?? null, 150) ?? $texto($a['formattedAddress'] ?? null, 150) ?? 'Endereço informado no iFood',
            'numero' => $texto($a['streetNumber'] ?? null, 20) ?? 'S/N',
            'complemento' => $texto($a['complement'] ?? null, 100),
            'bairro' => $texto($a['neighborhood'] ?? null, 100) ?? '-',
            'cidade' => $texto($a['city'] ?? null, 100) ?? '-',
            'estado' => preg_match('/\A[A-Z]{2}\z/', $estado) === 1 ? $estado : null,
            'cep' => strlen((string) $cep) === 8 ? $cep : null,
            'referencia' => $texto($a['reference'] ?? null, 150),
            'latitude' => $temCoordenada ? round((float) $lat, 7) : null,
            'longitude' => $temCoordenada ? round((float) $lng, 7) : null,
        ];
    }

    /** Telefone do iFood (0800 + codigo localizador): o cliente nao aparece com o numero real. */
    private static function telefone(array $t): ?string
    {
        $numero = trim((string) ($t['number'] ?? ''));
        if ($numero === '') {
            return null;
        }
        $localizador = trim((string) ($t['localizer'] ?? ''));
        return mb_substr($numero . ($localizador !== '' ? ', código ' . $localizador : ''), 0, 60);
    }

    private static function codigo(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        return preg_match('/\A[A-Za-z0-9-]{1,20}\z/', $v) === 1 ? $v : null;
    }

    /** Data ISO do iFood no fuso do sistema ('Y-m-d H:i:s'). */
    public static function data(mixed $iso): ?string
    {
        if (!is_string($iso) || trim($iso) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public static function centavos(mixed $valor): int
    {
        return is_numeric($valor) ? (int) round((float) $valor * 100) : 0;
    }

    public static function decimal(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    private static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }

    private static function numero(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',');
    }
}
