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
        $crt=(string)($dados['crt'] ?? '');
        $pares=['cfop'=>'cfop',$tipo=>'situacao_icms','cst_pis'=>'cst_pis','cst_cofins'=>'cst_cofins','cst_ibs_cbs'=>'cst_ibs_cbs','cclass_trib'=>'cclass_trib','cst_is'=>'cst_is','cclass_is'=>'cclass_is'];
        $rot=static fn(string $c):string=>CampoFiscalInvalido::rotulo($c);
        foreach ($pares as $cat=>$campo) {
            $codigo=(string)($dados[$campo] ?? '');
            if ($codigo==='') {
                if (in_array($campo,['cfop','situacao_icms'],true)) { $r[]='Perfil: informe '.$rot($campo).'.'; }
                continue;
            }
            $cst=match($cat) {'cclass_trib'=>(string)($dados['cst_ibs_cbs'] ?? ''),'cclass_is'=>(string)($dados['cst_is'] ?? ''),default=>''};
            if (in_array($cat,['cclass_trib','cclass_is'],true) && $cst==='') { $r[]='Perfil: informe o CST correspondente à '.mb_strtolower($rot($campo)).'.'; }
            // CFOP e CST/CSOSN do escopo NFC-e já são listas fixas validadas abaixo (MOC N12a-81/91, I08-150, N12-30):
            // não dependem de catálogo importado pela administração.
            if (in_array($cat,['cfop','csosn','cst_icms'],true)) { continue; }
            if (empty($catalogos[$cat])) { $r[]='Perfil: a tabela oficial de '.$rot($campo).' ainda não foi disponibilizada pela administração.'; }
            elseif (!CatalogosFiscais::contem($catalogos[$cat],$codigo,'',$cst)) { $r[]='Perfil: o código de '.$rot($campo).' não está na tabela vigente ou não combina com o CST.'; }
        }
        foreach ([['cst_ibs_cbs','cclass_trib'],['cst_is','cclass_is']] as [$a,$b]) {
            if ((($dados[$a] ?? '')==='')!== (($dados[$b] ?? '')==='')) { $r[]='Perfil: preencha '.$rot($a).' e '.mb_strtolower($rot($b)).' juntos, ou deixe os dois em branco.'; }
        }
        // MOC NFC-e: I08-150, N12-30, N12a-20, N12-40/44, N12a-40/44.
        // Escopo inicial: venda de mercadorias de restaurante, sem combustível, ISSQN ou remessas.
        $cfop=(string)($dados['cfop'] ?? '');
        $icms=(string)($dados['situacao_icms'] ?? '');
        $uf=(string)($dados['uf'] ?? '');
        $crt=(string)($dados['crt'] ?? '');
        if ($cfop!=='' && !isset(CadastroProdutoFiscal::cfops($crt)[$cfop])) { $r[]='CFOP fora das vendas de mercadoria suportadas nesta etapa; confira a regra I08-150.'; }
        if ($icms!=='') {
            if (!isset(CadastroProdutoFiscal::situacoes($crt)[$icms])) { $r[]='CST/CSOSN fora do escopo NFC-e deste cadastro.'; }
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
            if (empty($catalogos['cest'])) { $r[]='CEST: a tabela oficial ainda não foi disponibilizada pela administração.'; }
            elseif ($codigo==='' || !CatalogosFiscais::contem($catalogos['cest'],$d['cest'],$codigo)) { $r[]='CEST não combina com o NCM informado. Pesquise o CEST de novo e confira a descrição.'; }
        }
        if (($d['unidade_comercial'] ?? '')!=='' && strtoupper((string)$produto['unidade_venda'])!==$d['unidade_comercial']) { $r[]='A unidade comercial fiscal é diferente da unidade de venda do cardápio. Confira a conversão.'; }
        if (($d['unidade_comercial'] ?? '')!=='' && ($d['unidade_comercial'] ?? '')===($d['unidade_tributavel'] ?? '') && ($d['fator'] ?? '')!=='' && !preg_match('/\A0*1(?:\.0+)?\z/',$d['fator'])) { $r[]='Com unidades iguais, o fator de conversão deve ser 1.'; }
        return array_values(array_unique($r));
    }
}
