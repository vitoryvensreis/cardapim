<?php

declare(strict_types=1);

namespace Cardapim\Services\Caixa;

use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Geo\Geocodificador;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Impressao\FilaImpressao;
use Cardapim\Services\Pedidos\AvisosWhatsApp;
use Cardapim\Services\Pedidos\Endereco;
use Cardapim\Services\Pedidos\VerificadorEntrega;
use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Shared\Numero;
use PDO;
use Throwable;

/**
 * Entrega feita no caixa: a venda do balcao (itens ja lancados na tela de sempre) vira pedido de entrega.
 * - Telefone: puxa nome e ultimo endereco usados NESTE restaurante.
 * - Rua: procura na cidade do restaurante e devolve os bairros (com o CEP).
 * - Taxa: a mesma regra do cardapio online (VerificadorEntrega). Fora da area bloqueia; abaixo do minimo so avisa.
 * - Preparar pedido: pagamento agora (entra no caixa na hora) ou na entrega (entra no "Entregue e recebido").
 *   O pedido vai para a fila do delivery ja em preparo e a cozinha recebe so o que ainda nao tinha saido.
 * O servidor recalcula taxa e total; nada vindo do navegador e usado como valor.
 */
final class EntregaBalcaoService
{
    /** Formas presenciais do caixa (as mesmas do "Receber"). */
    public const FORMAS = ['dinheiro' => 'Dinheiro', 'pix' => 'Pix', 'credito' => 'Crédito', 'debito' => 'Débito'];
    /** Forma presencial -> forma gravada no pedido de entrega. */
    private const FORMA_PEDIDO = ['dinheiro' => 'dinheiro', 'pix' => 'pix_entrega', 'credito' => 'credito', 'debito' => 'debito'];

    private readonly PedidoRepository $pedidos;
    private readonly Geocodificador $geo;

    public function __construct(
        private readonly PDO $db,
        private readonly int $idRestaurante,
        private readonly UsuarioLogado $autor,
        ?Geocodificador $geo = null,
        private readonly ?VerificadorEntrega $verificador = null,
    ) {
        $this->pedidos = new PedidoRepository($db);
        $this->geo = $geo ?? new Geocodificador();
    }

    /** Telefone com DDD (10 ou 11 numeros); aceita +55, espacos e tracos. Null = invalido. */
    public static function telefone(string $texto): ?string
    {
        $d = (string) preg_replace('/\D+/', '', $texto);
        if (strlen($d) >= 12 && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }
        return preg_match('/\A[1-9][0-9]{9,10}\z/', $d) === 1 ? $d : null;
    }

    /**
     * Total do pedido de entrega: itens - desconto + taxa (sem servico). Desconto nunca passa do que ha para descontar.
     * @return array{subtotal:int,desconto:int,taxa:int,total:int}
     */
    public static function totais(int $subtotal, int $desconto, int $taxa): array
    {
        $desconto = max(0, min($desconto, $subtotal + $taxa));
        return ['subtotal' => $subtotal, 'desconto' => $desconto, 'taxa' => $taxa, 'total' => $subtotal + $taxa - $desconto];
    }

    /**
     * Troco: pagamento agora em dinheiro ("cliente entregou") ou na entrega ("troco para"). Vazio = sem troco.
     * @return array{ok:bool,centavos:?int,troco:int,mensagem:string}
     */
    public static function troco(string $texto, int $total): array
    {
        if (trim($texto) === '') {
            return ['ok' => true, 'centavos' => null, 'troco' => 0, 'mensagem' => ''];
        }
        $dec = Numero::dinheiroParaDecimal($texto);
        if ($dec === null) {
            return ['ok' => false, 'centavos' => null, 'troco' => 0, 'mensagem' => 'Digite um valor válido.'];
        }
        $c = Numero::centavos($dec);
        if ($c < $total) {
            return ['ok' => false, 'centavos' => $c, 'troco' => 0, 'mensagem' => 'O valor precisa ser pelo menos ' . Numero::reais(CaixaService::dec($total)) . '.'];
        }
        if ($c - $total > 100_000) {
            return ['ok' => false, 'centavos' => $c, 'troco' => 0, 'mensagem' => 'Troco muito alto. Confira o valor.'];
        }
        return ['ok' => true, 'centavos' => $c, 'troco' => $c - $total, 'mensagem' => ''];
    }

    /**
     * Busca pelo telefone: nome e ultimo endereco de entrega deste restaurante.
     * @return array{ok:bool,mensagem:string,encontrado?:bool,telefone?:string,nome?:string,endereco?:?array<string,string>}
     */
    public function porTelefone(string $texto): array
    {
        $tel = self::telefone($texto);
        if ($tel === null) {
            return ['ok' => false, 'mensagem' => 'Digite o telefone com DDD (ex.: 85 99999-9999).'];
        }
        $achado = $this->pedidos->ultimoDoTelefone($this->idRestaurante, $tel);
        if ($achado === null) {
            return ['ok' => true, 'encontrado' => false, 'telefone' => $tel, 'mensagem' => 'Cliente novo: preencha o nome e o endereço.'];
        }
        return ['ok' => true, 'encontrado' => true, 'telefone' => $tel, 'nome' => $achado['nome'], 'endereco' => $achado['endereco'],
            'mensagem' => $achado['endereco'] !== null ? 'Endereço do último pedido preenchido. Confira com o cliente.' : 'Cliente encontrado, sem endereço de entrega anterior.'];
    }

    /**
     * Bairros por onde a rua passa, na cidade do restaurante.
     * @return array{ok:bool,mensagem:string,bairros:list<array{cep:string,logradouro:string,bairro:string,cidade:string,estado:string}>}
     */
    public function bairros(string $rua): array
    {
        $local = (new EntregaPagamentoRepository($this->db))->local($this->idRestaurante);
        if ($local['cidade'] === '' || $local['estado'] === '') {
            return ['ok' => false, 'bairros' => [], 'mensagem' => 'Cadastre a cidade do restaurante em Gerência > Perfil para buscar o bairro. Por enquanto, digite o bairro.'];
        }
        try {
            $lista = $this->geo->ruas($rua, $local['cidade'], $local['estado']);
        } catch (Throwable $e) {
            error_log('[Cardapim] Busca de rua indisponivel (' . get_class($e) . ').');
            $lista = [];
        }
        return ['ok' => true, 'bairros' => $lista, 'mensagem' => match (count($lista)) {
            0 => 'Não achamos essa rua em ' . $local['cidade'] . '. Digite o bairro.',
            1 => 'Bairro preenchido.',
            default => 'Essa rua passa por mais de um bairro. Escolha o certo.',
        }];
    }

    /**
     * Taxa e total para o endereco digitado (a mesma regra do cardapio online).
     * @param array<string,mixed> $dados logradouro, numero, bairro, complemento, cep
     * @return array<string,mixed>
     */
    public function calcular(int $idPedido, array $dados): array
    {
        $p = $this->venda($idPedido);
        if ($p instanceof Resultado) {
            return ['ok' => false, 'entrega' => false, 'mensagem' => $p->mensagem];
        }
        return $this->calcularPara($p, $dados);
    }

    /**
     * Prepara o pedido: a venda do balcao vira entrega em preparo.
     * @param array<string,mixed> $dados nome, telefone, logradouro, numero, bairro, complemento, cep, quando, forma, valor (recebido ou troco para)
     */
    public function preparar(int $idPedido, array $dados): Resultado
    {
        $p = $this->venda($idPedido);
        if ($p instanceof Resultado) {
            return $p;
        }
        $nome = TextoLocal::limpo(is_string($dados['nome'] ?? null) ? $dados['nome'] : '', 80);
        $telTexto = is_string($dados['telefone'] ?? null) ? trim($dados['telefone']) : '';
        $tel = $telTexto === '' ? null : self::telefone($telTexto);
        $quando = ($dados['quando'] ?? '') === 'agora' ? 'agora' : (($dados['quando'] ?? '') === 'entrega' ? 'entrega' : '');
        $forma = is_string($dados['forma'] ?? null) && isset(self::FORMAS[$dados['forma']]) ? $dados['forma'] : '';
        if (mb_strlen($nome) < 2) {
            return Resultado::falha('Digite o nome do cliente na aba Entrega.', ['nome' => 'Digite o nome do cliente.']);
        }
        if ($telTexto !== '' && $tel === null) {
            return Resultado::falha('Confira o telefone na aba Entrega (DDD + número).', ['telefone' => 'Digite o telefone com DDD.']);
        }
        if ($quando === '') {
            return Resultado::falha('Escolha quando o cliente paga: agora ou na entrega.', ['quando' => 'Escolha quando paga.']);
        }
        if ($forma === '') {
            return Resultado::falha('Escolha a forma de pagamento.', ['forma' => 'Escolha a forma de pagamento.']);
        }
        $caixa = (new CaixaRepository($this->db))->aberto($this->idRestaurante);
        if ($caixa === null) {
            return Resultado::falha('Abra o caixa para preparar o pedido de entrega.');
        }
        $calc = $this->calcularPara($p, $dados);
        if (!$calc['ok']) {
            return Resultado::falha((string) $calc['mensagem'], is_array($calc['erros'] ?? null) ? $calc['erros'] : []);
        }
        if (!$calc['entrega']) {
            return Resultado::falha((string) $calc['mensagem'] . ' O pedido não foi enviado.');
        }
        $total = (int) $calc['total_centavos'];
        $troco = self::troco(is_string($dados['valor'] ?? null) ? $dados['valor'] : '', $total);
        if ($forma === 'dinheiro' && !$troco['ok']) {
            return Resultado::falha(($quando === 'agora' ? 'Cliente entregou: ' : 'Troco para: ') . $troco['mensagem'], ['valor' => $troco['mensagem']]);
        }
        $e = $calc['endereco'];
        $config = (new EntregaPagamentoRepository($this->db))->config($this->idRestaurante);
        $agora = date('Y-m-d H:i:s');
        $pagoAgora = $quando === 'agora';
        $trocoPara = !$pagoAgora && $forma === 'dinheiro' && $troco['centavos'] !== null && $troco['troco'] > 0 ? CaixaService::dec($troco['centavos']) : null;
        $this->db->beginTransaction();
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->db->prepare('SELECT id FROM pedidos WHERE id = ? AND id_restaurante = ? FOR UPDATE')->execute([$idPedido, $this->idRestaurante]);
            }
            $atual = $this->venda($idPedido);
            if ($atual instanceof Resultado) {
                $this->db->rollBack();
                return $atual;
            }
            $u = $this->db->prepare("UPDATE pedidos SET tipo = 'entrega', status = 'em_preparo', forma_pagamento = ?, pagamento_status = ?, troco_para = ?,
                    subtotal = ?, taxa_entrega = ?, servico_percentual = '0.00', taxa_servico = '0.00', desconto = ?, total = ?,
                    cliente_nome = ?, cliente_whatsapp = ?, entrega_cep = ?, entrega_logradouro = ?, entrega_numero = ?, entrega_complemento = ?,
                    entrega_bairro = ?, entrega_cidade = ?, entrega_estado = ?, entrega_latitude = ?, entrega_longitude = ?, entrega_distancia_km = ?,
                    id_area_entrega = ?, area_descricao = ?, previsao_minutos_min = ?, previsao_minutos_max = ?, pago_em = ?, id_caixa = ?, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ? AND tipo = 'balcao' AND status = 'aberto'");
            $u->execute([
                self::FORMA_PEDIDO[$forma], $pagoAgora ? 'pago' : 'pendente', $trocoPara,
                CaixaService::dec((int) $calc['subtotal_centavos']), CaixaService::dec((int) $calc['taxa_centavos']), CaixaService::dec((int) $calc['desconto_centavos']), CaixaService::dec($total),
                $nome, $tel, $e['cep'] !== '' ? $e['cep'] : null, $e['logradouro'], $e['numero'], $e['complemento'] !== '' ? $e['complemento'] : null,
                $e['bairro'], $e['cidade'], $e['estado'], $calc['latitude'], $calc['longitude'], $calc['distancia_km'],
                $calc['area_id'], mb_substr((string) $calc['area'], 0, 140), $config['entrega_minutos_min'], $config['entrega_minutos_max'],
                $pagoAgora ? $agora : null, $pagoAgora ? (int) $caixa['id'] : null, $agora,
                $idPedido, $this->idRestaurante,
            ]);
            if ($u->rowCount() !== 1) {
                $this->db->rollBack();
                return Resultado::falha('Esta venda mudou enquanto você preenchia. Confira na tela.');
            }
            if ($pagoAgora) {
                $this->db->prepare('INSERT INTO pedido_pagamentos (id_pedido, id_restaurante, id_caixa, forma, valor, troco, id_user, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$idPedido, $this->idRestaurante, (int) $caixa['id'], $forma, CaixaService::dec($total),
                        CaixaService::dec($forma === 'dinheiro' ? $troco['troco'] : 0), $this->autor->id, $agora]);
            }
            $this->pedidos->registrarEvento($this->idRestaurante, $idPedido, 'em_preparo', 'Pedido de entrega feito no caixa por ' . $this->autor->nome
                . ($pagoAgora ? ' (pago na hora: ' . self::FORMAS[$forma] . ').' : ' (pagamento na entrega: ' . self::FORMAS[$forma] . ').') . ' Em preparo.', $this->autor->id);
            AvisosWhatsApp::enfileirar($this->db, $this->idRestaurante, $idPedido, 'em_preparo');
            TempoReal::registrar($this->db, $this->idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => $idPedido, 'numero' => (int) $p['numero'], 'status' => 'em_preparo']);
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', 'comanda.fechada', ['id' => $idPedido]);
            if ($pagoAgora) {
                TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.pagamento', ['caixa' => (int) $caixa['id']]);
            }
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
        // Cozinha: so os itens que ainda nao tinham saido. Nunca desfaz o pedido.
        (new FilaImpressao($this->db, $this->idRestaurante, (string) ($this->autor->restauranteNome ?? ''), $this->autor))->producaoEntregaBalcao($idPedido);
        $valor = Numero::reais(CaixaService::dec($total));
        Auditoria::registrar($this->autor, 'Entrega feita no caixa', sprintf('Pedido #%d para %s (%s, %s). Total %s, %s.', (int) $p['numero'], $nome,
            $e['bairro'], $calc['taxa_centavos'] > 0 ? 'taxa ' . Numero::reais(CaixaService::dec((int) $calc['taxa_centavos'])) : 'entrega grátis',
            $valor, $pagoAgora ? 'pago agora em ' . self::FORMAS[$forma] : 'pagamento na entrega em ' . self::FORMAS[$forma]));
        $msg = 'Pedido #' . (int) $p['numero'] . ' enviado para a cozinha e para o delivery. ' . ($pagoAgora
            ? self::FORMAS[$forma] . ' ' . $valor . ' recebido.' . ($forma === 'dinheiro' && $troco['troco'] > 0 ? ' Troco: ' . Numero::reais(CaixaService::dec($troco['troco'])) . '.' : '')
            : 'O entregador recebe ' . $valor . ' em ' . self::FORMAS[$forma] . ($trocoPara !== null ? ' (troco para ' . Numero::reais($trocoPara) . ')' : '') . '.');
        return Resultado::sucesso($msg, $idPedido);
    }

    /**
     * @param array<string,mixed> $p venda do balcao
     * @param array<string,mixed> $dados
     * @return array<string,mixed>
     */
    private function calcularPara(array $p, array $dados): array
    {
        $local = (new EntregaPagamentoRepository($this->db))->local($this->idRestaurante);
        $campo = static fn (string $c): string => is_string($dados[$c] ?? null) ? $dados[$c] : '';
        $n = Endereco::normalizar([
            'cep' => $campo('cep'), 'logradouro' => $campo('logradouro'), 'numero' => $campo('numero'), 'complemento' => $campo('complemento'),
            'bairro' => $campo('bairro'), 'cidade' => $campo('cidade') !== '' ? $campo('cidade') : $local['cidade'],
            'estado' => $campo('estado') !== '' ? $campo('estado') : $local['estado'],
        ]);
        if ($n['erros'] !== []) {
            $erros = $n['erros'];
            if (isset($erros['cidade']) || isset($erros['estado'])) {
                return ['ok' => false, 'entrega' => false, 'erros' => $erros, 'mensagem' => 'Cadastre a cidade e o estado do restaurante em Gerência > Perfil.'];
            }
            return ['ok' => false, 'entrega' => false, 'erros' => $erros, 'mensagem' => (string) reset($erros)];
        }
        $e = $n['dados'];
        $v = ($this->verificador ?? new VerificadorEntrega($this->db, $this->geo))->verificar($this->idRestaurante, $e);
        $mensagem = (string) $v['mensagem'];
        if (($v['motivo'] ?? '') === 'sem_entrega') {
            $mensagem = 'A entrega está desligada. Ligue em Gerência > Entrega e pagamento.';
        } elseif (!$v['entrega'] && ($v['motivo'] ?? '') === 'sem_areas') {
            $mensagem = 'Cadastre onde o restaurante entrega em Gerência > Entrega e pagamento.';
        }
        $taxa = $v['entrega'] ? Numero::centavos((string) $v['taxa']) : 0;
        $t = self::totais(Numero::centavos((string) $p['subtotal']), Numero::centavos((string) $p['desconto']), $taxa);
        $minimo = $v['entrega'] && $v['pedido_minimo'] !== null ? Numero::centavos((string) $v['pedido_minimo']) : null;
        $abaixo = $minimo !== null && $t['subtotal'] < $minimo;
        $r = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
        return [
            'ok' => true,
            'entrega' => (bool) $v['entrega'],
            'mensagem' => $v['entrega'] ? ($taxa === 0 ? 'Entrega grátis neste endereço.' : 'Taxa de entrega: ' . $r($taxa) . '.') : $mensagem,
            'aviso' => $abaixo ? 'Abaixo do pedido mínimo deste endereço (' . $r((int) $minimo) . '). Você pode enviar mesmo assim.' : '',
            'area' => (string) $v['area'], 'area_id' => $v['area_id'],
            'latitude' => $v['latitude'], 'longitude' => $v['longitude'], 'distancia_km' => $v['distancia_km'],
            'taxa_centavos' => $taxa, 'subtotal_centavos' => $t['subtotal'], 'desconto_centavos' => $t['desconto'], 'total_centavos' => $t['total'],
            'taxa' => $taxa === 0 ? 'Grátis' : $r($taxa), 'subtotal' => $r($t['subtotal']), 'desconto' => $r($t['desconto']), 'total' => $r($t['total']),
            'endereco' => ['cep' => $e['cep'], 'logradouro' => $e['logradouro'], 'numero' => $e['numero'], 'complemento' => $e['complemento'],
                'bairro' => $e['bairro'], 'cidade' => $e['cidade'], 'estado' => $e['estado']],
        ];
    }

    /**
     * Venda do balcao que ainda pode virar entrega: aberta, com itens, sem pagamento e sem Pix gerado.
     * @return array<string,mixed>|Resultado
     */
    private function venda(int $idPedido): array|Resultado
    {
        $p = $this->pedidos->buscar($this->idRestaurante, $idPedido);
        if ($p === null || $p['tipo'] !== 'balcao') {
            return Resultado::falha('A entrega só pode ser feita a partir de uma venda no balcão.');
        }
        if ($p['status'] !== 'aberto') {
            return Resultado::falha('Esta venda já foi fechada.');
        }
        if (Numero::centavos((string) $p['subtotal']) <= 0) {
            return Resultado::falha('Lance os itens antes de preparar o pedido.');
        }
        if (ComandaService::totalPago($this->db, $this->idRestaurante, $idPedido) > 0) {
            return Resultado::falha('Esta venda já tem pagamento registrado. Remova o pagamento para enviar como entrega.');
        }
        if ($this->temPixPendente($idPedido)) {
            return Resultado::falha('Esta venda tem um Pix gerado esperando pagamento. Cancele o Pix para enviar como entrega.');
        }
        return $p;
    }

    private function temPixPendente(int $idPedido): bool
    {
        try {
            $q = $this->db->prepare("SELECT 1 FROM comanda_pix WHERE id_restaurante = ? AND id_pedido = ? AND status = 'pendente' AND expira_em > ? LIMIT 1");
            $q->execute([$this->idRestaurante, $idPedido, date('Y-m-d H:i:s')]);
            return $q->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
