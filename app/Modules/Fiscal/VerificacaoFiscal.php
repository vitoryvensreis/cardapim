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
        // Não presumir obrigatoriedade, isenção ou alíquotas a partir do regime.
        return array_values(array_unique($r));
    }

    public static function produto(array $produto,?array $perfil,array $empresa,array $catalogos,?array $ncm): array
    {
        $r=CadastroProdutoFiscal::pendencias($produto,$perfil,$empresa);$d=$produto['dados'];
        if ($perfil) { $r=array_merge($r,self::perfil($perfil['dados'],$catalogos)); }
        $codigo=(string)($d['ncm'] ?? '');
        if ($codigo!=='') {
            if ($ncm===null) { $r[]='Catálogo NCM vigente indisponível; consulte novamente.'; }
            elseif (!isset($ncm['itens'][$codigo])) { $r[]='NCM ausente no catálogo vigente.'; }
            elseif (($d['ncm_fonte'] ?? '')!==$ncm['versao']) { $r[]='NCM precisa de revisão na versão atual.'; }
        }
        if (($d['cest'] ?? '')!=='') {
            if (empty($catalogos['cest'])) { $r[]='Tabela CEST não disponibilizada.'; }
            elseif ($codigo==='' || !CatalogosFiscais::contem($catalogos['cest'],$d['cest'],$codigo)) { $r[]='CEST não localizado para o NCM; revise o segmento e descrição.'; }
        }
        if (($d['unidade_comercial'] ?? '')!=='' && strtoupper((string)$produto['unidade_venda'])!==$d['unidade_comercial']) { $r[]='Unidade comercial fiscal difere da unidade de venda; revise a conversão.'; }
        if (($d['unidade_comercial'] ?? '')!=='' && ($d['unidade_comercial'] ?? '')===($d['unidade_tributavel'] ?? '') && ($d['fator'] ?? '')!=='' && !preg_match('/\A0*1(?:\.0+)?\z/',$d['fator'])) { $r[]='Unidades iguais exigem revisão do fator de conversão.'; }
        return array_values(array_unique($r));
    }
}
