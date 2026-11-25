<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use Cardapim\Shared\Esquema;
use PDO;
use RuntimeException;

/** Leitura das composições comerciais e configuração fiscal isolada. Não altera pedidos ou estoque. */
final class ComposicaoFiscal
{
    public function __construct(private readonly PDO $db,private readonly int $id) {}

    private function linhas(string $sql): array
    {
        $q=$this->db->prepare($sql);$q->execute([$this->id]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function estruturas(): array
    {
        $out=[];
        $combo=Esquema::tem($this->db,'combos');
        if ($combo) {
            foreach ($this->linhas('SELECT c.id_produto FROM combos c JOIN produtos p ON p.id=c.id_produto AND p.id_restaurante=c.id_restaurante AND p.ativo=1 WHERE c.id_restaurante=? ORDER BY c.id_produto') as $r) {
                $out[(int)$r['id_produto']]=['combo'=>true,'fixos'=>[],'opcoes'=>[]];
            }
            foreach ($this->linhas('SELECT i.id_combo,i.id_produto,i.quantidade FROM combo_itens i JOIN combos c ON c.id_produto=i.id_combo AND c.id_restaurante=i.id_restaurante WHERE i.id_restaurante=? ORDER BY i.id_combo,i.id_produto,i.quantidade') as $r) {
                $id=(int)$r['id_combo'];
                if (isset($out[$id])) { $out[$id]['fixos'][]=['id_produto'=>(int)$r['id_produto'],'quantidade'=>(string)$r['quantidade']]; }
            }
        }
        if (Esquema::tem($this->db,'complementos')) {
            $join=$combo?' LEFT JOIN combo_opcoes co ON co.id_restaurante=o.id_restaurante AND co.id_complemento=o.id':'';
            $campo=$combo?'co.id_produto':'NULL';
            foreach ($this->linhas('SELECT pg.id_produto,o.id,o.nome,o.preco,g.nome AS grupo,'.$campo.' AS ligado FROM produto_complemento_grupos pg JOIN produtos p ON p.id=pg.id_produto AND p.id_restaurante=pg.id_restaurante AND p.ativo=1 JOIN complemento_grupos g ON g.id=pg.id_grupo AND g.id_restaurante=pg.id_restaurante AND g.ativo=1 JOIN complementos o ON o.id_grupo=g.id AND o.id_restaurante=g.id_restaurante AND o.ativo=1'.$join.' WHERE pg.id_restaurante=? ORDER BY pg.id_produto,o.id') as $r) {
                $id=(int)$r['id_produto'];
                $out[$id]??=['combo'=>false,'fixos'=>[],'opcoes'=>[]];
                $out[$id]['opcoes'][(int)$r['id']]=['nome'=>$r['nome'],'grupo'=>$r['grupo'],'preco'=>(string)$r['preco'],'ligado'=>$out[$id]['combo']&&$r['ligado']!==null?(int)$r['ligado']:null];
            }
        }
        return $out;
    }

    public static function assinatura(array $estrutura): string
    { return hash('sha256',json_encode($estrutura,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }

    public function configuracoes(): array
    {
        if (!Esquema::tem($this->db,'fiscal_composicoes')) { return []; }
        $out=[];
        foreach ($this->linhas('SELECT id_produto,assinatura,dados FROM fiscal_composicoes WHERE id_restaurante=?') as $r) {
            $out[(int)$r['id_produto']]=['assinatura'=>$r['assinatura'],'dados'=>json_decode($r['dados'],true,512,JSON_THROW_ON_ERROR)];
        }
        return $out;
    }

    public static function normalizar(int $id,array $estrutura,array $produtos,array $estruturas,array $post): array
    {
        if (($post['confirmado'] ?? '')!=='1' || ($post['assinatura'] ?? '')!==self::assinatura($estrutura)) { throw new RuntimeException('Revise a composição atual e confirme.'); }
        $ref=CadastroProdutoFiscal::campo($post,'referencia',250);
        if ($ref==='') { throw new RuntimeException('Informe a referência da orientação fiscal.'); }
        $opcoes=[];$entradas=is_array($post['opcoes'] ?? null)?$post['opcoes']:[];
        foreach ($estrutura['opcoes'] as $oid=>$o) {
            $p=is_array($entradas[$oid] ?? null)?$entradas[$oid]:[];
            if ($estrutura['combo'] && $o['ligado']!==null) {
                $modo='produto';$destino=$o['ligado'];
            } else {
                $modo=$p['modo'] ?? '';
                $destino=filter_var($p['produto'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            }
            if ($modo==='principal' && !$estrutura['combo']) {
                $opcoes[$oid]=['modo'=>'principal','produto'=>$id];
            } elseif ($modo==='produto' && $destino!==false && $destino!==$id && isset($produtos[$destino]) && empty($estruturas[$destino]['combo'])) {
                $opcoes[$oid]=['modo'=>'produto','produto'=>$destino];
            } else { throw new RuntimeException('Defina a classificação fiscal de todas as opções. Não use um combo como referência.'); }
        }
        return ['referencia'=>$ref,'modo'=>$estrutura['combo']?'componentes':'principal','opcoes'=>$opcoes];
    }

    public function salvar(int $id,array $estrutura,array $d,int $autor): void
    {
        if (!Esquema::tem($this->db,'fiscal_composicoes')) { throw new RuntimeException('Configuração de composição indisponível.'); }
        $this->db->prepare('INSERT INTO fiscal_composicoes(id_restaurante,id_produto,assinatura,dados,autor) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE assinatura=VALUES(assinatura),dados=VALUES(dados),autor=VALUES(autor),atualizado_em=CURRENT_TIMESTAMP')
            ->execute([$this->id,$id,self::assinatura($estrutura),json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$autor]);
    }

    /** Não monta linhas de venda: verifica a classificação de referências sem duplicar o pai do combo. */
    public static function pendencias(int $id,array $estrutura,?array $cfg,array $pendenciasBase,array $estruturas): array
    {
        $r=[];
        if (!$cfg || $cfg['assinatura']!==self::assinatura($estrutura)) { return ['Composição fiscal não revisada ou alterada desde a revisão.']; }
        if ($estrutura['combo'] && $estrutura['fixos']===[] && $estrutura['opcoes']===[]) { $r[]='Combo sem componentes.'; }
        foreach ($estrutura['fixos'] as $f) {
            $p=$f['id_produto'];
            if ($p===$id || !empty($estruturas[$p]['combo']) || !array_key_exists($p,$pendenciasBase) || $pendenciasBase[$p]!==[]) { $r[]='Componente '.$p.' sem classificação revisada ou é outro combo.'; }
        }
        foreach ($estrutura['opcoes'] as $oid=>$o) {
            $regra=$cfg['dados']['opcoes'][$oid] ?? [];
            $p=$regra['produto'] ?? null;
            $modo=$regra['modo'] ?? '';
            if ($modo==='principal' && !$estrutura['combo'] && $p===$id) { continue; }
            if ($modo!=='produto' || $p===$id || ($o['ligado']!==null && $p!==$o['ligado']) || !isset($pendenciasBase[$p]) || !empty($estruturas[$p]['combo']) || $pendenciasBase[$p]!==[]) {
                $r[]='Complemento '.$o['nome'].' sem referência fiscal revisada.';
            }
        }
        return $r;
    }
}
