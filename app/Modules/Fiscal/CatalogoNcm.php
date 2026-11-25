<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use Cardapim\Core\Session;
use Cardapim\Shared\Http\ClienteHttp;
use RuntimeException;

/** Consulta BrasilAPI por ação explícita; cache curto na sessão existente, sem banco ou cron novos. */
final class CatalogoNcm
{
    public const URL='https://brasilapi.com.br/api/ncm/v1';
    private const CHAVE='fiscal_ncm_consultas_v2';
    public function __construct(private readonly ClienteHttp $http=new ClienteHttp(8)) {}

    public static function codigo(string $q): string
    {
        $q=preg_replace('/[.\s]+/','',trim($q));
        return ctype_digit($q)?$q:'';
    }

    private static function data(mixed $v): bool
    {
        return is_string($v) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/',$v,$m)===1 && checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
    }

    public static function fonteValida(string $codigo,mixed $fonte): bool
    {
        if (!is_string($fonte) || !preg_match('/\ABrasilAPI \| ([0-9]{8}) \| (\d{4}-\d{2}-\d{2}) \| (\d{4}-\d{2}-\d{2}) a (\d{4}-\d{2}-\d{2})\z/',$fonte,$m)) { return false; }
        $hoje=date('Y-m-d');
        return $m[1]===$codigo && self::data($m[2]) && self::data($m[3]) && self::data($m[4])
            && $m[2]<=$hoje && $m[2]>=date('Y-m-d',strtotime('-30 days')) && $m[3]<=$hoje && $m[4]>=$hoje;
    }

    public static function normalizar(array $rows,?string $exato=null): array
    {
        $hoje=date('Y-m-d'); $out=[];
        foreach ($rows as $r) {
            if (!is_array($r) || !is_string($r['codigo'] ?? null) || !is_string($r['descricao'] ?? null)) { throw new RuntimeException('Resposta NCM inválida.'); }
            $c=self::codigo($r['codigo']);
            if ($c==='' || strlen($c)>8) { throw new RuntimeException('Código NCM inválido na resposta.'); }
            if ($exato!==null && $c!==$exato) { throw new RuntimeException('Código NCM divergente na resposta.'); }
            if (strlen($c)!==8) { continue; }
            $ini=$r['data_inicio'] ?? null; $fim=$r['data_fim'] ?? null;
            if (!self::data($ini) || !self::data($fim) || $ini>$fim) { throw new RuntimeException('Vigência NCM inválida na resposta.'); }
            if ($ini>$hoje || $fim<$hoje) { continue; }
            $desc=mb_substr(trim(strip_tags($r['descricao'])),0,600);
            if ($desc==='') { throw new RuntimeException('Descrição NCM vazia na resposta.'); }
            $out[$c]=['codigo'=>$c,'descricao'=>$desc,'data_inicio'=>$ini,'data_fim'=>$fim,
                'fonte'=>'BrasilAPI | '.$c.' | '.$hoje.' | '.$ini.' a '.$fim];
            if (count($out)>=35) { break; }
        }
        return array_values($out);
    }

    private static function cache(): array
    {
        if (PHP_SAPI==='cli') { return []; }
        $c=Session::get(self::CHAVE,[]);
        if (!is_array($c)) { return []; }
        return array_filter($c,static fn($v)=>is_array($v) && ($v['expira'] ?? 0)>time());
    }

    /** Ausência de evidência não significa código inexistente. Não faz rede. */
    public static function evidencia(string $codigo): ?array
    {
        if (!preg_match('/\A[0-9]{8}\z/',$codigo)) { return null; }
        $e=self::cache()['c:'.$codigo]['dados'] ?? null;
        if (!is_array($e)) { return null; }
        if ($e===[]) { return ['itens'=>[],'versao'=>'BrasilAPI']; }
        if (!self::fonteValida($codigo,$e['fonte'] ?? null)) { return null; }
        return ['itens'=>[$codigo=>$e['descricao']],'versao'=>$e['fonte']];
    }

    public function consultar(string $q): array
    {
        $q=trim($q);
        if (mb_strlen($q)<2 || mb_strlen($q)>100) { throw new RuntimeException('Pesquisa NCM inválida.'); }
        $codigo=self::codigo($q);
        $exato=strlen($codigo)===8;
        $termo=$codigo!==''?$codigo:$q;
        $key='q:'.hash('sha256',mb_strtolower($termo));
        $cache=self::cache();
        if (isset($cache[$key])) {
            $res=$cache[$key]['dados'];
            if (is_array($res) && !array_filter($res,static fn($i)=>!self::fonteValida($i['codigo'],$i['fonte']))) { return $res; }
        }
        $url=self::URL.($exato?'/'.$codigo:'?search='.rawurlencode($termo));
        $r=$this->http->enviar('GET',$url);
        if ($exato && $r->status===404) { $resultados=[]; }
        else {
            if (!$r->ok()) { throw new RuntimeException('BrasilAPI HTTP '.$r->status); }
            $shape=json_decode($r->corpo,false,512,JSON_THROW_ON_ERROR);
            if ($exato?!($shape instanceof \stdClass):!is_array($shape)) { throw new RuntimeException('Formato de resposta NCM inválido.'); }
            $d=json_decode($r->corpo,true,512,JSON_THROW_ON_ERROR);
            $resultados=self::normalizar($exato?[$d]:$d,$exato?$codigo:null);
        }
        if (PHP_SAPI!=='cli') {
            $expira=time()+($resultados===[]?300:86400);
            $cache[$key]=['expira'=>$expira,'dados'=>$resultados];
            foreach ($resultados as $item) { $cache['c:'.$item['codigo']]=['expira'=>$expira,'dados'=>$item]; }
            if ($exato && $resultados===[]) { $cache['c:'.$codigo]=['expira'=>$expira,'dados'=>[]]; }
            Session::set(self::CHAVE,array_slice($cache,-200,null,true));
        }
        return $resultados;
    }
}
