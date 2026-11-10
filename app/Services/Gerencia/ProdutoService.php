<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Inventory\Quantidade;
use Cardapim\Modules\Inventory\UnidadeMedida;
use Cardapim\Repositories\Estoque\CategoriaRepository;
use Cardapim\Repositories\Estoque\InsumoRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Numero;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/** Cadastro, edicao, pausa e exclusao de produtos do cardapio, com ficha tecnica e auditoria. */
final class ProdutoService
{
    public const CATEGORIA_NOVA = 'nova';

    private const ROTULOS = [
        'nome' => 'Nome', 'categoria' => 'Categoria', 'preco' => 'Preço', 'codigo' => 'Código', 'descricao' => 'Descrição',
        'controla_estoque' => 'Controla estoque', 'disponivel' => 'Disponível', 'ficha' => 'Ficha técnica',
    ];

    private readonly ProdutoRepository $produtos;
    private readonly CategoriaRepository $categorias;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor, private readonly int $idRestaurante)
    {
        $this->produtos = new ProdutoRepository($db);
        $this->categorias = new CategoriaRepository($db);
    }

    /** @param array<string,mixed> $entrada */
    public function salvar(?int $id, array $entrada): Resultado
    {
        $antes = null;
        $fichaAntes = [];
        if ($id !== null) {
            $antes = $this->produtos->buscar($this->idRestaurante, $id);
            if ($antes === null) {
                return Resultado::falha('Produto não encontrado. Ele pode ter sido excluído.');
            }
            $fichaAntes = $this->produtos->ficha($this->idRestaurante, $id);
        }
        [$dados, $ficha, $novaCategoria, $erros] = $this->validar($entrada, $id);
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }

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
            $this->produtos->salvarFicha($this->idRestaurante, $id, array_map(static fn (array $i): string => $i['quantidade'], $ficha));
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            if ($exception->getCode() === '23000') {
                return Resultado::falha('Confira os campos destacados.', ['nome' => 'Já existe um produto (ou categoria) com este nome ou código.']);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        if ($novaCategoria !== null) {
            Auditoria::registrar($this->autor, 'Categoria cadastrada', $novaCategoria . ' (criada no cadastro do produto ' . $dados['nome'] . ').');
        }
        $depoisLog = $this->paraLog($dados, $novaCategoria ?? $this->nomeCategoria($dados['id_categoria']), $ficha);
        if ($antes === null) {
            Auditoria::registrar($this->autor, 'Produto cadastrado', sprintf(
                '%s - preço %s; categoria %s; %s.',
                $dados['nome'],
                Numero::reais($dados['preco']),
                $depoisLog['categoria'] ?? 'nenhuma',
                $depoisLog['ficha'] !== '' ? 'ficha: ' . $depoisLog['ficha'] : 'sem ficha técnica',
            ));
            return Resultado::sucesso('Produto "' . $dados['nome'] . '" cadastrado.', $id);
        }
        $antesLog = $this->paraLog([
            ...$antes,
            'controla_estoque' => (int) $antes['controla_estoque'],
            'disponivel' => (int) $antes['disponivel'],
        ], $antes['categoria'], array_map(static fn (array $i): array => $i + ['quantidade' => $i['quantidade']], $fichaAntes));
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
        if ($produto === null) {
            return Resultado::falha('Produto não encontrado. Ele pode já ter sido excluído.');
        }
        $this->db->beginTransaction();
        try {
            $this->produtos->excluir($this->idRestaurante, $id);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        Auditoria::registrar($this->autor, 'Produto excluído', sprintf('%s (preço %s, categoria %s).', $produto['nome'], Numero::reais((string) $produto['preco']), $produto['categoria'] ?? 'nenhuma'));
        return Resultado::sucesso('Produto "' . $produto['nome'] . '" excluído.');
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

        $preco = Numero::dinheiroParaDecimal((string) ($e['preco'] ?? ''));
        if ($preco === null) {
            $erros['preco'] = 'Digite o preço de venda. Exemplo: 24,90';
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

        $controla = ($e['controle'] ?? '') === 'estoque';
        [$ficha, $erroFicha] = $this->validarFicha($e['ficha_insumo'] ?? [], $e['ficha_quantidade'] ?? []);
        if ($erroFicha !== null) {
            $erros['ficha'] = $erroFicha;
        } elseif ($controla && $ficha === []) {
            $erros['ficha'] = 'Adicione pelo menos um insumo, ou escolha "Não controlar estoque".';
        }

        return [[
            'nome' => $nome,
            'descricao' => $descricao === '' ? null : $descricao,
            'preco' => $preco ?? '0.00',
            'codigo' => $codigo === '' ? null : $codigo,
            'id_categoria' => $idCategoria,
            'controla_estoque' => $controla ? 1 : 0,
            'disponivel' => ($e['disponivel'] ?? '') === '1' ? 1 : 0,
        ], $ficha, $novaCategoria, $erros];
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

    private function nomeCategoria(?int $id): ?string
    {
        return $id === null ? null : ($this->categorias->buscar($this->idRestaurante, $id)['nome'] ?? null);
    }

    /**
     * @param array<string,mixed> $dados
     * @param iterable<array{nome:string,unidade:string,quantidade:string}> $ficha
     * @return array<string,mixed>
     */
    private function paraLog(array $dados, ?string $categoria, iterable $ficha): array
    {
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
        ];
    }
}
