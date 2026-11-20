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
        [$impressora, $erro] = $this->destino($ponto, $idImpressora);
        if ($erro !== null) {
            return $erro;
        }
        $c = (new ComandaService($this->db, $this->idRestaurante, $this->autor))->detalhes($idPedido);
        if ($c === null) {
            return Resultado::falha('Comanda não encontrada.');
        }
        $desde = $modo === 'itens_novos' ? $this->repo->ultimoItemImpresso($this->idRestaurante, $idPedido) : 0;
        $cupom = CupomImpressao::comanda($c, $this->restaurante, $modo, $desde, $this->autor->nome);
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
        $cupom = CupomImpressao::pedido($p, $pedidos->itens($this->idRestaurante, $idPedido), $this->restaurante);
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
        return count(array_filter($c['itens'], static fn (array $i): bool => $i['cancelado_em'] === null && (int) $i['id'] > $desde));
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
        return match (true) {
            $s['resultado'] === 'ok' => ['status' => 'impresso', 'mensagem' => 'Impresso.'],
            $s['resultado'] === 'erro' => ['status' => 'erro', 'mensagem' => 'A impressora não imprimiu: ' . (string) $s['erro']],
            $s['entregue_em'] !== null => ['status' => 'imprimindo', 'mensagem' => 'Imprimindo...'],
            (int) $s['vencido'] === 1 => ['status' => 'expirada', 'mensagem' => 'Nenhum computador pegou a impressão. Confira se o Deliveryzim Agent está aberto.'],
            default => ['status' => 'aguardando', 'mensagem' => 'Enviando para o computador da impressora...'],
        };
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
