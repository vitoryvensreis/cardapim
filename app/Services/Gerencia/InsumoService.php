<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Inventory\Quantidade;
use Cardapim\Modules\Inventory\UnidadeMedida;
use Cardapim\Repositories\Estoque\InsumoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Numero;
use InvalidArgumentException;
use PDO;
use PDOException;

/** Cadastro, edicao, reposicao e exclusao de insumos, com validacao e auditoria. */
final class InsumoService
{
    private const ROTULOS = [
        'nome' => 'Nome', 'unidade' => 'Unidade', 'estoque_atual' => 'Estoque', 'estoque_minimo' => 'Mínimo',
        'custo_unitario' => 'Custo', 'ativo' => 'Ativo',
    ];

    private readonly InsumoRepository $insumos;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor, private readonly int $idRestaurante)
    {
        $this->insumos = new InsumoRepository($db);
    }

    /** @param array<string,mixed> $entrada dados do formulario */
    public function salvar(?int $id, array $entrada): Resultado
    {
        $antes = null;
        if ($id !== null) {
            $antes = $this->insumos->buscar($this->idRestaurante, $id);
            if ($antes === null) {
                return Resultado::falha('Insumo não encontrado. Ele pode ter sido excluído.');
            }
        }
        [$dados, $erros] = $this->validar($entrada, $id);
        if ($antes !== null && !isset($erros['unidade']) && $dados['unidade'] !== $antes['unidade']) {
            $produtos = $this->insumos->produtosQueUsam($this->idRestaurante, (int) $id);
            if ($produtos !== []) {
                $erros['unidade'] = 'Não dá para trocar a unidade: este insumo está na ficha de ' . self::lista($produtos) . '. Tire ele das fichas primeiro.';
            }
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }

        try {
            if ($antes === null) {
                $novoId = $this->insumos->criar($this->idRestaurante, $dados);
                $unidade = UnidadeMedida::from($dados['unidade']);
                Auditoria::registrar($this->autor, 'Insumo cadastrado', sprintf(
                    '%s (%s): estoque %s, mínimo %s.',
                    $dados['nome'],
                    $unidade->sigla(),
                    $unidade->formatar(Quantidade::paraMilesimos($dados['estoque_atual'])),
                    $unidade->formatar(Quantidade::paraMilesimos($dados['estoque_minimo'])),
                ));
                return Resultado::sucesso('Insumo "' . $dados['nome'] . '" cadastrado.', $novoId);
            }
            $this->insumos->atualizar($this->idRestaurante, (int) $id, $dados);
            Auditoria::registrar($this->autor, 'Insumo editado', $antes['nome'] . ' - ' . Auditoria::diferencas([...$antes, 'ativo' => (int) $antes['ativo'] === 1], [...$dados, 'ativo' => $dados['ativo'] === 1], self::ROTULOS));
            return Resultado::sucesso('Insumo "' . $dados['nome'] . '" atualizado.', $id);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return Resultado::falha('Confira os campos destacados.', ['nome' => 'Já existe um insumo com este nome.']);
            }
            throw $exception;
        }
    }

    /** Soma a quantidade que chegou ao estoque atual (entrada rapida). */
    public function repor(int $id, string $quantidadeTexto): Resultado
    {
        try {
            $entrada = Quantidade::paraMilesimos($quantidadeTexto === '' ? '0' : $quantidadeTexto);
        } catch (InvalidArgumentException) {
            return Resultado::falha('Digite a quantidade que chegou, com até 3 casas decimais. Exemplo: 2,5');
        }
        if ($entrada <= 0) {
            return Resultado::falha('Digite uma quantidade maior que zero.');
        }

        $this->db->beginTransaction();
        try {
            $trava = $this->db->prepare('SELECT id, nome, unidade, estoque_atual FROM insumos WHERE id = ? AND id_restaurante = ?' . self::paraAtualizar($this->db));
            $trava->execute([$id, $this->idRestaurante]);
            $insumo = $trava->fetch(PDO::FETCH_ASSOC);
            if ($insumo === false) {
                $this->db->rollBack();
                return Resultado::falha('Insumo não encontrado. Ele pode ter sido excluído.');
            }
            $antes = Quantidade::paraMilesimos((string) $insumo['estoque_atual']);
            $depois = $antes + $entrada;
            if ($depois > 99_999_999_999_999) {
                $this->db->rollBack();
                return Resultado::falha('Quantidade grande demais. Confira o número digitado.');
            }
            $this->insumos->definirEstoque($this->idRestaurante, $id, Quantidade::paraDecimal($depois));
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $unidade = UnidadeMedida::from((string) $insumo['unidade']);
        Auditoria::registrar($this->autor, 'Estoque reposto', sprintf(
            '%s: +%s (de %s para %s).',
            $insumo['nome'],
            $unidade->formatar($entrada),
            $unidade->formatar($antes),
            $unidade->formatar($depois),
        ));
        return Resultado::sucesso(sprintf('Pronto! %s agora tem %s.', $insumo['nome'], $unidade->formatar($depois)), $id);
    }

    public function excluir(int $id): Resultado
    {
        $insumo = $this->insumos->buscar($this->idRestaurante, $id);
        if ($insumo === null) {
            return Resultado::falha('Insumo não encontrado. Ele pode já ter sido excluído.');
        }
        $produtos = $this->insumos->produtosQueUsam($this->idRestaurante, $id);
        if ($produtos !== []) {
            return Resultado::falha(sprintf(
                'Não dá para excluir "%s": ele está na ficha de %s. Tire ele das fichas ou desative o insumo.',
                $insumo['nome'],
                self::lista($produtos),
            ));
        }
        try {
            $this->insumos->excluir($this->idRestaurante, $id);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return Resultado::falha('Não dá para excluir este insumo porque ele ainda está em uso. Desative-o.');
            }
            throw $exception;
        }
        Auditoria::registrar($this->autor, 'Insumo excluído', sprintf('%s (estoque no momento: %s %s).', $insumo['nome'], Numero::decimalCampo((string) $insumo['estoque_atual']), $insumo['unidade']));
        return Resultado::sucesso('Insumo "' . $insumo['nome'] . '" excluído.');
    }

    /**
     * @param array<string,mixed> $e
     * @return array{0:array{nome:string,unidade:string,estoque_atual:string,estoque_minimo:string,custo_unitario:?string,ativo:int}, 1:array<string,string>}
     */
    private function validar(array $e, ?int $id): array
    {
        $erros = [];
        $nome = self::texto($e['nome'] ?? '');
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120) {
            $erros['nome'] = 'Digite um nome entre 2 e 120 letras.';
        } elseif ($this->insumos->nomeEmUso($this->idRestaurante, $nome, $id)) {
            $erros['nome'] = 'Já existe um insumo com este nome.';
        }

        $unidade = UnidadeMedida::tryFrom((string) ($e['unidade'] ?? ''));
        if ($unidade === null) {
            $erros['unidade'] = 'Escolha como você conta este insumo.';
        }

        $quantidades = [];
        foreach (['estoque_atual' => 'quanto tem agora', 'estoque_minimo' => 'o estoque mínimo'] as $campo => $rotulo) {
            $valor = trim((string) ($e[$campo] ?? ''));
            try {
                $milesimos = Quantidade::paraMilesimos($valor === '' ? '0' : $valor);
                if ($milesimos < 0 || $milesimos > 99_999_999_999_999) {
                    throw new InvalidArgumentException();
                }
                if ($unidade === UnidadeMedida::Unidade && $milesimos % 1000 !== 0) {
                    $erros[$campo] = 'Insumo contado por unidade não aceita casas decimais.';
                }
                $quantidades[$campo] = Quantidade::paraDecimal($milesimos);
            } catch (InvalidArgumentException) {
                $erros[$campo] = 'Digite ' . $rotulo . ' com até 3 casas decimais. Exemplo: 2,5';
                $quantidades[$campo] = '0.000';
            }
        }

        $custoTexto = trim((string) ($e['custo_unitario'] ?? ''));
        $custo = $custoTexto === '' ? null : Numero::custoParaDecimal($custoTexto);
        if ($custoTexto !== '' && $custo === null) {
            $erros['custo_unitario'] = 'Digite o custo em reais. Exemplo: 1,50';
        }

        return [[
            'nome' => $nome,
            'unidade' => $unidade?->value ?? '',
            'estoque_atual' => $quantidades['estoque_atual'],
            'estoque_minimo' => $quantidades['estoque_minimo'],
            'custo_unitario' => $custo,
            'ativo' => ($e['ativo'] ?? '') === '1' ? 1 : 0,
        ], $erros];
    }

    public static function texto(mixed $valor): string
    {
        return is_string($valor) ? trim((string) preg_replace('/\s+/u', ' ', $valor)) : '';
    }

    /** @param list<string> $nomes */
    public static function lista(array $nomes): string
    {
        $mostrar = array_slice($nomes, 0, 3);
        $resto = count($nomes) - count($mostrar);
        return implode(', ', $mostrar) . ($resto > 0 ? ' e mais ' . $resto : '');
    }

    /** SELECT ... FOR UPDATE no MySQL; o SQLite (testes locais) trava o banco inteiro na transacao. */
    public static function paraAtualizar(PDO $db): string
    {
        return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
