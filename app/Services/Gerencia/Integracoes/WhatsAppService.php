<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia\Integracoes;

use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Validation\Documento;
use PDO;

/**
 * WhatsApp do restaurante via WPPConnect: uma sessao por restaurante, conectada lendo o QR Code
 * no celular (como o WhatsApp Web). Permite mandar uma mensagem de teste.
 */
final class WhatsAppService extends BaseIntegracao
{
    public const TESTES_POR_HORA = 10;

    public function __construct(PDO $db, UsuarioLogado $autor, int $idRestaurante, private readonly WppConnectApi $api = new WppConnectApi())
    {
        parent::__construct($db, $autor, $idRestaurante);
    }

    /** Cria a sessao (se preciso) e pede um QR Code novo. */
    public function conectar(): Resultado
    {
        if (!WppConnectApi::disponivel()) {
            return Resultado::falha('O WhatsApp ainda não está disponível neste servidor. Avise o suporte do Cardapim.');
        }
        $atual = $this->integracoes->buscar($this->idRestaurante, 'whatsapp');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'whatsapp');
        $sessao = is_string($atual['dados']['sessao'] ?? null) ? $atual['dados']['sessao'] : null;
        try {
            if ($sessao === null || empty($cred['token'])) {
                $sessao = 'cardapim_r' . $this->idRestaurante . '_' . bin2hex(random_bytes(4));
                $cred = ['token' => $this->api->gerarToken($sessao)];
            }
            $this->api->iniciar($sessao, (string) $cred['token']);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $novo = $atual['status'] === 'desconectado';
        $this->integracoes->salvar($this->idRestaurante, 'whatsapp', [
            'status' => $atual['status'] === 'conectado' ? 'conectado' : 'pendente',
            'credenciais' => $cred,
            'dados' => [...$atual['dados'], 'sessao' => $sessao],
            'ultimo_erro' => null,
        ]);
        if ($novo) {
            $this->log('WhatsApp: conexão iniciada', 'QR Code gerado para conectar o celular.');
        }
        return Resultado::sucesso('Abra o WhatsApp no celular e leia o QR Code.');
    }

    /**
     * Situacao atual (consultada pela tela a cada poucos segundos).
     * @return array{estado:string, qrcode:?string, numero:?string, mensagem:string}
     *         estado: conectado | qrcode | iniciando | expirado | desconectado | indisponivel | erro
     */
    public function estado(): array
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'whatsapp');
        $numero = is_string($atual['dados']['numero'] ?? null) ? $atual['dados']['numero'] : null;
        if (!WppConnectApi::disponivel()) {
            return ['estado' => 'indisponivel', 'qrcode' => null, 'numero' => null, 'mensagem' => 'O WhatsApp ainda não está disponível neste servidor.'];
        }
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'whatsapp');
        $sessao = $atual['dados']['sessao'] ?? null;
        if ($atual['status'] === 'desconectado' || !is_string($sessao) || empty($cred['token'])) {
            return ['estado' => 'desconectado', 'qrcode' => null, 'numero' => null, 'mensagem' => 'WhatsApp não conectado.'];
        }
        try {
            $e = $this->api->estado($sessao, (string) $cred['token']);
        } catch (IntegracaoErro $erro) {
            return ['estado' => 'erro', 'qrcode' => null, 'numero' => $numero, 'mensagem' => $erro->getMessage()];
        }
        if ($e['status'] === 'CONNECTED' || $e['status'] === 'INCHAT' || $e['status'] === 'ISLOGGED') {
            if ($atual['status'] !== 'conectado' || $numero === null) {
                $numero = $this->api->numero($sessao, (string) $cred['token']) ?? $numero;
                $this->integracoes->salvar($this->idRestaurante, 'whatsapp', [
                    'status' => 'conectado',
                    'dados' => [...$atual['dados'], 'numero' => $numero],
                    'ultimo_erro' => null,
                    'conectado_em' => $atual['status'] === 'conectado' ? $atual['conectado_em'] : self::agora(),
                    'verificado_em' => self::agora(),
                ]);
                if ($atual['status'] !== 'conectado') {
                    $this->log('WhatsApp conectado', 'Celular conectado' . ($numero !== null ? ' (final ' . substr($numero, -4) . ')' : '') . '.');
                }
            }
            return ['estado' => 'conectado', 'qrcode' => null, 'numero' => $numero, 'mensagem' => 'WhatsApp conectado.'];
        }
        if ($e['status'] === 'QRCODE' && $e['qrcode'] !== null) {
            return ['estado' => 'qrcode', 'qrcode' => $e['qrcode'], 'numero' => null, 'mensagem' => 'Leia o QR Code com o celular.'];
        }
        if (in_array($e['status'], ['CLOSED', 'NOTLOGGED', 'DESCONNECTEDMOBILE', 'DISCONNECTED', 'BROWSERCLOSE', 'QRREADFAIL'], true)) {
            if ($atual['status'] === 'conectado') {
                $this->integracoes->salvar($this->idRestaurante, 'whatsapp', ['status' => 'erro', 'ultimo_erro' => 'O WhatsApp foi desconectado do celular.']);
                $this->log('WhatsApp desconectado pelo celular', 'A sessão caiu ou foi encerrada no aparelho.');
                return ['estado' => 'erro', 'qrcode' => null, 'numero' => $numero, 'mensagem' => 'O WhatsApp foi desconectado do celular. Conecte de novo.'];
            }
            return ['estado' => 'expirado', 'qrcode' => null, 'numero' => null, 'mensagem' => 'O QR Code venceu. Gere um novo.'];
        }
        return ['estado' => 'iniciando', 'qrcode' => null, 'numero' => null, 'mensagem' => 'Preparando o WhatsApp...'];
    }

    public function enviarTeste(string $telefone, string $mensagem): Resultado
    {
        $erros = [];
        $digitos = Documento::normalizarTelefone($telefone);
        if ($digitos === null) {
            $erros['telefone'] = 'Digite o número com DDD. Exemplo: (85) 99999-0000';
        }
        $mensagem = trim(str_replace("\r\n", "\n", $mensagem));
        if (mb_strlen($mensagem) < 1 || mb_strlen($mensagem) > 1000) {
            $erros['mensagem'] = 'Escreva a mensagem (até 1000 caracteres).';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $atual = $this->integracoes->buscar($this->idRestaurante, 'whatsapp');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'whatsapp');
        $sessao = $atual['dados']['sessao'] ?? null;
        if ($atual['status'] !== 'conectado' || !is_string($sessao) || empty($cred['token'])) {
            return Resultado::falha('Conecte o WhatsApp antes de enviar a mensagem de teste.');
        }
        $limite = RateLimiter::padrao(self::TESTES_POR_HORA, 3600);
        $chave = 'wpp-teste|' . $this->idRestaurante;
        if ($limite->excedido($chave)) {
            return Resultado::falha('Você já enviou muitas mensagens de teste nesta hora. Tente mais tarde.');
        }
        try {
            $this->api->enviarTexto($sessao, (string) $cred['token'], '55' . $digitos, $mensagem);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $limite->permitir($chave);
        $this->log('WhatsApp: mensagem de teste enviada', 'Para o número com final ' . substr((string) $digitos, -4) . ' (' . mb_strlen($mensagem) . ' caracteres).');
        return Resultado::sucesso('Mensagem enviada para ' . Documento::formatarTelefone((string) $digitos) . '. Confira no celular.');
    }

    public function desconectar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'whatsapp');
        if ($atual['status'] === 'desconectado') {
            return Resultado::falha('O WhatsApp já está desconectado.');
        }
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'whatsapp');
        $sessao = $atual['dados']['sessao'] ?? null;
        if (is_string($sessao) && !empty($cred['token']) && WppConnectApi::disponivel()) {
            $this->api->sair($sessao, (string) $cred['token']);
        }
        $this->integracoes->limpar($this->idRestaurante, 'whatsapp');
        $this->log('WhatsApp desconectado', 'Sessão encerrada pelo painel.');
        return Resultado::sucesso('WhatsApp desconectado. O celular saiu do Cardapim.');
    }
}
