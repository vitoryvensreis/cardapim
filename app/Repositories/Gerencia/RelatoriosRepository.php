<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use Cardapim\Services\Gerencia\FiltrosRelatorios;
use PDO;

/** Relatório gerencial somente leitura. Todas as relações respeitam id_restaurante. */
final class RelatoriosRepository
{
    public function __construct(private readonly PDO $db, private readonly int $idRestaurante) {}

    private function base(FiltrosRelatorios $f): array
    {
        $v=$f->f;
        $data=$v['data_por']==='fechamento' ? 'p.concluido_em' : 'i.criado_em';
        $situacao="CASE WHEN i.cancelado_em IS NOT NULL OR p.status='cancelado' THEN 'cancelado'
                    WHEN p.status='concluido' THEN 'vendido' ELSE 'aberto' END";
        $sql=" FROM pedido_itens i JOIN pedidos p ON p.id=i.id_pedido AND p.id_restaurante=i.id_restaurante
            LEFT JOIN produtos pr ON pr.id=i.id_produto AND pr.id_restaurante=i.id_restaurante
            LEFT JOIN mesas m ON m.id=p.id_mesa AND m.id_restaurante=p.id_restaurante
            LEFT JOIN " . chr(96) . "user" . chr(96) . " u ON u.id=i.id_user AND u.id_restaurante=i.id_restaurante
            WHERE i.id_restaurante=? AND $data>=? AND $data<?";
        $params=[$this->idRestaurante,$f->periodo->inicio(),$f->periodo->fimExclusivo()];
        if ($v['situacao']!=='todos') {
            $sql .= match($v['situacao']) {
                'vendido'=>" AND p.status='concluido' AND i.cancelado_em IS NULL",
                'cancelado'=>" AND (p.status='cancelado' OR i.cancelado_em IS NOT NULL)",
                default=>" AND p.status NOT IN ('concluido','cancelado') AND i.cancelado_em IS NULL",
            };
        }
        $like=static fn(string $s):string=>'%'.str_replace(['!','%','_'],['!!','!%','!_'],$s).'%';
        // A seleção por ID é exata, inclusive quando o nome histórico mudou.
        if ($v['busca']!=='' && $v['produto']==='') {
            $sql.=" AND (i.nome LIKE ? ESCAPE '!' OR pr.nome LIKE ? ESCAPE '!')";
            $params[]=$like($v['busca']); $params[]=$like($v['busca']);
        }
        if ($v['nome_historico']!=='') { $sql.=' AND i.nome=?'; $params[]=$v['nome_historico']; }
        if ($v['unidade']!=='') { $sql.=' AND i.unidade=?'; $params[]=$v['unidade']; }
        foreach (['produto'=>'i.id_produto','categoria'=>'pr.id_categoria','pedido'=>'p.numero',
                  'mesa'=>'p.id_mesa','origem'=>'p.origem','funcionario'=>'i.id_user'] as $k=>$col) {
            if ($v[$k]==='') { continue; }
            if ($k==='pedido' && \Cardapim\Shared\Esquema::tem($this->db,'numero_dia')) {
                // Busca pelo numero do dia (o do cupom) ou pelo numero corrido.
                $sql.=' AND (p.numero=? OR p.numero_dia=?)'; $params[]=$v[$k]; $params[]=$v[$k];
                continue;
            }
            $sql.=" AND $col=?"; $params[]=$v[$k];
        }
        if ($v['comanda']!=='') {
            $sql.=" AND p.tipo IN ('mesa','balcao') AND (p.comanda_nome LIKE ? ESCAPE '!' OR p.cliente_nome LIKE ? ESCAPE '!'";
            $params[]=$like($v['comanda']);$params[]=$like($v['comanda']);
            if (preg_match('/\A[0-9]{1,18}\z/',$v['comanda'])===1) {
                $sql.=' OR p.numero=?';$params[]=$v['comanda'];
            }
            $sql.=')';
        }
        $ini=$v['hora_de'];$fim=$v['hora_ate'];
        if ($ini!=='' && $fim!=='') {
            $sql.=" AND (TIME($data)>=? ".($ini>$fim?'OR':'AND')." TIME($data)<=?)";
            $params[]=$ini.':00';$params[]=$fim.':59';
        } elseif ($ini!=='') {
            $sql.=" AND TIME($data)>=?";$params[]=$ini.':00';
        } elseif ($fim!=='') {
            $sql.=" AND TIME($data)<=?";$params[]=$fim.':59';
        }
        return [$sql,$params,$data,$situacao];
    }

    private function consultar(string $sql,array $params): array
    {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resumo(FiltrosRelatorios $f): array
    {
        [$base,$params,,$situacao]=$this->base($f);
        return $this->consultar("SELECT $situacao AS situacao, i.unidade,
            SUM(i.quantidade) AS quantidade, SUM(i.total) AS total
            $base GROUP BY situacao,i.unidade ORDER BY situacao,i.unidade",$params);
    }

    public function linhas(FiltrosRelatorios $f,bool $exportar=false): array
    {
        [$base,$params,$data,$situacao]=$this->base($f);
        $agregado="i.unidade, $situacao AS situacao, SUM(i.quantidade) AS quantidade,
                     SUM(i.total) AS total, COUNT(DISTINCT p.id) AS pedidos";
        // Numero do dia (o mesmo do cupom e das telas); pedido antigo mostra o numero corrido.
        $numeroPedido=\Cardapim\Services\Pedidos\NumeroDoDia::sql($this->db,'p');
        $sql=match($f->f['visao']) {
            'hora'=>"SELECT DATE_FORMAT($data,'%H:00') AS rotulo, $agregado $base
                      GROUP BY rotulo,i.unidade,situacao ORDER BY rotulo,situacao,i.unidade",
            'dia'=>"SELECT DATE($data) AS rotulo, $agregado $base
                     GROUP BY rotulo,i.unidade,situacao ORDER BY rotulo DESC,situacao,i.unidade",
            'pedido'=>"SELECT i.nome AS rotulo,i.id_produto AS produto_id,i.unidade,$situacao AS situacao,
                     i.quantidade,i.total,1 AS pedidos,$numeroPedido AS numero,p.tipo,p.origem,p.comanda_nome,
                     CASE WHEN p.tipo='balcao' THEN p.cliente_nome ELSE NULL END AS balcao_nome,
                     m.numero AS mesa,u.nome AS funcionario,$data AS momento,i.combo_nome
                     $base ORDER BY $data DESC,i.id DESC",
            default=>"SELECT i.nome AS rotulo,i.id_produto AS produto_id,$agregado $base
                       GROUP BY i.id_produto,i.nome,i.unidade,situacao
                       ORDER BY quantidade DESC,rotulo,i.id_produto,i.unidade,situacao",
        };
        $limite=$exportar?FiltrosRelatorios::MAX_EXPORTAR:FiltrosRelatorios::POR_PAGINA;
        $offset=$exportar?0:($f->pagina-1)*$limite;
        $linhas=$this->consultar($sql.' LIMIT '.($limite+1).' OFFSET '.$offset,$params);
        return ['linhas'=>array_slice($linhas,0,$limite),'mais'=>count($linhas)>$limite];
    }

    /** Catálogo do próprio restaurante, inclusive produtos inativos com histórico de vendas. */
    public function sugestoes(string $busca): array
    {
        $busca=mb_substr(trim($busca),0,120);
        if (mb_strlen($busca)<2) { return []; }
        $termo=str_replace(['!','%','_'],['!!','!%','!_'],$busca);
        return $this->consultar("SELECT pr.id,pr.nome,pr.ativo,c.nome AS categoria
            FROM produtos pr LEFT JOIN categorias c ON c.id=pr.id_categoria AND c.id_restaurante=pr.id_restaurante
            WHERE pr.id_restaurante=? AND pr.nome LIKE ? ESCAPE '!'
            ORDER BY (pr.nome LIKE ? ESCAPE '!') DESC,pr.ativo DESC,pr.nome,pr.id LIMIT 20",
            [$this->idRestaurante,'%'.$termo.'%',$termo.'%']);
    }

    public function opcoes(string $produto=''): array
    {
        $r=[$this->idRestaurante];
        $produtos=$this->consultar('SELECT id,nome FROM produtos WHERE id_restaurante=? ORDER BY nome,id LIMIT 500',$r);
        if ($produto!=='' && !in_array($produto,array_map('strval',array_column($produtos,'id')),true)) {
            $produtos=array_merge($produtos,$this->consultar('SELECT id,nome FROM produtos WHERE id_restaurante=? AND id=?',[$this->idRestaurante,$produto]));
        }
        return [
            'produto'=>$produtos,
            'categoria'=>$this->consultar('SELECT id,nome FROM categorias WHERE id_restaurante=? ORDER BY nome,id LIMIT 500',$r),
            'mesa'=>$this->consultar("SELECT id,CONCAT(numero,IF(COALESCE(apelido,'')='', '', CONCAT(' - ',apelido))) AS nome FROM mesas WHERE id_restaurante=? ORDER BY numero,id LIMIT 500",$r),
            'funcionario'=>$this->consultar('SELECT id,nome FROM '.chr(96).'user'.chr(96).' WHERE id_restaurante=? ORDER BY nome,id LIMIT 500',$r),
            'origem'=>$this->consultar('SELECT DISTINCT origem AS id,origem AS nome FROM pedidos WHERE id_restaurante=? ORDER BY origem',$r),
        ];
    }
}
