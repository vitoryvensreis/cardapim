<?php

declare(strict_types=1);

namespace Cardapim\Services\Impressao;

use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\Desktop\ImpressaoRepository;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Esquema;
use PDO;

/**
 * Pede impressoes ao Deliveryzim Agent (caixa e garcom). O servidor monta o cupom com os dados do banco
 * (nunca com valores vindos do navegador) e grava na fila; o Agent do restaurante busca e imprime.
 * Sem Agent ligado o pedido e recusado na hora (nada fica guardado para imprimir de surpresa depois).
 */
final class FilaImpressao
{
    private readonly ImpressaoRepository $repo;
    /** @var array{mostrar_atendente:bool,delivery_com_preco:bool,delivery_com_endereco:bool,delivery_com_telefone:bool}|null */
    private ?array $opcoesCupom = null;

    public function __construct(
        private readonly PDO $db,
        private readonly int $idRestaurante,
        private readonly string $restaurante,
        private readonly UsuarioLogado $autor,
    ) {
        $this->repo = new ImpressaoRepository($db);
    }

    public function disponivel(): bool
    {
        return Esquema::tem($this->db, 'impressoes');
    }

    /**
     * Comanda de mesa/balcao. Modos: conta (caixa), pre_conta (garcom: comanda inteira), itens_novos (garcom).
     */
    public function comanda(int $idPedido, string $modo, string $ponto = 'caixa_conta', ?int $idImpressora = null): Resultado
    {
        if (!in_array($modo, CupomImpressao::MODOS_COMANDA, true)) {
            return Resultado::falha('Escolha o que imprimir.');
        }
        if ($modo === 'itens_novos' && $this->producaoAtiva()) {
            return $this->comandaProducao($idPedido);
        }
        [$impressora, $erro] = $this->destino($ponto, $idImpressora);
        if ($erro !== null) {
            return $erro;
        }
        $c = (new ComandaService($this->db, $this->idRestaurante, $this->autor))->detalhes($idPedido);
        if ($c === null) {
            return Resultado::falha('Comanda não encontrada.');
        }
        $desde = $modo === 'itens_novos' ? $this->repo->ultimoItemImpresso($this->idRestaurante, $idPedido) : 0;
        $cupom = CupomImpressao::comanda($c, $this->restaurante, $modo, $desde, $this->quem());
        if ($cupom['itens'] === 0) {
            return Resultado::falha($modo === 'itens_novos' ? 'Nenhum item novo desde a última impressão.' : 'A comanda ainda não tem itens.');
        }
        $id = $this->repo->criar($this->idRestaurante, $idPedido, $modo, $cupom['titulo'], $cupom['blocos'], $cupom['ate_item'], $this->autor->id, $impressora['id'] ?? null);
        return Resultado::sucesso(self::enviado($impressora), $id);
    }

    /** Pedido de entrega/retirada (tela do delivery no caixa). */
    public function pedido(int $idPedido, ?int $idImpressora = null): Resultado
    {
        [$impressora, $erro] = $this->destino('caixa_delivery', $idImpressora);
        if ($erro !== null) {
            return $erro;
        }
        $pedidos = new PedidoRepository($this->db);
        $p = $pedidos->buscar($this->idRestaurante, $idPedido);
        if ($p === null || !in_array($p['tipo'], ['entrega', 'retirada'], true)) {
            return Resultado::falha('Pedido não encontrado.');
        }
        $itens = $pedidos->itens($this->idRestaurante, $idPedido);
        $cupom = CupomImpressao::pedido($p, $itens, $this->restaurante);
        $id = $this->repo->criar($this->idRestaurante, $idPedido, 'pedido', $cupom['titulo'], $cupom['blocos'], null, $this->autor->id, $impressora['id'] ?? null);
        return Resultado::sucesso(self::enviado($impressora), $id);
    }

    /** Resumo de um caixa (aberto = parcial; fechado = o que foi gravado no fechamento). */
    public function fechamento(int $idCaixa, ?int $idImpressora = null): Resultado
    {
        [$impressora, $erro] = $this->destino('caixa_fechamento', $idImpressora);
        if ($erro !== null) {
            return $erro;
        }
        $repo = new CaixaRepository($this->db);
        $caixa = $repo->buscar($this->idRestaurante, $idCaixa);
        if ($caixa === null) {
            return Resultado::falha('Caixa não encontrado.');
        }
        $resumo = (new CaixaService($this->db, $this->idRestaurante, $this->autor))->resumo($caixa);
        $cupom = CupomImpressao::fechamento(
            $caixa, $resumo, $repo->movimentos($this->idRestaurante, $idCaixa),
            count($repo->pagamentos($this->idRestaurante, $idCaixa)), $this->restaurante,
        );
        $id = $this->repo->criar($this->idRestaurante, null, 'fechamento', $cupom['titulo'], $cupom['blocos'], null, $this->autor->id, $impressora['id'] ?? null);
        return Resultado::sucesso(self::enviado($impressora), $id);
    }

    /**
     * Vias de producao ligadas: o gerente escolheu a impressora padrao da producao em Integracoes > Impressoras.
     * Com isso, "So os itens novos" sai dividido: cada item na impressora marcada no produto.
     */
    public function producaoAtiva(): bool
    {
        return Esquema::tem($this->db, 'impressao_produto') && $this->opcoes('producao') !== [];
    }

    /** "So os itens novos" dividido por estacao (Churrasqueira, Cozinha, Bar...). Todas precisam estar ligadas. */
    private function comandaProducao(int $idPedido): Resultado
    {
        $c = (new ComandaService($this->db, $this->idRestaurante, $this->autor))->detalhes($idPedido);
        if ($c === null) {
            return Resultado::falha('Comanda não encontrada.');
        }
        $desde = $this->repo->ultimoItemImpresso($this->idRestaurante, $idPedido);
        $ativos = array_values(array_filter($c['itens'], static fn (array $i): bool => $i['cancelado_em'] === null));
        $novos = array_values(array_filter($ativos, static fn (array $i): bool => (int) $i['id'] > $desde));
        if ($novos === []) {
            return Resultado::falha('Nenhum item novo desde a última impressão.');
        }
        $ateItem = max(array_map(static fn (array $i): int => (int) $i['id'], $ativos));
        $rotulo = $c['pedido']['tipo'] === 'mesa' ? (string) $c['rotulo'] : 'Balcão';
        $ids = $this->enviarProducao($idPedido, 'itens_novos', $novos, CupomImpressao::cabecaComanda($c), $rotulo, $ateItem, true);
        if ($ids === []) {
            return Resultado::sucesso('Os itens novos estão marcados para não imprimir.');
        }
        return $ids instanceof Resultado ? $ids : Resultado::sucesso('Enviado para ' . self::juntar(array_keys($ids)) . '.', reset($ids));
    }

    /**
     * Garcom ao lancar, com a impressao por produto ligada: manda os itens novos na hora, cada item na impressora
     * marcada no produto; sem marcacao, na padrao. Null = impressao por produto desligada (nao imprime).
     * Falha nunca desfaz o lancamento: os itens continuam em "Novos itens" no caixa.
     */
    public function aoLancar(int $idPedido): ?Resultado
    {
        if (!$this->disponivel() || !$this->producaoAtiva()) {
            return null;
        }
        $c = (new ComandaService($this->db, $this->idRestaurante, $this->autor))->detalhes($idPedido);
        if ($c === null) {
            return null;
        }
        $desde = $this->repo->ultimoItemImpresso($this->idRestaurante, $idPedido);
        $ativos = array_values(array_filter($c['itens'], static fn (array $i): bool => $i['cancelado_em'] === null));
        $novos = array_values(array_filter($ativos, static fn (array $i): bool => (int) $i['id'] > $desde));
        if ($novos === []) {
            return null;
        }
        $ateItem = max(array_map(static fn (array $i): int => (int) $i['id'], $ativos));
        $rotulo = $c['pedido']['tipo'] === 'mesa' ? (string) $c['rotulo'] : 'Balcão';
        $ids = $this->enviarProducao($idPedido, 'itens_novos', $novos, CupomImpressao::cabecaComanda($c), $rotulo, $ateItem, true);
        if ($ids instanceof Resultado) {
            return str_starts_with($ids->mensagem, 'Nenhuma impressora') ? null : $ids;
        }
        if ($ids === []) {
            return null; // so itens que nao imprimem
        }
        return Resultado::sucesso('Enviado para ' . self::juntar(array_keys($ids)) . '.', reset($ids));
    }

    /**
     * Grava um cupom por estacao, todos no mesmo lote.
     * @param list<array<string,mixed>> $itens
     * @param array{titulo:string,linha:string,detalhe:string,observacao:string} $cabeca
     * @param list<array<string,mixed>> $resumo valores do pedido (so na via da impressora padrao)
     * @return array<string,int>|Resultado apelido => id do cupom
     */
    private function enviarProducao(int $idPedido, string $tipo, array $itens, array $cabeca, string $rotulo, ?int $ateItem, bool $exigirLigadas, array $resumo = [], array $contato = []): array|Resultado
    {
        // Mesma regra do "Testar impressao": cada item na impressora do produto, sem marcacao na padrao, "Nao imprime" fora.
        $padrao = $this->opcoes('producao')[0]['id'];
        $rota = RotaProducao::dividir($this->db, $this->idRestaurante, $itens, $padrao);
        $grupos = $rota['grupos'];
        if ($grupos === [] && $rota['fora'] !== []) {
            return []; // so itens marcados "Nao imprime": nada a imprimir
        }
        $impressoras = [];
        foreach ((new ImpressoraRepository($this->db))->listar($this->idRestaurante) as $imp) {
            $impressoras[$imp['id']] = $imp;
        }
        $desligadas = [];
        foreach (array_keys($grupos) as $idImp) {
            if (!isset($impressoras[$idImp])) {
                unset($grupos[$idImp]); // impressora tirada do Agent depois de marcada: sai na padrao
                continue;
            }
            if ($exigirLigadas && !$this->repo->impressoraLigada($this->idRestaurante, $idImp)) {
                $desligadas[] = $impressoras[$idImp]['apelido'];
            }
        }
        if ($desligadas !== []) {
            return Resultado::falha((count($desligadas) === 1 ? 'A impressora ' . $desligadas[0] . ' está desligada' : 'As impressoras ' . self::juntar($desligadas) . ' estão desligadas')
                . '. Abra o Deliveryzim Agent no computador dela e tente de novo. Nada foi impresso.');
        }
        if ($grupos === []) {
            return Resultado::falha('Nenhuma impressora de produção disponível. Confira em Integrações > Impressoras.');
        }
        // Resumo de valores e contato do cliente (delivery com as opcoes ligadas): so em uma via, a da impressora
        // padrao da producao (ou a primeira, se nenhum item do pedido saiu nela).
        $comResumo = isset($grupos[$padrao]) ? $padrao : array_key_first($grupos);
        $lote = bin2hex(random_bytes(8));
        $ids = [];
        $this->db->beginTransaction();
        try {
            foreach ($grupos as $idImp => $itensDaEstacao) {
                $apelido = $impressoras[$idImp]['apelido'];
                $blocos = CupomImpressao::producao($cabeca, $apelido, $itensDaEstacao, $this->quem(), $idImp === $comResumo ? $resumo : [], $idImp === $comResumo ? $contato : []);
                $ids[$apelido] = $this->repo->criar($this->idRestaurante, $idPedido, $tipo, 'PRODUÇÃO · ' . $apelido . ' · ' . $rotulo,
                    $blocos, $ateItem, $this->autor->id > 0 ? $this->autor->id : null, $idImp, $lote);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $ids;
    }

    /**
     * Pedido do delivery aceito: manda as vias de producao (uma vez por pedido). Nunca impede o aceite:
     * qualquer falha so vai para o log. Sem a producao configurada, nao faz nada.
     */
    public function producaoPedido(int $idPedido): void
    {
        try {
            if (!$this->producaoAtiva() || $this->repo->temProducao($this->idRestaurante, $idPedido)) {
                return;
            }
            $pedidos = new PedidoRepository($this->db);
            $p = $pedidos->buscar($this->idRestaurante, $idPedido);
            if ($p === null || !in_array($p['tipo'], ['entrega', 'retirada'], true)) {
                return;
            }
            $itens = array_map(static fn (array $i): array => $i + ['cancelado_em' => null], $pedidos->itens($this->idRestaurante, $idPedido));
            if ($itens === []) {
                return;
            }
            $resumo = $this->opcoesCupom()['delivery_com_preco'] ? CupomImpressao::resumoPedido($p) : [];
            $res = $this->enviarProducao($idPedido, 'producao', $itens, CupomImpressao::cabecaPedido($p), 'Pedido #' . (int) $p['numero'], null, false, $resumo, $this->contatoPedido($p));
            if ($res instanceof Resultado) {
                error_log('[Cardapim] Producao do pedido nao enviada: ' . $res->mensagem);
            }
        } catch (\Throwable $e) {
            error_log('[Cardapim] Producao do pedido indisponivel (' . get_class($e) . ').');
        }
    }

    /**
     * Venda do balcao que virou entrega no caixa: manda para a producao so os itens que ainda nao tinham saido
     * (o "Novos itens" do balcao pode ja ter impresso parte). Cabecalho de pedido de entrega e, com "Mostrar o total"
     * ligado, o resumo de valores. Nunca impede o pedido: qualquer falha so vai para o log.
     */
    public function producaoEntregaBalcao(int $idPedido): void
    {
        try {
            if (!$this->disponivel() || !$this->producaoAtiva()) {
                return;
            }
            $pedidos = new PedidoRepository($this->db);
            $p = $pedidos->buscar($this->idRestaurante, $idPedido);
            if ($p === null || $p['tipo'] !== 'entrega') {
                return;
            }
            $desde = $this->repo->ultimoItemImpresso($this->idRestaurante, $idPedido);
            // Itens com o id (PedidoRepository::itens nao traz): so os que ainda nao sairam na producao.
            $q = $this->db->prepare('SELECT * FROM pedido_itens WHERE id_pedido = ? AND id_restaurante = ? AND cancelado_em IS NULL ORDER BY id');
            $q->execute([$idPedido, $this->idRestaurante]);
            $ativos = $q->fetchAll(PDO::FETCH_ASSOC);
            $novos = array_values(array_filter($ativos, static fn (array $i): bool => (int) $i['id'] > $desde));
            if ($novos === []) {
                return;
            }
            $ateItem = max(array_map(static fn (array $i): int => (int) $i['id'], $ativos));
            $resumo = $this->opcoesCupom()['delivery_com_preco'] ? CupomImpressao::resumoPedido($p) : [];
            $res = $this->enviarProducao($idPedido, 'producao', $novos, CupomImpressao::cabecaPedido($p), 'Pedido #' . (int) $p['numero'], $ateItem, false, $resumo, $this->contatoPedido($p));
            if ($res instanceof Resultado) {
                error_log('[Cardapim] Producao da entrega do balcao nao enviada: ' . $res->mensagem);
            }
        } catch (\Throwable $e) {
            error_log('[Cardapim] Producao da entrega do balcao indisponivel (' . get_class($e) . ').');
        }
    }

    /** Vias de producao quando o aceite nao vem de uma pessoa (iFood aceito la ou aceite automatico). */
    public static function producaoDoSistema(PDO $db, int $idRestaurante, int $idPedido): void
    {
        $sistema = new UsuarioLogado(0, 'sistema', '', '', true, $idRestaurante, null, null, null);
        (new self($db, $idRestaurante, '', $sistema))->producaoPedido($idPedido);
    }

    /**
     * Aviso de cancelamento na impressora de cada item (mesma regra da producao), so para o que ja tinha saido na
     * producao: na comanda, itens ate o ultimo impresso; no delivery, quando as vias do pedido ja foram enviadas.
     * Nunca impede o cancelamento: qualquer falha so vai para o log.
     * @param list<int>|null $idsItens null = pedido/comanda inteira (itens ativos no momento do cancelamento)
     */
    public function cancelamento(int $idPedido, ?array $idsItens, string $motivo): void
    {
        try {
            if (!Esquema::tem($this->db, 'produto_opcoes') || !$this->producaoAtiva()
                || !(new ImpressoraRepository($this->db))->cancelamentoAtivo($this->idRestaurante)) {
                return;
            }
            $pedidos = new PedidoRepository($this->db);
            $p = $pedidos->buscar($this->idRestaurante, $idPedido);
            if ($p === null) {
                return;
            }
            if (in_array($p['tipo'], ['mesa', 'balcao'], true)) {
                $c = (new ComandaService($this->db, $this->idRestaurante, $this->autor))->detalhes($idPedido);
                if ($c === null) {
                    return;
                }
                $desde = $this->repo->ultimoItemImpresso($this->idRestaurante, $idPedido);
                $itens = array_values(array_filter($c['itens'], static fn (array $i): bool => (int) $i['id'] <= $desde
                    && ($idsItens === null ? $i['cancelado_em'] === null : in_array((int) $i['id'], $idsItens, true))));
                $cabeca = CupomImpressao::cabecaComanda($c);
                $rotulo = $p['tipo'] === 'mesa' ? (string) $c['rotulo'] : 'Balcão';
            } else {
                // Vias do aceite ja enviadas (ou, na entrega feita no caixa, itens impressos ainda no balcao).
                if (!$this->repo->temProducao($this->idRestaurante, $idPedido) && $this->repo->ultimoItemImpresso($this->idRestaurante, $idPedido) === 0) {
                    return;
                }
                $itens = array_map(static fn (array $i): array => ['cancelado_em' => null] + $i, $pedidos->itens($this->idRestaurante, $idPedido));
                if ($idsItens !== null) {
                    $itens = array_values(array_filter($itens, static fn (array $i): bool => in_array((int) $i['id'], $idsItens, true)));
                }
                $cabeca = CupomImpressao::cabecaPedido($p);
                $rotulo = 'Pedido #' . (int) $p['numero'];
            }
            if ($itens === []) {
                return;
            }
            // O item ja esta marcado como cancelado; a divisao por estacao ignoraria linhas canceladas.
            $itens = array_map(static fn (array $i): array => ['cancelado_em' => null] + $i, $itens);
            $grupos = RotaProducao::dividir($this->db, $this->idRestaurante, $itens, $this->opcoes('producao')[0]['id'])['grupos'];
            if ($grupos === []) {
                return;
            }
            $impressoras = [];
            foreach ((new ImpressoraRepository($this->db))->listar($this->idRestaurante) as $imp) {
                $impressoras[$imp['id']] = $imp;
            }
            $lote = bin2hex(random_bytes(8));
            $this->db->beginTransaction();
            try {
                foreach ($grupos as $idImp => $lista) {
                    if (!isset($impressoras[$idImp])) {
                        continue;
                    }
                    $apelido = $impressoras[$idImp]['apelido'];
                    $this->repo->criar($this->idRestaurante, $idPedido, 'cancelamento', mb_substr('CANCELADO · ' . $apelido . ' · ' . $rotulo, 0, 80),
                        CupomImpressao::cancelamento($cabeca, $apelido, $lista, $motivo, $idsItens === null, $this->quem()),
                        null, $this->autor->id > 0 ? $this->autor->id : null, $idImp, $lote);
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            error_log('[Cardapim] Aviso de cancelamento nao impresso (' . get_class($e) . ').');
        }
    }

    /** Cancelamento sem pessoa no sistema (iFood, Pix vencido) ou pelo fluxo comum de cancelar pedido. */
    public static function cancelamentoDoSistema(PDO $db, int $idRestaurante, int $idPedido, string $motivo, ?UsuarioLogado $autor = null): void
    {
        $autor ??= new UsuarioLogado(0, 'sistema', '', '', true, $idRestaurante, null, null, null);
        (new self($db, $idRestaurante, '', $autor))->cancelamento($idPedido, null, $motivo);
    }

    /** @param list<string> $nomes */
    private static function juntar(array $nomes): string
    {
        $ultimo = array_pop($nomes);
        return $nomes === [] ? (string) $ultimo : implode(', ', $nomes) . ' e ' . $ultimo;
    }

    /**
     * Opcoes do cupom do restaurante (nome de quem atendeu; total, endereco e telefone no delivery). Falha = padrao.
     * @return array{mostrar_atendente:bool,delivery_com_preco:bool,delivery_com_endereco:bool,delivery_com_telefone:bool}
     */
    public function opcoesCupom(): array
    {
        if ($this->opcoesCupom === null) {
            try {
                $this->opcoesCupom = (new ImpressoraRepository($this->db))->opcoesCupom($this->idRestaurante);
            } catch (\Throwable $e) {
                error_log('[Cardapim] Opcoes do cupom indisponiveis (' . get_class($e) . ').');
                $this->opcoesCupom = ImpressoraRepository::OPCOES_CUPOM_PADRAO;
            }
        }
        return $this->opcoesCupom;
    }

    /**
     * Endereco e telefone do cliente para a via do delivery aceito, conforme o que o restaurante ligou.
     * @param array<string,mixed> $p
     * @return list<array<string,mixed>>
     */
    public function contatoPedido(array $p): array
    {
        $o = $this->opcoesCupom();
        return CupomImpressao::contatoPedido($p, $o['delivery_com_endereco'], $o['delivery_com_telefone']);
    }

    /** Nome de quem atendeu no cupom (garcom, caixa ou quem aceitou), ou vazio se o restaurante desligou. */
    public function quem(): string
    {
        return $this->opcoesCupom()['mostrar_atendente'] ? $this->autor->nome : '';
    }

    /**
     * Impressoras que o gerente marcou para o ponto (a padrao primeiro). Vazio = sem escolha (Agent principal).
     * A tela so pergunta quando ha mais de uma.
     * @return list<array{id:int,apelido:string,computador:string,ligada:bool,padrao:bool}>
     */
    public function opcoes(string $ponto): array
    {
        if (!Esquema::tem($this->db, 'impressoras')) {
            return [];
        }
        try {
            return (new ImpressoraRepository($this->db))->opcoes($this->idRestaurante, $ponto);
        } catch (\Throwable $e) {
            error_log('[Cardapim] Impressoras indisponiveis (' . get_class($e) . ').');
            return [];
        }
    }

    /**
     * Decide a impressora do cupom: ponto sem configuracao = qualquer Agent (principal); com uma = ela;
     * com varias = a escolhida (precisa estar na lista do ponto) ou a padrao.
     * @return array{0:array{id:int,apelido:string}|null,1:Resultado|null}
     */
    private function destino(string $ponto, ?int $idImpressora): array
    {
        if (!$this->disponivel()) {
            return [null, $this->conferir()];
        }
        $opcoes = $this->opcoes($ponto);
        if ($opcoes === []) {
            return [null, $this->conferir()];
        }
        $escolhida = $opcoes[0];
        if ($idImpressora !== null) {
            $achou = array_values(array_filter($opcoes, static fn (array $o): bool => $o['id'] === $idImpressora));
            if ($achou === []) {
                return [null, Resultado::falha('Essa impressora não está liberada aqui. Atualize a página e escolha de novo.')];
            }
            $escolhida = $achou[0];
        }
        if (!$this->repo->impressoraLigada($this->idRestaurante, $escolhida['id'])) {
            return [null, Resultado::falha('A impressora ' . $escolhida['apelido'] . ' está desligada: abra o Deliveryzim Agent no computador "' . $escolhida['computador'] . '".')];
        }
        return [$escolhida, null];
    }

    /** @param array{apelido:string}|null $impressora */
    private static function enviado(?array $impressora): string
    {
        return $impressora !== null ? 'Enviado para a impressora ' . $impressora['apelido'] . '.' : 'Enviado para a impressora.';
    }

    /** Quantos itens a comanda tem depois da ultima impressao (para o botao "So os itens novos"). */
    public function itensNovos(array $c): int
    {
        if (!$this->disponivel()) {
            return 0;
        }
        $desde = $this->repo->ultimoItemImpresso($this->idRestaurante, (int) $c['pedido']['id']);
        $novos = array_filter($c['itens'], static fn (array $i): bool => $i['cancelado_em'] === null && (int) $i['id'] > $desde);
        if ($novos !== [] && $this->producaoAtiva()) {
            $nao = RotaProducao::naoImprimem($this->db, $this->idRestaurante, array_map(static fn (array $i): int => (int) ($i['id_produto'] ?? 0), $novos));
            $novos = array_filter($novos, static fn (array $i): bool => !isset($nao[(int) ($i['id_produto'] ?? 0)]));
        }
        return count($novos);
    }

    /**
     * Situacao para o aviso do navegador.
     * @return array{status:string,mensagem:string}|null
     */
    public function situacao(int $id): ?array
    {
        if (!$this->disponivel()) {
            return null;
        }
        $s = $this->repo->situacao($this->idRestaurante, $id);
        if ($s === null) {
            return null;
        }
        $lote = Esquema::tem($this->db, 'impressao_produto') ? $this->repo->loteDe($this->idRestaurante, $id) : null;
        if ($lote !== null) {
            return self::situacaoLote($this->repo->lote($this->idRestaurante, $lote));
        }
        return match (true) {
            $s['resultado'] === 'ok' => ['status' => 'impresso', 'mensagem' => 'Impresso.'],
            $s['resultado'] === 'erro' => ['status' => 'erro', 'mensagem' => 'A impressora não imprimiu: ' . (string) $s['erro']],
            $s['entregue_em'] !== null => ['status' => 'imprimindo', 'mensagem' => 'Imprimindo...'],
            (int) $s['vencido'] === 1 => ['status' => 'expirada', 'mensagem' => 'Nenhum computador pegou a impressão. Confira se o Deliveryzim Agent está aberto.'],
            default => ['status' => 'aguardando', 'mensagem' => 'Enviando para o computador da impressora...'],
        };
    }

    /**
     * Situacao unica de uma impressao dividida por estacao: impresso so quando todas imprimiram.
     * @param list<array<string,mixed>> $cupons
     * @return array{status:string,mensagem:string}
     */
    public static function situacaoLote(array $cupons): array
    {
        $erros = array_filter($cupons, static fn (array $c): bool => $c['resultado'] === 'erro');
        if ($erros !== []) {
            $c = reset($erros);
            return ['status' => 'erro', 'mensagem' => 'A impressora ' . $c['apelido'] . ' não imprimiu: ' . (string) $c['erro']];
        }
        $perdidos = array_filter($cupons, static fn (array $c): bool => $c['entregue_em'] === null && (int) $c['vencido'] === 1);
        if ($perdidos !== []) {
            return ['status' => 'expirada', 'mensagem' => 'Nenhum computador pegou a impressão de ' . self::juntar(array_values(array_map(static fn (array $c): string => (string) $c['apelido'], $perdidos))) . '. Confira se o Deliveryzim Agent está aberto.'];
        }
        $prontos = array_filter($cupons, static fn (array $c): bool => $c['resultado'] === 'ok');
        if (count($prontos) === count($cupons)) {
            return ['status' => 'impresso', 'mensagem' => 'Impresso em ' . self::juntar(array_values(array_map(static fn (array $c): string => (string) $c['apelido'], $cupons))) . '.'];
        }
        $entregues = array_filter($cupons, static fn (array $c): bool => $c['entregue_em'] !== null);
        return $entregues !== []
            ? ['status' => 'imprimindo', 'mensagem' => 'Imprimindo (' . count($prontos) . ' de ' . count($cupons) . ')...']
            : ['status' => 'aguardando', 'mensagem' => 'Enviando para as impressoras...'];
    }

    private function conferir(): ?Resultado
    {
        if (!$this->disponivel()) {
            return Resultado::falha('A impressão pelo Deliveryzim Agent ainda está sendo ativada. Tente de novo em alguns minutos.');
        }
        if ($this->repo->agenteImprimindo($this->idRestaurante)) {
            return null;
        }
        $q = $this->db->prepare('SELECT 1 FROM desktop_agent_devices WHERE id_restaurante = ? AND revogado_em IS NULL
            AND expira_em > NOW() AND ultimo_acesso_em >= NOW() - INTERVAL ' . ImpressaoRepository::AGENTE_SEGUNDOS . ' SECOND LIMIT 1');
        $q->execute([$this->idRestaurante]);
        if ($q->fetchColumn() !== false) {
            return Resultado::falha('O Deliveryzim Agent do computador está desatualizado. Instale a versão nova (Gerência > Integrações) para imprimir por ele.');
        }
        return Resultado::falha('Nenhum computador com o Deliveryzim Agent está ligado. Abra o Agent no computador da impressora e tente de novo.');
    }
}
