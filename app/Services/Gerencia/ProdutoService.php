<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Catalog\ImagemCardapio;
use Cardapim\Modules\Inventory\Quantidade;
use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Modules\Inventory\UnidadeMedida;
use Cardapim\Repositories\Estoque\CategoriaRepository;
use Cardapim\Repositories\Estoque\ComplementoRepository;
use Cardapim\Repositories\Estoque\InsumoRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Repositories\Gerencia\IfoodVinculoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Cadastro, edicao, pausa e exclusao de produtos do cardapio, com ficha tecnica e auditoria. */
final class ProdutoService
{
    public const CATEGORIA_NOVA = 'nova';

    private const ROTULOS = [
        'nome' => 'Nome', 'categoria' => 'Categoria', 'preco' => 'Preço', 'codigo' => 'Código', 'descricao' => 'Descrição',
        'controla_estoque' => 'Controla estoque', 'disponivel' => 'Disponível', 'ficha' => 'Ficha técnica',
        'unidade_venda' => 'Como vende', 'venda_minima' => 'Venda mínima', 'porcoes' => 'Porções do cardápio online',
        'complementos' => 'Complementos', 'foto' => 'Foto', 'estoque_proprio' => 'Estoque do produto',
    ];

    /** Opcoes de "Estoque deste produto". */
    public const CONTROLE_FICHA = 'estoque';
    public const CONTROLE_PROPRIO = 'proprio';
    public const CONTROLE_LIVRE = 'livre';

    /** Rotulos de "Como vende" (produtos.unidade_venda). */
    public const UNIDADES_VENDA = ['un' => 'Por unidade', 'kg' => 'Por quilo (kg)', 'l' => 'Por litro (L)'];
    public const MAX_PORCOES = 6;

    private readonly ProdutoRepository $produtos;
    private readonly CategoriaRepository $categorias;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor, private readonly int $idRestaurante)
    {
        $this->produtos = new ProdutoRepository($db);
        $this->categorias = new CategoriaRepository($db);
    }

    /**
     * @param array<string,mixed> $entrada
     * @param array<string,mixed>|null $foto item de $_FILES (foto do produto, opcional)
     */
    public function salvar(?int $id, array $entrada, ?array $foto = null): Resultado
    {
        $antes = null;
        $fichaAntes = [];
        $porcoesAntes = [];
        if ($id !== null) {
            $antes = $this->produtos->buscar($this->idRestaurante, $id);
            if ($antes === null) {
                return Resultado::falha('Produto não encontrado. Ele pode ter sido excluído.');
            }
            $fichaAntes = $this->produtos->ficha($this->idRestaurante, $id);
            $porcoesAntes = $this->produtos->porcoes($this->idRestaurante, $id);
        }
        [$dados, $ficha, $novaCategoria, $erros] = $this->validar($entrada, $id);
        $insumos = new InsumoRepository($this->db);
        $proprioAntes = $id !== null ? $insumos->doProduto($this->idRestaurante, $id) : null;
        [$proprio, $errosProprio] = $this->validarProprio($entrada, $dados['unidade_venda'], $proprioAntes);
        $erros += $errosProprio;
        [$porcoes, $erroPorcoes] = $this->validarPorcoes($dados['unidade_venda'], $entrada['porcoes'] ?? []);
        if ($erroPorcoes !== null) {
            $erros['porcoes'] = $erroPorcoes;
        }
        $comComplementos = Esquema::tem($this->db, 'complementos');
        $grupos = [];
        $gruposAntes = [];
        if ($comComplementos) {
            $repoComplementos = new ComplementoRepository($this->db);
            [$grupos, $erroGrupos] = $this->validarGrupos($repoComplementos, $entrada, $dados['unidade_venda']);
            if ($erroGrupos !== null) {
                $erros['complementos'] = $erroGrupos;
            }
            $gruposAntes = $id !== null ? $repoComplementos->idsGruposDoProduto($this->idRestaurante, $id) : [];
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        // Foto: recebida antes de gravar (se falhar, nada muda e o erro aparece no campo).
        $novaFoto = null;
        if (is_array($foto) && (int) ($foto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $novaFoto = ImagemCardapio::salvar($foto);
            } catch (RuntimeException $erro) {
                return Resultado::falha('Confira os campos destacados.', ['foto' => $erro->getMessage()]);
            }
        }
        $fotoAntes = $antes['imagem_path'] ?? null;
        $tirarFoto = ($entrada['tirar_foto'] ?? '') === '1';

        $this->db->beginTransaction();
        try {
            if ($novaCategoria !== null) {
                $dados['id_categoria'] = $this->categorias->criar($this->idRestaurante, $novaCategoria);
            }
            if ($antes === null) {
                $id = $this->produtos->criar($this->idRestaurante, $dados);
            } else {
                $this->produtos->atualizar($this->idRestaurante, $id, $dados);
            }
            if ($proprio !== null) {
                // Estoque contado no produto: um insumo proprio (mesmo nome) e ficha de 1 unidade (ou 1 kg/L).
                $ficha = [$this->salvarProprio($insumos, $id, $dados, $proprio, $proprioAntes) => [
                    'nome' => $dados['nome'], 'unidade' => $dados['unidade_venda'], 'quantidade' => Quantidade::paraDecimal(1000)]];
            } elseif ($proprioAntes !== null) {
                // Saiu da opcao "contar unidades": o estoque proprio e arquivado (historico preservado).
                $insumos->arquivar($this->idRestaurante, $proprioAntes['id'], $proprioAntes['nome']);
            }
            $this->produtos->salvarFicha($this->idRestaurante, $id, array_map(static fn (array $i): string => $i['quantidade'], $ficha));
            $this->produtos->salvarPorcoes($this->idRestaurante, $id, $porcoes);
            if ($novaFoto !== null || $tirarFoto) {
                $this->produtos->definirImagem($this->idRestaurante, $id, $novaFoto);
            }
            if ($comComplementos) {
                (new ComplementoRepository($this->db))->salvarGruposDoProduto($this->idRestaurante, $id, $grupos);
            }
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            if ($novaFoto !== null) {
                ImagemCardapio::apagar($novaFoto);
            }
            if ($exception->getCode() === '23000') {
                return Resultado::falha('Confira os campos destacados.', ['nome' => 'Já existe um produto (ou categoria) com este nome ou código.']);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($novaFoto !== null) {
                ImagemCardapio::apagar($novaFoto);
            }
            throw $exception;
        }
        if ($fotoAntes !== null && ($novaFoto !== null || $tirarFoto)) {
            ImagemCardapio::apagar($fotoAntes);
        }

        if ($novaCategoria !== null) {
            Auditoria::registrar($this->autor, 'Categoria cadastrada', $novaCategoria . ' (criada no cadastro do produto ' . $dados['nome'] . ').');
        }
        $depoisLog = $this->paraLog($dados, $novaCategoria ?? $this->nomeCategoria($dados['id_categoria']), $proprio !== null ? [] : $ficha, $porcoes)
            + ['estoque_proprio' => $proprio !== null ? $this->textoProprio($proprio, $dados['unidade_venda'], $proprioAntes) : 'não conta']
            + ['complementos' => $this->nomesGrupos($grupos), 'foto' => ($novaFoto !== null ? 'nova' : ($tirarFoto ? 'sem foto' : ($fotoAntes !== null ? 'mantida' : 'sem foto')))];
        if ($antes === null) {
            Auditoria::registrar($this->autor, 'Produto cadastrado', sprintf(
                '%s - preço %s; categoria %s; %s.',
                $dados['nome'],
                Numero::reais($dados['preco']) . QuantidadeVenda::sufixoPreco($dados['unidade_venda']),
                $depoisLog['categoria'] ?? 'nenhuma',
                $proprio !== null ? 'estoque do produto: ' . $depoisLog['estoque_proprio']
                    : ($depoisLog['ficha'] !== '' ? 'ficha: ' . $depoisLog['ficha'] : 'sem ficha técnica'),
            ));
            return Resultado::sucesso('Produto "' . $dados['nome'] . '" cadastrado.', $id);
        }
        $antesLog = $this->paraLog([
            ...$antes,
            'controla_estoque' => (int) $antes['controla_estoque'],
            'disponivel' => (int) $antes['disponivel'],
        ], $antes['categoria'], $proprioAntes !== null ? [] : array_map(static fn (array $i): array => $i + ['quantidade' => $i['quantidade']], $fichaAntes), $porcoesAntes)
            + ['estoque_proprio' => $proprioAntes !== null ? $this->textoProprio(['estoque' => null, 'minimo' => $proprioAntes['estoque_minimo'], 'custo' => $proprioAntes['custo_unitario']], (string) $proprioAntes['unidade'], $proprioAntes) : 'não conta']
            + ['complementos' => $this->nomesGrupos($gruposAntes), 'foto' => $fotoAntes !== null ? 'mantida' : 'sem foto'];
        Auditoria::registrar($this->autor, 'Produto editado', $antes['nome'] . ' - ' . Auditoria::diferencas($antesLog, $depoisLog, self::ROTULOS));
        return Resultado::sucesso('Produto "' . $dados['nome'] . '" atualizado.', $id);
    }

    /** Pausar/voltar a vender sem abrir o formulario. */
    public function alternarDisponivel(int $id): Resultado
    {
        $produto = $this->produtos->buscar($this->idRestaurante, $id);
        if ($produto === null) {
            return Resultado::falha('Produto não encontrado. Ele pode ter sido excluído.');
        }
        $novo = (int) $produto['disponivel'] !== 1;
        $this->produtos->definirDisponivel($this->idRestaurante, $id, $novo);
        Auditoria::registrar($this->autor, $novo ? 'Produto liberado para venda' : 'Produto pausado', (string) $produto['nome'] . '.');
        return Resultado::sucesso($novo ? '"' . $produto['nome'] . '" voltou a ser vendido.' : '"' . $produto['nome'] . '" foi pausado e não aparece para venda.', $id);
    }

    public function excluir(int $id): Resultado
    {
        $produto = $this->produtos->buscar($this->idRestaurante, $id);
        if ($produto === null || (int) $produto['ativo'] !== 1) {
            return Resultado::falha('Produto não encontrado. Ele pode já ter sido excluído.');
        }
        $temPedidos = $this->produtos->temPedidos($this->idRestaurante, $id);
        $vinculadoIfood = $this->produtos->temVinculoIfood($this->idRestaurante, $id);
        $publicadoIfood = Esquema::tem($this->db, 'complementos')
            && (new IfoodVinculoRepository($this->db, $this->idRestaurante))->buscar('item', $id) !== null;
        // Nunca apagar fisicamente um produto referenciado pelo iFood: a FK impediria a exclusao.
        // Arquivar mantem pedidos e vinculacoes para que o cron possa remover/pausar o item remoto.
        $arquivado = $temPedidos || $vinculadoIfood || $publicadoIfood;
        $this->db->beginTransaction();
        try {
            $proprio = (new InsumoRepository($this->db))->doProduto($this->idRestaurante, $id);
            if ($proprio !== null) {
                (new InsumoRepository($this->db))->arquivar($this->idRestaurante, $proprio['id'], $proprio['nome']);
            }
            if ($arquivado) {
                $this->produtos->arquivar($this->idRestaurante, $id, (string) $produto['nome']);
            } else {
                if (Esquema::tem($this->db, 'complementos')) {
                    (new ComplementoRepository($this->db))->removerLigacoesDoProduto($this->idRestaurante, $id);
                }
                $this->produtos->excluir($this->idRestaurante, $id);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        Auditoria::registrar($this->autor, 'Produto excluído', sprintf('%s (preço %s, categoria %s).%s', $produto['nome'], Numero::reais((string) $produto['preco']), $produto['categoria'] ?? 'nenhuma',
            $arquivado ? ' Arquivado: ' . ($temPedidos ? 'possui pedidos; ' : '') . ($publicadoIfood ? 'publicado no iFood; ' : '') . ($vinculadoIfood ? 'vinculado ao iFood; ' : '') . 'histórico preservado.' : ''));
        $avisoIfood = $publicadoIfood ? ' A exclusão do produto publicado no iFood será enviada pela sincronização.'
            : ($vinculadoIfood ? ' O item vinculado ao iFood será pausado pela sincronização.' : '');
        return Resultado::sucesso('Produto "' . $produto['nome'] . '" excluído do Deliveryzim.'
            . ($temPedidos ? ' Os pedidos antigos continuam no histórico.' : '') . $avisoIfood);
    }

    /**
     * @param array<string,mixed> $e
     * @return array{0:array{nome:string,descricao:?string,preco:string,codigo:?string,id_categoria:?int,controla_estoque:int,disponivel:int}, 1:array<int,array{nome:string,unidade:string,quantidade:string}>, 2:?string, 3:array<string,string>}
     */
    private function validar(array $e, ?int $id): array
    {
        $erros = [];
        $nome = InsumoService::texto($e['nome'] ?? '');
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 150) {
            $erros['nome'] = 'Digite um nome entre 2 e 150 letras.';
        } elseif ($this->produtos->nomeEmUso($this->idRestaurante, $nome, $id)) {
            $erros['nome'] = 'Já existe um produto com este nome.';
        }

        $unidade = QuantidadeVenda::unidade(is_string($e['unidade_venda'] ?? null) ? $e['unidade_venda'] : 'un');
        $preco = Numero::dinheiroParaDecimal((string) ($e['preco'] ?? ''));
        if ($preco === null) {
            $erros['preco'] = $unidade === 'un' ? 'Digite o preço de venda. Exemplo: 24,90' : 'Digite o preço do ' . ($unidade === 'kg' ? 'quilo' : 'litro') . '. Exemplo: 59,90';
        }
        // Venda minima (so por quilo/litro): opcional, ex.: 0,100 kg.
        $minimo = null;
        $minimoTexto = is_string($e['venda_minima'] ?? null) ? trim($e['venda_minima']) : '';
        if ($unidade !== 'un' && $minimoTexto !== '') {
            try {
                $m = Quantidade::paraMilesimos($minimoTexto);
            } catch (InvalidArgumentException) {
                $m = 0;
            }
            if ($m <= 0 || $m > QuantidadeVenda::MAX_FRACAO) {
                $erros['venda_minima'] = 'Digite a quantidade mínima com até 3 casas (ex.: 0,100) ou deixe vazio.';
            } else {
                $minimo = QuantidadeVenda::paraBanco($m);
            }
        }

        $descricao = is_string($e['descricao'] ?? null) ? trim($e['descricao']) : '';
        if (mb_strlen($descricao) > 500) {
            $erros['descricao'] = 'Use no máximo 500 caracteres.';
        }

        $codigo = InsumoService::texto($e['codigo'] ?? '');
        if ($codigo !== '' && preg_match('/\A[A-Za-z0-9._-]{1,40}\z/', $codigo) !== 1) {
            $erros['codigo'] = 'Use até 40 letras, números, ponto, hífen ou sublinhado.';
        } elseif ($codigo !== '' && $this->produtos->codigoEmUso($this->idRestaurante, $codigo, $id)) {
            $erros['codigo'] = 'Outro produto já usa este código.';
        }

        $idCategoria = null;
        $novaCategoria = null;
        $categoria = (string) ($e['categoria'] ?? '');
        if ($categoria === self::CATEGORIA_NOVA) {
            $novaCategoria = InsumoService::texto($e['nova_categoria'] ?? '');
            if (mb_strlen($novaCategoria) < 2 || mb_strlen($novaCategoria) > 80) {
                $erros['nova_categoria'] = 'Digite o nome da nova categoria (2 a 80 letras).';
            } elseif ($this->categorias->nomeEmUso($this->idRestaurante, $novaCategoria)) {
                $erros['nova_categoria'] = 'Esta categoria já existe. Escolha ela na lista.';
            }
        } elseif ($categoria !== '') {
            if (ctype_digit($categoria) && $this->categorias->buscar($this->idRestaurante, (int) $categoria) !== null) {
                $idCategoria = (int) $categoria;
            } else {
                $erros['categoria'] = 'Escolha uma categoria da lista.';
            }
        }

        $modo = self::controle($e['controle'] ?? '');
        if ($modo === self::CONTROLE_PROPRIO && !Esquema::tem($this->db, 'estoque_produto')) {
            $erros['controle'] = 'Esta opção ainda está sendo liberada. Use a ficha técnica por enquanto.';
        }
        $controla = $modo !== self::CONTROLE_LIVRE;
        $ficha = [];
        if ($modo === self::CONTROLE_FICHA) {
            [$ficha, $erroFicha] = $this->validarFicha($e['ficha_insumo'] ?? [], $e['ficha_quantidade'] ?? []);
            if ($erroFicha !== null) {
                $erros['ficha'] = $erroFicha;
            } elseif ($ficha === []) {
                $erros['ficha'] = 'Adicione pelo menos um insumo, ou escolha outra opção de estoque.';
            }
        }

        return [[
            'nome' => $nome,
            'descricao' => $descricao === '' ? null : $descricao,
            'preco' => $preco ?? '0.00',
            'unidade_venda' => $unidade,
            'venda_minima' => $minimo,
            'codigo' => $codigo === '' ? null : $codigo,
            'id_categoria' => $idCategoria,
            'controla_estoque' => $controla ? 1 : 0,
            'disponivel' => ($e['disponivel'] ?? '') === '1' ? 1 : 0,
        ], $ficha, $novaCategoria, $erros];
    }

    public static function controle(mixed $valor): string
    {
        return in_array($valor, [self::CONTROLE_PROPRIO, self::CONTROLE_LIVRE], true) ? $valor : self::CONTROLE_FICHA;
    }

    /**
     * "Contar as unidades deste produto": quanto tem agora, aviso de estoque baixo e custo (opcional).
     * Na edicao, o estoque so muda se a pessoa alterou o numero (vendas feitas com a tela aberta nao se perdem).
     * @param array<string,mixed> $e
     * @param array<string,mixed>|null $antes insumo proprio atual
     * @return array{0: ?array{estoque:?string, minimo:string, custo:?string}, 1: array<string,string>}
     */
    private function validarProprio(array $e, string $unidade, ?array $antes): array
    {
        if (self::controle($e['controle'] ?? '') !== self::CONTROLE_PROPRIO) {
            return [null, []];
        }
        $erros = [];
        $nomeUnidade = ['un' => 'unidades', 'kg' => 'kg', 'l' => 'litros'][$unidade];
        $ler = static function (string $campo, bool $obrigatorio) use ($e, $unidade, &$erros, $nomeUnidade): ?string {
            $texto = is_string($e[$campo] ?? null) ? trim($e[$campo]) : '';
            if ($texto === '') {
                if ($obrigatorio) {
                    $erros[$campo] = 'Digite quantas ' . $nomeUnidade . ' você tem agora (pode ser 0).';
                }
                return null;
            }
            try {
                $m = Quantidade::paraMilesimos($texto);
            } catch (InvalidArgumentException) {
                $m = -1;
            }
            if ($m < 0 || $m > 999_999_999_999 || ($unidade === 'un' && $m % 1000 !== 0)) {
                $erros[$campo] = $unidade === 'un' ? 'Use um número inteiro de unidades. Exemplo: 10' : 'Use até 3 casas decimais. Exemplo: 2,500';
                return null;
            }
            return Quantidade::paraDecimal($m);
        };
        $estoque = $ler('estoque_quantidade', $antes === null || (string) $antes['unidade'] !== $unidade);
        if ($antes !== null && $estoque !== null) {
            $original = is_string($e['estoque_quantidade_antes'] ?? null) ? trim($e['estoque_quantidade_antes']) : '';
            try {
                $igual = $original !== '' && Quantidade::paraMilesimos($original) === Quantidade::paraMilesimos($estoque);
            } catch (InvalidArgumentException) {
                $igual = false;
            }
            // Mudou "como vende" (un/kg/L): o numero digitado vale na unidade nova, mesmo igual ao anterior.
            if ($igual && (string) $antes['unidade'] === $unidade) {
                $estoque = null; // nao mexeu no numero: preserva o que foi vendido com a tela aberta
            }
        }
        $minimo = $ler('estoque_minimo_produto', false) ?? '0.000';
        $custoTexto = is_string($e['estoque_custo'] ?? null) ? trim($e['estoque_custo']) : '';
        $custo = null;
        if ($custoTexto !== '') {
            $custo = Numero::dinheiroParaDecimal($custoTexto);
            if ($custo === null) {
                $erros['estoque_custo'] = 'Digite quanto você paga por unidade. Exemplo: 3,50';
            }
        }
        return [$erros === [] ? ['estoque' => $estoque, 'minimo' => $minimo, 'custo' => $custo] : null, $erros];
    }

    /**
     * Cria ou atualiza o insumo proprio do produto (dentro da transacao do produto). Devolve o id dele.
     * @param array<string,mixed> $dados produto validado
     * @param array{estoque:?string, minimo:string, custo:?string} $proprio
     * @param array<string,mixed>|null $antes
     */
    private function salvarProprio(InsumoRepository $insumos, int $idProduto, array $dados, array $proprio, ?array $antes): int
    {
        $nome = $this->nomeProprio($insumos, (string) $dados['nome'], $antes['id'] ?? null);
        if ($antes === null) {
            return $insumos->criarDoProduto($this->idRestaurante, $idProduto, [
                'nome' => $nome, 'unidade' => $dados['unidade_venda'], 'estoque_atual' => $proprio['estoque'] ?? '0.000',
                'estoque_minimo' => $proprio['minimo'], 'custo_unitario' => $proprio['custo'],
            ]);
        }
        $this->db->prepare('UPDATE insumos SET nome = ?, unidade = ?, estoque_minimo = ?, custo_unitario = ?, ativo = 1
            WHERE id = ? AND id_restaurante = ?')
            ->execute([$nome, $dados['unidade_venda'], $proprio['minimo'], $proprio['custo'], $antes['id'], $this->idRestaurante]);
        if ($proprio['estoque'] !== null) {
            $insumos->definirEstoque($this->idRestaurante, $antes['id'], $proprio['estoque']);
        }
        return (int) $antes['id'];
    }

    /** Mesmo nome do produto; se ja houver um insumo comum com esse nome, acrescenta "(produto)". */
    private function nomeProprio(InsumoRepository $insumos, string $nomeProduto, ?int $idProprio): string
    {
        $base = mb_substr($nomeProduto, 0, 100);
        foreach ([$base, $base . ' (produto)'] as $opcao) {
            if (!$insumos->nomeEmUso($this->idRestaurante, $opcao, $idProprio)) {
                return $opcao;
            }
        }
        return $base . ' (produto ' . bin2hex(random_bytes(2)) . ')';
    }

    /** @param array{estoque:?string, minimo:string, custo:?string} $p @param array<string,mixed>|null $antes */
    private function textoProprio(array $p, string $unidade, ?array $antes): string
    {
        $u = UnidadeMedida::from($unidade);
        $estoque = $p['estoque'] ?? ($antes['estoque_atual'] ?? '0');
        return 'tem ' . $u->formatar(Quantidade::paraMilesimos((string) $estoque)) . ', aviso com ' . $u->formatar(Quantidade::paraMilesimos($p['minimo']))
            . ($p['custo'] !== null ? ', custo ' . Numero::reais($p['custo']) : '');
    }

    /**
     * @return array{0:array<int,array{nome:string,unidade:string,quantidade:string}>, 1:?string}
     */
    private function validarFicha(mixed $insumos, mixed $quantidades): array
    {
        if (!is_array($insumos) || !is_array($quantidades)) {
            return [[], null];
        }
        $mapa = (new InsumoRepository($this->db))->mapaParaFicha($this->idRestaurante);
        $ficha = [];
        foreach (array_values($insumos) as $i => $idInsumo) {
            $idInsumo = is_string($idInsumo) ? trim($idInsumo) : '';
            $texto = is_string($quantidades[$i] ?? null) ? trim($quantidades[$i]) : '';
            if ($idInsumo === '' && $texto === '') {
                continue; // linha vazia
            }
            if (count($ficha) >= 50) {
                return [[], 'A ficha aceita no máximo 50 insumos.'];
            }
            if (!ctype_digit($idInsumo) || !isset($mapa[(int) $idInsumo])) {
                return [[], 'Escolha o insumo em todas as linhas da ficha (ou remova a linha vazia).'];
            }
            $insumo = $mapa[(int) $idInsumo];
            if (isset($ficha[$insumo['id']])) {
                return [[], '"' . $insumo['nome'] . '" aparece duas vezes na ficha. Deixe só uma linha e some as quantidades.'];
            }
            try {
                $milesimos = Quantidade::paraMilesimos($texto);
            } catch (InvalidArgumentException) {
                $milesimos = 0;
            }
            if ($milesimos <= 0 || $milesimos > 999_999_999_999) {
                return [[], 'Digite quanto vai de "' . $insumo['nome'] . '" em cada produto. Exemplo: 0,080'];
            }
            $ficha[$insumo['id']] = ['nome' => $insumo['nome'], 'unidade' => $insumo['unidade'], 'quantidade' => Quantidade::paraDecimal($milesimos)];
        }
        return [$ficha, null];
    }

    /**
     * Porcoes fixas do cardapio online (so produto por quilo/litro): ate 6, cada uma de 0,001 a 99,999, sem repetir.
     * Nenhuma porcao = o produto vende so no caixa e no garcom.
     * @return array{0:list<string>, 1:?string} decimais em ordem crescente e o erro
     */
    private function validarPorcoes(string $unidade, mixed $entrada): array
    {
        if ($unidade === 'un' || !is_array($entrada)) {
            return [[], null];
        }
        $lista = [];
        foreach ($entrada as $texto) {
            $texto = is_string($texto) ? trim($texto) : '';
            if ($texto === '') {
                continue;
            }
            try {
                $m = Quantidade::paraMilesimos($texto);
            } catch (InvalidArgumentException) {
                $m = 0;
            }
            if ($m <= 0 || $m > QuantidadeVenda::MAX_FRACAO) {
                return [[], 'Digite cada porção com até 3 casas (ex.: 0,500 para meio quilo).'];
            }
            $lista[$m] = true;
        }
        if (count($lista) > self::MAX_PORCOES) {
            return [[], 'Cadastre no máximo ' . self::MAX_PORCOES . ' porções.'];
        }
        $milesimos = array_keys($lista);
        sort($milesimos);
        return [array_map([QuantidadeVenda::class, 'paraBanco'], $milesimos), null];
    }

    /**
     * Grupos de complementos marcados no produto, na ordem escolhida (grupos[] = ids; ordem_grupo[id] = posicao).
     * @param array<string,mixed> $e
     * @return array{0: list<int>, 1: ?string}
     */
    private function validarGrupos(ComplementoRepository $repo, array $e, string $unidade): array
    {
        $marcados = is_array($e['grupos'] ?? null) ? array_values(array_filter($e['grupos'], static fn ($v): bool => is_string($v) && ctype_digit($v))) : [];
        if ($marcados === []) {
            return [[], null];
        }
        if ($unidade !== 'un') {
            return [[], 'Complementos só podem ser usados em produto vendido por unidade.'];
        }
        $ordem = is_array($e['ordem_grupo'] ?? null) ? $e['ordem_grupo'] : [];
        $ids = array_map('intval', $marcados);
        usort($ids, static fn (int $a, int $b): int => [(int) ($ordem[$a] ?? 99), $a] <=> [(int) ($ordem[$b] ?? 99), $b]);
        $validos = $repo->gruposValidos($this->idRestaurante, $ids);
        if (count($validos) !== count(array_unique($ids))) {
            return [[], 'Algum grupo escolhido não existe mais. Recarregue a página e escolha de novo.'];
        }
        if (count($validos) > 20) {
            return [[], 'Use no máximo 20 grupos de complementos por produto.'];
        }
        return [$validos, null];
    }

    /** @param list<int> $ids */
    private function nomesGrupos(array $ids): string
    {
        if ($ids === []) {
            return 'nenhum';
        }
        $nomes = [];
        foreach ((new ComplementoRepository($this->db))->grupos($this->idRestaurante) as $g) {
            $nomes[$g['id']] = $g['nome'];
        }
        return implode(', ', array_map(static fn (int $id): string => $nomes[$id] ?? ('#' . $id), $ids));
    }

    private function nomeCategoria(?int $id): ?string
    {
        return $id === null ? null : ($this->categorias->buscar($this->idRestaurante, $id)['nome'] ?? null);
    }

    /**
     * @param array<string,mixed> $dados
     * @param iterable<array{nome:string,unidade:string,quantidade:string}> $ficha
     * @return array<string,mixed>
     */
    private function paraLog(array $dados, ?string $categoria, iterable $ficha, array $porcoes = []): array
    {
        $unidade = QuantidadeVenda::unidade($dados['unidade_venda'] ?? 'un');
        $itens = [];
        foreach ($ficha as $item) {
            $itens[] = $item['nome'] . ' ' . UnidadeMedida::from($item['unidade'])->formatar(Quantidade::paraMilesimos($item['quantidade']));
        }
        sort($itens);
        return [
            'nome' => $dados['nome'],
            'categoria' => $categoria,
            'preco' => Numero::dinheiroCampo((string) $dados['preco']),
            'codigo' => $dados['codigo'] ?? null,
            'descricao' => $dados['descricao'] ?? null,
            'controla_estoque' => (int) $dados['controla_estoque'] === 1,
            'disponivel' => (int) $dados['disponivel'] === 1,
            'ficha' => implode(', ', $itens),
            'unidade_venda' => self::UNIDADES_VENDA[$unidade],
            'venda_minima' => ($dados['venda_minima'] ?? null) !== null ? QuantidadeVenda::textoDoBanco($dados['venda_minima'], $unidade) : null,
            'porcoes' => implode(', ', array_map(static fn (string $p): string => QuantidadeVenda::textoDoBanco($p, $unidade), $porcoes)),
        ];
    }
}
