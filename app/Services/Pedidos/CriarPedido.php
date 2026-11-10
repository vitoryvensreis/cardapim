<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Modules\Inventory\EstoqueInsuficiente;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Shared\Numero;
use Cardapim\Shared\Validation\Documento;
use PDO;
use Throwable;

/**
 * Pedido feito pelo cliente no cardapio online. O navegador manda so ids, quantidades e escolhas:
 * precos, taxa de entrega, total e disponibilidade sao calculados AQUI, de novo.
 */
final class CriarPedido
{
    private const FORMAS = ['pix_online' => 'pag_pix_online', 'pix_entrega' => 'pag_pix_entrega', 'debito' => 'pag_debito', 'credito' => 'pag_credito', 'dinheiro' => 'pag_dinheiro'];
    private const MAX_LINHAS = 50;

    public function __construct(
        private readonly PDO $db,
        private readonly VerificadorEntrega $verificador,
        private readonly PixPedido $pix,
    ) {
    }

    /**
     * @param array{id:int,nome:string,slug:string} $restaurante
     * @param array<string,mixed> $cliente linha de clientes
     * @param array<string,mixed> $e corpo JSON
     * @return array{status:int, ok:bool, mensagem:string, campo?:string, erros?:array<string,string>, pedido?:array{id:int,numero:int}, indisponiveis?:list<int>}
     */
    public function criar(array $restaurante, array $cliente, array $e, string $ip): array
    {
        $idRest = (int) $restaurante['id'];
        $idCliente = (int) $cliente['id'];
        $chave = is_string($e['chave'] ?? null) ? strtolower($e['chave']) : '';
        if (preg_match('/\A[a-f0-9]{32}\z/', $chave) !== 1) {
            return self::erro(400, 'Atualize a página e tente de novo.');
        }
        $repetido = (new PedidoRepository($this->db))->porChave($idRest, $chave);
        if ($repetido !== null) {
            return (int) $repetido['id_cliente'] === $idCliente
                ? ['status' => 200, 'ok' => true, 'mensagem' => 'Pedido já enviado.', 'pedido' => ['id' => (int) $repetido['id'], 'numero' => (int) $repetido['numero']]]
                : self::erro(409, 'Atualize a página e tente de novo.');
        }
        $limite = RateLimiter::padrao(15, 3600);
        if ($limite->excedido('pedido-cliente|' . $idCliente) || $limite->excedido('pedido-ip|' . $ip)) {
            return self::erro(429, 'Muitos pedidos em pouco tempo. Aguarde alguns minutos ou fale com o restaurante.');
        }

        $config = (new EntregaPagamentoRepository($this->db))->config($idRest);
        $tipo = ($e['tipo'] ?? '') === 'retirada' ? 'retirada' : (($e['tipo'] ?? '') === 'entrega' ? 'entrega' : '');
        if ($tipo === '' || ($tipo === 'entrega' && $config['aceita_entrega'] !== 1) || ($tipo === 'retirada' && $config['aceita_retirada'] !== 1)) {
            return self::erro(422, 'Escolha como quer receber o pedido.', 'tipo');
        }
        $forma = is_string($e['forma_pagamento'] ?? null) ? $e['forma_pagamento'] : '';
        if (!isset(self::FORMAS[$forma]) || $config[self::FORMAS[$forma]] !== 1 || ($forma === 'pix_online' && !$this->pix->disponivel($idRest))) {
            return self::erro(422, 'Escolha uma forma de pagamento.', 'forma_pagamento');
        }
        $info = (new CardapioPublico($this->db))->informacoes($idRest);
        if ($info['horario_informado'] && !$info['aberto']) {
            return self::erro(409, 'O restaurante está fechado agora. Confira o horário de funcionamento.');
        }

        // Itens: precos e disponibilidade de agora.
        $linhas = [];
        foreach (array_slice(is_array($e['itens'] ?? null) ? $e['itens'] : [], 0, self::MAX_LINHAS + 1) as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            $qtd = is_array($item) ? ($item['quantidade'] ?? null) : null;
            if (!is_int($id) || $id <= 0 || !is_int($qtd) || $qtd < 1 || $qtd > 99) {
                return self::erro(422, 'Confira os itens da sacola.');
            }
            $obs = is_string($item['observacao'] ?? null) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $item['observacao'])), 0, 140) : '';
            $linhas[] = ['id' => $id, 'quantidade' => $qtd, 'observacao' => $obs];
        }
        if ($linhas === [] || count($linhas) > self::MAX_LINHAS) {
            return self::erro(422, $linhas === [] ? 'Sua sacola está vazia.' : 'Pedido grande demais. Fale com o restaurante.');
        }
        $ids = array_values(array_unique(array_column($linhas, 'id')));
        $q = $this->db->prepare('SELECT id, nome, preco FROM produtos WHERE id_restaurante = ? AND ativo = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute([$idRest, ...$ids]);
        $produtos = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $produtos[(int) $p['id']] = $p;
        }
        $disp = (new DisponibilidadeProdutos($this->db))->verificar($idRest, $ids);
        $indisponiveis = array_values(array_filter($ids, static fn (int $id): bool => !isset($produtos[$id]) || !($disp[$id]['disponivel'] ?? false)));
        if ($indisponiveis !== []) {
            return self::erro(409, 'Alguns itens acabaram. Confira a sacola.') + ['indisponiveis' => $indisponiveis];
        }
        $subtotal = 0;
        $quantidades = [];
        foreach ($linhas as &$l) {
            $l['nome'] = (string) $produtos[$l['id']]['nome'];
            $l['centavos'] = Numero::centavos((string) $produtos[$l['id']]['preco']);
            $subtotal += $l['centavos'] * $l['quantidade'];
            $quantidades[$l['id']] = ($quantidades[$l['id']] ?? 0) + $l['quantidade'];
        }
        unset($l);

        // Entrega: endereco, area e taxa (sempre recalculados aqui).
        $endereco = null;
        $entrega = null;
        $taxa = 0;
        if ($tipo === 'entrega') {
            $norm = Endereco::normalizar($e['endereco'] ?? null);
            if ($norm['erros'] !== []) {
                return self::erro(422, 'Confira o endereço de entrega.', 'endereco') + ['erros' => $norm['erros']];
            }
            $endereco = $norm['dados'];
            $entrega = $this->verificador->verificar($idRest, $endereco);
            if (!$entrega['entrega']) {
                return self::erro(422, $entrega['mensagem'], 'endereco');
            }
            $taxa = Numero::centavos($entrega['taxa']);
            if ($entrega['pedido_minimo'] !== null && $subtotal < Numero::centavos($entrega['pedido_minimo'])) {
                return self::erro(422, 'O pedido mínimo para entrega neste endereço é ' . Numero::reais($entrega['pedido_minimo']) . '.');
            }
        }
        $total = $subtotal + $taxa;

        $troco = null;
        if ($forma === 'dinheiro' && is_string($e['troco_para'] ?? null) && trim($e['troco_para']) !== '') {
            $trocoDec = Numero::dinheiroParaDecimal($e['troco_para']);
            if ($trocoDec === null || Numero::centavos($trocoDec) < $total || Numero::centavos($trocoDec) > $total + 100000) {
                return self::erro(422, 'O troco precisa ser para um valor igual ou maior que o total (' . Numero::reais(self::dec($total)) . ').', 'troco_para');
            }
            $troco = $trocoDec;
        }
        $cpf = null;
        if ($forma === 'pix_online') {
            $cpf = !empty($cliente['cpf']) ? (string) $cliente['cpf'] : Documento::normalizarCpf(is_string($e['cpf'] ?? null) ? $e['cpf'] : '');
            if ($cpf === null || !Documento::cpfValido($cpf)) {
                return self::erro(422, 'Digite um CPF válido. Ele é exigido para gerar o Pix.', 'cpf');
            }
        }
        $obsPedido = is_string($e['observacao'] ?? null) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $e['observacao'])), 0, 300) : '';

        // Grava tudo de uma vez: pedido, itens, baixa de estoque, linha do tempo e aviso para as telas da equipe.
        $limite->permitir('pedido-cliente|' . $idCliente);
        $limite->permitir('pedido-ip|' . $ip);
        $agora = date('Y-m-d H:i:s');
        $status = $forma === 'pix_online' ? 'aguardando_pagamento' : 'recebido';
        $this->db->beginTransaction();
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->db->prepare('SELECT id FROM restaurantes WHERE id = ? FOR UPDATE')->execute([$idRest]);
            }
            $n = $this->db->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM pedidos WHERE id_restaurante = ?');
            $n->execute([$idRest]);
            $numero = (int) $n->fetchColumn();
            $tempoMin = $tipo === 'entrega' ? $config['entrega_minutos_min'] : $config['retirada_minutos'];
            $tempoMax = $tipo === 'entrega' ? $config['entrega_minutos_max'] : $config['retirada_minutos'];
            $this->db->prepare('INSERT INTO pedidos (id_restaurante, numero, id_cliente, id_user, origem, tipo, status, forma_pagamento, pagamento_status, troco_para,
                subtotal, taxa_entrega, desconto, total, observacao, cliente_nome, cliente_whatsapp, entrega_cep, entrega_logradouro, entrega_numero,
                entrega_complemento, entrega_bairro, entrega_cidade, entrega_estado, entrega_referencia, entrega_latitude, entrega_longitude,
                entrega_distancia_km, id_area_entrega, area_descricao, previsao_minutos_min, previsao_minutos_max, chave_idempotencia, criado_em, atualizado_em)
                VALUES (?, ?, ?, NULL, \'cardapio\', ?, ?, ?, \'pendente\', ?, ?, ?, \'0.00\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $idRest, $numero, $idCliente, $tipo, $status, $forma, $troco,
                    self::dec($subtotal), self::dec($taxa), self::dec($total), $obsPedido !== '' ? $obsPedido : null,
                    mb_substr((string) $cliente['nome'], 0, 80), (string) $cliente['whatsapp'],
                    $endereco !== null && $endereco['cep'] !== '' ? $endereco['cep'] : null,
                    $endereco['logradouro'] ?? null, $endereco['numero'] ?? null, self::vazioNulo($endereco['complemento'] ?? null),
                    $endereco['bairro'] ?? null, $endereco['cidade'] ?? null, self::vazioNulo($endereco['estado'] ?? null), self::vazioNulo($endereco['referencia'] ?? null),
                    $entrega['latitude'] ?? null, $entrega['longitude'] ?? null,
                    isset($entrega['distancia_km']) ? number_format((float) $entrega['distancia_km'], 2, '.', '') : null,
                    $entrega['area_id'] ?? null, $entrega !== null ? mb_substr($entrega['area'], 0, 140) : null,
                    $tempoMin, $tempoMax, $chave, $agora, $agora,
                ]);
            $pedido = (new PedidoRepository($this->db))->porChave($idRest, $chave);
            if ($pedido === null) {
                throw new \RuntimeException('Pedido nao gravado.');
            }
            $idPedido = (int) $pedido['id'];
            $insItem = $this->db->prepare('INSERT INTO pedido_itens (id_pedido, id_restaurante, id_produto, nome, preco_unitario, quantidade, observacao, total, criado_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($linhas as $l) {
                $insItem->execute([$idPedido, $idRest, $l['id'], $l['nome'], self::dec($l['centavos']), $l['quantidade'], $l['observacao'] !== '' ? $l['observacao'] : null,
                    self::dec($l['centavos'] * $l['quantidade']), $agora]);
            }
            $consumo = (new BaixaEstoque($this->db))->baixar($idRest, $quantidades);
            if ($consumo !== []) {
                $this->db->prepare('UPDATE pedidos SET consumo_estoque = ? WHERE id = ? AND id_restaurante = ?')
                    ->execute([json_encode($consumo, JSON_THROW_ON_ERROR), $idPedido, $idRest]);
            }
            $repo = new PedidoRepository($this->db);
            $repo->registrarEvento($idRest, $idPedido, $status, $status === 'recebido' ? 'Pedido feito pelo cardápio online.' : 'Pedido feito. Aguardando o Pix.', null, $idCliente);
            if ($status === 'recebido') {
                TempoReal::registrar($this->db, $idRest, 'pedidos', 'pedido.novo', ['id' => $idPedido, 'numero' => $numero]);
            }
            $this->db->commit();
        } catch (EstoqueInsuficiente) {
            $this->db->rollBack();
            return self::erro(409, 'Alguns ingredientes acabaram agora há pouco. Confira a sacola.') + ['indisponiveis' => $ids];
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }

        $this->lembrarDados($idCliente, $endereco, $cpf, $cliente);

        if ($forma === 'pix_online') {
            try {
                $this->pix->gerar($pedido + ['numero' => $numero], $cliente, (string) $cpf, (string) $restaurante['nome']);
            } catch (IntegracaoErro|Throwable $erro) {
                error_log('[Cardapim] Pix do pedido ' . $idPedido . ' nao gerado: ' . get_class($erro));
                (new CancelarPedido($this->db))->cancelar($idRest, $idPedido, 'Não foi possível gerar o Pix.', null, $idCliente);
                return self::erro(502, 'Não conseguimos gerar o Pix agora. Escolha outra forma de pagamento ou tente de novo em instantes.', 'forma_pagamento');
            }
        }
        return ['status' => 201, 'ok' => true, 'mensagem' => 'Pedido enviado!', 'pedido' => ['id' => $idPedido, 'numero' => $numero]];
    }

    /** Guarda o ultimo endereco (proximo pedido ja vem preenchido) e o CPF (se ainda nao for de outra conta). */
    private function lembrarDados(int $idCliente, ?array $endereco, ?string $cpf, array $cliente): void
    {
        try {
            if ($endereco !== null) {
                $this->db->prepare('UPDATE clientes SET cep = ?, logradouro = ?, numero = ?, complemento = ?, bairro = ?, cidade = ?, estado = ?, ponto_referencia = ?,
                    latitude = ?, longitude = ?, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?')->execute([
                    $endereco['cep'] !== '' ? $endereco['cep'] : null, $endereco['logradouro'], $endereco['numero'], self::vazioNulo($endereco['complemento']),
                    $endereco['bairro'], $endereco['cidade'], self::vazioNulo($endereco['estado']), self::vazioNulo($endereco['referencia']),
                    $endereco['latitude'], $endereco['latitude'] !== null ? $endereco['longitude'] : null, $idCliente,
                ]);
            }
            if ($cpf !== null && empty($cliente['cpf'])) {
                $q = $this->db->prepare('SELECT 1 FROM clientes WHERE cpf = ? AND id <> ?');
                $q->execute([$cpf, $idCliente]);
                if ($q->fetchColumn() === false) {
                    $this->db->prepare('UPDATE clientes SET cpf = ? WHERE id = ? AND cpf IS NULL')->execute([$cpf, $idCliente]);
                }
            }
        } catch (Throwable $erro) {
            error_log('[Cardapim] Endereco do cliente nao guardado: ' . get_class($erro));
        }
    }

    private static function dec(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }

    private static function vazioNulo(?string $v): ?string
    {
        return $v === null || $v === '' ? null : $v;
    }

    /** @return array{status:int, ok:false, mensagem:string, campo?:string} */
    private static function erro(int $status, string $mensagem, ?string $campo = null): array
    {
        return ['status' => $status, 'ok' => false, 'mensagem' => $mensagem] + ($campo !== null ? ['campo' => $campo] : []);
    }
}
