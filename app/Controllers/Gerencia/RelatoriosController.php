<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Gerencia\FiltrosRelatorios;
use Cardapim\Services\Gerencia\RelatoriosService;

final class RelatoriosController extends BaseGerencia
{
    public function index(string $slug): Response
    {
        if ($r=$this->entrar($slug)) { return $r; }
        $f=FiltrosRelatorios::daRequisicao($_GET);
        $s=new RelatoriosService($this->db,$this->restaurante['id']);
        if (($_GET['exportar']??'')==='1') {
            $csv=$s->csv($f);
            if ($csv===null) {
                return $this->voltar($this->url('financeiro/relatorios').'?'.$f->query(['pagina'=>1]),
                    'erro','A planilha ultrapassa 10 mil linhas. Reduza o período ou aplique mais filtros.');
            }
            return Guard::semCache(new Response($csv,200,[
                'Content-Type'=>'text/csv; charset=UTF-8',
                'Content-Disposition'=>'attachment; filename="relatorio-vendas-'.$f->periodo->de.'-a-'.$f->periodo->ate.'.csv"',
                'X-Content-Type-Options'=>'nosniff',
            ]));
        }
        return $this->tela('gerencia/financeiro/relatorios','financeiro','Relatórios de vendas',
            $s->tela($f)+['filtros'=>$f,'periodo'=>$f->periodo,'abaFinanceiro'=>'relatorios',
                'pageStyles'=>['css/financeiro.css','css/relatorios.css'],'pageScripts'=>['js/relatorios.js']]);
    }
}
