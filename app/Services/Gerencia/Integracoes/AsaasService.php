<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia\Integracoes;

use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Asaas: cada restaurante conecta a PROPRIA conta para receber Pix dos pedidos online.
 * Na plataforma (idRestaurante null) a conta recebe as ASSINATURAS dos restaurantes: avisos tambem dos eventos de
 * assinatura e checklist da conta (aprovacao, chave Pix e fila de avisos).
 */
final class AsaasService extends BaseIntegracao
{
    public function __construct(PDO $db, UsuarioLogado $autor, ?int $idRestaurante, private readonly AsaasApi $api = new AsaasApi())
    {
        parent::__construct($db, $autor, $idRestaurante);
    }

    public function conectar(string $ambiente, string $chave, string $emailAvisos): Resultado
    {
        $chave = trim($chave);
        $erros = [];
        if (!in_array($ambiente, ['producao', 'sandbox'], true)) {
            $erros['ambiente'] = 'Escolha se é a conta real (Produção) ou de testes (Sandbox).';
        }
        if (strlen($chave) < 20 || strlen($chave) > 400 || preg_match('/\s/', $chave) === 1) {
            $erros['chave'] = 'Cole a chave de API completa, sem espaços. No Asaas: Integrações > Chaves de API.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        try {
            $saldo = $this->api->saldo($chave, $ambiente);
            $conta = $this->api->conta($chave, $ambiente);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage(), ['chave' => $erro->getMessage()]);
        }

        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        $antigas = $this->integracoes->credenciais($this->idRestaurante, 'asaas');
        $this->removerWebhookAntigo($antigas, $atual);
        $this->integracoes->salvar($this->idRestaurante, 'asaas', [
            'status' => 'conectado',
            'ambiente' => $ambiente,
            'credenciais' => ['chave' => $chave, 'token_webhook' => bin2hex(random_bytes(24))],
            'dados' => ['conta_nome' => $conta['nome'], 'conta_email' => $conta['email'], 'chave_final' => self::finalDaChave($chave), 'email_avisos' => $emailAvisos],
            'webhook_id' => bin2hex(random_bytes(16)),
            'ultimo_erro' => null,
            'conectado_em' => self::agora(),
            'verificado_em' => self::agora(),
        ]);
        $this->log('Integração Asaas conectada', sprintf('Ambiente: %s. Conta: %s. Chave %s.', $ambiente === 'producao' ? 'Produção' : 'Sandbox', $conta['nome'] ?: 'sem nome informado', self::finalDaChave($chave)));

        $webhook = $this->configurarWebhook();
        if ($this->idRestaurante === null) {
            $this->verificarSaude();
        }
        return Resultado::sucesso('Conta Asaas conectada' . ($conta['nome'] !== '' ? ' (' . $conta['nome'] . ')' : '') . '. Saldo atual: ' . Numero::reais($saldo) . '. '
            . ($webhook->ok ? 'Os avisos de pagamento já estão configurados.' : 'Falta configurar os avisos de pagamento: veja abaixo.'));
    }

    /** Cria no Asaas o webhook que avisa o Cardapim quando um pagamento muda. */
    public function configurarWebhook(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'asaas');
        if ($atual['status'] !== 'conectado' || empty($cred['chave']) || empty($cred['token_webhook']) || $atual['webhook_id'] === null) {
            return Resultado::falha('Conecte a conta Asaas primeiro.');
        }
        $url = self::urlPublica('/webhooks/asaas/' . $atual['webhook_id']);
        if (!str_starts_with($url, 'https://')) {
            return Resultado::falha('O endereço do Cardapim precisa ser HTTPS para receber avisos do Asaas.');
        }
        $email = filter_var($atual['dados']['email_avisos'] ?? '', FILTER_VALIDATE_EMAIL) ? (string) $atual['dados']['email_avisos'] : (string) ($atual['dados']['conta_email'] ?? '');
        try {
            $this->removerWebhookAntigo($cred, $atual);
            $id = $this->api->criarWebhook((string) $cred['chave'], (string) $atual['ambiente'], $url, (string) $cred['token_webhook'], $email, $this->eventos());
        } catch (IntegracaoErro $erro) {
            $this->integracoes->salvar($this->idRestaurante, 'asaas', ['ultimo_erro' => $erro->getMessage(), 'dados' => [...$atual['dados'], 'webhook_asaas_id' => null]]);
            return Resultado::falha('Não foi possível configurar os avisos automaticamente. ' . $erro->getMessage());
        }
        $this->integracoes->salvar($this->idRestaurante, 'asaas', [
            'ultimo_erro' => null,
            'dados' => [...$atual['dados'], 'webhook_asaas_id' => $id, 'webhook_configurado_em' => self::agora()],
        ]);
        $this->log('Integração Asaas: avisos configurados', 'Webhook criado no Asaas para os eventos de pagamento' . ($this->idRestaurante === null ? ' e de assinatura.' : '.'));
        if ($this->idRestaurante === null) {
            $this->verificarSaude();
        }
        return Resultado::sucesso('Pronto! O Asaas vai avisar o Cardapim sempre que um pagamento' . ($this->idRestaurante === null ? ' ou uma assinatura' : '') . ' mudar.');
    }

    public function testar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'asaas');
        if (empty($cred['chave'])) {
            return Resultado::falha('Conecte a conta Asaas primeiro.');
        }
        try {
            $saldo = $this->api->saldo((string) $cred['chave'], (string) $atual['ambiente']);
        } catch (IntegracaoErro $erro) {
            $this->integracoes->salvar($this->idRestaurante, 'asaas', ['status' => 'erro', 'ultimo_erro' => $erro->getMessage()]);
            return Resultado::falha($erro->getMessage());
        }
        $this->integracoes->salvar($this->idRestaurante, 'asaas', ['status' => 'conectado', 'ultimo_erro' => null, 'verificado_em' => self::agora()]);
        if ($this->idRestaurante === null) {
            $saude = $this->verificarSaude();
            $pendentes = count(array_filter($saude['itens'], static fn (array $i): bool => $i['situacao'] === 'erro'));
            return Resultado::sucesso('Conexão com o Asaas funcionando. Saldo atual: ' . Numero::reais($saldo) . '. '
                . ($pendentes === 0 ? 'Tudo pronto para cobrar as assinaturas.' : ($pendentes === 1 ? 'Falta 1 item na lista abaixo.' : 'Faltam ' . $pendentes . ' itens na lista abaixo.')));
        }
        return Resultado::sucesso('Conexão com o Asaas funcionando. Saldo atual: ' . Numero::reais($saldo) . '.');
    }

    /**
     * Checklist da conta da plataforma: aprovacao do Asaas, chave Pix e avisos (webhook ativo, fila nao pausada e com os
     * eventos de assinatura). Guardado em dados.saude para a tela abrir rapido (sem chamar o Asaas a cada visita).
     * @return array{verificado_em:string, itens:list<array{chave:string, situacao:string, titulo:string, texto:string}>}
     */
    public function verificarSaude(): array
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'asaas');
        $chave = (string) ($cred['chave'] ?? '');
        $amb = (string) $atual['ambiente'];
        $itens = [];
        try {
            $st = $this->api->statusConta($chave, $amb);
            $nomes = ['dados_comerciais' => 'dados da empresa', 'conta_bancaria' => 'conta bancária', 'documentos' => 'documentos'];
            $faltam = [];
            foreach ($nomes as $k => $rotulo) {
                if ($st[$k] !== 'APPROVED') {
                    $faltam[] = $rotulo . ($st[$k] === 'AWAITING_APPROVAL' ? ' (em análise)' : ($st[$k] === 'REJECTED' ? ' (recusado)' : ' (pendente)'));
                }
            }
            $itens[] = $st['geral'] === 'APPROVED'
                ? ['chave' => 'aprovacao', 'situacao' => 'ok', 'titulo' => 'Conta aprovada pelo Asaas', 'texto' => 'Pode emitir boletos e Pix.']
                : ['chave' => 'aprovacao', 'situacao' => $st['geral'] === 'AWAITING_APPROVAL' ? 'aviso' : 'erro', 'titulo' => 'Conta ainda não aprovada',
                    'texto' => 'No Asaas, complete: ' . ($faltam !== [] ? implode(', ', $faltam) : 'o cadastro') . '.'];
        } catch (IntegracaoErro) {
            $itens[] = ['chave' => 'aprovacao', 'situacao' => 'aviso', 'titulo' => 'Não deu para ver a aprovação da conta', 'texto' => 'Confira no Asaas em Minha conta.'];
        }
        try {
            $pix = $this->api->chavesPixAtivas($chave, $amb);
            $itens[] = $pix > 0
                ? ['chave' => 'pix', 'situacao' => 'ok', 'titulo' => 'Chave Pix cadastrada', 'texto' => $pix === 1 ? '1 chave ativa.' : $pix . ' chaves ativas.']
                : ['chave' => 'pix', 'situacao' => 'aviso', 'titulo' => 'Sem chave Pix no Asaas', 'texto' => 'O Pix funciona pelo QR do Asaas, mas cadastre uma chave em Pix > Minhas chaves.'];
        } catch (IntegracaoErro) {
            $itens[] = ['chave' => 'pix', 'situacao' => 'aviso', 'titulo' => 'Não deu para ver as chaves Pix', 'texto' => 'Confira no Asaas em Pix > Minhas chaves.'];
        }
        $idWebhook = (string) ($atual['dados']['webhook_asaas_id'] ?? '');
        if ($idWebhook === '') {
            $itens[] = ['chave' => 'avisos', 'situacao' => 'erro', 'titulo' => 'Avisos não configurados', 'texto' => 'Toque em "Configurar automaticamente" em Avisos.'];
        } else {
            try {
                $w = $this->api->webhook($chave, $amb, $idWebhook);
                $faltando = $w === null ? $this->eventos() : array_diff($this->eventos(), $w['eventos']);
                $itens[] = match (true) {
                    $w === null => ['chave' => 'avisos', 'situacao' => 'erro', 'titulo' => 'Avisos apagados no Asaas', 'texto' => 'Toque em "Configurar de novo" em Avisos.'],
                    $w['interrompido'] || !$w['ativo'] => ['chave' => 'avisos', 'situacao' => 'erro', 'titulo' => 'Avisos pausados pelo Asaas', 'texto' => 'O Asaas parou de enviar depois de falhas. Toque em "Reativar avisos".'],
                    $faltando !== [] => ['chave' => 'avisos', 'situacao' => 'erro', 'titulo' => 'Faltam avisos de assinatura', 'texto' => 'Toque em "Configurar de novo" em Avisos.'],
                    default => ['chave' => 'avisos', 'situacao' => 'ok', 'titulo' => 'Avisos ativos', 'texto' => 'Pagamentos e assinaturas chegam na hora.'],
                };
            } catch (IntegracaoErro) {
                $itens[] = ['chave' => 'avisos', 'situacao' => 'aviso', 'titulo' => 'Não deu para ver os avisos', 'texto' => 'Toque em "Testar conexão" de novo em instantes.'];
            }
        }
        $saude = ['verificado_em' => self::agora(), 'itens' => $itens];
        $this->integracoes->salvar($this->idRestaurante, 'asaas', ['dados' => [...$atual['dados'], 'saude' => $saude]]);
        return $saude;
    }

    /** Fila de avisos pausada pelo Asaas: reativa e confere de novo. */
    public function reativarAvisos(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'asaas');
        $id = (string) ($atual['dados']['webhook_asaas_id'] ?? '');
        if (empty($cred['chave']) || $id === '') {
            return Resultado::falha('Configure os avisos primeiro.');
        }
        try {
            $this->api->reativarWebhook((string) $cred['chave'], (string) $atual['ambiente'], $id);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $this->log('Integração Asaas: avisos reativados', 'Fila de avisos do Asaas reativada.');
        if ($this->idRestaurante === null) {
            $this->verificarSaude();
        }
        return Resultado::sucesso('Avisos reativados. O Asaas volta a enviar o que ficou na fila.');
    }

    /** @return list<string> eventos do webhook conforme o escopo */
    private function eventos(): array
    {
        return $this->idRestaurante === null ? AsaasApi::EVENTOS_WEBHOOK_PLATAFORMA : AsaasApi::EVENTOS_WEBHOOK;
    }

    public function desconectar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        if ($atual['status'] === 'desconectado') {
            return Resultado::falha('O Asaas já está desconectado.');
        }
        $this->removerWebhookAntigo($this->integracoes->credenciais($this->idRestaurante, 'asaas'), $atual);
        $this->integracoes->limpar($this->idRestaurante, 'asaas');
        $this->log('Integração Asaas desconectada', 'Chave de API e token dos avisos apagados do Cardapim.');
        return Resultado::sucesso('Asaas desconectado. A chave foi apagada do Cardapim.');
    }

    /** @param array<string,mixed> $cred @param array<string,mixed> $atual */
    private function removerWebhookAntigo(array $cred, array $atual): void
    {
        $id = $atual['dados']['webhook_asaas_id'] ?? null;
        if (!is_string($id) || $id === '' || empty($cred['chave']) || empty($atual['ambiente'])) {
            return;
        }
        try {
            $this->api->removerWebhook((string) $cred['chave'], (string) $atual['ambiente'], $id);
        } catch (IntegracaoErro) {
            // Se o Asaas nao responder, o webhook antigo continua la, mas o token dele deixa de valer aqui.
        }
    }
}
