<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Shared\Validation\Documento;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Cobranca das assinaturas pelo Asaas DA PLATAFORMA:
 * - gerar: cadastra o restaurante como cliente e cria a assinatura recorrente (ciclo do plano, regras de cobranca);
 * - sincronizar: traz as faturas (link para pagar por Pix, boleto ou cartao);
 * - processarAviso: webhook do Asaas (fatura criada, paga, vencida, apagada, estornada) -> libera o restaurante;
 * - conferirAtraso: fatura em aberto alem da tolerancia -> restaurante "inadimplente" (so o gerente entra, para pagar).
 * MySQL e a fonte de verdade; o Asaas e consultado so para criar/alterar e trazer as faturas.
 */
final class AssinaturaCobranca
{
    /** Status da cobranca no Asaas -> status da fatura no Cardapim. */
    private const STATUS_FATURA = [
        'PENDING' => 'pendente', 'AWAITING_RISK_ANALYSIS' => 'pendente', 'AUTHORIZED' => 'pendente',
        'RECEIVED' => 'paga', 'CONFIRMED' => 'paga', 'RECEIVED_IN_CASH' => 'paga', 'DUNNING_RECEIVED' => 'paga',
        'OVERDUE' => 'vencida', 'DUNNING_REQUESTED' => 'vencida',
        'REFUNDED' => 'estornada', 'REFUND_REQUESTED' => 'estornada', 'CHARGEBACK_REQUESTED' => 'estornada', 'CHARGEBACK_DISPUTE' => 'estornada',
        'DELETED' => 'cancelada',
    ];

    private readonly AssinaturaRepository $assinaturas;

    public function __construct(
        private readonly PDO $db,
        private readonly AsaasApi $api = new AsaasApi(),
        private readonly ?DateTimeImmutable $hoje = null,
    ) {
        $this->assinaturas = new AssinaturaRepository($db);
    }

    /** Conta Asaas da plataforma conectada? @return array{chave:string, ambiente:string}|null */
    public function conta(): ?array
    {
        $repo = new IntegracaoRepository($this->db);
        $integracao = $repo->buscar(null, 'asaas');
        $cred = $repo->credenciais(null, 'asaas');
        if ($integracao['status'] !== 'conectado' || empty($cred['chave']) || !in_array($integracao['ambiente'], ['sandbox', 'producao'], true)) {
            return null;
        }
        return ['chave' => (string) $cred['chave'], 'ambiente' => (string) $integracao['ambiente']];
    }

    /**
     * Cria (ou recria) a cobranca recorrente da assinatura no Asaas. Nunca lanca erro: o motivo fica em cobranca_erro
     * e a tela mostra "Gerar cobranca de novo".
     * @return array{ok:bool, mensagem:string}
     */
    public function gerar(int $idAssinatura, ?string $primeiroVencimento = null): array
    {
        $a = $this->assinaturas->buscar($idAssinatura);
        if ($a === null || (float) $a['valor'] <= 0 || !isset(CobrancaAssinaturas::CICLOS[$a['periodo']])) {
            return ['ok' => true, 'mensagem' => 'Plano sem cobrança.'];
        }
        if ($a['asaas_assinatura_id'] !== null) {
            $this->sincronizar($idAssinatura);
            return ['ok' => true, 'mensagem' => 'A cobrança já existe.'];
        }
        $conta = $this->conta();
        if ($conta === null) {
            $this->assinaturas->salvarAsaas($idAssinatura, null, null, null, 'O Asaas da plataforma não está conectado.');
            return ['ok' => false, 'mensagem' => 'A cobrança ainda não pôde ser gerada: o pagamento online está indisponível no momento.'];
        }
        $restaurante = $this->restaurante((int) $a['id_restaurante']);
        $regras = (new CobrancaAssinaturas($this->db))->regras();
        $cliente = $a['asaas_cliente_id'] ?? $this->assinaturas->clienteAsaas((int) $a['id_restaurante'], $conta['ambiente']);
        try {
            if ($cliente === null) {
                if ($restaurante === null || $restaurante['cnpj'] === null) {
                    throw new IntegracaoErro('O restaurante não tem CNPJ cadastrado.');
                }
                $cliente = $this->api->criarClienteAssinante($conta['chave'], $conta['ambiente'], $restaurante['nome'], $restaurante['cnpj'],
                    $restaurante['email'], $restaurante['celular'], 'restaurante:' . (int) $a['id_restaurante'], (bool) $regras['avisos_asaas']);
            }
            $vencimento = $primeiroVencimento ?? $this->hoje()->modify('+' . max(1, $regras['vencimento_dias']) . ' days')->format('Y-m-d');
            $idAsaas = $this->api->criarAssinatura($conta['chave'], $conta['ambiente'], [
                'customer' => $cliente,
                'billingType' => CobrancaAssinaturas::tipoAsaas($regras['formas']),
                'value' => (float) $a['valor'],
                'nextDueDate' => $vencimento,
                'cycle' => CobrancaAssinaturas::CICLOS[$a['periodo']],
                'description' => mb_substr(marca() . ' - plano ' . $a['plano_nome'] . ' (' . ($restaurante['nome'] ?? '') . ')', 0, 500),
                'externalReference' => 'assinatura:' . $idAssinatura,
                ...CobrancaAssinaturas::encargosAsaas($regras),
            ]);
        } catch (IntegracaoErro $erro) {
            $this->assinaturas->salvarAsaas($idAssinatura, $conta['ambiente'], $cliente, null, $erro->getMessage());
            return ['ok' => false, 'mensagem' => 'A cobrança não pôde ser gerada agora. ' . $erro->getMessage()];
        }
        $this->assinaturas->salvarAsaas($idAssinatura, $conta['ambiente'], $cliente, $idAsaas, null);
        $this->sincronizar($idAssinatura);
        return ['ok' => true, 'mensagem' => 'Cobrança gerada.'];
    }

    /** Traz as faturas da assinatura do Asaas (o aviso tambem traz; aqui e para a tela nao depender dele). */
    public function sincronizar(int $idAssinatura): void
    {
        $a = $this->assinaturas->buscar($idAssinatura);
        $conta = $this->conta();
        if ($a === null || $a['asaas_assinatura_id'] === null || $conta === null || $conta['ambiente'] !== $a['ambiente']) {
            return;
        }
        try {
            $faturas = $this->api->faturasDaAssinatura($conta['chave'], $conta['ambiente'], (string) $a['asaas_assinatura_id']);
        } catch (IntegracaoErro $erro) {
            error_log('[Cardapim] Faturas da assinatura nao sincronizadas: ' . $erro->getMessage());
            return;
        }
        foreach ($faturas as $f) {
            $this->aplicarFatura($a, (string) $a['ambiente'], $f);
        }
    }

    /**
     * Aviso (webhook) do Asaas da plataforma. So eventos de cobranca de assinatura mudam algo aqui.
     * @param array<string,mixed> $dados corpo do aviso
     */
    public function processarAviso(string $evento, array $dados): void
    {
        if (!str_starts_with($evento, 'PAYMENT_') || !is_array($dados['payment'] ?? null)) {
            return;
        }
        $conta = $this->conta();
        $f = AsaasApi::fatura($dados['payment']);
        if ($conta === null || $f === null) {
            return;
        }
        $a = null;
        if ($f['assinatura'] !== null) {
            $a = $this->assinaturas->porAsaas($conta['ambiente'], $f['assinatura']);
        }
        $ref = (string) ($dados['payment']['externalReference'] ?? '');
        if ($a === null && preg_match('/\Aassinatura:(\d{1,18})\z/', $ref, $m) === 1) {
            $a = $this->assinaturas->buscar((int) $m[1]);
        }
        if ($a === null) {
            return; // cobranca avulsa da conta da plataforma: so fica registrada no historico de avisos
        }
        if ($evento === 'PAYMENT_DELETED') {
            $f['status'] = 'DELETED';
        }
        $this->aplicarFatura($a, $conta['ambiente'], $f);
    }

    /**
     * Fatura em aberto alem da tolerancia -> restaurante inadimplente. Chamado ao abrir as telas do restaurante,
     * no cardapio publico e pela rotina diaria (bin/cobranca-diaria.php). @return bool true se esta bloqueado
     */
    public function conferirAtraso(int $idRestaurante): bool
    {
        $status = $this->assinaturas->statusRestaurante($idRestaurante);
        if ($status === 'inadimplente') {
            return true;
        }
        if (!in_array($status, ['ativo', 'pendente'], true)) {
            return false;
        }
        $tolerancia = (new CobrancaAssinaturas($this->db))->regras()['tolerancia_dias'];
        $limite = $this->hoje()->modify('-' . (int) $tolerancia . ' days')->format('Y-m-d');
        if ($this->assinaturas->faturaAtrasada($idRestaurante, $limite) === null) {
            return false;
        }
        return $this->assinaturas->mudarRestaurante($idRestaurante, 'inadimplente', ['ativo', 'pendente']);
    }

    /** Remove a assinatura no Asaas (cancelamento ou troca para plano gratis). Falha vira mensagem, nunca excecao. */
    public function remover(array $a): ?string
    {
        if ($a['asaas_assinatura_id'] === null) {
            return null;
        }
        $conta = $this->conta();
        if ($conta === null || $conta['ambiente'] !== $a['ambiente']) {
            return 'O Asaas da plataforma não está conectado; a cobrança antiga precisa ser cancelada no Asaas.';
        }
        try {
            $this->api->removerAssinatura($conta['chave'], $conta['ambiente'], (string) $a['asaas_assinatura_id']);
        } catch (IntegracaoErro $erro) {
            return $erro->getMessage();
        }
        $this->assinaturas->cancelarFaturasAbertas((int) $a['id_restaurante'], $conta['ambiente'], (string) $a['asaas_assinatura_id']);
        return null;
    }

    /** Novo valor/ciclo na assinatura do Asaas (troca de plano pago -> pago). */
    public function atualizar(array $a, string $valor, string $periodo, string $planoNome): ?string
    {
        $conta = $this->conta();
        if ($a['asaas_assinatura_id'] === null || $conta === null || $conta['ambiente'] !== $a['ambiente']) {
            return 'sem-asaas';
        }
        try {
            $this->api->atualizarAssinatura($conta['chave'], $conta['ambiente'], (string) $a['asaas_assinatura_id'], [
                'value' => (float) $valor,
                'cycle' => CobrancaAssinaturas::CICLOS[$periodo],
                'description' => mb_substr(marca() . ' - plano ' . $planoNome, 0, 500),
                'updatePendingPayments' => true,
            ]);
        } catch (IntegracaoErro $erro) {
            return $erro->getMessage();
        }
        return null;
    }

    /**
     * Grava a fatura e, se foi paga agora, libera o restaurante e estende o periodo pago.
     * @param array<string,mixed> $a assinatura
     * @param array{id:string,status:string,valor:string,vencimento:string,forma:string,link_fatura:?string,link_boleto:?string,pago_em:?string,assinatura:?string} $f
     */
    private function aplicarFatura(array $a, string $ambiente, array $f): void
    {
        $status = self::STATUS_FATURA[$f['status']] ?? 'pendente';
        $idRestaurante = (int) $a['id_restaurante'];
        $this->db->beginTransaction();
        try {
            $salvo = $this->assinaturas->salvarFatura($idRestaurante, (int) $a['id'], $ambiente, $f, $status);
            if ($status === 'paga' && $salvo['status_antes'] !== 'paga') {
                $meses = AssinaturaService::MESES[$a['periodo']] ?? 1;
                $vencimento = DateTimeImmutable::createFromFormat('!Y-m-d', $f['vencimento']) ?: $this->hoje();
                $this->assinaturas->marcarPaga((int) $a['id'], AssinaturaService::somarMeses($vencimento, $meses)->format('Y-m-d'));
                $this->assinaturas->registrarPagamentoRestaurante($idRestaurante);
                $tolerancia = (new CobrancaAssinaturas($this->db))->regras()['tolerancia_dias'];
                $limite = $this->hoje()->modify('-' . (int) $tolerancia . ' days')->format('Y-m-d');
                if ($this->assinaturas->faturaAtrasada($idRestaurante, $limite) === null) {
                    $this->assinaturas->mudarRestaurante($idRestaurante, 'ativo', ['pendente', 'inadimplente']);
                }
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return array{nome:string, cnpj:?string, email:string, celular:string}|null */
    private function restaurante(int $id): ?array
    {
        $q = $this->db->prepare('SELECT r.nome, r.cnpj, r.email, r.telefone,
            (SELECT u.email FROM `user` u WHERE u.id_restaurante = r.id AND u.cargo = \'gerente\' AND u.ativo = 1 ORDER BY u.id LIMIT 1) AS email_gerente
            FROM restaurantes r WHERE r.id = ?');
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $email = (string) ($r['email'] ?: $r['email_gerente'] ?: '');
        $celular = Documento::normalizarTelefone((string) ($r['telefone'] ?? '')) ?? '';
        return [
            'nome' => (string) $r['nome'],
            'cnpj' => $r['cnpj'] !== null && Documento::cnpjValido((string) $r['cnpj']) ? (string) $r['cnpj'] : null,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'celular' => strlen($celular) === 11 ? $celular : '',
        ];
    }

    private function hoje(): DateTimeImmutable
    {
        return ($this->hoje ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }
}
