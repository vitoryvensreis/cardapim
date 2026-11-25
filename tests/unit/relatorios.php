<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/app.php';
use Cardapim\Services\Gerencia\FiltrosRelatorios as F;
use Cardapim\Services\Gerencia\RelatoriosService as S;
function relOk(bool $cond):void { if(!$cond) throw new RuntimeException('Falha de relatório'); }
$hoje=new DateTimeImmutable('2026-11-25');
$f=F::daRequisicao([],$hoje);
relOk($f->periodo->de==='2026-11-25'&&$f->f['situacao']==='todos'&&$f->pagina===1);
$f=F::daRequisicao(['periodo'=>'personalizado','de'=>'2020-01-01','ate'=>'2026-11-25','hora_de'=>'25:00','hora_ate'=>'23:59','produto'=>['1'],'visao'=>'sql','pagina'=>'9999'],$hoje);
relOk($f->aviso!==null&&$f->f['hora_de']===''&&$f->f['hora_ate']==='23:59'&&$f->f['produto']===''&&$f->f['visao']==='produto'&&$f->pagina===2000);
$f=F::daRequisicao(['data_por'=>'fechamento','situacao'=>'cancelado','busca'=>'Cerveja'],$hoje);
relOk($f->f['situacao']==='vendido'&&str_contains($f->query(['pagina'=>2]),'pagina=2'));
relOk(F::csvSeguro('=1+1')==="'=1+1"&&F::csvSeguro('  @x')==="'  @x"&&F::csvSeguro('Cerveja')==='Cerveja');
relOk(S::quantidade('0.846','kg')==='0,846 kg'&&S::quantidade('42.000','un')==='42 un');
echo "Relatórios: filtros, limites, unidades e CSV conferidos.\n";
