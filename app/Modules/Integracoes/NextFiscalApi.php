<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Shared\Http\ClienteHttp;
use RuntimeException;

/** Cadastro e configuração fiscal; nenhum método de emissão. */
final class NextFiscalApi
{
    private const BASE = 'https://www.apinext.com.br/ws/v1/';

    public function __construct(private readonly ClienteHttp $http = new ClienteHttp(8))
    {
    }

    public function disponibilidade(string $cnpj, string $token, int $ambiente): void
    {
        if (!in_array($ambiente, [2, 3], true)) {
            throw new RuntimeException('Ambiente fiscal não permitido nesta etapa.');
        }
        $this->consultar('nfe/Disponibilidade', $cnpj, $token, $ambiente);
    }

    /** @return array{quantidade:int,clientes:list<array{cnpj:string,razao:string,software_house:bool}>,parcial:bool} */
    public function clientes(string $cnpj, string $token): array
    {
        $dados = $this->consultar('clientes/ClientesListar', $cnpj, $token, null);
        return self::filtrarClientes($dados);
    }

    /** Retorna apenas campos cadastrais permitidos. Nunca repassa a resposta original. */
    public static function filtrarClientes(array $dados): array
    {
        $ini = $dados['ArqIniClientes'] ?? null;
        if (!is_array($ini) || !is_array($ini['Clientes'] ?? null)) {
            throw new RuntimeException('Formato da lista de emitentes não reconhecido.');
        }
        $quantidade = filter_var($ini['Clientes']['Quantidade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($quantidade === false) {
            throw new RuntimeException('Quantidade de emitentes inválida.');
        }
        $clientes = [];
        $encontrados = 0;
        foreach ($ini as $secao => $campos) {
            if (!is_string($secao) || preg_match('/\ACliente[0-9]+\z/', $secao) !== 1 || !is_array($campos)) {
                continue;
            }
            $encontrados++;
            $documento = $campos['CNPJ'] ?? null;
            // CPF de emitente pessoa fisica nao e necessario nesta central de restaurantes.
            if (!is_string($documento) || preg_match('/\A[0-9]{14}\z/', $documento) !== 1) {
                continue;
            }
            $razao = is_string($campos['RazaoSocial'] ?? null) ? $campos['RazaoSocial'] : '';
            $razao = mb_substr(trim($razao), 0, 160);
            // Limita o tamanho da fotografia administrativa, sem confundir com o total da conta.
            if (count($clientes) < 100) {
                $clientes[] = [
                    'cnpj' => $documento,
                    'razao' => $razao,
                    'software_house' => (string) ($campos['SoftwareHouse'] ?? '0') === '1',
                ];
            }
        }
        if ($encontrados !== $quantidade) {
            throw new RuntimeException('A lista de emitentes recebida está incompleta.');
        }
        return ['quantidade' => $quantidade, 'clientes' => $clientes, 'parcial' => count($clientes) !== $quantidade];
    }

    public function incluirCliente(string $sh, #[\SensitiveParameter] string $admin, string $cliente, string $razao): string
    {
        if ($sh === $cliente) { throw new RuntimeException('Emitente não permitido.'); }
        $d = $this->consultar('clientes/ClienteIncluir', $sh, $admin, null, $cliente, ['RazaoSocial'=>$razao]);
        $t = $d['TokenCliente'] ?? null;
        if (!is_string($t) || preg_match('/\A[\x21-\x7E]{1,512}\z/', $t) !== 1) { throw new RuntimeException('Credencial individual ausente.'); }
        return $t;
    }

    public function certificadoUpload(string $sh, string $cliente, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $bytes, #[\SensitiveParameter] string $senha, string $razao, string $uf, #[\SensitiveParameter] string $csc, string $id): void
    {
        $this->consultar('nfe/CertificadoUpload', $sh, $token, 2, $cliente, [
            'DocBin'=>base64_encode($bytes), 'NomeEmpresa'=>$razao, 'UF'=>$uf, 'CSC'=>$csc,
            'TokenCSC'=>$id, 'ArquivoCertificado'=>'certificado.pfx', 'SenhaCertificado'=>$senha,
        ]);
    }

    public function configHomologacao(string $sh, string $cliente, #[\SensitiveParameter] string $token, string $razao, string $uf, #[\SensitiveParameter] string $csc, string $id): void
    {
        $this->consultar('nfe/Config', $sh, $token, 2, $cliente, ['UF'=>$uf,'CSC'=>$csc,'IdCSC'=>$id,'NomeEmpresa'=>$razao,'FormaEmissao'=>1]);
    }

    public function statusCliente(string $sh, string $cliente, #[\SensitiveParameter] string $token, int $ambiente): int
    {
        if (!in_array($ambiente,[2,3],true)) { throw new RuntimeException('Ambiente não permitido.'); }
        $d = $this->consultar('nfe/StatusServico',$sh,$token,$ambiente,$cliente);
        $cod = filter_var($d['CodStatus'] ?? null,FILTER_VALIDATE_INT);
        if ($cod === false || $cod < 0 || $cod > 9999) { throw new RuntimeException('Status não reconhecido.'); }
        return $cod;
    }

    /** Consulta de catálogo, nunca emissão. Exige A1 e ambiente de homologação neste fluxo. */
    public function gtin(string $sh,string $cliente,#[\SensitiveParameter] string $token,string $ean): array
    {
        if (!\Cardapim\Modules\Fiscal\CadastroProdutoFiscal::gtin($ean)) { throw new RuntimeException('Código de barras inválido.'); }
        $d=$this->consultar('nfe/GTINConsulta?EAN='.rawurlencode($ean),$sh,$token,2,$cliente,[],'GET');
        return self::filtrarGtin($d);
    }

    public static function filtrarGtin(array $d): array
    {
        $status=filter_var($d['CodStatus'] ?? null,FILTER_VALIDATE_INT);
        $ncm=is_string($d['NCM'] ?? null)?$d['NCM']:'';
        $cest=is_string($d['CEST'] ?? null)?$d['CEST']:'';
        $nome=is_string($d['XProd'] ?? null)?mb_substr(trim($d['XProd']),0,160):'';
        if (!in_array($status,[100,9490],true) || !preg_match('/\A[0-9]{8}\z/',$ncm) || $nome==='') {
            throw new RuntimeException('A NeXT não retornou uma classificação de GTIN reconhecida.');
        }
        return ['descricao'=>$nome,'ncm'=>$ncm,'cest'=>preg_match('/\A[0-9]{7}\z/',$cest)?$cest:'','fonte'=>'NeXT / CCG (homologação)'];
    }

    private function consultar(string $metodo, string $cnpj, #[\SensitiveParameter] string $token, ?int $ambiente, ?string $cliente = null, #[\SensitiveParameter] array $corpo = [], string $verbo = 'POST'): array
    {
        $headers = ['X-CnpjSh' => $cnpj, 'X-CnpjCliente' => $cliente ?? $cnpj, 'X-Token' => $token];
        if ($ambiente !== null) {
            $headers['X-Ambiente'] = (string) $ambiente;
        }
        $resposta = $this->http->enviar($verbo, self::BASE . $metodo, $headers, $verbo==='GET'?null:$corpo);
        if (!$resposta->ok()) {
            throw new RuntimeException(match ($resposta->status) {
                401, 403 => 'A NeXT recusou a credencial ou a permissão administrativa.',
                429 => 'Limite de consultas da NeXT atingido.',
                default => 'A NeXT retornou erro HTTP ' . $resposta->status . '.',
            });
        }
        $json = $resposta->json();
        if (($json['sucesso'] ?? null) !== true || !is_array($json['dados'] ?? null)) {
            throw new RuntimeException('A NeXT não confirmou a operação. Confira as credenciais e tente novamente.');
        }
        return $json['dados'];
    }
}
