<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

/** Verificação de consistência cadastral. Não calcula tributos nem substitui XSD/regras SEFAZ. */
final class VerificacaoFiscal
{
    public static function perfil(array $dados,array $catalogos): array
    {
        $r=[];
        $tipo=in_array((string)($dados['crt'] ?? ''),['1','4'],true)?'csosn':'cst_icms';
        $pares=['cfop'=>'cfop',$tipo=>'situacao_icms','cst_pis'=>'cst_pis','cst_cofins'=>'cst_cofins','cst_ibs_cbs'=>'cst_ibs_cbs','cclass_trib'=>'cclass_trib','cst_is'=>'cst_is','cclass_is'=>'cclass_is'];
        foreach ($pares as $cat=>$campo) {
            $codigo=(string)($dados[$campo] ?? '');
            if ($codigo==='') {
                if (in_array($campo,['cfop','situacao_icms'],true)) { $r[]='Informe '.$campo.'.'; }
                continue;
            }
            $cst=match($cat) {'cclass_trib'=>(string)($dados['cst_ibs_cbs'] ?? ''),'cclass_is'=>(string)($dados['cst_is'] ?? ''),default=>''};
            if (in_array($cat,['cclass_trib','cclass_is'],true) && $cst==='') { $r[]='Informe o CST correspondente a '.$campo.'.'; }
            if (empty($catalogos[$cat])) { $r[]='Tabela '.$cat.' não disponibilizada.'; }
            elseif (!CatalogosFiscais::contem($catalogos[$cat],$codigo,'',$cst)) { $r[]='Código '.$campo.' não localizado na versão vigente ou incompatível com o filtro.'; }
        }
        foreach ([['cst_ibs_cbs','cclass_trib'],['cst_is','cclass_is']] as [$a,$b]) {
            if ((($dados[$a] ?? '')==='')!== (($dados[$b] ?? '')==='')) { $r[]='Informe o par '.$a.' e '.$b.' ou revise sua aplicabilidade.'; }
        }
        // MOC NFC-e: I08-150, N12-30, N12a-20, N12-40/44, N12a-40/44.
        // Escopo inicial: venda de mercadorias de restaurante, sem combustível, ISSQN ou remessas.
        $cfop=(string)($dados['cfop'] ?? '');
        $icms=(string)($dados['situacao_icms'] ?? '');
        $uf=(string)($dados['uf'] ?? '');
        $crt=(string)($dados['crt'] ?? '');
        $venda=['5101','5102','5103','5104','5115'];
        if ($cfop!=='' && !in_array($cfop,[...$venda,'5405'],true)) { $r[]='CFOP fora das vendas de mercadoria suportadas nesta etapa; confira a regra I08-150.'; }
        if ($icms!=='') {
            $aceitos=$crt==='4'?['102','300']:($crt==='1'?['102','103','300','400','500','900']:['00','20','40','41','60','90']);
            if (!in_array($icms,$aceitos,true)) { $r[]='CST/CSOSN fora do escopo NFC-e deste cadastro.'; }
            if (in_array($icms,['90','900','103','400'],true)) { $r[]='Confirme a permissão específica da UF para esta situação tributária na homologação.'; }
            if ($cfop!=='' && in_array($icms,['60','500'],true) && $cfop!=='5405') { $r[]='CFOP incompatível com ICMS já cobrado por ST neste escopo.'; }
            if ($cfop==='5405' && !in_array($icms,['60','500'],true) && !($uf==='CE' && in_array($icms,['90','900'],true))) { $r[]='Confira a compatibilidade entre CFOP 5405 e CST/CSOSN.'; }
            if (in_array($icms,['00','20'],true) && ($dados['aliq_icms'] ?? '')==='') { $r[]='Informe a alíquota ICMS conforme orientação fiscal.'; }
            if ($icms==='20' && ($dados['aliq_reducao_icms'] ?? '')==='') { $r[]='Informe a redução da base de cálculo conforme orientação fiscal.'; }
        }
        // Não presumir obrigatoriedade, isenção ou alíquotas a partir do regime.
        return array_values(array_unique($r));
    }

    public static function produto(array $produto,?array $perfil,array $empresa,array $catalogos): array
    {
        $r=CadastroProdutoFiscal::pendencias($produto,$perfil,$empresa);$d=$produto['dados'];
        if ($perfil) { $r=array_merge($r,self::perfil($perfil['dados'],$catalogos)); }
        $codigo=(string)($d['ncm'] ?? '');
        if (($d['cest'] ?? '')!=='') {
            if (empty($catalogos['cest'])) { $r[]='Tabela CEST não disponibilizada.'; }
            elseif ($codigo==='' || !CatalogosFiscais::contem($catalogos['cest'],$d['cest'],$codigo)) { $r[]='CEST não localizado para o NCM; revise o segmento e descrição.'; }
        }
        if (($d['unidade_comercial'] ?? '')!=='' && strtoupper((string)$produto['unidade_venda'])!==$d['unidade_comercial']) { $r[]='Unidade comercial fiscal difere da unidade de venda; revise a conversão.'; }
        if (($d['unidade_comercial'] ?? '')!=='' && ($d['unidade_comercial'] ?? '')===($d['unidade_tributavel'] ?? '') && ($d['fator'] ?? '')!=='' && !preg_match('/\A0*1(?:\.0+)?\z/',$d['fator'])) { $r[]='Unidades iguais exigem revisão do fator de conversão.'; }
        return array_values(array_unique($r));
    }
}
