<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia\Integracoes;

use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use PDO;

/**
 * iFood: o restaurante autoriza o app do Cardapim no Portal do Parceiro (codigo de vinculo),
 * cola o codigo de autorizacao e escolhe a loja. Tokens ficam cifrados e sao renovados sozinhos.
 */
final class IfoodService extends BaseIntegracao
{
    public function __construct(PDO $db, UsuarioLogado $autor, ?int $idRestaurante, private readonly IfoodApi $api = new IfoodApi())
    {
        parent::__construct($db, $autor, $idRestaurante);
    }

    /** Passo 1: pede ao iFood um codigo de vinculo para o restaurante digitar no Portal do Parceiro. */
    public function iniciar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        if ($atual['status'] === 'conectado') {
            return Resultado::falha('O iFood já está conectado. Desconecte antes de vincular outra conta.');
        }
        try {
            $vinculo = $this->api->codigoVinculo();
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $this->integracoes->salvar($this->idRestaurante, 'ifood', [
            'status' => 'pendente',
            'credenciais' => ['verificador' => $vinculo['verificador']],
            'dados' => ['codigo' => $vinculo['codigo'], 'url' => $vinculo['url'], 'expira' => $vinculo['expira']],
            'ultimo_erro' => null,
        ]);
        $this->log('iFood: vínculo iniciado', 'Código de vínculo gerado (vale 10 minutos).');
        return Resultado::sucesso('Código gerado. Siga os passos abaixo no Portal do Parceiro iFood.');
    }

    /** Passo 2: troca o codigo de autorizacao (colado pelo gerente) pelo acesso e busca as lojas. */
    public function concluir(string $codigoAutorizacao): Resultado
    {
        $codigo = strtoupper(trim($codigoAutorizacao));
        if (preg_match('/\A[A-Z0-9-]{4,40}\z/', $codigo) !== 1) {
            return Resultado::falha('Cole o código de autorização que o Portal do Parceiro mostrou.', ['codigo' => 'Código inválido. Exemplo: ABCD-EFGH']);
        }
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'ifood');
        if ($atual['status'] !== 'pendente' || empty($cred['verificador'])) {
            return Resultado::falha('Gere um código de vínculo primeiro.');
        }
        try {
            $tokens = $this->api->trocarCodigo($codigo, (string) $cred['verificador']);
            $lojas = $this->api->lojas($tokens['acesso']);
        } catch (IntegracaoErro $erro) {
            $this->integracoes->salvar($this->idRestaurante, 'ifood', ['ultimo_erro' => $erro->getMessage()]);
            return Resultado::falha($erro->getMessage(), ['codigo' => $erro->getMessage()]);
        }
        if ($lojas === []) {
            return Resultado::falha('O iFood não mostrou nenhuma loja autorizada. Confira se você autorizou o ' . marca() . ' com a conta da loja.');
        }
        $this->integracoes->salvar($this->idRestaurante, 'ifood', [
            'credenciais' => ['acesso' => $tokens['acesso'], 'renovacao' => $tokens['renovacao'], 'expira' => $tokens['expira']],
            'dados' => ['lojas' => $lojas],
            'ultimo_erro' => null,
        ]);
        if (count($lojas) === 1) {
            return $this->escolherLoja($lojas[0]['id']);
        }
        return Resultado::sucesso('Autorização aceita. Agora escolha qual loja do iFood é deste restaurante.');
    }

    /** Passo 3: define a loja do iFood deste restaurante. */
    public function escolherLoja(string $idLoja): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        $lojas = is_array($atual['dados']['lojas'] ?? null) ? $atual['dados']['lojas'] : [];
        $loja = null;
        foreach ($lojas as $l) {
            if (($l['id'] ?? null) === $idLoja) {
                $loja = $l;
            }
        }
        if ($loja === null) {
            return Resultado::falha('Escolha uma das lojas da lista.');
        }
        $this->integracoes->salvar($this->idRestaurante, 'ifood', [
            'status' => 'conectado',
            'dados' => ['loja_id' => $loja['id'], 'loja_nome' => $loja['nome']],
            'conectado_em' => self::agora(),
            'verificado_em' => self::agora(),
            'ultimo_erro' => null,
        ]);
        $this->log('Integração iFood conectada', 'Loja: ' . $loja['nome'] . '.');
        return Resultado::sucesso('iFood conectado à loja "' . $loja['nome'] . '".');
    }

    public function testar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        $idLoja = $atual['dados']['loja_id'] ?? null;
        if ($atual['status'] === 'desconectado' || !is_string($idLoja)) {
            return Resultado::falha('Conecte o iFood primeiro.');
        }
        try {
            $status = $this->api->statusLoja($this->tokenValido(), $idLoja);
        } catch (IntegracaoErro $erro) {
            $this->integracoes->salvar($this->idRestaurante, 'ifood', ['status' => 'erro', 'ultimo_erro' => $erro->getMessage()]);
            return Resultado::falha($erro->getMessage());
        }
        $this->integracoes->salvar($this->idRestaurante, 'ifood', ['status' => 'conectado', 'ultimo_erro' => null, 'verificado_em' => self::agora()]);
        return Resultado::sucesso('Conexão com o iFood funcionando. ' . $status['mensagem']);
    }

    public function cancelar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        if ($atual['status'] !== 'pendente') {
            return Resultado::falha('Não há vínculo em andamento.');
        }
        $this->integracoes->limpar($this->idRestaurante, 'ifood');
        return Resultado::sucesso('Vínculo cancelado.');
    }

    public function desconectar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        if ($atual['status'] === 'desconectado') {
            return Resultado::falha('O iFood já está desconectado.');
        }
        $this->integracoes->limpar($this->idRestaurante, 'ifood');
        $this->log('Integração iFood desconectada', 'Acesso apagado do ' . marca() . (isset($atual['dados']['loja_nome']) ? ' (loja ' . $atual['dados']['loja_nome'] . ')' : '') . '.');
        return Resultado::sucesso('iFood desconectado. Para remover o ' . marca() . ' de vez, tire a autorização também no Portal do Parceiro.');
    }

    /** Token de acesso valido (renova com o refresh token quando vence). */
    private function tokenValido(): string
    {
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'ifood');
        if (!empty($cred['acesso']) && (int) ($cred['expira'] ?? 0) > time()) {
            return (string) $cred['acesso'];
        }
        if (empty($cred['renovacao'])) {
            throw new IntegracaoErro('O acesso ao iFood venceu. Desconecte e conecte de novo.');
        }
        $tokens = $this->api->renovar((string) $cred['renovacao']);
        $this->integracoes->salvar($this->idRestaurante, 'ifood', ['credenciais' => [
            'acesso' => $tokens['acesso'],
            'renovacao' => $tokens['renovacao'] !== '' ? $tokens['renovacao'] : (string) $cred['renovacao'],
            'expira' => $tokens['expira'],
        ]]);
        return $tokens['acesso'];
    }
}
