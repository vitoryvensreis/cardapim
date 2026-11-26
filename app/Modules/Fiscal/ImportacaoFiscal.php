<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/** Apenas candidatos cadastrais. Não autentica a NF-e, nem importa valores/tributos da entrada. */
final class ImportacaoFiscal
{
    public const LIMITE=200;
    public const BYTES=2097152;
    public const CAMPOS=['ncm','cest','origem','gtin','gtin_tributavel','unidade_comercial','unidade_tributavel','fator'];

    public static function csvLinhas(string $bytes,int $max=self::LIMITE): array
    {
        if ($bytes!=='' && strlen($bytes)<=self::BYTES && !str_contains($bytes,"\0") && !mb_check_encoding($bytes,'UTF-8')) {
            // Excel no Windows salva CSV em Windows-1252: converte em vez de recusar.
            $bytes=mb_convert_encoding($bytes,'UTF-8','Windows-1252');
        }
        if ($bytes==='' || strlen($bytes)>self::BYTES || !mb_check_encoding($bytes,'UTF-8') || str_contains($bytes,"\0")) {
            throw new RuntimeException('Arquivo vazio, inválido ou acima de 2 MB.');
        }
        $f=fopen('php://temp','w+');
        if (!$f) { throw new RuntimeException('Não foi possível ler o arquivo.'); }
        try {
            fwrite($f,preg_replace('/^\xEF\xBB\xBF/','',$bytes)); rewind($f);
            $header=fgetcsv($f,0,';','"','');
            if (!is_array($header) || count($header)>20) { throw new RuntimeException('Cabeçalho CSV inválido. Use ponto e vírgula.'); }
            $header=array_map(static fn($x)=>trim((string)$x),$header);
            if (count(array_unique($header))!==count($header) || in_array('',$header,true)) { throw new RuntimeException('Colunas repetidas ou vazias.'); }
            $rows=[];
            while (($l=fgetcsv($f,0,';','"',''))!==false) {
                if ($l===[null]) { continue; }
                if (count($l)!==count($header) || count($rows)>=$max) { throw new RuntimeException('CSV inválido ou limite de linhas excedido.'); }
                $rows[]=array_combine($header,array_map(static fn($x)=>trim((string)$x),$l));
            }
            if ($rows===[]) { throw new RuntimeException('Nenhuma linha encontrada.'); }
            return $rows;
        } finally { fclose($f); }
    }

    public static function csv(string $bytes): array
    {
        $rows=self::csvLinhas($bytes);
        if (!isset($rows[0]['nome']) || !array_key_exists('ncm',$rows[0])) { throw new RuntimeException('CSV precisa das colunas nome e ncm. Consulte o modelo.'); }
        $out=[];
        foreach ($rows as $r) { $out[]=self::candidato($r); }
        return $out;
    }

    public static function xml(string $bytes,string $cnpj): array
    {
        if ($bytes==='' || strlen($bytes)>self::BYTES || !mb_check_encoding($bytes,'UTF-8') || str_contains($bytes,"\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i',$bytes)) {
            throw new RuntimeException('XML inválido, acima de 2 MB ou com entidades proibidas.');
        }
        $doc=new DOMDocument();
        $anterior=libxml_use_internal_errors(true);
        try { $ok=$doc->loadXML($bytes,LIBXML_NONET|LIBXML_COMPACT); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($anterior); }
        if (!$ok || $doc->doctype!==null || $doc->documentElement?->localName!=='nfeProc') {
            throw new RuntimeException('Use o XML processado de uma NF-e ou NFC-e (nfeProc).');
        }
        $x=new DOMXPath($doc); $x->registerNamespace('n','http://www.portalfiscal.inf.br/nfe');
        $base='/n:nfeProc/n:NFe/n:infNFe';
        $ler=static fn(string $p,?\DOMNode $node=null):string=>trim((string)$x->evaluate('string('.$p.')',$node));
        $status=$ler('/n:nfeProc/n:protNFe/n:infProt/n:cStat');
        $chave=$ler('/n:nfeProc/n:protNFe/n:infProt/n:chNFe');
        $id=$ler($base.'/@Id');
        $modelo=$ler($base.'/n:ide/n:mod');
        $ambiente=$ler($base.'/n:ide/n:tpAmb');
        if (!in_array($status,['100','150'],true) || !preg_match('/\A[0-9]{44}\z/',$chave) || $id!=='NFe'.$chave || !in_array($modelo,['55','65'],true) || $ambiente!=='1') {
            throw new RuntimeException('O XML precisa indicar autorização de produção e chave correspondente. Não há verificação de autenticidade nesta importação.');
        }
        if (!preg_match('/\A[0-9]{14}\z/',$cnpj) || !in_array($cnpj,[$ler($base.'/n:emit/n:CNPJ'),$ler($base.'/n:dest/n:CNPJ')],true)) {
            throw new RuntimeException('O CNPJ do restaurante não consta como emitente ou destinatário do XML.');
        }
        $itens=$x->query($base.'/n:det');
        if (!$itens || $itens->length<1 || $itens->length>self::LIMITE) { throw new RuntimeException('XML deve conter de 1 a 200 itens.'); }
        $out=[];
        foreach ($itens as $item) {
            $r=['nome'=>$ler('n:prod/n:xProd',$item),'codigo'=>$ler('n:prod/n:cProd',$item)];
            foreach (['ncm'=>'NCM','cest'=>'CEST','gtin'=>'cEAN','gtin_tributavel'=>'cEANTrib','unidade_comercial'=>'uCom','unidade_tributavel'=>'uTrib'] as $k=>$tag) {
                $r[$k]=$ler('n:prod/n:'.$tag,$item);
            }
            $r['origem']=$ler('n:imposto/n:ICMS/*/n:orig',$item);
            // Não inferir conversão nem copiar CFOP, CST, alíquotas ou valores da compra.
            $out[]=self::candidato($r);
        }
        return $out;
    }

    private static function candidato(array $r): array
    {
        $nome=CadastroProdutoFiscal::campo($r,'nome',160);
        $codigo=CadastroProdutoFiscal::campo($r,'codigo',80);
        if ($nome==='') { throw new RuntimeException('Cada item precisa de descrição.'); }
        $campos=array_intersect_key($r,array_flip(self::CAMPOS));
        try { $d=CadastroProdutoFiscal::produto($campos); }
        catch (\InvalidArgumentException $e) { throw new RuntimeException('Item "'.mb_substr($nome,0,60).'" — '.$e->getMessage()); }
        $d=array_filter(array_intersect_key($d,array_flip(self::CAMPOS)),static fn($v)=>$v!=='');
        if ($d===[]) { throw new RuntimeException('Item sem classificação fiscal aproveitável.'); }
        return ['nome'=>$nome,'codigo'=>$codigo,'dados'=>$d];
    }

    /** Sugestão só quando um GTIN fiscal já confirmado identifica um único cadastro. */
    public static function sugerir(array $item,array $produtos): ?int
    {
        $ean=$item['dados']['gtin'] ?? '';
        if (!CadastroProdutoFiscal::gtin($ean)) { return null; }
        $ids=[];
        foreach ($produtos as $p) {
            if (!empty($p['dados']['confirmado']) && ($p['dados']['gtin'] ?? '')===$ean) { $ids[]=(int)$p['id']; }
        }
        return count($ids)===1?$ids[0]:null;
    }

    public static function mesclar(array $atual,array $dados,bool $substituir,?array $catalogo): array
    {
        $v=CadastroProdutoFiscal::produto($dados);
        $mudancas=[];
        foreach (self::CAMPOS as $k) {
            if (!array_key_exists($k,$dados) || $v[$k]==='') { continue; }
            $antes=(string)($atual[$k] ?? '');
            if ($antes===$v[$k] || (!$substituir && $antes!=='')) { continue; }
            if ($k==='ncm' && $catalogo!==null && !isset($catalogo['itens'][$v[$k]])) { throw new RuntimeException('NCM importado não localizado como vigente na BrasilAPI. Confira antes de importar.'); }
            $mudancas[$k]=['de'=>$antes,'para'=>$v[$k]];
            $atual[$k]=$v[$k];
            if ($k==='ncm') { $atual['ncm_fonte']=isset($catalogo['itens'][$v[$k]])?$catalogo['versao']:''; }
        }
        if ($mudancas!==[]) { $atual['confirmado']=false; }
        return ['dados'=>$atual,'mudancas'=>$mudancas];
    }
}
