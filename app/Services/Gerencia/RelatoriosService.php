<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Repositories\Gerencia\RelatoriosRepository;
use Cardapim\Shared\Numero;
use PDO;

final class RelatoriosService
{
    private readonly RelatoriosRepository $repo;
    public function __construct(PDO $db,int $restaurante)
    {
        $this->repo=new RelatoriosRepository($db,$restaurante);
    }

    public function tela(FiltrosRelatorios $f): array
    {
        return $this->repo->linhas($f)+['resumos'=>$this->repo->resumo($f),'opcoes'=>$this->repo->opcoes()];
    }

    public static function quantidade(string $valor,string $unidade): string
    {
        return Numero::decimalCampo($valor).' '.match($unidade){'kg'=>'kg','l'=>'L',default=>'un'};
    }

    public static function situacao(string $s): string
    {
        return FiltrosRelatorios::SITUACOES[$s]??$s;
    }

    public static function comanda(array $r): string
    {
        if (($r['tipo']??'')==='mesa') {
            return trim('Mesa '.($r['mesa']??'—').' / '.($r['comanda_nome']?:'Comanda #'.$r['numero']));
        }
        return ($r['tipo']??'')==='balcao'?'Balcão / '.($r['balcao_nome']?:'Comanda #'.$r['numero']):'—';
    }

    public function csv(FiltrosRelatorios $f): ?string
    {
        $r=$this->repo->linhas($f,true);
        if ($r['mais']) { return null; }
        $out=fopen('php://temp','w+');
        fwrite($out,"\xEF\xBB\xBF");
        $detalhe=$f->f['visao']==='pedido';
        $cab=['Produto / período','Situação','Quantidade','Unidade','Valor dos itens (R$)','Pedidos'];
        if ($detalhe) { array_push($cab,'Número','Comanda','Origem','Horário','Funcionário','Combo'); }
        fputcsv($out,$cab,';','"','');
        foreach ($r['linhas'] as $l) {
            $row=[FiltrosRelatorios::csvSeguro((string)$l['rotulo']),self::situacao($l['situacao']),
                Numero::decimalCampo((string)$l['quantidade']),$l['unidade'],Numero::dinheiroCampo((string)$l['total']),$l['pedidos']];
            if ($detalhe) {
                foreach ([$l['numero'],self::comanda($l),$l['origem'],$l['momento'],$l['funcionario'],$l['combo_nome']] as $campo) {
                    $row[]=FiltrosRelatorios::csvSeguro((string)$campo);
                }
            }
            fputcsv($out,$row,';','"','');
        }
        rewind($out);$csv=stream_get_contents($out);fclose($out);
        return $csv;
    }
}
