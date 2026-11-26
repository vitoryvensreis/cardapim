<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Integracoes\NextFiscalApi;
use Cardapim\Repositories\Plataforma\FiscalNextRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Http\ErroHttp;
use Cardapim\Shared\Seguranca\Cripto;
use Cardapim\Shared\Validation\Documento;
use PDO;
use RuntimeException;
use Throwable;

/** Configuracao administrativa. Nenhum efeito no PDV ou no cadastro de restaurantes. */
final class FiscalNextService
{
    private readonly FiscalNextRepository $repo;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $usuario, private readonly NextFiscalApi $api = new NextFiscalApi())
    {
        $this->repo = new FiscalNextRepository($db);
    }

    public function restaurantes(): ?array
    {
        if (!\Cardapim\Shared\Esquema::tem($this->db, 'fiscal_restaurante')) { return null; }
        try {
            return (new \Cardapim\Repositories\Gerencia\FiscalRepository($this->db))->resumoPlataforma();
        } catch (Throwable $e) {
            error_log('[Cardapim] Resumo fiscal indisponível (' . get_class($e) . ').');
            return null;
        }
    }

    public function estado(): array
    {
        $d = $this->repo->ler()['dados'];
        // Projecao explicita: nao devolver token, ciphertext nem resposta bruta da NeXT.
        return [
            'configurado' => isset($d['token']),
            'cnpj' => $d['cnpj'] ?? '',
            'razao' => $d['razao'] ?? '',
            'ambiente' => $d['ambiente'] ?? 3,
            'limite' => $d['limite'] ?? null,
            'ativo' => ($d['ativo'] ?? false) === true,
            'testado_em' => $d['testado_em'] ?? '',
            'disponibilidade' => $d['disponibilidade'] ?? 'Não testada',
            'administracao' => $d['administracao'] ?? 'Não testada',
            'sincronizado_em' => $d['sincronizado_em'] ?? '',
            'quantidade' => $d['quantidade'] ?? null,
            'clientes' => $d['clientes'] ?? [],
            'parcial' => $d['parcial'] ?? false,
        ];
    }

    public function salvar(string $cnpj, string $razao, string $token, string $ambiente, string $limite): Resultado
    {
        $cnpj = Documento::somenteDigitos($cnpj);
        if (!Documento::cnpjValido($cnpj) || $razao === '' || mb_strlen($razao) > 160 || !in_array($ambiente, ['2', '3'], true)) {
            return Resultado::falha('Confira o CNPJ, a razão social e o ambiente de testes.');
        }
        if ($limite !== '' && filter_var($limite, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000000]]) === false) {
            return Resultado::falha('Informe um limite mensal inteiro positivo ou deixe em branco.');
        }
        if (!Cripto::disponivel()) {
            return Resultado::falha('A criptografia do servidor não está disponível.');
        }
        $atual = $this->repo->ler();
        $d = $atual['dados'];
        if ($token === '') {
            if (($d['cnpj'] ?? '') !== $cnpj || !isset($d['token'])) {
                return Resultado::falha('Informe o token administrativo para este CNPJ.');
            }
            $token = $d['token'];
        }
        if (preg_match('/\A[\x21-\x7E]{1,512}\z/', $token) !== 1) {
            return Resultado::falha('Informe um token válido, sem espaços ou quebras de linha.');
        }
        $mudou = ($d['cnpj'] ?? '') !== $cnpj || ($d['token'] ?? '') !== $token || ($d['ambiente'] ?? 3) !== (int) $ambiente;
        if ($mudou) {
            $d = ['ativo' => false];
        }
        $d = array_replace($d, ['cnpj' => $cnpj, 'razao' => $razao, 'token' => $token, 'ambiente' => (int) $ambiente, 'limite' => $limite === '' ? null : (int) $limite]);
        $this->repo->salvar($d, $atual['versao']);
        Auditoria::registrar($this->usuario, 'Fiscal NeXT: configuração salva', 'Configuração administrativa atualizada; segredo não registrado.');
        return Resultado::sucesso($mudou ? 'Configuração salva. Teste a conexão e as permissões antes de ativar.' : 'Configuração salva.');
    }

    public function testar(): Resultado
    {
        $atual = $this->repo->ler();
        $d = $atual['dados'];
        if (!isset($d['token'])) {
            return Resultado::falha('Cadastre as credenciais antes de testar.');
        }
        if (!RateLimiter::padrao(20, 3600)->permitir('next-fiscal-teste|' . $this->usuario->id)) {
            return Resultado::falha('Limite de testes por hora atingido.');
        }
        $disponivel = false;
        $administrativo = false;
        try {
            $this->api->disponibilidade($d['cnpj'], $d['token'], $d['ambiente']);
            $disponivel = true;
            $d['disponibilidade'] = 'Serviço em operação';
        } catch (Throwable $e) {
            $d['disponibilidade'] = self::mensagemFalha($e);
        }
        try {
            $lista = $this->api->clientes($d['cnpj'], $d['token']);
            $d = array_replace($d, $lista);
            $d['sincronizado_em'] = date('Y-m-d H:i:s');
            $d['administracao'] = 'Permissão administrativa confirmada';
            $administrativo = true;
        } catch (Throwable $e) {
            // Ultima fotografia valida permanece visivel com sua data original.
            $d['administracao'] = self::mensagemFalha($e);
        }
        $d['testado_em'] = date('Y-m-d H:i:s');
        $d['teste_ok'] = $disponivel && $administrativo;
        $this->repo->salvar($d, $atual['versao']);
        Auditoria::registrar($this->usuario, 'Fiscal NeXT: teste administrativo', sprintf('Disponibilidade: %s; permissões: %s.', $disponivel ? 'OK' : 'falha', $administrativo ? 'OK' : 'falha'));
        return $d['teste_ok']
            ? Resultado::sucesso('Conexão e permissões confirmadas. Lista de emitentes atualizada.')
            : Resultado::falha('Nem todos os testes passaram. Confira os resultados na central.');
    }

    public function ativar(bool $ativo): Resultado
    {
        $atual = $this->repo->ler();
        $d = $atual['dados'];
        if (!isset($d['token']) || ($ativo && ($d['teste_ok'] ?? false) !== true)) {
            return Resultado::falha('Salve as credenciais e conclua os dois testes antes de ativar.');
        }
        $d['ativo'] = $ativo;
        $this->repo->salvar($d, $atual['versao']);
        Auditoria::registrar($this->usuario, 'Fiscal NeXT: integração ' . ($ativo ? 'ativada' : 'desativada'), 'Controle administrativo; PDV não alterado.');
        return Resultado::sucesso($ativo ? 'Integração administrativa ativada. Emissão real continua bloqueada.' : 'Integração administrativa desativada.');
    }

    private static function mensagemFalha(Throwable $e): string
    {
        // As mensagens da API sao produzidas pelo nosso cliente a partir de codigos fixos.
        // Nunca persistir mensagens de excecoes arbitrarias ou corpo de resposta do fornecedor.
        if ($e instanceof ErroHttp) {
            return 'Falha de comunicação com a NeXT.';
        }
        if ($e instanceof \Cardapim\Modules\Integracoes\NextFiscalErro) {
            return $e->paraUsuario();
        }
        if (get_class($e) === RuntimeException::class && preg_match('/\AA NeXT retornou erro HTTP [0-9]{3}\.\z/', $e->getMessage()) === 1) {
            return $e->getMessage();
        }
        if (get_class($e) === RuntimeException::class && in_array($e->getMessage(), [
            'A NeXT recusou a credencial ou a permissão administrativa.',
            'Limite de consultas da NeXT atingido.',
            'A NeXT não confirmou a operação. Confira as credenciais e tente novamente.',
            'Formato da lista de emitentes não reconhecido.',
            'Quantidade de emitentes inválida.',
            'A lista de emitentes recebida está incompleta.',
        ], true)) {
            return $e->getMessage();
        }
        return 'Não foi possível concluir a consulta.';
    }
}
