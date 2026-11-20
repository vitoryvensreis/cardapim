<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Modules\Inventory\AnaliseEstoque;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Repositories\Estoque\CategoriaRepository;
use Cardapim\Repositories\Estoque\ComplementoRepository;
use Cardapim\Repositories\Estoque\InsumoRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Services\Gerencia\CategoriaService;
use Cardapim\Services\Gerencia\ComplementoService;
use Cardapim\Services\Gerencia\InsumoService;
use Cardapim\Services\Gerencia\ProdutoService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;

/**
 * Aba Estoque: resumo (analise), produtos, complementos, insumos e categorias.
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
        $insumos = (new InsumoRepository($this->db))->listar($id, true); // inclui o estoque contado nos produtos
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
        return $this->formProduto(null, ['disponivel' => '1', 'controle' => 'estoque', 'unidade_venda' => 'un', 'categoria' => (string) ($_GET['categoria'] ?? '')], []);
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
        $proprio = (new InsumoRepository($this->db))->doProduto($this->restaurante['id'], $id);
        $campoQtd = static fn (string $d, string $u): string => $u === 'un' ? (string) intdiv(\Cardapim\Modules\Inventory\Quantidade::paraMilesimos($d), 1000) : Numero::decimalCampo($d);
        $extraProprio = $proprio === null ? [] : [
            'estoque_quantidade' => $campoQtd($proprio['estoque_atual'], $proprio['unidade']),
            'estoque_quantidade_antes' => $campoQtd($proprio['estoque_atual'], $proprio['unidade']),
            'estoque_minimo_produto' => (float) $proprio['estoque_minimo'] > 0 ? $campoQtd($proprio['estoque_minimo'], $proprio['unidade']) : '',
            'estoque_custo' => $proprio['custo_unitario'] !== null ? Numero::dinheiroCampo($proprio['custo_unitario']) : '',
        ];
        if ($proprio !== null) {
            $ficha = []; // a ficha automatica (1 unidade) nao aparece: o estoque e mostrado nos campos acima
        }
        return $this->formProduto($produto, $extraProprio + [
            'nome' => (string) $produto['nome'],
            'categoria' => $produto['id_categoria'] === null ? '' : (string) $produto['id_categoria'],
            'preco' => Numero::dinheiroCampo((string) $produto['preco']),
            'codigo' => (string) ($produto['codigo'] ?? ''),
            'descricao' => (string) ($produto['descricao'] ?? ''),
            'controle' => (int) $produto['controla_estoque'] !== 1 ? 'livre' : ($proprio !== null ? 'proprio' : 'estoque'),
            'disponivel' => (int) $produto['disponivel'] === 1 ? '1' : '',
            'ficha_insumo' => array_map(static fn (array $i): string => (string) $i['id_insumo'], $ficha),
            'ficha_quantidade' => array_map(static fn (array $i): string => Numero::decimalCampo($i['quantidade']), $ficha),
            'unidade_venda' => (string) ($produto['unidade_venda'] ?? 'un'),
            'venda_minima' => $produto['venda_minima'] !== null ? \Cardapim\Modules\Inventory\QuantidadeVenda::campo(\Cardapim\Modules\Inventory\QuantidadeVenda::doBanco($produto['venda_minima'])) : '',
            'porcoes' => array_map(static fn (string $p): string => \Cardapim\Modules\Inventory\QuantidadeVenda::campo(\Cardapim\Modules\Inventory\QuantidadeVenda::doBanco($p)),
                (new ProdutoRepository($this->db))->porcoes($this->restaurante['id'], $id)),
        ] + $this->gruposDoProdutoParaForm($id) + $this->impressorasDoProdutoParaForm($id), []);
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
        // Estoque contado no proprio produto: e editado na tela do produto.
        $dono = (new InsumoRepository($this->db))->produtoDono($this->restaurante['id'], (int) $id);
        if ($dono !== null) {
            return Response::redirect($this->url('estoque/produtos/' . $dono['id']), 303);
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
            'impressorasProducao' => $this->impressorasProducao(),
            'impressorasCategoria' => Esquema::tem($this->db, 'impressao_produto')
                ? (new \Cardapim\Repositories\Desktop\ProdutoImpressoraRepository($this->db))->resumoCategorias($this->restaurante['id']) : [],
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

    /** Atalho: marca as mesmas impressoras de producao (apelidos) em todos os produtos da categoria. */
    public function impressorasCategoria(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $volta = $this->url('estoque/categorias');
        $id = self::id($idTexto);
        $categoria = null;
        foreach ((new CategoriaRepository($this->db))->listar($this->restaurante['id']) as $c) {
            if ((int) $c['id'] === $id) {
                $categoria = $c;
            }
        }
        if ($categoria === null) {
            return $this->voltar($volta, 'erro', 'Categoria não encontrada.');
        }
        if (!Esquema::tem($this->db, 'impressao_produto')) {
            return $this->voltar($volta, 'erro', 'As impressoras por produto ainda estão sendo ativadas.');
        }
        $ids = self::idsMarcados($_POST['impressoras'] ?? null);
        $n = (new \Cardapim\Repositories\Desktop\ProdutoImpressoraRepository($this->db))->aplicarCategoria($this->restaurante['id'], (int) $id, $ids);
        \Cardapim\Services\Auditoria::registrar($this->usuario, 'Impressoras da categoria', 'Categoria "' . $categoria['nome'] . '": impressoras de produção aplicadas a ' . $n . ' produto(s).');
        return $this->voltar($volta, 'sucesso', $n === 0 ? 'A categoria "' . $categoria['nome'] . '" ainda não tem produtos.'
            : ($ids === [] ? 'Os ' . $n . ' produto(s) de "' . $categoria['nome'] . '" voltam a sair na impressora padrão da produção.'
                : 'Impressoras aplicadas aos ' . $n . ' produto(s) de "' . $categoria['nome'] . '".'));
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

    /* ---------- Complementos (adicionais, bordas, molhos...) ---------- */

    public function complementos(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $pronto = Esquema::tem($this->db, 'complementos');
        return $this->tela('gerencia/estoque/complementos', 'estoque', 'Complementos', [
            'pronto' => $pronto,
            'grupos' => $pronto ? (new ComplementoRepository($this->db))->grupos($this->restaurante['id']) : [],
            'contagem' => $this->contagem(null, null),
            'abaAtual' => 'complementos',
            'pageStyles' => ['css/complementos.css'],
        ]);
    }

    public function novoGrupo(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        if (!Esquema::tem($this->db, 'complementos')) {
            return $this->voltar($this->url('estoque/complementos'), 'erro', 'Os complementos ainda não foram liberados neste servidor.');
        }
        return $this->formGrupo(null, ['minimo' => '0', 'maximo' => '1', 'opcoes' => [['disponivel' => '1']]], []);
    }

    public function editarGrupo(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $id = self::id($idTexto);
        $grupo = $id === null || !Esquema::tem($this->db, 'complementos') ? null : (new ComplementoRepository($this->db))->grupo($this->restaurante['id'], $id);
        if ($grupo === null) {
            return $this->naoEncontrado('Grupo de complementos', $this->url('estoque/complementos'));
        }
        return $this->formGrupo($grupo, self::valoresGrupo($grupo), []);
    }

    public function criarGrupo(string $slug): Response
    {
        return $this->salvarGrupo($slug, null);
    }

    public function atualizarGrupo(string $slug, string $idTexto): Response
    {
        return $this->salvarGrupo($slug, $idTexto);
    }

    public function excluirGrupo(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null || !Esquema::tem($this->db, 'complementos')) {
            return $this->naoEncontrado('Grupo de complementos', $this->url('estoque/complementos'));
        }
        $res = (new ComplementoService($this->db, $this->usuario, $this->restaurante['id']))->excluirGrupo($id);
        return $this->responder($this->avisarEstoque($res, 'complemento', 'excluido', $id), $this->url('estoque/complementos'));
    }

    public function alternarOpcao(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        $voltar = $this->origem($this->url('estoque/complementos'));
        if ($id === null || !Esquema::tem($this->db, 'complementos')) {
            return $this->naoEncontrado('Opção', $voltar);
        }
        $res = (new ComplementoService($this->db, $this->usuario, $this->restaurante['id']))->alternarOpcao($id);
        return $this->responder($this->avisarEstoque($res, 'complemento', 'pausado_ou_liberado', $id), $voltar);
    }

    /* ---------- Internos ---------- */

    private function salvarGrupo(string $slug, ?string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        if (!Esquema::tem($this->db, 'complementos')) {
            return $this->voltar($this->url('estoque/complementos'), 'erro', 'Os complementos ainda não foram liberados neste servidor.');
        }
        $id = $idTexto === null ? null : self::id($idTexto);
        $repo = new ComplementoRepository($this->db);
        $grupo = $id === null ? null : $repo->grupo($this->restaurante['id'], $id);
        if ($idTexto !== null && $grupo === null) {
            return $this->naoEncontrado('Grupo de complementos', $this->url('estoque/complementos'));
        }
        $res = (new ComplementoService($this->db, $this->usuario, $this->restaurante['id']))->salvar($id, $_POST, self::arquivos('opcao_foto'));
        if ($this->avisarEstoque($res, 'complemento', $id === null ? 'criado' : 'editado', $id ?? $res->id)->ok) {
            return $this->voltar($this->url('estoque/complementos'), 'sucesso', $res->mensagem);
        }
        $valores = ['nome' => $this->post('nome'), 'minimo' => $this->post('minimo'), 'maximo' => $this->post('maximo'),
            'opcoes' => is_array($_POST['opcoes'] ?? null) ? $_POST['opcoes'] : []];
        return $this->formGrupo($grupo, $valores, $res->erros + ($res->erros === [] ? ['_geral' => $res->mensagem] : []), 422);
    }

    /** @param array<string,mixed>|null $grupo @param array<string,mixed> $valores @param array<string,string> $erros */
    private function formGrupo(?array $grupo, array $valores, array $erros, int $status = 200): Response
    {
        $imagens = [];
        foreach ($grupo['opcoes'] ?? [] as $o) {
            $imagens[$o['id']] = $o['imagem_path'];
        }
        return $this->tela('gerencia/estoque/complemento-form', 'estoque', $grupo === null ? 'Novo grupo de complementos' : 'Editar grupo', [
            'grupo' => $grupo,
            'valores' => $valores,
            'imagens' => $imagens,
            'erros' => $erros,
            'insumos' => (new InsumoRepository($this->db))->mapaParaFicha($this->restaurante['id']),
            'pageStyles' => ['css/complementos.css'],
            'pageScripts' => ['js/forms.js', 'js/estoque.js'],
        ], $status);
    }

    /** @param array<string,mixed> $grupo @return array<string,mixed> */
    private static function valoresGrupo(array $grupo): array
    {
        return [
            'nome' => $grupo['nome'],
            'minimo' => (string) $grupo['minimo'],
            'maximo' => (string) $grupo['maximo'],
            'opcoes' => array_map(static fn (array $o): array => [
                'id' => (string) $o['id'], 'nome' => $o['nome'], 'preco' => Numero::dinheiroCampo($o['preco']), 'codigo' => (string) ($o['codigo'] ?? ''),
                'descricao' => (string) ($o['descricao'] ?? ''), 'disponivel' => $o['disponivel'] ? '1' : '',
                'insumo' => isset($o['ficha'][0]) ? (string) $o['ficha'][0]['id_insumo'] : '',
                'quantidade' => isset($o['ficha'][0]) ? Numero::decimalCampo($o['ficha'][0]['quantidade']) : '',
            ], $grupo['opcoes']),
        ];
    }

    /** $_FILES[campo] com varios arquivos (campo[i]) -> i => arquivo. @return array<int|string,array<string,mixed>> */
    private static function arquivos(string $campo): array
    {
        $f = $_FILES[$campo] ?? null;
        if (!is_array($f) || !is_array($f['name'] ?? null)) {
            return [];
        }
        $saida = [];
        foreach (array_keys($f['name']) as $i) {
            $saida[$i] = ['name' => $f['name'][$i] ?? '', 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
        }
        return $saida;
    }

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
        $resultado = (new ProdutoService($this->db, $this->usuario, $this->restaurante['id']))->salvar($id, $_POST, is_array($_FILES['foto'] ?? null) ? $_FILES['foto'] : null);
        if ($this->avisarEstoque($resultado, 'produto', $id === null ? 'criado' : 'editado', $id ?? $resultado->id)->ok) {
            $this->salvarImpressorasDoProduto($id ?? $resultado->id);
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
            'gruposComplemento' => Esquema::tem($this->db, 'complementos') ? (new ComplementoRepository($this->db))->grupos($idRest) : null,
            'impressorasProducao' => $this->impressorasProducao(),
            'fotoAtual' => $produto['imagem_path'] ?? null,
            'pageStyles' => ['css/complementos.css'],
            'pageScripts' => ['js/forms.js', 'js/estoque.js'],
        ], $status);
    }

    /**
     * Impressoras cadastradas nos Agents (pelos apelidos) para marcar onde o produto sai na via de producao.
     * null = recurso ainda nao liberado (migracao pendente).
     * @return list<array{id:int,apelido:string,computador:string,ligada:bool}>|null
     */
    private function impressorasProducao(): ?array
    {
        if (!Esquema::tem($this->db, 'impressao_produto')) {
            return null;
        }
        return (new \Cardapim\Repositories\Desktop\ImpressoraRepository($this->db))->listar($this->restaurante['id']);
    }

    /** @return array{impressoras?:list<string>} */
    private function impressorasDoProdutoParaForm(int $idProduto): array
    {
        if (!Esquema::tem($this->db, 'impressao_produto')) {
            return [];
        }
        return ['impressoras' => array_map('strval', (new \Cardapim\Repositories\Desktop\ProdutoImpressoraRepository($this->db))->doProduto($this->restaurante['id'], $idProduto))];
    }

    /** Grava as impressoras marcadas (so quando o formulario mostrou a secao, para nao apagar sem querer). */
    private function salvarImpressorasDoProduto(?int $idProduto): void
    {
        if ($idProduto === null || ($_POST['impressoras_enviado'] ?? '') !== '1' || !Esquema::tem($this->db, 'impressao_produto')) {
            return;
        }
        (new \Cardapim\Repositories\Desktop\ProdutoImpressoraRepository($this->db))
            ->salvar($this->restaurante['id'], $idProduto, self::idsMarcados($_POST['impressoras'] ?? null));
    }

    /** @return list<int> */
    private static function idsMarcados(mixed $valor): array
    {
        $ids = [];
        foreach (is_array($valor) ? $valor : [] as $v) {
            if (is_string($v) && ctype_digit($v) && strlen($v) <= 18 && (int) $v > 0) {
                $ids[] = (int) $v;
            }
        }
        return $ids;
    }

    /** Grupos de complementos do produto para o formulario. @return array{grupos?:list<string>, ordem_grupo?:array<int,string>} */
    private function gruposDoProdutoParaForm(int $idProduto): array
    {
        if (!Esquema::tem($this->db, 'complementos')) {
            return [];
        }
        $ids = (new ComplementoRepository($this->db))->idsGruposDoProduto($this->restaurante['id'], $idProduto);
        $ordem = [];
        foreach ($ids as $pos => $idGrupo) {
            $ordem[$idGrupo] = (string) ($pos + 1);
        }
        return ['grupos' => array_map('strval', $ids), 'ordem_grupo' => $ordem];
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
            if (is_array($valor) && $campo === 'ordem_grupo') {
                $valores[$campo] = array_map(static fn ($v): string => is_string($v) ? $v : '', $valor);
            } elseif (is_array($valor)) {
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
            $sql = match ($tabela) {
                'produtos' => 'SELECT COUNT(*) FROM produtos WHERE id_restaurante = ? AND ativo = 1',
                'insumos' => 'SELECT COUNT(*) FROM insumos WHERE id_restaurante = ? AND excluido_em IS NULL'
                    . (\Cardapim\Shared\Esquema::tem($this->db, 'estoque_produto') ? ' AND id_produto_proprio IS NULL' : ''),
                'categorias' => 'SELECT COUNT(*) FROM categorias WHERE id_restaurante = ?',
            };
            $q = $this->db->prepare($sql);
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
