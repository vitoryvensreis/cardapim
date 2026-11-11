<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Repositories\Gerencia\FinanceiroRepository;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Gerencia\ContaFinanceiraService;
use Cardapim\Services\Gerencia\FinanceiroService;
use Cardapim\Services\Gerencia\PeriodoFinanceiro;
use Cardapim\Shared\Numero;

/** Aba Financeiro: resumo do periodo, entradas e saidas, contas a pagar/receber, fechamentos de caixa e planilha. */
final class FinanceiroController extends BaseGerencia
{
    private const SITUACOES = ['abertas' => 'Em aberto', 'vencidas' => 'Vencidas', 'pagas' => 'Pagas e recebidas', 'todas' => 'Todas'];

    public function resumo(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $periodo = PeriodoFinanceiro::daRequisicao($_GET);
        $servico = $this->relatorio();
        return $this->telaFinanceiro('gerencia/financeiro/resumo', 'resumo', 'Financeiro', $periodo, [
            'resumo' => $servico->resumo($periodo),
            'pendencias' => $servico->pendencias(date('Y-m-d')),
        ]);
    }

    public function extrato(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $periodo = PeriodoFinanceiro::daRequisicao($_GET);
        $sentido = in_array($_GET['sentido'] ?? '', ['entrada', 'saida'], true) ? $_GET['sentido'] : 'tudo';
        $linhas = $this->relatorio()->extrato($periodo);
        $totais = ['entrada' => 0, 'saida' => 0, 'transferencia' => 0];
        foreach ($linhas as $l) {
            $totais[$l['transferencia'] ? 'transferencia' : $l['sentido']] += $l['valor'];
        }
        $total = count($linhas);
        if ($sentido !== 'tudo') {
            $linhas = array_values(array_filter($linhas, static fn (array $l): bool => $l['sentido'] === $sentido));
        }
        return $this->telaFinanceiro('gerencia/financeiro/extrato', 'extrato', 'Entradas e saídas', $periodo, compact('linhas', 'sentido', 'totais', 'total'));
    }

    public function fechamentos(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $periodo = PeriodoFinanceiro::daRequisicao($_GET);
        return $this->telaFinanceiro('gerencia/financeiro/fechamentos', 'fechamentos', 'Fechamentos de caixa', $periodo, [
            'fechamentos' => $this->relatorio()->fechamentos($periodo),
            'caixaBase' => '/' . $this->restaurante['slug'] . '/caixa',
        ]);
    }

    /** Planilha do periodo para abrir no Excel ou enviar ao contador. */
    public function planilha(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $periodo = PeriodoFinanceiro::daRequisicao($_GET);
        $arquivo = 'financeiro-' . $this->restaurante['slug'] . '-' . $periodo->de . ($periodo->umDia() ? '' : '-a-' . $periodo->ate) . '.csv';
        return Guard::semCache(new Response($this->relatorio()->csv($periodo, $this->restaurante['nome']), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $arquivo . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    /* ---------- Contas a pagar e a receber ---------- */

    public function contas(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $situacao = isset(self::SITUACOES[$_GET['situacao'] ?? '']) ? $_GET['situacao'] : 'abertas';
        $tipo = in_array($_GET['tipo'] ?? '', ['pagar', 'receber'], true) ? $_GET['tipo'] : null;
        $hoje = date('Y-m-d');
        return $this->telaFinanceiro('gerencia/financeiro/contas', 'contas', 'Contas a pagar e a receber', PeriodoFinanceiro::daRequisicao($_GET), [
            'contas' => (new FinanceiroRepository($this->db))->contas($this->restaurante['id'], $situacao, $tipo, $hoje),
            'situacao' => $situacao,
            'situacoes' => self::SITUACOES,
            'tipoFiltro' => $tipo,
            'hoje' => $hoje,
            'pendencias' => $this->relatorio()->pendencias($hoje),
        ]);
    }

    public function novaConta(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $tipo = ($_GET['tipo'] ?? '') === 'receber' ? 'receber' : 'pagar';
        return $this->formConta(null, ['tipo' => $tipo, 'vencimento' => date('Y-m-d'), 'repetir' => '1'], []);
    }

    public function editarConta(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $conta = $this->buscarConta($idTexto);
        if ($conta === null) {
            return $this->naoEncontrada();
        }
        return $this->formConta($conta, [
            'tipo' => (string) $conta['tipo'],
            'descricao' => (string) $conta['descricao'],
            'categoria' => (string) $conta['categoria'],
            'valor' => Numero::dinheiroCampo((string) $conta['valor']),
            'vencimento' => (string) $conta['vencimento'],
            'observacao' => (string) ($conta['observacao'] ?? ''),
        ], []);
    }

    public function criarConta(string $slug): Response
    {
        return $this->salvarConta($slug, null);
    }

    public function atualizarConta(string $slug, string $idTexto): Response
    {
        return $this->salvarConta($slug, $idTexto);
    }

    public function baixarConta(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->naoEncontrada();
        }
        $resultado = $this->contasServico()->baixar($id, $this->post('pago_em'), $this->post('forma'));
        return $this->responder($resultado, $this->origem($this->url('financeiro/contas')));
    }

    public function reabrirConta(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->naoEncontrada();
        }
        return $this->responder($this->contasServico()->reabrir($id), $this->origem($this->url('financeiro/contas')));
    }

    public function excluirConta(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->naoEncontrada();
        }
        $destino = $this->origem($this->url('financeiro/contas'));
        // Excluiu pela tela da propria conta: volta para a lista (a tela da conta nao existe mais).
        if (preg_match('~/financeiro/contas/\d+\z~', (string) parse_url($destino, PHP_URL_PATH)) === 1) {
            $destino = $this->url('financeiro/contas');
        }
        return $this->responder($this->contasServico()->excluir($id), $destino);
    }

    private function salvarConta(string $slug, ?string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = $idTexto === null ? null : self::id($idTexto);
        if ($idTexto !== null && $id === null) {
            return $this->naoEncontrada();
        }
        $resultado = $this->contasServico()->salvar($id, $_POST);
        if ($resultado->ok) {
            return $this->voltar($this->url('financeiro/contas'), 'sucesso', $resultado->mensagem);
        }
        $conta = $id === null ? null : (new FinanceiroRepository($this->db))->conta($this->restaurante['id'], $id);
        if ($id !== null && $conta === null) {
            return $this->voltar($this->url('financeiro/contas'), 'erro', $resultado->mensagem);
        }
        $valores = [];
        foreach (['tipo', 'descricao', 'categoria', 'valor', 'vencimento', 'observacao', 'repetir'] as $campo) {
            $valores[$campo] = is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
        }
        return $this->formConta($conta, $valores, $resultado->erros, 422);
    }

    /** @param array<string,mixed>|null $conta @param array<string,string> $valores @param array<string,string> $erros */
    private function formConta(?array $conta, array $valores, array $erros, int $status = 200): Response
    {
        return $this->tela('gerencia/financeiro/conta-form', 'financeiro', $conta === null ? 'Nova conta' : 'Editar conta', [
            'conta' => $conta,
            'valores' => $valores,
            'erros' => $erros,
            'categorias' => ContaFinanceiraService::CATEGORIAS,
            'formas' => ContaFinanceiraService::FORMAS,
            'pageStyles' => ['css/financeiro.css'],
            'pageScripts' => ['js/forms.js', 'js/financeiro.js'],
        ], $status);
    }

    /* ---------- Apoio ---------- */

    /** @param array<string,mixed> $dados */
    private function telaFinanceiro(string $view, string $aba, string $titulo, PeriodoFinanceiro $periodo, array $dados): Response
    {
        return $this->tela($view, 'financeiro', $titulo, [
            'abaFinanceiro' => $aba,
            'periodo' => $periodo,
            'pageStyles' => ['css/financeiro.css'],
            'pageScripts' => ['js/financeiro.js'],
            ...$dados,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function buscarConta(string $idTexto): ?array
    {
        $id = self::id($idTexto);
        return $id === null ? null : (new FinanceiroRepository($this->db))->conta($this->restaurante['id'], $id);
    }

    private function naoEncontrada(): Response
    {
        return $this->voltar($this->url('financeiro/contas'), 'erro', 'Conta não encontrada. Ela pode ter sido excluída.');
    }

    private function relatorio(): FinanceiroService
    {
        return new FinanceiroService($this->db, $this->restaurante['id']);
    }

    private function contasServico(): ContaFinanceiraService
    {
        return new ContaFinanceiraService($this->db, $this->usuario, $this->restaurante['id']);
    }
}
