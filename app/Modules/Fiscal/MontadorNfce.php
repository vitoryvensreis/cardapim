<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

/**
 * Converte a foto fiscal no INI da NFC-e (modelo 65) aceito pela NeXT em EnviarNFeTXTObj2, enviado como objeto
 * {"Secao": {"Campo": "valor"}}. Seções e campos seguem o "Modelo 1 - NFCe.INI" do ACBr indicado na documentação
 * da NeXT. Valores com vírgula; seções repetidas com sufixo numérico de 3 dígitos (Produto001, ICMS001...).
 * Não calcula tributos além do ICMS próprio (CST 00/20) e de PIS/COFINS por alíquota, quando o perfil informa.
 */
final class MontadorNfce
{
    /**
     * @param array<string,mixed> $foto
     * @param array{numero:int,serie:int,cnf:string,emissao:\DateTimeInterface,cnpj_sh:string,contato_sh:string,email_sh?:string,fone_sh?:string} $doc
     * @return array<string,array<string,string>>
     */
    public static function ini(array $foto, array $doc): array
    {
        $e = $foto['empresa'];
        $crt = (string) $e['crt'];
        $simples = in_array($crt, ['1', '4'], true);
        $amb = (int) $foto['ambiente'];
        $ini = [];
        $ini['infNFe'] = ['versao' => '4.00'];
        $ini['Identificacao'] = [
            'cNF' => $doc['cnf'], 'natOp' => 'Venda de mercadoria', 'mod' => '65', 'serie' => (string) $doc['serie'],
            'nNF' => (string) $doc['numero'], 'dEmi' => $doc['emissao']->format('d/m/Y H:i:s'), 'tpNF' => '1', 'idDest' => '1',
            'tpImp' => '4', 'tpEmis' => '1', 'tpAmb' => $amb === 1 ? '1' : '2', 'finNFe' => '1', 'indFinal' => '1',
            'indPres' => in_array($foto['pedido']['tipo'], ['entrega'], true) ? '4' : '1',
            'cUF' => (string) MunicipioIbge::codigoUf((string) $e['estado']), 'cMunFG' => (string) $e['ibge'], 'procEmi' => '0', 'verProc' => 'Deliveryzim',
        ];
        $ini['Emitente'] = array_filter([
            'CNPJCPF' => $foto['cnpj'], 'xNome' => self::texto((string) $e['razao'], 60), 'xFant' => self::texto((string) ($e['nome'] ?? ''), 60),
            'IE' => (string) ($e['ie'] ?? '') !== '' ? (string) $e['ie'] : 'ISENTO', 'CRT' => $crt,
            'xLgr' => self::texto((string) $e['logradouro'], 60), 'nro' => self::texto((string) $e['numero'], 60), 'xCpl' => self::texto((string) ($e['complemento'] ?? ''), 60),
            'xBairro' => self::texto((string) $e['bairro'], 60), 'cMun' => (string) $e['ibge'], 'xMun' => self::texto((string) $e['cidade'], 60),
            'UF' => (string) $e['estado'], 'CEP' => preg_replace('/\D/', '', (string) $e['cep']), 'cPais' => '1058', 'xPais' => 'BRASIL',
        ], static fn ($v) => $v !== '');
        if (($foto['destinatario'] ?? '') !== '') {
            $ini['Destinatario'] = ['CNPJCPF' => (string) $foto['destinatario'], 'indIEDest' => '9'];
        }
        $tot = ['vBC'=>0, 'vICMS'=>0, 'vProd'=>0, 'vDesc'=>0, 'vOutro'=>0, 'vPIS'=>0, 'vCOFINS'=>0];
        foreach ($foto['itens'] as $n => $it) {
            $s = sprintf('%03d', $n + 1);
            $qTrib = self::qTrib((int) $it['qtd'], (string) $it['fator']);
            $ini['Produto' . $s] = array_filter([
                'cProd' => (string) $it['codigo'], 'cEAN' => (string) $it['gtin'], 'xProd' => self::texto((string) $it['nome'], 120),
                'NCM' => (string) $it['ncm'], 'CEST' => (string) $it['cest'], 'cBenef' => (string) $it['cbenef'], 'CFOP' => (string) $it['cfop'],
                'uCom' => (string) $it['ucom'], 'qCom' => RateioFiscal::quantidade((int) $it['qtd']), 'vUnCom' => RateioFiscal::unitario((int) $it['vprod'], (int) $it['qtd']),
                'vProd' => RateioFiscal::dinheiro((int) $it['vprod']), 'cEANTrib' => (string) $it['gtin_trib'], 'uTrib' => (string) $it['utrib'],
                'qTrib' => RateioFiscal::quantidade($qTrib), 'vUnTrib' => RateioFiscal::unitario((int) $it['vprod'], $qTrib),
                'vDesc' => $it['vdesc'] > 0 ? RateioFiscal::dinheiro((int) $it['vdesc']) : '', 'vOutro' => $it['voutro'] > 0 ? RateioFiscal::dinheiro((int) $it['voutro']) : '',
                'indTot' => '1',
            ], static fn ($v) => $v !== '');
            $base = (int) $it['vprod'] - (int) $it['vdesc'] + (int) $it['voutro'];
            [$icms, $vbc, $vicms] = self::icms($it, $simples, $base);
            $ini['ICMS' . $s] = $icms;
            $tot['vBC'] += $vbc; $tot['vICMS'] += $vicms;
            foreach (['PIS' => ['cst_pis', 'aliq_pis', 'pPIS', 'vPIS'], 'COFINS' => ['cst_cofins', 'aliq_cofins', 'pCOFINS', 'vCOFINS']] as $g => [$cCst, $cAliq, $cp, $cv]) {
                // NFC-e: grupos PIS/COFINS só quando o perfil informa o CST (regras Q01-20/S01-20 valem para o modelo 55).
                if ($it[$cCst] === '') { continue; }
                $tributado = in_array($it[$cCst], ['01', '02'], true);
                $valor = $tributado ? RateioFiscal::percentual($base, $it[$cAliq]) : 0;
                $ini[$g . $s] = ['CST' => $it[$cCst], 'vBC' => RateioFiscal::dinheiro($tributado ? $base : 0), $cp => $tributado ? RateioFiscal::aliquota($it[$cAliq]) : '0,00', $cv => RateioFiscal::dinheiro($valor)];
                $tot[$cv] += $valor;
            }
            $tot['vProd'] += (int) $it['vprod']; $tot['vDesc'] += (int) $it['vdesc']; $tot['vOutro'] += (int) $it['voutro'];
        }
        $vNF = $tot['vProd'] - $tot['vDesc'] + $tot['vOutro'];
        $ini['Total'] = [
            'vBC' => RateioFiscal::dinheiro($tot['vBC']), 'vICMS' => RateioFiscal::dinheiro($tot['vICMS']), 'vICMSDeson' => '0,00', 'vBCST' => '0,00', 'vST' => '0,00',
            'vProd' => RateioFiscal::dinheiro($tot['vProd']), 'vFrete' => '0,00', 'vSeg' => '0,00', 'vDesc' => RateioFiscal::dinheiro($tot['vDesc']),
            'vIPI' => '0,00', 'vPIS' => RateioFiscal::dinheiro($tot['vPIS']), 'vCOFINS' => RateioFiscal::dinheiro($tot['vCOFINS']),
            'vOutro' => RateioFiscal::dinheiro($tot['vOutro']), 'vNF' => RateioFiscal::dinheiro($vNF),
        ];
        $ini['Transportador'] = ['modFrete' => '9'];
        $troco = 0;
        foreach ($foto['pagamentos'] as $n => $pg) {
            $s = sprintf('%03d', $n + 1);
            $ini['pag' . $s] = array_filter([
                'tPag' => (string) $pg['tpag'], 'vPag' => RateioFiscal::dinheiro((int) $pg['vpag']), 'indPag' => '0',
                // Cartão e Pix dinâmico exigem o grupo do cartão; 2 = pagamento não integrado ao sistema.
                'tpIntegra' => in_array($pg['tpag'], ['03', '04', '17'], true) ? '2' : '',
                'xPag' => $pg['tpag'] === '99' ? ($foto['pedido']['origem'] === 'ifood' ? 'iFood' : 'Outros') : '',
            ], static fn ($v) => $v !== '');
            $troco += (int) $pg['troco'];
        }
        if ($troco > 0) { $ini['pag' . sprintf('%03d', count($foto['pagamentos']))]['vTroco'] = RateioFiscal::dinheiro($troco); }
        $ini['DadosAdicionais'] = ['infCpl' => 'Pedido ' . (int) $foto['pedido']['numero'] . ($amb === 1 ? '' : ' - documento de teste, sem valor fiscal')];
        $ini['infRespTec'] = array_filter(['CNPJ' => $doc['cnpj_sh'], 'xContato' => self::texto($doc['contato_sh'], 60), 'email' => (string) ($doc['email_sh'] ?? ''), 'fone' => preg_replace('/\D/', '', (string) ($doc['fone_sh'] ?? ''))], static fn ($v) => $v !== '');
        return $ini;
    }

    /** @return array{0:array<string,string>,1:int,2:int} grupo ICMS, vBC e vICMS em centavos */
    private static function icms(array $it, bool $simples, int $base): array
    {
        $sit = (string) $it['icms'];
        if ($simples) {
            $g = ['Origem' => (string) $it['origem'], 'CSOSN' => $sit];
            if ($sit === '900') { $g += ['modBC' => '3', 'vBC' => '0,00', 'pICMS' => '0,00', 'vICMS' => '0,00']; }
            return [$g, 0, 0];
        }
        $g = ['Origem' => (string) $it['origem'], 'CST' => $sit];
        if (in_array($sit, ['00', '20'], true)) {
            $red = $sit === '20' ? (string) $it['red_icms'] : '';
            $vbc = $red !== '' ? $base - RateioFiscal::percentual($base, $red) : $base;
            $vicms = RateioFiscal::percentual($vbc, (string) $it['aliq_icms']);
            $g += ['modBC' => '3', 'vBC' => RateioFiscal::dinheiro($vbc), 'pICMS' => RateioFiscal::aliquota((string) $it['aliq_icms']), 'vICMS' => RateioFiscal::dinheiro($vicms)];
            if ($red !== '') { $g['pRedBC'] = RateioFiscal::aliquota($red); }
            return [$g, $vbc, $vicms];
        }
        return [$g, 0, 0];
    }

    /** Quantidade tributável em milésimos = quantidade comercial x fator de conversão. */
    private static function qTrib(int $qtdMil, string $fator): int
    {
        $f = (float) str_replace(',', '.', $fator);
        return $f > 0 ? (int) round($qtdMil * $f) : $qtdMil;
    }

    /** Texto limpo para o INI: sem quebras, sem "=" e sem colchetes que confundem o parser. */
    public static function texto(string $v, int $max): string
    {
        $v = trim(preg_replace('/[\x00-\x1f\x7f\[\]=;]+/u', ' ', $v) ?? '');
        return mb_substr(preg_replace('/\s+/u', ' ', $v) ?? '', 0, $max);
    }
}
