<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Catalog\PlanilhaCardapio;
use Cardapim\Repositories\Estoque\CategoriaRepository;
use Cardapim\Repositories\Estoque\ComplementoRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Esquema;
use PDO;
use PDOException;
use Throwable;

/**
 * Importacao do cardapio por planilha (Gerencia > Integracoes > Importar cardapio).
 * 1. conferir(): compara o plano da planilha com o que o restaurante ja tem (nada e gravado).
 * 2. importar(): confere de novo e grava tudo numa transacao so (ou tudo, ou nada).
 * Produto com nome ja cadastrado e ignorado (nunca altera nem apaga o que existe).
 * Categorias sao reaproveitadas pelo nome; grupos de complementos iguais (nome, limites, opcoes e precos) tambem.
 * Produtos novos entram a venda, sem controle de estoque, por unidade, na ordem da planilha.
 */
final class ImportarCardapioService
{
    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor, private readonly int $idRestaurante)
    {
    }

    /**
     * @param array{produtos: list<array<string,mixed>>, erros: list<array{linha:int, mensagem:string}>} $plano PlanilhaCardapio::interpretar
     * @return array{
     *   novos: list<array<string,mixed>>, existentes: list<array{linha:int, nome:string}>, erros: list<array{linha:int, mensagem:string}>,
     *   categorias_novas: list<string>, grupos_novos: int, grupos_reaproveitados: int, opcoes: int
     * }
     */
    public function conferir(array $plano): array
    {
        $erros = $plano['erros'];
        $nomesBanco = $this->valores('SELECT nome FROM produtos WHERE id_restaurante = ?');
        $codigosBanco = $this->valores('SELECT codigo FROM produtos WHERE id_restaurante = ? AND codigo IS NOT NULL');
        $categorias = [];
        foreach ((new CategoriaRepository($this->db))->listar($this->idRestaurante) as $cat) {
            $categorias[PlanilhaCardapio::chave($cat['nome'])] = true;
        }
        $complementosProntos = Esquema::tem($this->db, 'complementos');
        $gruposBanco = $complementosProntos ? $this->assinaturasDoBanco() : [];

        $novos = [];
        $existentes = [];
        $categoriasNovas = [];
        $gruposNovos = [];
        $gruposReaproveitados = [];
        $opcoes = 0;
        foreach ($plano['produtos'] as $p) {
            if (isset($nomesBanco[PlanilhaCardapio::chave((string) $p['nome'])])) {
                $existentes[] = ['linha' => (int) $p['linha'], 'nome' => (string) $p['nome']];
                continue;
            }
            if ($p['codigo'] !== null && isset($codigosBanco[PlanilhaCardapio::chave((string) $p['codigo'])])) {
                $erros[] = ['linha' => (int) $p['linha'], 'mensagem' => 'O código ' . $p['codigo'] . ' já é usado por outro produto do restaurante. Troque o código ou deixe vazio.'];
            }
            if ($p['grupos'] !== [] && !$complementosProntos) {
                $erros[] = ['linha' => (int) $p['linha'], 'mensagem' => 'Os complementos ainda estão sendo liberados neste servidor. Tire as colunas de complemento por enquanto.'];
            }
            foreach ($p['grupos'] as $g) {
                if ($g['opcoes'] === []) {
                    continue; // as linhas com erro ja foram avisadas
                }
                $assinatura = PlanilhaCardapio::assinaturaGrupo($g);
                if (isset($gruposBanco[$assinatura])) {
                    $gruposReaproveitados[$assinatura] = true;
                } elseif (!isset($gruposNovos[$assinatura])) {
                    $gruposNovos[$assinatura] = true;
                    $opcoes += count($g['opcoes']); // opcoes que serao criadas (grupo repetido conta uma vez)
                }
            }
            $cat = $p['categoria'];
            if ($cat !== null && !isset($categorias[PlanilhaCardapio::chave((string) $cat)])) {
                $categorias[PlanilhaCardapio::chave((string) $cat)] = true;
                $categoriasNovas[] = (string) $cat;
            }
            $novos[] = $p;
        }
        if ($novos === [] && $erros === [] && $existentes !== []) {
            $erros[] = ['linha' => 0, 'mensagem' => 'Todos os produtos desta planilha já estão cadastrados. Não há nada novo para importar.'];
        }
        usort($erros, static fn (array $a, array $b): int => $a['linha'] <=> $b['linha']);
        return [
            'novos' => $novos, 'existentes' => $existentes, 'erros' => $erros, 'categorias_novas' => $categoriasNovas,
            'grupos_novos' => count($gruposNovos), 'grupos_reaproveitados' => count($gruposReaproveitados), 'opcoes' => $opcoes,
        ];
    }

    /**
     * Grava os produtos novos do plano. Confere de novo antes (alguem pode ter cadastrado algo enquanto a tela estava aberta).
     * @param array{produtos: list<array<string,mixed>>, erros: list<array{linha:int, mensagem:string}>} $plano
     */
    public function importar(array $plano, string $arquivo): Resultado
    {
        $this->db->beginTransaction();
        try {
            $conferencia = $this->conferir($plano);
            if ($conferencia['erros'] !== []) {
                $this->db->rollBack();
                return Resultado::falha('A planilha tem linhas para corrigir. Confira a lista e envie de novo.');
            }
            $categorias = [];
            foreach ((new CategoriaRepository($this->db))->listar($this->idRestaurante) as $cat) {
                $categorias[PlanilhaCardapio::chave($cat['nome'])] = $cat['id'];
            }
            $catRepo = new CategoriaRepository($this->db);
            $produtos = new ProdutoRepository($this->db);
            $complementos = new ComplementoRepository($this->db);
            $gruposPorAssinatura = Esquema::tem($this->db, 'complementos') ? $this->assinaturasDoBanco() : [];
            $ordem = $this->proximaOrdemProduto();
            $ordemGrupo = null;
            $criadosGrupos = 0;

            foreach ($conferencia['novos'] as $p) {
                $idCategoria = null;
                if ($p['categoria'] !== null) {
                    $chave = PlanilhaCardapio::chave((string) $p['categoria']);
                    $idCategoria = $categorias[$chave] ??= $catRepo->criar($this->idRestaurante, (string) $p['categoria']);
                }
                $idProduto = $produtos->criar($this->idRestaurante, [
                    'nome' => (string) $p['nome'], 'descricao' => $p['descricao'], 'preco' => (string) $p['preco'], 'unidade_venda' => 'un',
                    'venda_minima' => null, 'codigo' => $p['codigo'], 'id_categoria' => $idCategoria, 'controla_estoque' => 0, 'disponivel' => 1,
                ]);
                $this->db->prepare('UPDATE produtos SET ordem = ? WHERE id = ? AND id_restaurante = ?')->execute([min(65000, $ordem++), $idProduto, $this->idRestaurante]);

                $idsGrupos = [];
                foreach ($p['grupos'] as $g) {
                    if ($g['opcoes'] === []) {
                        continue;
                    }
                    $assinatura = PlanilhaCardapio::assinaturaGrupo($g);
                    if (!isset($gruposPorAssinatura[$assinatura])) {
                        $ordemGrupo ??= $complementos->proximaOrdemGrupo($this->idRestaurante);
                        $idGrupo = $complementos->criarGrupo($this->idRestaurante, (string) $g['nome'], (int) $g['minimo'], (int) $g['maximo'], min(65000, $ordemGrupo++));
                        foreach (array_values($g['opcoes']) as $i => $o) {
                            $complementos->criarOpcao($this->idRestaurante, $idGrupo, [
                                'nome' => (string) $o['nome'], 'descricao' => null, 'preco' => (string) $o['preco'], 'codigo' => null,
                                'imagem_path' => null, 'disponivel' => 1, 'ordem' => $i,
                            ]);
                        }
                        $gruposPorAssinatura[$assinatura] = $idGrupo;
                        $criadosGrupos++;
                    }
                    $idsGrupos[] = $gruposPorAssinatura[$assinatura];
                }
                if ($idsGrupos !== []) {
                    $complementos->salvarGruposDoProduto($this->idRestaurante, $idProduto, $idsGrupos);
                }
            }
            $this->db->commit();
        } catch (PDOException $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($erro->getCode() === '23000') {
                return Resultado::falha('Algum produto ou código da planilha foi cadastrado agora há pouco por outra pessoa. Envie a planilha de novo para conferir.');
            }
            throw $erro;
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }

        $total = count($conferencia['novos']);
        $resumo = sprintf(
            '%s: %d produto(s) novo(s), %d categoria(s) nova(s), %d grupo(s) de complementos novo(s)%s.',
            $arquivo !== '' ? $arquivo : 'planilha',
            $total,
            count($conferencia['categorias_novas']),
            $criadosGrupos,
            $conferencia['existentes'] !== [] ? ', ' . count($conferencia['existentes']) . ' já cadastrado(s) e ignorado(s)' : '',
        );
        $nomes = implode(', ', array_map(static fn (array $p): string => (string) $p['nome'], $conferencia['novos']));
        Auditoria::registrar($this->autor, 'Cardápio importado por planilha', mb_substr($resumo . ' Produtos: ' . $nomes, 0, 900));
        return Resultado::sucesso(
            ($total === 1 ? '1 produto importado.' : $total . ' produtos importados.')
            . ($conferencia['existentes'] !== [] ? ' ' . count($conferencia['existentes']) . ' já estavam cadastrados e não mudaram.' : '')
            . ' Confira os preços e coloque as fotos quando puder.',
            $total,
        );
    }

    /** @return array<string,true> valores (chave sem acento) de uma coluna do restaurante */
    private function valores(string $sql): array
    {
        $q = $this->db->prepare($sql);
        $q->execute([$this->idRestaurante]);
        $saida = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $saida[PlanilhaCardapio::chave((string) $v)] = true;
        }
        return $saida;
    }

    /** @return array<string,int> assinatura => id dos grupos ativos do restaurante */
    private function assinaturasDoBanco(): array
    {
        $saida = [];
        foreach ((new ComplementoRepository($this->db))->grupos($this->idRestaurante) as $g) {
            $assinatura = PlanilhaCardapio::assinaturaGrupo([
                'nome' => $g['nome'], 'minimo' => $g['minimo'], 'maximo' => $g['maximo'],
                'opcoes' => array_map(static fn (array $o): array => ['nome' => (string) $o['nome'], 'preco' => (string) $o['preco']], $g['opcoes']),
            ]);
            $saida[$assinatura] ??= $g['id'];
        }
        return $saida;
    }

    private function proximaOrdemProduto(): int
    {
        $q = $this->db->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM produtos WHERE id_restaurante = ?');
        $q->execute([$this->idRestaurante]);
        return (int) $q->fetchColumn();
    }
}
