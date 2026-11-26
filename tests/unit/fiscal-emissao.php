<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Fiscal\ChaveAcesso;
use Cardapim\Modules\Fiscal\MontadorNfce;
use Cardapim\Modules\Fiscal\RateioFiscal;
use Cardapim\Modules\Integracoes\NextFiscalApi;
use Cardapim\Services\Fiscal\FiscalEmissao;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;

function confere(bool $ok, string $msg): void { if (!$ok) { throw new RuntimeException('Emissão fiscal: ' . $msg); } }

// ---------- Rateio em centavos exatos ----------
$r = RateioFiscal::repartir(100, [1 => 333, 2 => 333, 3 => 334]);
confere(array_sum($r) === 100, 'rateio deve somar o total');
confere(RateioFiscal::repartir(0, [1 => 10]) === [1 => 0], 'rateio de zero');
confere(array_sum(RateioFiscal::repartir(1, [1 => 1, 2 => 1, 3 => 1])) === 1, 'rateio de 1 centavo');
confere(RateioFiscal::centavos('12.3') === 1230 && RateioFiscal::centavos('0,05') === 5 && RateioFiscal::centavos('x') === 0, 'centavos');
confere(RateioFiscal::milesimos('1.25') === 1250 && RateioFiscal::milesimos('2') === 2000, 'milésimos');
confere(RateioFiscal::dinheiro(123456) === '1234,56' && RateioFiscal::dinheiro(5) === '0,05', 'dinheiro');
confere(RateioFiscal::quantidade(1000) === '1' && RateioFiscal::quantidade(1250) === '1,25' && RateioFiscal::quantidade(350) === '0,35', 'quantidade');
confere(RateioFiscal::unitario(1000, 3000) === '3,33333333' && RateioFiscal::unitario(2590, 1000) === '25,9', 'unitário');
confere(RateioFiscal::percentual(10000, '18') === 1800 && RateioFiscal::percentual(333, '7.5') === 25, 'percentual');

// ---------- Chave de acesso (exemplo do manual da NF-e: DV 5) ----------
confere(ChaveAcesso::dv('5206043300991100250655012000000780026730161') === 5, 'DV módulo 11');
$chave = ChaveAcesso::gerar(23, new DateTimeImmutable('2026-11-27'), '11222333000181', 65, 1, 42, 1, '12345678');
confere(strlen($chave) === 44 && ChaveAcesso::valida($chave) && substr($chave, 0, 6) === '232611', 'chave gerada válida');
confere(!ChaveAcesso::valida(substr($chave, 0, 43) . (((int) $chave[43] + 1) % 10)), 'chave com DV errado');
$c = ChaveAcesso::codigoAleatorio(12345678);
confere(preg_match('/\A\d{8}\z/', $c) === 1 && $c !== '12345678', 'cNF aleatório');

// ---------- Portão: origens e padrão aprovado ----------
confere(FiscalEmissao::origemDoPedido('ifood', 'entrega') === 'ifood', 'origem iFood');
confere(FiscalEmissao::origemDoPedido('balcao', 'mesa') === 'mesa' && FiscalEmissao::origemDoPedido('balcao', 'balcao') === 'balcao', 'origem salão');
confere(FiscalEmissao::origemDoPedido('cardapio', 'retirada') === 'delivery', 'origem delivery');
$cfg = FiscalEmissao::configuracao([]);
confere($cfg['mesa'] && $cfg['balcao'] && $cfg['delivery'] && !$cfg['ifood'] && $cfg['imprimir'], 'padrão: iFood desligado');
confere(FiscalEmissao::configuracao(['emissao' => ['mesa' => false, 'ifood' => 'sim']])['mesa'] === false
    && FiscalEmissao::configuracao(['emissao' => ['ifood' => 'sim']])['ifood'] === false, 'configuração só aceita booleanos');

// ---------- Montador do INI da NFC-e ----------
$item = static fn (array $x): array => $x + [
    'codigo' => '10', 'gtin' => 'SEM GTIN', 'gtin_trib' => 'SEM GTIN', 'nome' => 'X-Burguer', 'ncm' => '21069090', 'cest' => '', 'cbenef' => '',
    'cfop' => '5102', 'ucom' => 'UN', 'utrib' => 'UN', 'fator' => '1', 'qtd' => 1000, 'vprod' => 2500, 'vdesc' => 0, 'voutro' => 0,
    'origem' => '0', 'icms' => '102', 'aliq_icms' => '', 'red_icms' => '', 'cst_pis' => '', 'aliq_pis' => '', 'cst_cofins' => '', 'aliq_cofins' => '',
];
$foto = [
    'ambiente' => 2, 'cnpj' => '11222333000181', 'destinatario' => '52998224725',
    'empresa' => ['crt' => '4', 'razao' => 'Lanchonete [Teste]=LTDA', 'nome' => 'Lanche', 'ie' => '', 'logradouro' => 'Rua A', 'numero' => '1', 'complemento' => '',
        'bairro' => 'Centro', 'cidade' => 'Fortaleza', 'estado' => 'CE', 'cep' => '60.000-000', 'ibge' => '2304400'],
    'pedido' => ['numero' => 77, 'tipo' => 'entrega', 'origem' => 'cardapio'],
    'itens' => [
        $item(['vdesc' => 100, 'voutro' => 300]),
        $item(['codigo' => '11', 'nome' => 'Refri', 'qtd' => 2000, 'vprod' => 1000, 'vdesc' => 50, 'voutro' => 200, 'icms' => '300']),
    ],
    'pagamentos' => [
        ['tpag' => '03', 'vpag' => 2000, 'troco' => 0],
        ['tpag' => '01', 'vpag' => 2000, 'troco' => 150],
    ],
];
$ini = MontadorNfce::ini($foto, ['numero' => 42, 'serie' => 1, 'cnf' => '12345678', 'emissao' => new DateTimeImmutable('2026-11-27 12:30:00'), 'cnpj_sh' => '99888777000166', 'contato_sh' => 'Suporte']);
confere($ini['Identificacao']['mod'] === '65' && $ini['Identificacao']['tpAmb'] === '2' && $ini['Identificacao']['indPres'] === '4' && $ini['Identificacao']['cUF'] === '23', 'identificação');
confere($ini['Identificacao']['dEmi'] === '27/11/2026 12:30:00' && $ini['Identificacao']['nNF'] === '42', 'data e número');
confere($ini['Emitente']['IE'] === 'ISENTO' && $ini['Emitente']['CEP'] === '60000000' && !str_contains($ini['Emitente']['xNome'], '[') && !str_contains($ini['Emitente']['xNome'], '='), 'emitente saneado');
confere($ini['Destinatario']['CNPJCPF'] === '52998224725' && $ini['Destinatario']['indIEDest'] === '9', 'CPF na nota');
confere($ini['ICMS001'] === ['Origem' => '0', 'CSOSN' => '102'] && $ini['ICMS002']['CSOSN'] === '300', 'ICMS do MEI');
confere(!isset($ini['PIS001']) && !isset($ini['COFINS001']), 'sem PIS/COFINS quando o perfil não informa');
confere($ini['Produto002']['qCom'] === '2' && $ini['Produto002']['vUnCom'] === '5' && $ini['Produto002']['vDesc'] === '0,50', 'produto 2');
confere($ini['Total']['vProd'] === '35,00' && $ini['Total']['vDesc'] === '1,50' && $ini['Total']['vOutro'] === '5,00' && $ini['Total']['vNF'] === '38,50', 'totais');
confere($ini['pag001']['tPag'] === '03' && $ini['pag001']['tpIntegra'] === '2' && !isset($ini['pag001']['vTroco']), 'cartão com tpIntegra');
confere($ini['pag002']['vTroco'] === '1,50' && !isset($ini['pag002']['tpIntegra']), 'troco no último pagamento');
confere(RateioFiscal::centavos(str_replace(',', '.', $ini['pag001']['vPag'])) + RateioFiscal::centavos(str_replace(',', '.', $ini['pag002']['vPag'])) - 150 === 3850, 'pagamentos menos troco = vNF');
confere(str_contains($ini['DadosAdicionais']['infCpl'], 'sem valor fiscal') && $ini['infRespTec']['CNPJ'] === '99888777000166', 'aviso de teste e responsável técnico');

// Regime normal com ICMS 00 e PIS/COFINS
$foto['empresa']['crt'] = '3';
$foto['ambiente'] = 1;
$foto['pedido']['tipo'] = 'mesa';
$foto['itens'] = [$item(['icms' => '00', 'aliq_icms' => '18', 'cst_pis' => '01', 'aliq_pis' => '1.65', 'cst_cofins' => '07'])];
$foto['pagamentos'] = [['tpag' => '20', 'vpag' => 2500, 'troco' => 0]];
$ini = MontadorNfce::ini($foto, ['numero' => 1, 'serie' => 2, 'cnf' => '87654321', 'emissao' => new DateTimeImmutable(), 'cnpj_sh' => '99888777000166', 'contato_sh' => 'Suporte']);
confere($ini['Identificacao']['tpAmb'] === '1' && $ini['Identificacao']['indPres'] === '1', 'produção presencial');
confere($ini['ICMS001']['CST'] === '00' && $ini['ICMS001']['vICMS'] === '4,50' && $ini['Total']['vICMS'] === '4,50', 'ICMS 00');
confere($ini['PIS001']['vPIS'] === '0,41' && $ini['COFINS001']['vCOFINS'] === '0,00' && $ini['Total']['vPIS'] === '0,41', 'PIS/COFINS');
confere(!str_contains($ini['DadosAdicionais']['infCpl'], 'teste'), 'produção sem aviso de teste');

// ---------- Contrato das chamadas novas da NeXT ----------
final class NextEmissaoFalsa extends ClienteHttp
{
    public array $pedidos = [];
    public array $respostas = [];
    public function enviar(string $metodo, string $url, array $cabecalhos = [], array|string|null $corpo = null): RespostaHttp
    {
        $this->pedidos[] = ['url' => $url, 'h' => $cabecalhos, 'corpo' => is_string($corpo) ? json_decode($corpo, true) : $corpo];
        return array_shift($this->respostas) ?? new RespostaHttp(200, '{"sucesso":true,"mensagem":"","dados":{}}');
    }
}
$http = new NextEmissaoFalsa();
$api = new NextFiscalApi($http);
$http->respostas[] = new RespostaHttp(200, '{"sucesso":false,"mensagem":"Rejeição","dados":{"CodStatus":"539","XMotivo":"Duplicidade"}}');
$env = $api->enviarNfce('11222333000181', '11444777000161', 'tok', 2, 'CE', 7, ['infNFe' => ['versao' => '4.00']], $chave);
confere($env['sucesso'] === false && ($env['dados']['CodStatus'] ?? '') === '539', 'rejeição devolvida sem exceção');
$api->consultarSituacao('11222333000181', '11444777000161', 'tok', 2, 'CE', $chave);
$api->cancelar('11222333000181', '11444777000161', 'tok', 2, 'CE', $chave, '123456789012345', 'Cliente desistiu da compra', 8);
$api->inutilizar('11222333000181', '11444777000161', 'tok', 2, 'CE', 1, 10, 12, 'Numeração pulada por falha');
$permitidos = [
    'nfe/EnviarNFeTXTObj2' => ['UF', 'IdLote', 'TXTNFe', 'ChNFe', 'Email', 'EmailCopia', 'DANFeTipo'],
    'nfe/ConsultaSituacao' => ['UF', 'ChNFe'],
    'nfe/Cancelamento' => ['UF', 'NProt', 'ChNFe', 'IdLote', 'NSeqEvento', 'XJust', 'Email'],
    'nfe/Inutilizacao' => ['UF', 'ModeloDFe', 'NNFIni', 'NNFFin', 'Serie', 'XJust'],
];
foreach ($http->pedidos as $p) {
    $metodo = substr($p['url'], strlen('https://www.apinext.com.br/ws/v1/'));
    confere(isset($permitidos[$metodo]), 'método inesperado ' . $metodo);
    confere(array_diff(array_keys((array) $p['corpo']), $permitidos[$metodo]) === [], 'campo fora da OpenAPI em ' . $metodo);
    confere(($p['h']['X-Ambiente'] ?? '') === '2' && ($p['h']['X-CnpjCliente'] ?? '') === '11444777000161' && ($p['h']['X-Token'] ?? '') === 'tok', 'cabeçalhos em ' . $metodo);
}
confere($http->pedidos[0]['corpo']['ChNFe'] === $chave && is_array($http->pedidos[0]['corpo']['TXTNFe']), 'envio com chave e INI em objeto');

echo "fiscal-emissao: ok\n";
