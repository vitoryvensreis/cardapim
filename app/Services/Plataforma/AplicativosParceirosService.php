<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Core\Config;
use Cardapim\Modules\Integracoes\AplicativosParceiros;
use Cardapim\Repositories\Plataforma\AplicativoParceiroRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use PDO;

/**
 * Cadastro, no painel da plataforma, do Client ID / Client Secret dos apps do Cardapim nos marketplaces.
 * O segredo nunca volta para a tela (so os 4 ultimos caracteres) e nunca vai para os logs.
 */
final class AplicativosParceirosService
{
    private readonly AplicativoParceiroRepository $repo;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $usuario)
    {
        $this->repo = new AplicativoParceiroRepository($db);
    }

    /**
     * @return array{provedor:string, nome:string, portal:string, rotulo_id:string, pronta:bool, origem:string,
     *   client_id:string, segredo_final:string, salvo_em:string, salvo_por:string, lojas:int, env_id:string, env_secret:string}
     */
    public function resumo(string $provedor): array
    {
        $p = AplicativosParceiros::PROVEDORES[$provedor];
        $salvo = $this->repo->buscar($provedor);
        $origem = 'painel';
        if ($salvo === null) {
            $origem = Config::get($p['env_id']) !== '' && Config::get($p['env_secret']) !== '' ? 'env' : '';
        }
        return [
            'provedor' => $provedor,
            'nome' => $p['nome'],
            'portal' => $p['portal'],
            'rotulo_id' => $p['rotulo_id'],
            'pronta' => $p['pronta'],
            'origem' => $origem,
            'client_id' => $salvo['client_id'] ?? '',
            'segredo_final' => $salvo !== null ? self::final4($salvo['client_secret']) : '',
            'salvo_em' => $salvo['salvo_em'] ?? '',
            'salvo_por' => $salvo['salvo_por'] ?? '',
            'lojas' => $this->repo->lojasVinculadas($provedor),
            'env_id' => $p['env_id'],
            'env_secret' => $p['env_secret'],
        ];
    }

    /** Client Secret em branco = mantem o que ja esta salvo. */
    public function salvar(string $provedor, string $clientId, string $clientSecret): Resultado
    {
        if (!AplicativosParceiros::existe($provedor)) {
            return Resultado::falha('Aplicativo desconhecido.');
        }
        $nome = AplicativosParceiros::PROVEDORES[$provedor]['nome'];
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $atual = $this->repo->buscar($provedor);
        $erros = [];

        if ($clientId === '') {
            $erros['client_id'] = 'Cole o ' . AplicativosParceiros::PROVEDORES[$provedor]['rotulo_id'] . ' do aplicativo.';
        } elseif (mb_strlen($clientId) > 200 || preg_match('/\A[A-Za-z0-9._:\-]{3,200}\z/', $clientId) !== 1) {
            $erros['client_id'] = 'Confira o código: use só letras, números, ponto, hífen ou dois-pontos, sem espaços.';
        }
        if ($clientSecret === '' && $atual === null) {
            $erros['client_secret'] = 'Cole o Client Secret do aplicativo.';
        } elseif ($clientSecret !== '' && (mb_strlen($clientSecret) > 400 || preg_match('/\A\S{8,400}\z/u', $clientSecret) !== 1)) {
            $erros['client_secret'] = 'Confira o Client Secret: precisa ter pelo menos 8 caracteres e nenhum espaço.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }

        $segredoNovo = $clientSecret !== '' ? $clientSecret : (string) $atual['client_secret'];
        $trocouId = $atual !== null && $atual['client_id'] !== $clientId;
        $this->repo->salvar($provedor, $clientId, $segredoNovo, trim($this->usuario->nome . ' ' . $this->usuario->sobrenome));
        AplicativosParceiros::esquecer();

        Auditoria::registrar($this->usuario, 'Aplicativo ' . $nome . ' configurado', implode('; ', [
            $atual === null ? 'Credenciais cadastradas pela primeira vez' : ($trocouId ? 'Client ID trocado' : 'Client ID mantido'),
            $clientSecret !== '' ? 'Client Secret ' . ($atual === null ? 'cadastrado' : 'trocado') : 'Client Secret mantido',
        ]));

        $mensagem = 'Credenciais do aplicativo ' . $nome . ' salvas.';
        if ($trocouId && ($lojas = $this->repo->lojasVinculadas($provedor)) > 0) {
            $mensagem .= ' Atenção: ' . $lojas . ($lojas === 1 ? ' loja estava vinculada' : ' lojas estavam vinculadas')
                . ' ao aplicativo anterior e ' . ($lojas === 1 ? 'precisa' : 'precisam') . ' vincular de novo.';
        }
        return Resultado::sucesso($mensagem);
    }

    public function remover(string $provedor): Resultado
    {
        if (!AplicativosParceiros::existe($provedor)) {
            return Resultado::falha('Aplicativo desconhecido.');
        }
        $nome = AplicativosParceiros::PROVEDORES[$provedor]['nome'];
        if ($this->repo->buscar($provedor) === null) {
            return Resultado::falha('Não há credenciais do ' . $nome . ' salvas no painel.');
        }
        $this->repo->remover($provedor);
        AplicativosParceiros::esquecer();
        Auditoria::registrar($this->usuario, 'Aplicativo ' . $nome . ' removido', 'Credenciais apagadas do painel');
        $restou = AplicativosParceiros::disponivel($provedor) ? ' O sistema volta a usar as do arquivo de configuração do servidor.' : '';
        return Resultado::sucesso('Credenciais do ' . $nome . ' apagadas.' . $restou);
    }

    private static function final4(string $segredo): string
    {
        return mb_strlen($segredo) > 8 ? '••••' . mb_substr($segredo, -4) : '••••';
    }
}
