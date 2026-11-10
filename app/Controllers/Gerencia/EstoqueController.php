<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Modules\Inventory\AnaliseEstoque;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Repositories\Estoque\CategoriaRepository;
use Cardapim\Repositories\Estoque\InsumoRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Services\Gerencia\CategoriaService;
use Cardapim\Services\Gerencia\InsumoService;
use Cardapim\Services\Gerencia\ProdutoService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;

/**
 * Aba Estoque: resumo (analise), produtos, insumos e categorias.
 * Rotas em /{slug}/gerencia/estoque/... Toda escrita exige CSRF e gera log em log_user.
 */
final class EstoqueController extends BaseGerencia
{
    private const FILTROS_PRODUTO = ['todos', 'a-venda', 'indisponiveis'];
    private const FILTROS_INSUMO = ['todos', 'atencao', 'ok'];

    /* ---------- Resumo ---------- */

    public function resumo(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $id = $this->restaurante['id'];
        $insumos = (new InsumoRepository($this->db))->listar($id);
        $produtos = (new ProdutoRepository($this->db))->listar($id);
        $analise = AnaliseEstoque::analisar($insumos, $produtos, (new DisponibilidadeProdutos($this->db))->verificar($id));
        return $this->tela('gerencia/estoque/resumo', 'estoque', 'Estoque', [
            'analise' => $analise,
            'contagem' => $this->contagem(count($produtos), count($insumos)),
            'abaAtual' => 'resumo',
        ]);
    }

    /* ---------- Produtos ---------- */

    public function produtos(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $id = $this->restaurante['id'];
        $produtos = (new ProdutoRepository($this->db))->listar($id);
        $disponibilidade = (new DisponibilidadeProdutos($this->db))->verificar($id);
        $filtro = in_array($_GET['filtro'] ?? '', self::FILTROS_PRODUTO, true) ? $_GET['filtro'] : 'todos';
        $total = count($produtos);
        if ($filtro !== 'todos') {
            $produtos = array_values(array_filter($produtos, static fn (array $p): bool => ($disponibilidade[(int) $p['id']]['disponivel'] ?? false) === ($filtro === 'a-venda')));
        }
        return $this->tela('gerencia/estoque/produtos', 'estoque', 'Produtos', [
            'produtos' => $produtos,
            'disponibilidade' => $disponibilidade,
            'filtro' => $filtro,
            'contagem' => $this->contagem($total, null),
            'abaAtual' => 'produtos',
        ]);
    }

    public function novoProduto(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->formProduto(null, ['disponivel' => '1', 'controle' => 'estoque', 'categoria' => (string) ($_GET['categoria'] ?? '')], []);
    }

    public function editarProduto(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $id = self::id($idTexto);
        $produto = $id === null ? null : (new ProdutoRepository($this->db))->buscar($this->restaurante['id'], $id);
        if ($produto === null) {
            return $this->naoEncontrado('Produto', $this->url('estoque/produtos'));
        }
        $ficha = (new ProdutoRepository($this->db))->ficha($this->restaurante['id'], $id);
        return $this->formProduto($produto, [
            'nome' => (string) $produto['nome'],
            'categoria' => $produto['id_categoria'] === null ? '' : (string) $produto['id_categoria'],
            'preco' => Numero::dinheiroCampo((string) $produto['preco']),
            'codigo' => (string) ($produto['codigo'] ?? ''),
            'descricao' => (string) ($produto['descricao'] ?? ''),
            'controle' => (int) $produto['controla_estoque'] === 1 ? 'estoque' : 'livre',
            'disponivel' => (int) $produto['disponivel'] === 1 ? '1' : '',
            'ficha_insumo' => array_map(static fn (array $i): string => (string) $i['id_insumo'], $ficha),
            'ficha_quantidade' => array_map(static fn (array $i): string => Numero::decimalCampo($i['quantidade']), $ficha),
        ], []);
    }

    public function criarProduto(string $slug): Response
    {
        return $this->salvarProduto($slug, null);
    }

    public function atualizarProduto(string $slug, string $idTexto): Response
    {
        return $this->salvarProduto($slug, $idTexto);
    }

    public function alternarProduto(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        $voltar = $this->origem($this->url('estoque/produtos'));
        if ($id === null) {
            return $this->naoEncontrado('Produto', $voltar);
        }
        return $this->responder($this->avisarEstoque((new ProdutoService($this->db, $this->usuario, $this->restaurante['id']))->alternarDisponivel($id), 'produto', 'pausado_ou_liberado', $id), $voltar);
    }

    public function excluirProduto(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->naoEncontrado('Produto', $this->url('estoque/produtos'));
        }
        return $this->responder($this->avisarEstoque((new ProdutoService($this->db, $this->usuario, $this->restaurante['id']))->excluir($id), 'produto', 'excluido', $id), $this->url('estoque/produtos'));
    }

    /* ---------- Insumos ---------- */

    public function insumos(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $insumos = (new InsumoRepository($this->db))->listar($this->restaurante['id']);
        $total = count($insumos);
        $filtro = in_array($_GET['filtro'] ?? '', self::FILTROS_INSUMO, true) ? $_GET['filtro'] : 'todos';
        foreach ($insumos as &$insumo) {
            $insumo['analise'] = AnaliseEstoque::classificarInsumo($insumo);
        }
        unset($insumo);
        if ($filtro !== 'todos') {
            $insumos = array_values(array_filter($insumos, static fn (array $i): bool => in_array(
                $i['analise']['situacao'],
                $filtro === 'atencao' ? [AnaliseEstoque::FALTA, AnaliseEstoque::BAIXO] : [AnaliseEstoque::OK],
                true,
            )));
        }
        return $this->tela('gerencia/estoque/insumos', 'estoque', 'Insumos', [
            'insumos' => $insumos,
            'filtro' => $filtro,
            'contagem' => $this->contagem(null, $total),
            'abaAtual' => 'insumos',
        ]);
    }

    public function novoInsumo(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->formInsumo(null, ['ativo' => '1', 'unidade' => '', 'estoque_atual' => '', 'estoque_minimo' => ''], []);
    }

    public function editarInsumo(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $id = self::id($idTexto);
        $insumo = $id === null ? null : (new InsumoRepository($this->db))->buscar($this->restaurante['id'], $id);
        if ($insumo === null) {
            return $this->naoEncontrado('Insumo', $this->url('estoque/insumos'));
        }
        return $this->formInsumo($insumo, [
            'nome' => (string) $insumo['nome'],
            'unidade' => (string) $insumo['unidade'],
            'estoque_atual' => Numero::decimalCampo((string) $insumo['estoque_atual']),
            'estoque_minimo' => Numero::decimalCampo((string) $insumo['estoque_minimo']),
            'custo_unitario' => $insumo['custo_unitario'] === null ? '' : Numero::decimalCampo((string) $insumo['custo_unitario']),
            'ativo' => (int) $insumo['ativo'] === 1 ? '1' : '',
        ], []);
    }

    public function criarInsumo(string $slug): Response
    {
        return $this->salvarInsumo($slug, null);
    }

    public function atualizarInsumo(string $slug, string $idTexto): Response
    {
        return $this->salvarInsumo($slug, $idTexto);
    }

    public function reporInsumo(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        $voltar = $this->origem($this->url('estoque/insumos'));
        if ($id === null) {
            return $this->naoEncontrado('Insumo', $voltar);
        }
        return $this->responder($this->avisarEstoque((new InsumoService($this->db, $this->usuario, $this->restaurante['id']))->repor($id, $this->post('quantidade')), 'insumo', 'reposto', $id), $voltar);
    }

    public function excluirInsumo(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->naoEncontrado('Insumo', $this->url('estoque/insumos'));
        }
        return $this->responder($this->avisarEstoque((new InsumoService($this->db, $this->usuario, $this->restaurante['id']))->excluir($id), 'insumo', 'excluido', $id), $this->url('estoque/insumos'), $this->origem($this->url('estoque/insumos')));
    }

    /* ---------- Categorias ---------- */

    public function categorias(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->tela('gerencia/estoque/categorias', 'estoque', 'Categorias', [
            'categorias' => (new CategoriaRepository($this->db))->listar($this->restaurante['id']),
            'contagem' => $this->contagem(null, null),
            'abaAtual' => 'categorias',
        ]);
    }

    public function criarCategoria(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $resultado = (new CategoriaService($this->db, $this->usuario, $this->restaurante['id']))->salvar(null, $this->post('nome'));
        return $this->responder($this->avisarEstoque($resultado, 'categoria', 'criado', $resultado->id), $this->url('estoque/categorias'));
    }

    public function atualizarCategoria(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->voltar($this->url('estoque/categorias'), 'erro', 'Categoria não encontrada.');
        }
        $resultado = (new CategoriaService($this->db, $this->usuario, $this->restaurante['id']))->salvar($id, $this->post('nome'));
        return $this->responder($this->avisarEstoque($resultado, 'categoria', 'editado', $id), $this->url('estoque/categorias'));
    }

    public function excluirCategoria(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->voltar($this->url('estoque/categorias'), 'erro', 'Categoria não encontrada.');
        }
        return $this->responder($this->avisarEstoque((new CategoriaService($this->db, $this->usuario, $this->restaurante['id']))->excluir($id), 'categoria', 'excluido', $id), $this->url('estoque/categorias'));
    }

    /* ---------- Internos ---------- */

    /** Outras telas abertas do restaurante ficam sabendo que o estoque mudou (tempo real). */
    private function avisarEstoque(Resultado $r, string $area, string $acao, ?int $id): Resultado
    {
        return $this->avisar($r, 'estoque', 'estoque.alterado', ['area' => $area, 'acao' => $acao, 'id' => $id]);
    }

    private function salvarProduto(string $slug, ?string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = $idTexto === null ? null : self::id($idTexto);
        if ($idTexto !== null && $id === null) {
            return $this->naoEncontrado('Produto', $this->url('estoque/produtos'));
        }
        $resultado = (new ProdutoService($this->db, $this->usuario, $this->restaurante['id']))->salvar($id, $_POST);
        if ($this->avisarEstoque($resultado, 'produto', $id === null ? 'criado' : 'editado', $id ?? $resultado->id)->ok) {
            return $this->voltar($this->url('estoque/produtos'), 'sucesso', $resultado->mensagem);
        }
        $produto = $id === null ? null : (new ProdutoRepository($this->db))->buscar($this->restaurante['id'], $id);
        if ($id !== null && $produto === null) {
            return $this->naoEncontrado('Produto', $this->url('estoque/produtos'));
        }
        return $this->formProduto($produto, $this->entradaPost(), $resultado->erros, 422);
    }

    private function salvarInsumo(string $slug, ?string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = $idTexto === null ? null : self::id($idTexto);
        if ($idTexto !== null && $id === null) {
            return $this->naoEncontrado('Insumo', $this->url('estoque/insumos'));
        }
        $resultado = (new InsumoService($this->db, $this->usuario, $this->restaurante['id']))->salvar($id, $_POST);
        if ($this->avisarEstoque($resultado, 'insumo', $id === null ? 'criado' : 'editado', $id ?? $resultado->id)->ok) {
            return $this->voltar($this->url('estoque/insumos'), 'sucesso', $resultado->mensagem);
        }
        $insumo = $id === null ? null : (new InsumoRepository($this->db))->buscar($this->restaurante['id'], $id);
        if ($id !== null && $insumo === null) {
            return $this->naoEncontrado('Insumo', $this->url('estoque/insumos'));
        }
        return $this->formInsumo($insumo, $this->entradaPost(), $resultado->erros, 422);
    }

    /** @param array<string,mixed>|null $produto @param array<string,mixed> $valores @param array<string,string> $erros */
    private function formProduto(?array $produto, array $valores, array $erros, int $status = 200): Response
    {
        $idRest = $this->restaurante['id'];
        return $this->tela('gerencia/estoque/produto-form', 'estoque', $produto === null ? 'Novo produto' : 'Editar produto', [
            'produto' => $produto,
            'valores' => $valores,
            'erros' => $erros,
            'categorias' => (new CategoriaRepository($this->db))->listar($idRest),
            'insumos' => (new InsumoRepository($this->db))->mapaParaFicha($idRest),
            'pageScripts' => ['js/forms.js', 'js/estoque.js'],
        ], $status);
    }

    /** @param array<string,mixed>|null $insumo @param array<string,mixed> $valores @param array<string,string> $erros */
    private function formInsumo(?array $insumo, array $valores, array $erros, int $status = 200): Response
    {
        $usos = $insumo === null ? [] : (new InsumoRepository($this->db))->produtosQueUsam($this->restaurante['id'], (int) $insumo['id']);
        return $this->tela('gerencia/estoque/insumo-form', 'estoque', $insumo === null ? 'Novo insumo' : 'Editar insumo', [
            'insumo' => $insumo,
            'valores' => $valores,
            'erros' => $erros,
            'usos' => $usos,
            'pageScripts' => ['js/forms.js', 'js/estoque.js'],
        ], $status);
    }

    /** @return array<string,mixed> o que a pessoa digitou, para devolver ao formulario em caso de erro */
    private function entradaPost(): array
    {
        $valores = [];
        foreach ($_POST as $campo => $valor) {
            if ($campo === '_csrf' || !is_string($campo)) {
                continue;
            }
            if (is_array($valor)) {
                $valores[$campo] = array_values(array_map(static fn ($v): string => is_string($v) ? $v : '', $valor));
            } elseif (is_string($valor)) {
                $valores[$campo] = $valor;
            }
        }
        return $valores;
    }

    /** Numeros das abas (produtos / insumos / categorias). @return array{produtos:int,insumos:int,categorias:int} */
    private function contagem(?int $produtos, ?int $insumos): array
    {
        $id = $this->restaurante['id'];
        $contar = function (string $tabela) use ($id): int {
            $q = $this->db->prepare('SELECT COUNT(*) FROM ' . $tabela . ' WHERE id_restaurante = ?');
            $q->execute([$id]);
            return (int) $q->fetchColumn();
        };
        return [
            'produtos' => $produtos ?? $contar('produtos'),
            'insumos' => $insumos ?? $contar('insumos'),
            'categorias' => $contar('categorias'),
        ];
    }
}
