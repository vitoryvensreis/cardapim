<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Shared\Http\ClienteHttp;
use RuntimeException;

/** Cache do catálogo oficial vigente; nenhum dado tributário é inventado. */
final class CatalogoNcm
{
    public const URL='https://portalunico.siscomex.gov.br/classif/api/publico/nomenclatura/download/json';
    private const TTL=86400;
    public function __construct(private readonly ClienteHttp $http=new ClienteHttp(12)) {}

    public static function normalizar(array $json): array
    {
        $lista=$json['Nomenclaturas'] ?? [];
        $versao=$json['Data_Ultima_Atualizacao_NCM'] ?? '';
        if (!is_array($lista) || !is_string($versao) || $versao==='') { throw new RuntimeException('Catálogo oficial inválido.'); }
        $nomes=[];
        foreach ($lista as $r) {
            if (!is_array($r) || !is_string($r['Codigo'] ?? null) || !is_string($r['Descricao'] ?? null)) { continue; }
            $c=str_replace('.','',$r['Codigo']);
            if (!ctype_digit($c) || strlen($c)>8) { continue; }
            $nomes[$c]=mb_substr(trim(strip_tags($r['Descricao'])),0,600);
        }
        $itens=[];
        foreach ($nomes as $c=>$nome) {
            $c=(string)$c;
            if (strlen($c)!==8) { continue; }
            $pais=[];
            foreach ([2,4,5,6,7] as $n) { $k=substr($c,0,$n); if (isset($nomes[$k])) { $pais[]=$nomes[$k]; } }
            $pais[]=$nome;
            $itens[$c]=implode(' / ',$pais);
        }
        if ($itens===[]) { throw new RuntimeException('Catálogo sem classificações.'); }
        return ['versao'=>mb_substr($versao,0,120),'coleta'=>time(),'itens'=>$itens];
    }

    private function arquivo(): string { return dirname(__DIR__,3).'/storage/cache/fiscal/ncm-vigente.json'; }

    public function ler(bool $buscar=false): ?array
    {
        $arq=$this->arquivo();
        $d=is_file($arq)?json_decode((string)@file_get_contents($arq),true):null;
        if (is_array($d) && isset($d['itens'],$d['versao'],$d['coleta']) && $d['coleta']>time()-self::TTL) { return $d; }
        if (!$buscar) { return null; }
        $dir=dirname($arq);
        if (!is_dir($dir)) { @mkdir($dir,0750,true); }
        $lock=@fopen($arq.'.lock','c+');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) { fclose($lock); } return null; }
        try {
            $d=is_file($arq)?json_decode((string)@file_get_contents($arq),true):null;
            if (is_array($d) && isset($d['itens'],$d['versao'],$d['coleta']) && $d['coleta']>time()-self::TTL) { return $d; }
            $r=$this->http->enviar('GET',self::URL);
            if (!$r->ok()) { return null; }
            $d=self::normalizar($r->json());
            // Não aceitar uma tabela de produção truncada.
            if (count($d['itens'])<5000) { return null; }
            $tmp=tempnam($dir,'ncm-');
            if ($tmp!==false) {
                try {
                    if (file_put_contents($tmp,json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))!==false) { chmod($tmp,0640); rename($tmp,$arq); }
                } finally { if (is_file($tmp)) { @unlink($tmp); } }
            }
            return $d;
        } catch (\Throwable) { return null; }
        finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public static function pesquisar(array $d,string $q): array
    {
        $q=TextoLocal::chave(trim($q));
        if (mb_strlen($q)<2) { return []; }
        $codigo=str_replace('.','',$q);
        $r=[];
        foreach ($d['itens'] ?? [] as $c=>$nome) {
            if ((ctype_digit($codigo) && str_starts_with((string)$c,$codigo)) || (!ctype_digit($codigo) && str_contains(TextoLocal::chave($nome),$q))) {
                $r[]=['codigo'=>(string)$c,'descricao'=>$nome];
                if (count($r)>=35) { break; }
            }
        }
        return $r;
    }
}
