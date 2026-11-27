<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Modules\Inventory\EstoqueInsuficiente;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Repositories\Gerencia\TaxaPagamentoRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Services\Descontos\DescontoPedido;
use Cardapim\Shared\Numero;
use Cardapim\Shared\Validation\Documento;
use Cardapim\Modules\Catalog\Combo;
use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Shared\Esquema;
use PDO;
use Throwable;

/**
 * Pedido feito pelo cliente no cardapio online. O navegador manda so ids, quantidades e escolhas:
 * precos, taxa de entrega, desconto (cupom ou fidelidade), total e disponibilidade sao calculados AQUI, de novo.
 */
final class CriarPedido
{
    private const FORMAS = ['pix_online' => 'pag_pix_online', 'pix_comprovante' => 'pag_pix_comprovante', 'pix_entrega' => 'pag_pix_entrega', 'debito' => 'pag_debito', 'credito' => 'pag_credito', 'dinheiro' => 'pag_dinheiro'];
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
                ? ['status' => 200, 'ok' => true, 'mensagem' => 'Pedido já enviado.', 'pedido' => ['id' => (int) $repetido['id'], 'numero' => NumeroDoDia::de($repetido)]]
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
        $pixOnlineAtivo = $config['pag_pix_online'] === 1 && $this->pix->disponivel($idRest);
        // Pix pela chave do restaurante (com comprovante): so com os dados completos e sem o Asaas ligado.
        $pixChave = (new EntregaPagamentoRepository($this->db))->pixChave($idRest);
        $pixChaveAtivo = !$pixOnlineAtivo && $pixChave['ligado'];
        if (!isset(self::FORMAS[$forma]) || $config[self::FORMAS[$forma]] !== 1 || ($forma === 'pix_online' && !$pixOnlineAtivo)
            || ($forma === 'pix_comprovante' && !$pixChaveAtivo) || ($forma === 'pix_entrega' && ($pixOnlineAtivo || $pixChaveAtivo))) {
            return self::erro(422, 'Escolha uma forma de pagamento.', 'forma_pagamento');
        }
        $info = (new CardapioPublico($this->db))->informacoes($idRest);
        if ($info['horario_informado'] && !$info['aberto']) {
            return self::erro(409, 'O restaurante está fechado agora. Confira o horário de funcionamento.');
        }

        // Itens: precos, porcoes e disponibilidade de agora.
        $formato = LinhasSacola::formato($e['itens'] ?? null, self::MAX_LINHAS);
        if ($formato === null) {
            return self::erro(422, 'Confira os itens da sacola.');
        }
        if ($formato === [] || count($formato) > self::MAX_LINHAS) {
            return self::erro(422, $formato === [] ? 'Sua sacola está vazia.' : 'Pedido grande demais. Fale com o restaurante.');
        }
        $ids = array_values(array_unique(array_column($formato, 'id')));
        $completas = LinhasSacola::completar($this->db, $idRest, $formato);
        if ($completas['invalida']) {
            return self::erro(409, $completas['mensagem'] ?? 'O restaurante mudou as porções de algum item. Confira a sacola.');
        }
        $disp = (new DisponibilidadeProdutos($this->db))->verificar($idRest, $ids);
        $indisponiveis = array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $completas['ausentes'], true) || !($disp[$id]['disponivel'] ?? false)));
        if ($indisponiveis !== []) {
            return self::erro(409, 'Alguns itens acabaram. Confira a sacola.') + ['indisponiveis' => $indisponiveis];
        }
        $linhas = $completas['linhas'];
        $subtotal = 0;
        $quantidades = [];
        $extras = []; // insumos gastos pelos complementos escolhidos
        foreach ($linhas as $l) {
            $subtotal += $l['total'];
            if (isset($l['combo'])) {
                // Combo: o estoque baixa de cada item do combo (e da ficha das opcoes sem produto ligado).
                foreach ($l['combo']['produtos'] as $idProduto => $m) {
                    $quantidades[$idProduto] = ($quantidades[$idProduto] ?? 0) + $m;
                }
                foreach ($l['combo']['insumos'] as $idInsumo => $m) {
                    $extras[$idInsumo] = ($extras[$idInsumo] ?? 0) + $m;
                }
                continue;
            }
            $quantidades[$l['id']] = ($quantidades[$l['id']] ?? 0) + $l['milesimos'];
            foreach ($l['sabores'] ?? [] as $idSabor => $m) { // Meio a Meio: fracao do estoque de cada sabor
                $quantidades[$idSabor] = ($quantidades[$idSabor] ?? 0) + $m;
            }
            foreach ($l['insumos_complementos'] ?? [] as $idInsumo => $m) {
                $extras[$idInsumo] = ($extras[$idInsumo] ?? 0) + $m;
            }
        }

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
                return self::erro($entrega['motivo'] === 'erro_rota' ? 503 : 422, $entrega['mensagem'], 'endereco');
            }
            $taxa = Numero::centavos($entrega['taxa']);
            if ($entrega['pedido_minimo'] !== null && $subtotal < Numero::centavos($entrega['pedido_minimo'])) {
                return self::erro(422, 'O pedido mínimo para entrega neste endereço é ' . Numero::reais($entrega['pedido_minimo']) . '.');
            }
        }
        // Desconto: cupom digitado OU recompensa da fidelidade (nao somam). Conferido de novo dentro da transacao.
        $cupomTexto = is_string($e['cupom'] ?? null) ? mb_substr($e['cupom'], 0, 40) : '';
        $descontos = new DescontoPedido($this->db);
        $desconto = $descontos->calcular($idRest, $idCliente, $subtotal, $cupomTexto);
        if (!$desconto['ok']) {
            return self::erro(422, (string) $desconto['mensagem'], 'cupom');
        }
        // Taxa da forma de pagamento (opcional): sobre itens + entrega - desconto. O cliente viu o valor na tela;
        // se a regra mudou desde entao, ele confere de novo (409), como no desconto.
        $taxasRepo = new TaxaPagamentoRepository($this->db);
        $regraTaxa = $taxasRepo->regras($idRest)[$forma] ?? null;
        $taxaPagamento = TaxaPagamento::calcular($subtotal + $taxa - $desconto['centavos'], $regraTaxa);
        $esperada = $e['taxa_pagamento_centavos'] ?? null;
        // Clientes antigos sem taxa continuam funcionando. Uma taxa positiva exige
        // a confirmacao do valor exibido, inclusive se o campo veio ausente ou invalido.
        if (($taxaPagamento > 0 || $esperada !== null) && (!is_int($esperada) || $esperada !== $taxaPagamento)) {
            return self::erro(409, 'A taxa da forma de pagamento mudou. Confira o total e tente de novo.', 'forma_pagamento') + ['taxa_pagamento_centavos' => $taxaPagamento];
        }
        $total = $subtotal + $taxa - $desconto['centavos'] + $taxaPagamento;

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
        $status = in_array($forma, ['pix_online', 'pix_comprovante'], true) ? 'aguardando_pagamento' : 'recebido';
        $this->db->beginTransaction();
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->db->prepare('SELECT id FROM restaurantes WHERE id = ? FOR UPDATE')->execute([$idRest]);
            }
            // Confere novamente o horário do Perfil sob a trava: ele pode ter mudado durante o checkout.
            // O fechamento do iFood não bloqueia os pedidos recebidos diretamente no Deliveryzim.
            $horarioAtual = (new CardapioPublico($this->db))->informacoes($idRest);
            if ($horarioAtual['horario_informado'] && !$horarioAtual['aberto']) {
                $this->db->rollBack();
                return self::erro(409, 'O restaurante fechou agora. Confira o horário de funcionamento.');
            }
            // Com o restaurante travado, ninguem mais grava pedido agora: o limite do cupom e a fidelidade nao estouram.
            if ($desconto['origem'] !== null) {
                $agoraDesconto = $descontos->calcular($idRest, $idCliente, $subtotal, $cupomTexto);
                if (!$agoraDesconto['ok'] || $agoraDesconto['origem'] !== $desconto['origem'] || $agoraDesconto['centavos'] !== $desconto['centavos']) {
                    $this->db->rollBack();
                    return self::erro(409, $agoraDesconto['ok'] ? 'O desconto mudou. Confira o total e tente de novo.' : (string) $agoraDesconto['mensagem'], 'cupom');
                }
            }
            // A gerencia usa a mesma trava para salvar configuracao e taxas.
            $configAtual = (new EntregaPagamentoRepository($this->db))->config($idRest);
            if ($configAtual[self::FORMAS[$forma]] !== 1
                || ($forma === 'pix_entrega' && (($configAtual['pag_pix_online'] === 1 && $this->pix->disponivel($idRest)) || $configAtual['pag_pix_comprovante'] === 1))
                || ($forma === 'pix_comprovante' && !(new EntregaPagamentoRepository($this->db))->pixChave($idRest)['ligado'])) {
                $this->db->rollBack();
                return self::erro(409, 'A forma de pagamento mudou. Confira as opções e tente de novo.', 'forma_pagamento');
            }
            if ($configAtual[$tipo === 'entrega' ? 'aceita_entrega' : 'aceita_retirada'] !== 1) {
                $this->db->rollBack();
                return self::erro(409, 'A opção de entrega ou retirada mudou. Confira e tente de novo.', 'tipo');
            }
            $regraAtual = $taxasRepo->regras($idRest)[$forma] ?? null;
            if ($regraAtual !== $regraTaxa || TaxaPagamento::calcular($subtotal + $taxa - $desconto['centavos'], $regraAtual) !== $taxaPagamento) {
                $this->db->rollBack();
                return self::erro(409, 'A taxa da forma de pagamento mudou. Confira o total e tente de novo.', 'forma_pagamento');
            }
            $n = $this->db->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM pedidos WHERE id_restaurante = ?');
            $n->execute([$idRest]);
            $numero = (int) $n->fetchColumn();
            $tempoMin = $tipo === 'entrega' ? $config['entrega_minutos_min'] : $config['retirada_minutos'];
            $tempoMax = $tipo === 'entrega' ? $config['entrega_minutos_max'] : $config['retirada_minutos'];
            // Taxa da forma de pagamento: colunas so existem depois da migracao (sem a migracao nao ha regra, a taxa e 0).
            $comTaxa = $taxasRepo->disponivel();
            $this->db->prepare('INSERT INTO pedidos (id_restaurante, numero, id_cliente, id_user, origem, tipo, status, forma_pagamento, pagamento_status, troco_para,
                ' . ($comTaxa ? 'taxa_pagamento, taxa_pagamento_rotulo, ' : '') . 'subtotal, taxa_entrega, desconto, desconto_origem, id_cupom, cupom_codigo, motivo_desconto, total, observacao, cliente_nome, cliente_whatsapp, entrega_cep, entrega_logradouro, entrega_numero,
                entrega_complemento, entrega_bairro, entrega_cidade, entrega_estado, entrega_referencia, entrega_latitude, entrega_longitude,
                entrega_distancia_km, id_area_entrega, area_descricao, previsao_minutos_min, previsao_minutos_max, chave_idempotencia, criado_em, atualizado_em)
                VALUES (?, ?, ?, NULL, \'cardapio\', ?, ?, ?, \'pendente\', ?, ' . ($comTaxa ? '?, ?, ' : '') . '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $idRest, $numero, $idCliente, $tipo, $status, $forma, $troco,
                    ...($comTaxa ? [self::dec($taxaPagamento), $taxaPagamento > 0 && $regraTaxa !== null ? TaxaPagamento::rotulo($forma, $regraTaxa) : null] : []),
                    self::dec($subtotal), self::dec($taxa), self::dec($desconto['centavos']), $desconto['origem'], $desconto['id_cupom'], $desconto['codigo'], $desconto['motivo'],
                    self::dec($total), $obsPedido !== '' ? $obsPedido : null,
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
            // Numero do dia (so visual, zera quando a loja fecha): e o que a equipe e o cliente veem.
            $numeroCorrido = $numero;
            $numero = NumeroDoDia::atribuir($this->db, $idRest, $idPedido, $numeroCorrido);
            $pedido['numero_dia'] = $numero;

            $comComplementos = Esquema::tem($this->db, 'complementos');
            $insItem = $this->db->prepare($comComplementos
                ? 'INSERT INTO pedido_itens (id_pedido, id_restaurante, id_produto, nome, preco_unitario, quantidade, unidade, observacao, total, criado_em, complementos_texto)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO pedido_itens (id_pedido, id_restaurante, id_produto, nome, preco_unitario, quantidade, unidade, observacao, total, criado_em)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($linhas as $l) {
                if (isset($l['combo'])) {
                    $this->gravarCombo($idPedido, $idRest, $l, $agora);
                    continue;
                }
                $valoresItem = [$idPedido, $idRest, $l['id'], $l['nome'], self::dec($l['centavos']), QuantidadeVenda::paraBanco($l['milesimos']), $l['unidade'],
                    $l['observacao'] !== '' ? $l['observacao'] : null, self::dec($l['total']), $agora];
                if ($comComplementos) {
                    $valoresItem[] = ($l['complementos_texto'] ?? '') !== '' ? $l['complementos_texto'] : null;
                }
                $insItem->execute($valoresItem);
                if ($comComplementos && ($l['complementos'] ?? []) !== []) {
                    Complementos::gravarNoItem($this->db, $idRest, (int) $this->db->lastInsertId(), $l['complementos']);
                }
            }
            $consumo = (new BaixaEstoque($this->db))->baixarMilesimos($idRest, $quantidades, $extras);
            if ($consumo !== []) {
                $this->db->prepare('UPDATE pedidos SET consumo_estoque = ? WHERE id = ? AND id_restaurante = ?')
                    ->execute([json_encode($consumo, JSON_THROW_ON_ERROR), $idPedido, $idRest]);
            }
            $repo = new PedidoRepository($this->db);
            $repo->registrarEvento($idRest, $idPedido, $status, $status === 'recebido' ? 'Pedido feito pelo cardápio online.' : 'Pedido feito. Aguardando o Pix.', null, $idCliente);
            if ($status === 'recebido') {
                TempoReal::registrar($this->db, $idRest, 'pedidos', 'pedido.novo', ['id' => $idPedido, 'numero' => $numero]);
                AvisosWhatsApp::enfileirar($this->db, $idRest, $idPedido, 'recebido');
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

        if ($forma === 'pix_comprovante') {
            try {
                PixChavePedido::gerar($this->db, $pedido + ['numero' => $numero], $pixChave);
            } catch (Throwable $erro) {
                error_log('[Cardapim] Pix pela chave do pedido ' . $idPedido . ' nao gerado: ' . get_class($erro));
                (new CancelarPedido($this->db))->cancelar($idRest, $idPedido, 'Não foi possível gerar o Pix.', null, $idCliente);
                return self::erro(502, 'Não conseguimos gerar o Pix agora. Escolha outra forma de pagamento ou fale com o restaurante.', 'forma_pagamento');
            }
        }
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

    /**
     * Combo do cardapio online: grava os itens separados (mesmo combo_lote), com o preco do combo ja dividido.
     * As escolhas ficam na primeira linha (o cliente ve o que pediu); todas levam o nome do combo.
     * @param array<string,mixed> $l linha de LinhasSacola com 'combo' (Combo::montar)
     */
    private function gravarCombo(int $idPedido, int $idRest, array $l, string $agora): void
    {
        $nome = Combo::rotulo((string) $l['nome'], (int) $l['quantidade']);
        $lote = Combo::novoLote();
        $ins = $this->db->prepare('INSERT INTO pedido_itens (id_pedido, id_restaurante, id_produto, nome, preco_unitario, quantidade, unidade, observacao, total, criado_em,
                complementos_texto, combo_lote, combo_nome) VALUES (?, ?, ?, ?, ?, ?, \'un\', ?, ?, ?, ?, ?, ?)');
        foreach ($l['combo']['linhas'] as $i => $linha) {
            $ins->execute([$idPedido, $idRest, $linha['id_produto'], mb_substr($linha['nome'], 0, 180), self::dec($linha['centavos']),
                QuantidadeVenda::paraBanco($linha['unidades'] * 1000), $i === 0 && $l['observacao'] !== '' ? $l['observacao'] : null,
                self::dec($linha['centavos'] * $linha['unidades']), $agora, 'Combo: ' . $nome, $lote, $nome]);
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
