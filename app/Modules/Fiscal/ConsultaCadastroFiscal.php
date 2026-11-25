<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Shared\Validation\Documento;
use RuntimeException;

/** Consulta pontual do próprio CNPJ, sem repassar QSA, documentos de sócios ou resposta bruta. */
final class ConsultaCadastroFiscal
{
    public function __construct(private readonly ClienteHttp $http = new ClienteHttp(7)) {}

    public function consultar(string $cnpj): array
    {
        if (!Documento::cnpjValido($cnpj)) {
            throw new RuntimeException('CNPJ inválido.');
        }
        $r = $this->http->enviar('GET', 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj);
        if (!$r->ok()) {
            throw new RuntimeException('Consulta cadastral indisponível.');
        }
        return self::normalizar($cnpj, $r->json());
    }

    public static function normalizar(string $cnpj, array $d): array
    {
        if (!is_string($d['cnpj'] ?? null) || Documento::somenteDigitos($d['cnpj']) !== $cnpj) {
            throw new RuntimeException('Resposta cadastral incompatível.');
        }
        $campos = [
            'razao'=>['razao_social',160], 'nome'=>['nome_fantasia',120],
            'email'=>['email',160], 'cep'=>['cep',9], 'logradouro'=>['logradouro',120],
            'numero'=>['numero',20], 'complemento'=>['complemento',100],
            'bairro'=>['bairro',80], 'cidade'=>['municipio',80], 'estado'=>['uf',2],
        ];
        $out = [];
        foreach ($campos as $k => [$origem,$max]) {
            $v = $d[$origem] ?? null;
            if (!is_string($v)) { continue; }
            $v = trim($v);
            if ($v === '' || mb_strlen($v) > $max || preg_match('/[\x00-\x1f\x7f]/', $v)) { continue; }
            if ($k === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { continue; }
            if ($k === 'cep') {
                $v = Documento::somenteDigitos($v);
                if (strlen($v) !== 8) { continue; }
                $v = substr($v,0,5) . '-' . substr($v,5);
            }
            if ($k === 'estado' && !MunicipioIbge::ufValida(strtoupper($v))) { continue; }
            $out[$k] = $k === 'estado' ? strtoupper($v) : $v;
        }
        // O cadastro federal separa o tipo do nome do logradouro.
        $tipo = $d['descricao_tipo_de_logradouro'] ?? null;
        if (isset($out['logradouro']) && is_string($tipo)) {
            $tipo = trim($tipo);
            if ($tipo !== '' && !preg_match('/[\x00-\x1f\x7f]/', $tipo)) {
                $nome = TextoLocal::chave($out['logradouro']);
                $prefixo = TextoLocal::chave($tipo);
                if ($prefixo !== '' && $nome !== $prefixo && !str_starts_with($nome, $prefixo . ' ')) {
                    $completo = $tipo . ' ' . $out['logradouro'];
                    if (mb_strlen($completo) <= 120) { $out['logradouro'] = $completo; }
                }
            }
        }
        // Sugestão, nunca inferir regime normal nem excesso de sublimite.
        if (($d['opcao_pelo_mei'] ?? null) === true) {
            $out['crt'] = '4';
        } elseif (($d['opcao_pelo_mei'] ?? null) === false && ($d['opcao_pelo_simples'] ?? null) === true) {
            $out['crt'] = '1';
        }
        if (!isset($out['razao'])) { throw new RuntimeException('Cadastro não retornou razão social.'); }
        // IE não é fornecida por essa API: não inventar nem remover a existente.
        return $out;
    }
}
