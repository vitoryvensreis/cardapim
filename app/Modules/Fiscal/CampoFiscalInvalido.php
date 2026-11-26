<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use InvalidArgumentException;

/** Erro de validação ligado a um campo do formulário, com mensagem em linguagem simples. */
final class CampoFiscalInvalido extends InvalidArgumentException
{
    public const ROTULOS = [
        'nome' => 'Nome do perfil', 'operacao' => 'Tipo de operação', 'cfop' => 'CFOP',
        'situacao_icms' => 'Situação tributária (CST/CSOSN)', 'inicio' => 'Vigência inicial',
        'referencia' => 'Referência da orientação fiscal', 'cst_pis' => 'CST do PIS', 'cst_cofins' => 'CST da COFINS',
        'cst_ibs_cbs' => 'CST do IBS/CBS', 'cclass_trib' => 'Classificação do IBS/CBS', 'cst_is' => 'CST do Imposto Seletivo',
        'cclass_is' => 'Classificação do Imposto Seletivo', 'cbenef' => 'Código de benefício fiscal',
        'ncm' => 'NCM', 'cest' => 'CEST', 'origem' => 'Origem da mercadoria', 'gtin' => 'GTIN comercial',
        'gtin_tributavel' => 'GTIN tributável', 'unidade_comercial' => 'Unidade comercial',
        'unidade_tributavel' => 'Unidade tributável', 'fator' => 'Fator de conversão',
        'aliq_icms' => 'Alíquota do ICMS', 'aliq_st' => 'Alíquota do ICMS ST', 'aliq_fcp' => 'Alíquota do FCP',
        'aliq_reducao_icms' => 'Redução da base do ICMS', 'aliq_pis' => 'Alíquota do PIS', 'aliq_cofins' => 'Alíquota da COFINS',
        'aliq_ibs_uf' => 'Alíquota do IBS estadual', 'aliq_ibs_mun' => 'Alíquota do IBS municipal', 'aliq_cbs' => 'Alíquota da CBS',
        'aliq_is' => 'Alíquota do Imposto Seletivo',
    ];

    public function __construct(public readonly string $campo, string $orientacao)
    {
        parent::__construct(self::rotulo($campo) . ': ' . $orientacao);
    }

    public static function rotulo(string $campo): string
    {
        return self::ROTULOS[$campo] ?? ucfirst(str_replace('_', ' ', $campo));
    }
}
