<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use PDO;

/**
 * Monta a foto imutável de uma venda para a NFC-e: empresa, itens com a classificação fiscal vigente,
 * rateios e pagamentos. Só lê dados; não altera pedido, estoque ou cadastro.
 * Itens sem dados fiscais suficientes geram pendências (a nota fica "bloqueada", nada é enviado).
 */
final class FotoFiscal
{
    /** Formas do Deliveryzim => código tPag da SEFAZ. */
    public const TPAG = ['dinheiro'=>'01', 'credito'=>'03', 'debito'=>'04', 'pix'=>'20', 'pix_entrega'=>'20', 'pix_online'=>'17', 'ifood'=>'99'];
    public const TPAG_ROTULO = ['01'=>'Dinheiro', '03'=>'Cartão de crédito', '04'=>'Cartão de débito', '17'=>'Pix', '20'=>'Pix', '99'=>'Outros'];

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante) {}

    /**
     * @param array<string,mixed> $empresa dados fiscais da empresa (restaurante_fiscal)
     * @return array{foto:array<string,mixed>,pendencias:list<string>}
     */
    public function montar(int $idPedido, string $cnpj, array $empresa, int $ambiente, ?string $documento = null): array
    {
        $pend = [];
        $p = $this->linha('SELECT id, numero, origem, tipo, status, subtotal, taxa_entrega, taxa_servico, desconto, total, forma_pagamento, troco_para, criado_em FROM pedidos WHERE id = ? AND id_restaurante = ?', [$idPedido, $this->idRestaurante]);
        if ($p === null) { return ['foto'=>[], 'pendencias'=>['Pedido não encontrado.']]; }
        if ($p['status'] === 'cancelado') { $pend[] = 'O pedido foi cancelado.'; }
        $crt = (string) ($empresa['crt'] ?? '');
        foreach (['razao'=>'razão social', 'logradouro'=>'logradouro', 'numero'=>'número do endereço', 'bairro'=>'bairro', 'cidade'=>'município', 'estado'=>'UF', 'cep'=>'CEP'] as $k => $rot) {
            if (trim((string) ($empresa[$k] ?? '')) === '') { $pend[] = 'Dados fiscais da empresa: falta ' . $rot . '.'; }
        }
        if (!in_array($crt, ['1','2','3','4'], true)) { $pend[] = 'Dados fiscais da empresa: falta o regime tributário.'; }
        if (!preg_match('/\A\d{7}\z/', (string) ($empresa['ibge'] ?? ''))) { $pend[] = 'Dados fiscais da empresa: município sem código IBGE. Salve os dados da empresa de novo.'; }
        $ie = preg_match('/\A\d{2,20}\z/', (string) ($empresa['ie'] ?? '')) ? (string) $empresa['ie'] : '';
        if ($ie === '' && $ambiente !== 3) { $pend[] = 'Dados fiscais da empresa: falta a inscrição estadual (CGF).'; }

        $itens = $this->linhas('SELECT id, id_produto, nome, preco_unitario, quantidade, unidade, total FROM pedido_itens WHERE id_pedido = ? AND id_restaurante = ? AND cancelado_em IS NULL ORDER BY id', [$idPedido, $this->idRestaurante]);
        if ($itens === []) { $pend[] = 'O pedido não tem itens.'; }
        $fiscais = $this->fiscaisDosProdutos(array_values(array_unique(array_filter(array_map(static fn ($i) => $i['id_produto'] === null ? null : (int) $i['id_produto'], $itens)))));
        $composicoes = (new ComposicaoFiscal($this->db, $this->idRestaurante))->configuracoes();
        $complementos = $this->complementos(array_map(static fn ($i) => (int) $i['id'], $itens));

        $linhas = [];
        foreach ($itens as $i) {
            $nome = mb_substr(trim((string) $i['nome']), 0, 120);
            if ($i['id_produto'] === null) { $pend[] = '"' . $nome . '": item avulso, sem cadastro de produto. Cadastre o produto para emitir a nota.'; continue; }
            $idp = (int) $i['id_produto'];
            $qtd = RateioFiscal::milesimos((string) $i['quantidade']);
            $total = RateioFiscal::centavos((string) $i['total']);
            $separadas = [];
            $regra = $composicoes[$idp]['dados']['opcoes'] ?? [];
            if (($i['unidade'] ?? 'un') === 'un') {
                foreach ($complementos[(int) $i['id']] ?? [] as $c) {
                    $r = $c['id_complemento'] !== null ? ($regra[(int) $c['id_complemento']] ?? null) : null;
                    if (is_array($r) && ($r['modo'] ?? '') === 'produto' && (int) ($r['produto'] ?? 0) > 0) {
                        $v = RateioFiscal::centavos((string) $c['total']) * intdiv($qtd, 1000);
                        if ($v > 0 && $v < $total) {
                            $separadas[] = ['produto'=>(int) $r['produto'], 'nome'=>mb_substr($nome . ' - ' . trim((string) $c['nome']), 0, 120), 'qtd'=>(int) $c['quantidade'] * $qtd, 'vprod'=>$v];
                            $total -= $v;
                        }
                    }
                }
            }
            $linhas[] = ['produto'=>$idp, 'nome'=>$nome, 'qtd'=>$qtd, 'vprod'=>$total, 'unidade'=>(string) ($i['unidade'] ?? 'un')];
            foreach ($separadas as $sep) { $linhas[] = $sep + ['unidade'=>'un']; }
        }
        $idsRef = array_values(array_diff(array_unique(array_column($linhas, 'produto')), array_keys($fiscais)));
        if ($idsRef !== []) { $fiscais += $this->fiscaisDosProdutos($idsRef); }

        $saida = [];
        foreach ($linhas as $n => $l) {
            $f = $fiscais[$l['produto']] ?? null;
            $erro = self::conferir($l['nome'], $f, $crt, (string) ($empresa['estado'] ?? ''), $ambiente, $empresa);
            if ($erro !== null) { $pend[] = $erro; continue; }
            $d = $f['dados']; $perfil = $f['perfil'];
            $ucom = $d['unidade_comercial'] !== '' ? $d['unidade_comercial'] : strtoupper($l['unidade']);
            $saida[] = [
                'codigo'=>(string) $l['produto'], 'nome'=>$l['nome'], 'ncm'=>$d['ncm'], 'cest'=>(string) ($d['cest'] ?? ''),
                'cfop'=>$perfil['cfop'], 'icms'=>$perfil['situacao_icms'], 'origem'=>$d['origem'],
                'gtin'=>($d['gtin'] ?? '') !== '' ? $d['gtin'] : 'SEM GTIN', 'gtin_trib'=>($d['gtin_tributavel'] ?? '') !== '' ? $d['gtin_tributavel'] : (($d['gtin'] ?? '') !== '' ? $d['gtin'] : 'SEM GTIN'),
                'ucom'=>$ucom, 'utrib'=>($d['unidade_tributavel'] ?? '') !== '' ? $d['unidade_tributavel'] : $ucom, 'fator'=>($d['fator'] ?? '') !== '' ? (string) $d['fator'] : '1',
                'qtd'=>$l['qtd'], 'vprod'=>$l['vprod'],
                'aliq_icms'=>(string) ($perfil['aliq_icms'] ?? ''), 'red_icms'=>(string) ($perfil['aliq_reducao_icms'] ?? ''),
                'cst_pis'=>(string) ($perfil['cst_pis'] ?? ''), 'aliq_pis'=>(string) ($perfil['aliq_pis'] ?? ''),
                'cst_cofins'=>(string) ($perfil['cst_cofins'] ?? ''), 'aliq_cofins'=>(string) ($perfil['aliq_cofins'] ?? ''),
                'cbenef'=>(string) ($perfil['cbenef'] ?? ''),
            ];
        }
        // Desconto e taxas (entrega e serviço) rateados ao centavo entre as linhas.
        $pesos = array_map(static fn ($l) => $l['vprod'], $saida);
        $desc = RateioFiscal::repartir(RateioFiscal::centavos((string) $p['desconto']), $pesos);
        $outro = RateioFiscal::repartir(RateioFiscal::centavos((string) $p['taxa_entrega']) + RateioFiscal::centavos((string) ($p['taxa_servico'] ?? '0')), $pesos);
        foreach ($saida as $k => &$l) { $l['vdesc'] = $desc[$k]; $l['voutro'] = $outro[$k]; }
        unset($l);

        $totalCent = RateioFiscal::centavos((string) $p['total']);
        if ($pend === [] && array_sum($pesos) - array_sum($desc) + array_sum($outro) !== $totalCent) {
            $pend[] = 'A soma dos itens não fecha com o total do pedido. Confira itens e descontos.';
        }
        $pagamentos = $this->pagamentos($p, $totalCent);
        if ($pagamentos === []) { $pend[] = 'Pagamento não identificado para este pedido.'; }
        elseif (array_sum(array_column($pagamentos, 'vpag')) - array_sum(array_column($pagamentos, 'troco')) !== $totalCent) {
            $pend[] = 'Os pagamentos registrados não fecham com o total do pedido.';
        }
        $doc = $documento !== null ? preg_replace('/\D/', '', $documento) : '';
        $foto = [
            'versao'=>1, 'ambiente'=>$ambiente, 'cnpj'=>$cnpj,
            'empresa'=>array_intersect_key($empresa, array_flip(['razao','nome','logradouro','numero','complemento','bairro','cidade','estado','cep','ibge','crt','email'])) + ['ie'=>$ie],
            'pedido'=>['id'=>(int) $p['id'], 'numero'=>(int) $p['numero'], 'origem'=>(string) $p['origem'], 'tipo'=>(string) $p['tipo'], 'total'=>$totalCent],
            'itens'=>$saida, 'pagamentos'=>$pagamentos,
            'destinatario'=>in_array(strlen((string) $doc), [11, 14], true) ? (string) $doc : '',
        ];
        return ['foto'=>$foto, 'pendencias'=>array_values(array_unique($pend))];
    }

    /** @param array{dados:array,perfil:array,revisado:bool,versao_ok:bool,pendencias:list<string>}|null $f */
    private static function conferir(string $nome, ?array $f, string $crt, string $uf, int $ambiente, array $empresa): ?string
    {
        if ($f === null) { return '"' . $nome . '": produto sem cadastro fiscal. Preencha em Fiscal > Produtos fiscais.'; }
        $d = $f['dados']; $perfil = $f['perfil'];
        if (!preg_match('/\A\d{8}\z/', (string) ($d['ncm'] ?? ''))) { return '"' . $nome . '": falta o NCM.'; }
        if (!preg_match('/\A[0-8]\z/', (string) ($d['origem'] ?? ''))) { return '"' . $nome . '": falta a origem da mercadoria.'; }
        if ($perfil === [] ) { return '"' . $nome . '": falta o perfil tributário.'; }
        if (!isset(CadastroProdutoFiscal::cfops($crt)[$perfil['cfop'] ?? ''])) { return '"' . $nome . '": o CFOP do perfil não vale para o regime atual.'; }
        if (!isset(CadastroProdutoFiscal::situacoes($crt)[$perfil['situacao_icms'] ?? ''])) { return '"' . $nome . '": a situação tributária (CST/CSOSN) do perfil não vale para o regime atual.'; }
        if (($perfil['uf'] ?? $uf) !== $uf) { return '"' . $nome . '": o perfil tributário é de outra UF.'; }
        // Produção exige o cadastro completo e revisado; testes aceitam o mínimo para a nota sair.
        if ($ambiente === 1 && $f['pendencias'] !== []) { return '"' . $nome . '": ' . lcfirst($f['pendencias'][0]) . '.'; }
        return null;
    }

    /** @param list<int> $ids @return array<int,array{dados:array,perfil:array,pendencias:list<string>}> */
    private function fiscaisDosProdutos(array $ids): array
    {
        if ($ids === []) { return []; }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->linhas("SELECT f.id_produto, f.id_perfil, f.versao, f.dados, v.dados AS perfil, v.revisado, p.versao AS versao_atual, p.nome AS perfil_nome
            FROM fiscal_produtos f
            LEFT JOIN fiscal_perfil_versoes v ON v.id_restaurante = f.id_restaurante AND v.id_perfil = f.id_perfil AND v.versao = f.versao
            LEFT JOIN fiscal_perfis p ON p.id_restaurante = f.id_restaurante AND p.id = f.id_perfil
            WHERE f.id_restaurante = ? AND f.id_produto IN ($in)", array_merge([$this->idRestaurante], $ids));
        $out = [];
        foreach ($rows as $r) {
            $dados = json_decode((string) $r['dados'], true) ?: [];
            $perfil = $r['perfil'] !== null ? (json_decode((string) $r['perfil'], true) ?: []) : [];
            $pend = CadastroProdutoFiscal::pendencias(['dados'=>$dados, 'versao'=>$r['versao']],
                $r['perfil'] !== null ? ['dados'=>$perfil, 'revisado'=>(int) $r['revisado'], 'versao'=>$r['versao_atual']] : null,
                ['crt'=>$perfil['crt'] ?? '', 'estado'=>$perfil['uf'] ?? '']);
            $out[(int) $r['id_produto']] = ['dados'=>$dados + ['ncm'=>'','origem'=>'','unidade_comercial'=>''], 'perfil'=>$perfil, 'pendencias'=>$pend];
        }
        return $out;
    }

    /** @param list<int> $idsItens @return array<int,list<array<string,mixed>>> */
    private function complementos(array $idsItens): array
    {
        if ($idsItens === []) { return []; }
        $in = implode(',', array_fill(0, count($idsItens), '?'));
        $out = [];
        try {
            foreach ($this->linhas("SELECT id_pedido_item, id_complemento, nome, quantidade, total FROM pedido_item_complementos WHERE id_restaurante = ? AND id_pedido_item IN ($in) ORDER BY id", array_merge([$this->idRestaurante], $idsItens)) as $c) {
                $out[(int) $c['id_pedido_item']][] = $c;
            }
        } catch (\PDOException) { return []; }
        return $out;
    }

    /** @return list<array{tpag:string,vpag:int,troco:int}> */
    private function pagamentos(array $p, int $total): array
    {
        $out = [];
        foreach ($this->linhas('SELECT forma, valor, troco FROM pedido_pagamentos WHERE id_pedido = ? AND id_restaurante = ? ORDER BY id', [(int) $p['id'], $this->idRestaurante]) as $r) {
            $troco = RateioFiscal::centavos((string) $r['troco']);
            $out[] = ['tpag'=>self::TPAG[(string) $r['forma']] ?? '99', 'vpag'=>RateioFiscal::centavos((string) $r['valor']) + $troco, 'troco'=>$troco];
        }
        if ($out !== []) { return $out; }
        $forma = (string) ($p['forma_pagamento'] ?? '');
        if (!isset(self::TPAG[$forma]) && !($p['origem'] === 'ifood')) { return []; }
        $tpag = $p['origem'] === 'ifood' && !isset(self::TPAG[$forma]) ? '99' : self::TPAG[$forma];
        $troco = $forma === 'dinheiro' && $p['troco_para'] !== null ? max(0, RateioFiscal::centavos((string) $p['troco_para']) - $total) : 0;
        return [['tpag'=>$tpag, 'vpag'=>$total + $troco, 'troco'=>$troco]];
    }

    private function linha(string $sql, array $args): ?array
    {
        $q = $this->db->prepare($sql); $q->execute($args);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function linhas(string $sql, array $args): array
    {
        $q = $this->db->prepare($sql); $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
