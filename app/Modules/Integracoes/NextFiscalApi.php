<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Shared\Http\ClienteHttp;
use RuntimeException;

/** Fase administrativa: somente consultas. Nenhuma rota de emissao ou mutacao de CNPJs. */
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

    private function consultar(string $metodo, string $cnpj, string $token, ?int $ambiente): array
    {
        $headers = ['X-CnpjSh' => $cnpj, 'X-CnpjCliente' => $cnpj, 'X-Token' => $token];
        if ($ambiente !== null) {
            $headers['X-Ambiente'] = (string) $ambiente;
        }
        $resposta = $this->http->enviar('POST', self::BASE . $metodo, $headers, []);
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
