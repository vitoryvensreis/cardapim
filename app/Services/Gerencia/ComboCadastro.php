<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Catalog\Combo;
use Cardapim\Repositories\Estoque\ComboRepository;
use PDO;

/**
 * Le e confere a secao "Combo" da ficha do produto (antes de gravar qualquer coisa):
 * combo_ativo, combo_maximo, combo_fixo_produto[i] + combo_fixo_qtd[i], combo_opcao[id][produto|maximo].
 */
final class ComboCadastro
{
    public function __construct(private readonly PDO $db, private readonly int $idRestaurante)
    {
    }

    /**
     * @param array<string,mixed> $post
     * @return array{ativo:bool, maximo:?int, fixos:list<array{id_produto:int,quantidade:int}>, opcoes:array<int,array{id_produto:?int,maximo:?int}>,
     *         grupos:array<int,array{minimo:int,maximo:int}>, erros:array<string,string>, enviado:bool}
     */
    public function ler(array $post, ?int $idProduto): array
    {
        $saida = ['ativo' => false, 'maximo' => null, 'fixos' => [], 'opcoes' => [], 'grupos' => [], 'erros' => [], 'enviado' => ($post['combo_enviado'] ?? '') === '1'];
        if (!$saida['enviado'] || !Combo::ativo($this->db)) {
            return $saida;
        }
        $permitidos = array_column((new ComboRepository($this->db))->produtosParaCombo($this->idRestaurante, $idProduto), 'id');
        $saida['ativo'] = ($post['combo_ativo'] ?? '') === '1';
        $maximo = trim(is_string($post['combo_maximo'] ?? null) ? $post['combo_maximo'] : '');
        if ($maximo !== '') {
            if (!ctype_digit($maximo) || (int) $maximo < 1 || (int) $maximo > 99) {
                $saida['erros']['combo_maximo'] = 'Digite de 1 a 99, ou deixe vazio para não limitar.';
            } else {
                $saida['maximo'] = (int) $maximo;
            }
        }
        $produtos = is_array($post['combo_fixo_produto'] ?? null) ? $post['combo_fixo_produto'] : [];
        $qtds = is_array($post['combo_fixo_qtd'] ?? null) ? $post['combo_fixo_qtd'] : [];
        $vistos = [];
        foreach (array_slice($produtos, 0, Combo::MAX_FIXOS, true) as $i => $p) {
            $p = is_string($p) ? trim($p) : '';
            if ($p === '') {
                continue;
            }
            $q = is_string($qtds[$i] ?? null) && trim($qtds[$i]) !== '' ? trim($qtds[$i]) : '1';
            if (!ctype_digit($p) || !in_array((int) $p, $permitidos, true)) {
                $saida['erros']['combo_fixos'] = 'Escolha produtos vendidos por unidade (um combo não entra em outro combo).';
                continue;
            }
            if (!ctype_digit($q) || (int) $q < 1 || (int) $q > 30) {
                $saida['erros']['combo_fixos'] = 'A quantidade de cada item vai de 1 a 30.';
                continue;
            }
            if (isset($vistos[(int) $p])) {
                $saida['erros']['combo_fixos'] = 'O mesmo produto apareceu duas vezes nos itens inclusos: use a quantidade.';
                continue;
            }
            $vistos[(int) $p] = true;
            $saida['fixos'][] = ['id_produto' => (int) $p, 'quantidade' => (int) $q];
        }
        foreach (is_array($post['combo_opcao'] ?? null) ? $post['combo_opcao'] : [] as $idComplemento => $o) {
            if ((!is_int($idComplemento) && !ctype_digit((string) $idComplemento)) || !is_array($o)) {
                continue;
            }
            $prod = is_string($o['produto'] ?? null) ? trim($o['produto']) : '';
            $max = is_string($o['maximo'] ?? null) ? trim($o['maximo']) : '';
            $ligado = null;
            if ($prod !== '') {
                if (!ctype_digit($prod) || !in_array((int) $prod, $permitidos, true)) {
                    $saida['erros']['combo_opcoes'] = 'Ligue as opções a produtos vendidos por unidade (um combo não entra em outro combo).';
                    continue;
                }
                $ligado = (int) $prod;
            }
            $limite = null;
            if ($max !== '') {
                if (!ctype_digit($max) || (int) $max < 1 || (int) $max > 30) {
                    $saida['erros']['combo_opcoes'] = 'O máximo de cada opção vai de 1 a 30 (ou vazio para não limitar).';
                    continue;
                }
                $limite = (int) $max;
            }
            $saida['opcoes'][(int) $idComplemento] = ['id_produto' => $ligado, 'maximo' => $limite];
        }
        // Quantos o cliente escolhe de cada grupo neste combo (so dos grupos marcados). Minimo vazio = 0 (opcional).
        $marcados = array_map('intval', array_filter(is_array($post['grupos'] ?? null) ? $post['grupos'] : [], static fn ($v): bool => is_string($v) && ctype_digit($v)));
        foreach (is_array($post['combo_grupo'] ?? null) ? $post['combo_grupo'] : [] as $idGrupo => $l) {
            if (!ctype_digit((string) $idGrupo) || !in_array((int) $idGrupo, $marcados, true) || !is_array($l)) {
                continue;
            }
            $min = is_string($l['minimo'] ?? null) && trim($l['minimo']) !== '' ? trim($l['minimo']) : '0';
            $max = is_string($l['maximo'] ?? null) ? trim($l['maximo']) : '';
            // "Escolha neste combo": opcional = minimo 0; obrigatoria = pelo menos 1 (ou o "pelo menos" informado).
            $obrigatorio = $l['obrigatorio'] ?? null;
            if ($obrigatorio === '0') {
                $min = '0';
            } elseif ($obrigatorio === '1' && ctype_digit($min) && (int) $min < 1) {
                $min = '1';
            }
            if (!ctype_digit($min) || !ctype_digit($max) || (int) $max < 1 || (int) $max > 30 || (int) $min > (int) $max) {
                $saida['erros']['combo_grupos'] = 'Em cada grupo do combo: o máximo vai de 1 a 30 e, se a escolha for obrigatória, o "pelo menos" não pode passar do máximo.';
                continue;
            }
            $saida['grupos'][(int) $idGrupo] = ['minimo' => (int) $min, 'maximo' => (int) $max];
        }
        if ($saida['ativo']) {
            if (($post['unidade_venda'] ?? 'un') !== 'un') {
                $saida['erros']['combo_ativo'] = 'Combo é vendido por unidade. Mude "Como vende" para unidade.';
            }
            $grupos = is_array($post['grupos'] ?? null) ? array_filter($post['grupos']) : [];
            if ($saida['fixos'] === [] && $grupos === []) {
                $saida['erros']['combo_fixos'] = 'Inclua pelo menos um item no combo ou marque um grupo de escolhas em Complementos.';
            }
            $inclusos = array_sum(array_column($saida['fixos'], 'quantidade'));
            if ($saida['maximo'] !== null && $saida['maximo'] < $inclusos) {
                $saida['erros']['combo_maximo'] = 'O máximo total (' . $saida['maximo'] . ') é menor que os itens já inclusos (' . $inclusos . ').';
            }
        }
        return $saida;
    }
}
