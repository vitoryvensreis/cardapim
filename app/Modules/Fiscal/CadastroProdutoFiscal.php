<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use InvalidArgumentException;

/** Validação estrutural, não substitui a classificação contábil nem a futura validação do XML. */
final class CadastroProdutoFiscal
{
    public const MODELOS = ['Alimentação preparada','Bebidas não alcoólicas','Bebidas alcoólicas','Sobremesas','Produtos industrializados','Alimentação por peso'];
    public const ALIQUOTAS = ['icms','st','fcp','reducao_icms','pis','cofins','ibs_uf','ibs_mun','cbs','is'];

    public static function campo(array $p,string $c,int $max=120): string
    {
        $v = is_string($p[$c] ?? null) ? trim($p[$c]) : '';
        if (mb_strlen($v) > $max || preg_match('/[\x00-\x1f\x7f]/',$v)) { throw new InvalidArgumentException('Confira o campo ' . $c . '.'); }
        return $v;
    }

    public static function gtin(string $v): bool
    {
        if (!preg_match('/\A(?:[0-9]{8}|[0-9]{12,14})\z/',$v) || preg_match('/\A0+\z/',$v)) { return false; }
        $s=0; $peso=3;
        for ($i=strlen($v)-2;$i>=0;$i--) { $s+=(int)$v[$i]*$peso; $peso=4-$peso; }
        return (10-$s%10)%10 === (int)substr($v,-1);
    }

    /** Escopo NFC-e de venda interna; não constitui autorização de emissão. */
    public static function cfops(string $crt): array
    {
        $v=['5101'=>'Venda de produção do estabelecimento','5102'=>'Venda de mercadoria adquirida de terceiros','5103'=>'Venda de produção fora do estabelecimento','5104'=>'Venda de mercadoria de terceiros fora do estabelecimento','5115'=>'Venda de mercadoria recebida em consignação','5405'=>'Venda de mercadoria com ICMS ST (substituído)'];
        return $crt==='4'?['5102'=>$v['5102']]:$v;
    }

    public static function situacoes(string $crt): array
    {
        if ($crt==='4') { return ['102'=>'Tributada pelo Simples Nacional sem permissão de crédito','300'=>'Imune']; }
        if ($crt==='1') { return ['102'=>'Sem permissão de crédito','103'=>'Isenção por faixa de receita','300'=>'Imune','400'=>'Não tributada pelo Simples Nacional','500'=>'ICMS cobrado anteriormente por ST','900'=>'Outros']; }
        if (in_array($crt,['2','3'],true)) { return ['00'=>'Tributada integralmente','20'=>'Com redução da base de cálculo','40'=>'Isenta','41'=>'Não tributada','60'=>'ICMS cobrado anteriormente por ST','90'=>'Outras']; }
        return [];
    }

    public static function perfil(array $p,array $empresa): array
    {
        $crt = (string)($empresa['crt'] ?? '');
        $uf = (string)($empresa['estado'] ?? '');
        if (!in_array($crt,['1','2','3','4'],true) || !MunicipioIbge::ufValida($uf)) { throw new InvalidArgumentException('Salve o regime tributário e UF da empresa primeiro.'); }
        $nome=self::campo($p,'nome');
        $op=self::campo($p,'operacao',20);
        $cfop=self::campo($p,'cfop',4);
        $icms=self::campo($p,'situacao_icms',3);
        if ($nome==='' || !in_array($op,['producao','revenda'],true)) { throw new InvalidArgumentException('Informe o nome e o tipo de operação.'); }
        if ($cfop!=='' && !isset(self::cfops($crt)[$cfop])) { throw new InvalidArgumentException('Informe um CFOP de saída interna para NFC-e.'); }
        if ($icms!=='' && !isset(self::situacoes($crt)[$icms])) { throw new InvalidArgumentException('Situação ICMS incompatível com o regime cadastrado.'); }
        $d=['nome'=>$nome,'crt'=>$crt,'uf'=>$uf,'modelo'=>65,'operacao'=>$op,'cfop'=>$cfop,'situacao_icms'=>$icms];
        foreach (['cst_pis'=>2,'cst_cofins'=>2,'cst_ibs_cbs'=>3,'cclass_trib'=>6,'cst_is'=>3,'cclass_is'=>6,'cbenef'=>10] as $k=>$max) {
            $v=self::campo($p,$k,$max);
            if ($v!=='' && ($k==='cbenef' ? !preg_match('/\A[A-Z0-9]{2,10}\z/',$v) : !preg_match('/\A[0-9]{'.$max.'}\z/',$v))) { throw new InvalidArgumentException('Confira ' . $k . '.'); }
            $d[$k]=$v;
        }
        foreach (self::ALIQUOTAS as $k) {
            $v=str_replace(',','.',self::campo($p,'aliq_'.$k,10));
            if ($v!=='' && (!preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,4})?\z/',$v) || (float)$v>100)) { throw new InvalidArgumentException('Confira a alíquota de ' . $k . '.'); }
            $d['aliq_'.$k]=$v; // decimal textual; nunca calcular impostos com float
        }
        $d['inicio']=self::campo($p,'inicio',10);
        if ($d['inicio']!=='' && (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/',$d['inicio'],$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))) { throw new InvalidArgumentException('Confira a data de vigência.'); }
        $d['referencia']=self::campo($p,'referencia',250);
        $revisado=($p['revisado'] ?? '')==='1';
        if ($revisado && ($cfop==='' || $icms==='' || $d['inicio']==='' || $d['referencia']==='')) { throw new InvalidArgumentException('Para registrar revisão, informe CFOP, situação ICMS, vigência e referência da orientação fiscal.'); }
        return ['dados'=>$d,'revisado'=>$revisado];
    }

    public static function produto(array $p): array
    {
        $d=[];
        foreach (['ncm'=>8,'cest'=>7,'origem'=>1,'gtin'=>14,'gtin_tributavel'=>14,'unidade_comercial'=>6,'unidade_tributavel'=>6,'fator'=>18] as $k=>$max) { $d[$k]=self::campo($p,$k,$max); }
        foreach (['ncm'=>8,'cest'=>7] as $k=>$n) {
            if ($d[$k]!=='' && !preg_match('/\A[0-9]{'.$n.'}\z/',$d[$k])) { throw new InvalidArgumentException('Confira os dígitos de ' . strtoupper($k) . '.'); }
        }
        if ($d['origem']!=='' && !preg_match('/\A[0-8]\z/',$d['origem'])) { throw new InvalidArgumentException('Confira a origem da mercadoria.'); }
        foreach (['gtin','gtin_tributavel'] as $k) {
            if ($d[$k]!=='' && $d[$k]!=='SEM GTIN' && !self::gtin($d[$k])) { throw new InvalidArgumentException('Código de barras inválido. Não use o código interno como GTIN.'); }
        }
        foreach (['unidade_comercial','unidade_tributavel'] as $k) {
            $d[$k]=strtoupper($d[$k]);
            if ($d[$k]!=='' && !preg_match('/\A[A-Z0-9]{1,6}\z/',$d[$k])) { throw new InvalidArgumentException('Confira a unidade fiscal.'); }
        }
        $d['fator']=str_replace(',','.',$d['fator']);
        if ($d['fator']!=='' && (!preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,8})?\z/',$d['fator']) || !preg_match('/[1-9]/',$d['fator']))) { throw new InvalidArgumentException('Informe um fator de conversão positivo.'); }
        $d['confirmado']=($p['confirmado'] ?? '')==='1';
        return $d;
    }

    public static function perfilVigente(array $p,array $empresa): bool
    {
        $d=$p['dados'] ?? [];
        return !empty($p['revisado']) && isset(self::cfops((string)($d['crt'] ?? ''))[$d['cfop'] ?? ''],self::situacoes((string)($d['crt'] ?? ''))[$d['situacao_icms'] ?? '']) && ($d['crt'] ?? '')===(string)($empresa['crt'] ?? '') && ($d['uf'] ?? '')===($empresa['estado'] ?? '') && !empty($d['inicio']) && $d['inicio']<=date('Y-m-d');
    }

    public static function pendencias(array $v,?array $perfil,array $empresa): array
    {
        $d=$v['dados'] ?? [];
        $r=[];
        if (!$perfil || !self::perfilVigente($perfil,$empresa)) { $r[]='Perfil não revisado ou incompatível com regime/UF/vigência'; }
        elseif ((int)($v['versao'] ?? 0)!==(int)$perfil['versao']) { $r[]='Nova versão do perfil: reaplicar após revisão'; }
        foreach (['ncm'=>'NCM','origem'=>'Origem','gtin'=>'GTIN ou SEM GTIN','gtin_tributavel'=>'GTIN tributável ou SEM GTIN','unidade_comercial'=>'Unidade comercial','unidade_tributavel'=>'Unidade tributável','fator'=>'Fator de conversão'] as $k=>$rotulo) {
            if (($d[$k] ?? '')==='') { $r[]=$rotulo; }
        }
        if (!CatalogoNcm::fonteValida((string)($d['ncm'] ?? ''),$d['ncm_fonte'] ?? null)) { $r[]='NCM sem consulta vigente registrada na BrasilAPI'; }
        if (empty($d['confirmado'])) { $r[]='Classificação individual não confirmada'; }
        return $r;
    }
}
