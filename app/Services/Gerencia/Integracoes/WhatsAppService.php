<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia\Integracoes;

use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\ProvedorWhatsApp;
use Cardapim\Modules\Integracoes\RecuperacaoDeSessao;
use Cardapim\Modules\Integracoes\WebhookDeMensagens;
use Cardapim\Modules\Integracoes\WhatsAppProvedores;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Validation\Documento;
use PDO;

/**
 * WhatsApp do restaurante: uma sessao por restaurante, conectada lendo o QR Code no celular
 * (como o WhatsApp Web). Permite mandar uma mensagem de teste.
 * Servidor: o gravado em dados.provedor (waha ou evolution; conexoes novas usam WhatsAppProvedores::padrao).
 * $api injetado (testes) vale para tudo.
 */
final class WhatsAppService extends BaseIntegracao
{
    public const TESTES_POR_HORA = 10;
    public const CODIGOS_POR_10_MIN = 5;

    public function __construct(PDO $db, UsuarioLogado $autor, ?int $idRestaurante, private readonly ?ProvedorWhatsApp $api = null)
    {
        parent::__construct($db, $autor, $idRestaurante);
    }

    /** Cria a sessao (se preciso) e pede um QR Code novo. */
    public function conectar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'whatsapp');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'whatsapp');
        $sessao = is_string($atual['dados']['sessao'] ?? null) ? $atual['dados']['sessao'] : null;
        $nova = $sessao === null || empty($cred['token']);
        $api = $nova ? ($this->api ?? WhatsAppProvedores::padrao()) : $this->provedor($atual['dados']);
        if (!$api->configurado()) {
            return Resultado::falha('O WhatsApp ainda não está disponível neste servidor. Avise o suporte do ' . marca() . '.');
        }
        try {
            if ($nova) {
                $sessao = WhatsAppProvedores::novaSessao($this->daPlataforma() ? null : $this->idRestaurante);
                $cred = ['token' => $api->criarSessao($sessao)];
            }
            $api->iniciar($sessao, (string) $cred['token']);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $novo = $atual['status'] === 'desconectado';
        $this->integracoes->salvar($this->idRestaurante, 'whatsapp', [
            'status' => $atual['status'] === 'conectado' ? 'conectado' : 'pendente',
            'credenciais' => $cred,
            'dados' => [...$atual['dados'], 'sessao' => $sessao, 'provedor' => $api->id()],
            'ultimo_erro' => null,
        ]);
        if ($novo) {
            $this->log('WhatsApp: conexão iniciada', 'QR Code gerado para conectar o celular.');
        }
        return Resultado::sucesso('Abra o WhatsApp no celular e leia o QR Code.');
    }

    /**
     * Situacao atual (consultada pela tela a cada poucos segundos).
     * @return array{estado:string, qrcode:?string, numero:?string, mensagem:string, codigo?:bool}
     *         estado: conectado | qrcode | iniciando | passkey | expirado | desconectado | indisponivel | erro
     *         codigo: no estado qrcode, se o servidor permite conectar digitando um codigo no celular
     */
    public function estado(): array
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'whatsapp');
        $numero = is_string($atual['dados']['numero'] ?? null) ? $atual['dados']['numero'] : null;
        $api = $this->provedor($atual['dados']);
        if (!$api->configurado()) {
            return ['estado' => 'indisponivel', 'qrcode' => null, 'numero' => null, 'mensagem' => 'O WhatsApp ainda não está disponível neste servidor.'];
        }
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'whatsapp');
        $sessao = $atual['dados']['sessao'] ?? null;
        if ($atual['status'] === 'desconectado' || !is_string($sessao) || empty($cred['token'])) {
            return ['estado' => 'desconectado', 'qrcode' => null, 'numero' => null, 'mensagem' => 'WhatsApp não conectado.'];
        }
        try {
            $e = $api->estado($sessao, (string) $cred['token']);
        } catch (IntegracaoErro $erro) {
            return ['estado' => 'erro', 'qrcode' => null, 'numero' => $numero, 'mensagem' => $erro->getMessage()];
        }
        if ($e['status'] === 'CONNECTED') {
            if ($atual['status'] !== 'conectado' || $numero === null) {
                $numero = $api->numero($sessao, (string) $cred['token']) ?? $numero;
                $this->integracoes->salvar($this->idRestaurante, 'whatsapp', [
                    'status' => 'conectado',
                    'dados' => [...$atual['dados'], 'numero' => $numero],
                    'ultimo_erro' => null,
                    'conectado_em' => $atual['status'] === 'conectado' ? $atual['conectado_em'] : self::agora(),
                    'verificado_em' => self::agora(),
                ]);
                if ($atual['status'] !== 'conectado') {
                    // Garante que as mensagens recebidas chegam ao sistema (boas-vindas), mesmo em instancia antiga.
                    // So grava de novo quando esta faltando (no WAHA, gravar reinicia a sessao).
                    if ($api instanceof WebhookDeMensagens && $api->webhookConfigurado() && !$this->daPlataforma()) {
                        try {
                            if ($api->situacaoWebhook($sessao, (string) $cred['token']) === 'errado') {
                                $api->configurarWebhook($sessao, (string) $cred['token']);
                            }
                        } catch (IntegracaoErro) {
                            // a tela de WhatsApp mostra a situacao e o botao para corrigir
                        }
                    }
                    $this->log('WhatsApp conectado', 'Celular conectado' . ($numero !== null ? ' (final ' . substr($numero, -4) . ')' : '') . '.');
                    $this->avisarTela('whatsapp', 'integracao.whatsapp.estado', ['estado' => 'conectado']);
                }
            }
            return ['estado' => 'conectado', 'qrcode' => null, 'numero' => $numero, 'mensagem' => 'WhatsApp conectado.'];
        }
        if ($e['status'] === 'QRCODE' && $e['qrcode'] !== null) {
            return ['estado' => 'qrcode', 'qrcode' => $e['qrcode'], 'numero' => null, 'mensagem' => 'Leia o QR Code com o celular.',
                'codigo' => $api instanceof RecuperacaoDeSessao];
        }
        if ($e['status'] === 'PASSKEY') {
            return ['estado' => 'passkey', 'qrcode' => null, 'numero' => $numero,
                'mensagem' => 'O WhatsApp pediu uma confirmação no celular. Abra o WhatsApp no aparelho da loja e siga o aviso na tela (pode pedir a senha, a digital ou o rosto do aparelho).'];
        }
        if ($e['status'] === 'CLOSED') {
            if ($atual['status'] === 'conectado') {
                $this->integracoes->salvar($this->idRestaurante, 'whatsapp', ['status' => 'erro', 'ultimo_erro' => 'O WhatsApp foi desconectado do celular.']);
                $this->log('WhatsApp desconectado pelo celular', 'A sessão caiu ou foi encerrada no aparelho.');
                $this->avisarTela('whatsapp', 'integracao.whatsapp.estado', ['estado' => 'erro']);
                return ['estado' => 'erro', 'qrcode' => null, 'numero' => $numero, 'mensagem' => 'O WhatsApp foi desconectado do celular. Conecte de novo.'];
            }
            if ($atual['status'] === 'erro') { // o vigia ja marcou o problema; mostra o motivo e o botao de conectar de novo
                return ['estado' => 'erro', 'qrcode' => null, 'numero' => $numero, 'mensagem' => $atual['ultimo_erro'] ?? 'O WhatsApp foi desconectado do celular. Conecte de novo.'];
            }
            return ['estado' => 'expirado', 'qrcode' => null, 'numero' => null, 'mensagem' => 'O QR Code venceu. Gere um novo.'];
        }
        return ['estado' => 'iniciando', 'qrcode' => null, 'numero' => null, 'mensagem' => 'Preparando o WhatsApp...'];
    }

    /**
     * Codigo para conectar digitando no celular (WhatsApp > Aparelhos conectados > Conectar com numero de telefone),
     * para quando a camera nao consegue ler o QR Code.
     * @return array{ok:bool, mensagem:string, codigo?:string, erros?:array<string,string>}
     */
    public function pedirCodigo(string $telefone): array
    {
        $digitos = Documento::normalizarTelefone($telefone);
        if ($digitos === null) {
            return ['ok' => false, 'mensagem' => 'Confira o número.', 'erros' => ['telefone' => 'Digite o número do WhatsApp da loja com DDD. Exemplo: (85) 99999-0000']];
        }
        $atual = $this->integracoes->buscar($this->idRestaurante, 'whatsapp');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'whatsapp');
        $sessao = $atual['dados']['sessao'] ?? null;
        if ($atual['status'] === 'desconectado' || !is_string($sessao) || empty($cred['token'])) {
            return ['ok' => false, 'mensagem' => 'Clique em "Conectar WhatsApp" antes de pedir o código.'];
        }
        $api = $this->provedor($atual['dados']);
        if (!$api instanceof RecuperacaoDeSessao || !$api->configurado()) {
            return ['ok' => false, 'mensagem' => 'Este servidor de WhatsApp só conecta pelo QR Code.'];
        }
        $limite = RateLimiter::padrao(self::CODIGOS_POR_10_MIN, 600);
        $chave = 'wpp-codigo|' . ($this->idRestaurante ?? 'plataforma');
        if ($limite->excedido($chave)) {
            return ['ok' => false, 'mensagem' => 'Você pediu muitos códigos seguidos. Espere 10 minutos e tente de novo.'];
        }
        $limite->permitir($chave);
        try {
            $codigo = $api->pedirCodigo($sessao, (string) $cred['token'], '55' . $digitos);
        } catch (IntegracaoErro $erro) {
            return ['ok' => false, 'mensagem' => $erro->getMessage()];
        }
        $this->log('WhatsApp: código de conexão gerado', 'Para o celular com final ' . substr((string) $digitos, -4) . '.');
        return ['ok' => true, 'mensagem' => 'Digite este código no celular.', 'codigo' => $codigo];
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
        $chave = 'wpp-teste|' . ($this->idRestaurante ?? 'plataforma');
        if ($limite->excedido($chave)) {
            return Resultado::falha('Você já enviou muitas mensagens de teste nesta hora. Tente mais tarde.');
        }
        try {
            $this->provedor($atual['dados'])->enviarTexto($sessao, (string) $cred['token'], '55' . $digitos, $mensagem);
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
        $saiu = true;
        $api = $this->provedor($atual['dados']);
        if (is_string($sessao) && !empty($cred['token']) && $api->configurado()) {
            $saiu = $api->sair($sessao, (string) $cred['token']);
        }
        $this->integracoes->limpar($this->idRestaurante, 'whatsapp');
        // Servidor nao confirmou: a sessao pode ter ficado aberta la. bin/whatsapp-sessoes.php --limpar (cron) fecha depois.
        $this->log('WhatsApp desconectado', $saiu ? 'Sessão encerrada pelo painel.'
            : 'Sessão encerrada pelo painel. O servidor do WhatsApp não confirmou; a limpeza automática fecha o que sobrou.');
        $this->avisarTela('whatsapp', 'integracao.whatsapp.estado', ['estado' => 'desconectado']);
        return Resultado::sucesso('WhatsApp desconectado. O celular saiu do ' . marca() . '.');
    }

    /**
     * Recebimento de mensagens (webhook) da instancia da loja: 'ok', 'errado', 'desconhecido' ou null (nao se aplica:
     * WhatsApp nao conectado ou servidor sem webhook).
     */
    public function situacaoWebhook(): ?string
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'whatsapp');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'whatsapp');
        $sessao = $atual['dados']['sessao'] ?? null;
        $api = $this->provedor($atual['dados']);
        if (!$api instanceof WebhookDeMensagens || !$api->configurado() || !$api->webhookConfigurado()
            || !in_array($atual['status'], ['conectado', 'erro'], true) || !is_string($sessao) || empty($cred['token'])) {
            return null;
        }
        return $api->situacaoWebhook($sessao, (string) $cred['token']);
    }

    /** Aponta o recebimento de mensagens da instancia da loja para o sistema. */
    public function corrigirWebhook(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'whatsapp');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'whatsapp');
        $sessao = $atual['dados']['sessao'] ?? null;
        $api = $this->provedor($atual['dados']);
        if (!in_array($atual['status'], ['conectado', 'erro'], true) || !is_string($sessao) || empty($cred['token'])) {
            return Resultado::falha('Conecte o WhatsApp antes.');
        }
        if (!$api instanceof WebhookDeMensagens) {
            return Resultado::falha('Este servidor de WhatsApp não permite o ajuste pelo painel.');
        }
        try {
            $api->configurarWebhook($sessao, (string) $cred['token']);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $this->log('WhatsApp: recebimento de mensagens ajustado', 'Webhook da sessão apontado para o sistema (boas-vindas).');
        return Resultado::sucesso('Recebimento de mensagens ajustado. Peça para alguém mandar uma mensagem para a loja e confira abaixo.');
    }

    /** @param array<string,mixed> $dados */
    private function provedor(array $dados): ProvedorWhatsApp
    {
        return $this->api ?? WhatsAppProvedores::daIntegracao($dados);
    }
}
