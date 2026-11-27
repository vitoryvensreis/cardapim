<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Catalog\MeioAMeio;
use Cardapim\Repositories\Estoque\MeioAMeioRepository;
use PDO;

/**
 * Le e confere a secao "Produtos especiais > Meio a Meio" da ficha do produto (antes de gravar qualquer coisa):
 * meio_enviado, meio_ativo, meio_origem (categoria|produtos), meio_categoria, meio_sabores[], meio_minimo, meio_maximo, meio_regra.
 */
final class MeioAMeioCadastro
{
    public function __construct(private readonly PDO $db, private readonly int $idRestaurante)
    {
    }

    /**
     * @param array<string,mixed> $post
     * @return array{enviado:bool, ativo:bool, config:?array{minimo:int,maximo:int,regra:string,id_categoria:?int,sabores:list<int>}, erros:array<string,string>}
     */
    public function ler(array $post, ?int $idProduto): array
    {
        $saida = ['enviado' => ($post['meio_enviado'] ?? '') === '1', 'ativo' => false, 'config' => null, 'erros' => []];
        if (!$saida['enviado'] || !MeioAMeio::ativo($this->db)) {
            return $saida;
        }
        $saida['ativo'] = ($post['meio_ativo'] ?? '') === '1';
        if (!$saida['ativo']) {
            return $saida;
        }
        $erros = [];
        if (($post['unidade_venda'] ?? 'un') !== 'un') {
            $erros['meio_ativo'] = 'Meio a Meio é vendido por unidade. Mude "Como vende" para unidade.';
        }
        if (($post['combo_ativo'] ?? '') === '1') {
            $erros['meio_ativo'] = 'Um produto não pode ser combo e Meio a Meio ao mesmo tempo. Desligue um dos dois.';
        }
        $num = static fn (string $c): ?int => is_string($post[$c] ?? null) && ctype_digit($post[$c]) ? (int) $post[$c] : null;
        $minimo = $num('meio_minimo');
        $maximo = $num('meio_maximo');
        if ($minimo === null || $maximo === null || $minimo < 1 || $maximo < 1 || $minimo > MeioAMeio::MAX_SABORES || $maximo > MeioAMeio::MAX_SABORES) {
            $erros['meio_quantidade'] = 'Escolha de 1 a ' . MeioAMeio::MAX_SABORES . ' sabores no mínimo e no máximo.';
        } elseif ($minimo > $maximo) {
            $erros['meio_quantidade'] = 'O mínimo de sabores não pode passar do máximo.';
        }
        $regra = is_string($post['meio_regra'] ?? null) && isset(MeioAMeio::REGRAS[$post['meio_regra']]) ? $post['meio_regra'] : '';
        if ($regra === '') {
            $erros['meio_regra'] = 'Escolha como o preço é calculado.';
        }
        $repo = new MeioAMeioRepository($this->db);
        $candidatos = $repo->candidatos($this->idRestaurante, $idProduto);
        $permitidos = array_column($candidatos, 'id');
        $origem = ($post['meio_origem'] ?? '') === 'produtos' ? 'produtos' : 'categoria';
        $idCategoria = null;
        $sabores = [];
        if ($origem === 'categoria') {
            $idCategoria = $num('meio_categoria');
            $daCategoria = array_values(array_filter($candidatos, static fn (array $c): bool => $idCategoria !== null && $c['id_categoria'] === $idCategoria));
            if ($idCategoria === null || !$this->categoriaDoRestaurante($idCategoria)) {
                $erros['meio_sabores'] = 'Escolha a categoria dos sabores.';
            } elseif ($maximo !== null && count($daCategoria) < $maximo) {
                $erros['meio_sabores'] = 'A categoria escolhida tem ' . count($daCategoria) . ' sabor(es) que podem entrar. Precisa de pelo menos ' . $maximo . '.';
            }
        } else {
            foreach (is_array($post['meio_sabores'] ?? null) ? $post['meio_sabores'] : [] as $v) {
                if (is_string($v) && ctype_digit($v) && in_array((int) $v, $permitidos, true)) {
                    $sabores[(int) $v] = true;
                }
            }
            $sabores = array_keys($sabores);
            if ($maximo !== null && count($sabores) < $maximo) {
                $erros['meio_sabores'] = 'Marque pelo menos ' . $maximo . ' sabores (produtos vendidos por unidade, que não são combo nem Meio a Meio).';
            }
        }
        if ($idProduto !== null && $repo->ehCombo($this->idRestaurante, $idProduto) && ($post['combo_enviado'] ?? '') !== '1') {
            $erros['meio_ativo'] = 'Este produto é um combo. Desligue o combo para usar o Meio a Meio.';
        }
        $saida['erros'] = $erros;
        if ($erros === []) {
            $saida['config'] = ['minimo' => (int) $minimo, 'maximo' => (int) $maximo, 'regra' => $regra, 'id_categoria' => $idCategoria, 'sabores' => $sabores];
        }
        return $saida;
    }

    private function categoriaDoRestaurante(int $idCategoria): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM categorias WHERE id = ? AND id_restaurante = ?');
        $q->execute([$idCategoria, $this->idRestaurante]);
        return $q->fetchColumn() !== false;
    }
}
