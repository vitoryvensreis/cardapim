<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Repositories\Estoque\CategoriaRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use PDO;
use PDOException;
use Throwable;

/** Categorias do cardapio: criar, renomear e excluir (produtos ficam sem categoria). */
final class CategoriaService
{
    private readonly CategoriaRepository $categorias;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor, private readonly int $idRestaurante)
    {
        $this->categorias = new CategoriaRepository($db);
    }

    public function salvar(?int $id, string $nomeDigitado): Resultado
    {
        $nome = InsumoService::texto($nomeDigitado);
        $antes = null;
        if ($id !== null) {
            $antes = $this->categorias->buscar($this->idRestaurante, $id);
            if ($antes === null) {
                return Resultado::falha('Categoria não encontrada. Ela pode ter sido excluída.');
            }
        }
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) {
            return Resultado::falha('Digite um nome de categoria entre 2 e 80 letras.', ['nome' => 'Digite um nome entre 2 e 80 letras.']);
        }
        if ($this->categorias->nomeEmUso($this->idRestaurante, $nome, $id)) {
            return Resultado::falha('Já existe uma categoria chamada "' . $nome . '".', ['nome' => 'Já existe uma categoria com este nome.']);
        }
        try {
            if ($antes === null) {
                $novoId = $this->categorias->criar($this->idRestaurante, $nome);
                Auditoria::registrar($this->autor, 'Categoria cadastrada', $nome . '.');
                return Resultado::sucesso('Categoria "' . $nome . '" criada.', $novoId);
            }
            if ($antes['nome'] === $nome) {
                return Resultado::sucesso('Nada mudou na categoria "' . $nome . '".', $id);
            }
            $this->categorias->renomear($this->idRestaurante, (int) $id, $nome);
            Auditoria::registrar($this->autor, 'Categoria editada', sprintf('Nome: %s -> %s.', $antes['nome'], $nome));
            return Resultado::sucesso('Categoria renomeada para "' . $nome . '".', $id);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return Resultado::falha('Já existe uma categoria chamada "' . $nome . '".', ['nome' => 'Já existe uma categoria com este nome.']);
            }
            throw $exception;
        }
    }

    public function excluir(int $id): Resultado
    {
        $categoria = $this->categorias->buscar($this->idRestaurante, $id);
        if ($categoria === null) {
            return Resultado::falha('Categoria não encontrada. Ela pode já ter sido excluída.');
        }
        $this->db->beginTransaction();
        try {
            $afetados = $this->categorias->excluir($this->idRestaurante, $id);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        Auditoria::registrar($this->autor, 'Categoria excluída', sprintf('%s (%d produto(s) ficaram sem categoria).', $categoria['nome'], $afetados));
        return Resultado::sucesso($afetados > 0
            ? sprintf('Categoria "%s" excluída. %d produto(s) ficaram sem categoria.', $categoria['nome'], $afetados)
            : 'Categoria "' . $categoria['nome'] . '" excluída.');
    }
}
