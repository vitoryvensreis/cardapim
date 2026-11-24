<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Core\Config;
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
        'REFUNDED' => 'estornada', 'CHARGEBACK_REQUESTED' => 'estornada', 'CHARGEBACK_DISPUTE' => 'estornada',
        'AWAITING_CHARGEBACK_REVERSAL' => 'estornada',
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

    /**
     * Retorno da fatura hospedada pelo Asaas. A origem vem da configuracao confiavel,
     * nunca do Host da requisicao. Em desenvolvimento HTTP, omite o callback.
     * @return array<string, mixed>
     */
    public static function callbackRetorno(string $base, string $slug): array
    {
        $base = rtrim($base, '/');
        $partes = parse_url($base);
        if (!is_array($partes) || ($partes['scheme'] ?? '') !== 'https'
            || empty($partes['host']) || isset($partes['user']) || isset($partes['pass']) || isset($partes['query']) || isset($partes['fragment'])
            || ($partes['path'] ?? '') !== ''
            || preg_match('/\A[a-z0-9][a-z0-9-]{0,189}\z/', $slug) !== 1
            || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return [];
        }
        return ['callback' => [
            'successUrl' => $base . '/' . $slug . '/gerencia/assinatura?retorno=asaas',
            'autoRedirect' => true,
        ]];
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
        if ($a === null) {
            return ['ok' => false, 'mensagem' => 'Assinatura não encontrada.'];
        }
        $chave = 'cardapim:assinatura:' . $a['id_restaurante'];
        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $q = $this->db->prepare('SELECT GET_LOCK(?, 3)');
            $q->execute([$chave]);
            if ((int) $q->fetchColumn() !== 1) {
                return ['ok' => false, 'mensagem' => 'Uma cobrança está sendo processada. Aguarde e atualize as faturas.'];
            }
        }
        try {
            return $this->gerarComTrava($idAssinatura, $primeiroVencimento);
        } finally {
            if ($mysql) {
                $q = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $q->execute([$chave]);
            }
        }
    }

    private function gerarComTrava(int $idAssinatura, ?string $primeiroVencimento): array
    {
        $a = $this->assinaturas->buscar($idAssinatura);
        if ($a === null || !in_array($a['status'], ['ativa', 'aguardando'], true)
            || (int) ($this->assinaturas->vigente((int) $a['id_restaurante'])['id'] ?? 0) !== $idAssinatura) {
            return ['ok' => false, 'mensagem' => 'Esta assinatura não está vigente.'];
        }
        if ((float) $a['valor'] <= 0 || !isset(CobrancaAssinaturas::CICLOS[$a['periodo']])) {
            return ['ok' => true, 'mensagem' => 'Plano sem cobrança.'];
        }
        if ($a['asaas_assinatura_id'] !== null) {
            return $this->sincronizar($idAssinatura)
                ? ['ok' => true, 'mensagem' => 'A cobrança já existe.']
                : ['ok' => false, 'mensagem' => 'Não foi possível consultar as faturas agora. A cobrança já existe; nenhuma nova foi emitida.'];
        }
        $conta = $this->conta();
        if ($conta === null) {
            // Preserva ambiente, cliente e marcador de tentativa incerta.
            if ($a['cobranca_erro'] === null) {
                $this->assinaturas->salvarAsaas($idAssinatura, $a['ambiente'], $a['asaas_cliente_id'], null, 'O Asaas da plataforma não está conectado.');
            }
            return ['ok' => false, 'mensagem' => 'A cobrança ainda não pôde ser gerada: o pagamento online está indisponível no momento.'];
        }
        if ($a['ambiente'] !== null && $a['ambiente'] !== $conta['ambiente']) {
            return ['ok' => false, 'mensagem' => 'A assinatura pertence a outro ambiente do Asaas. Fale com o suporte.'];
        }
        $restaurante = $this->restaurante((int) $a['id_restaurante']);
        $regras = (new CobrancaAssinaturas($this->db))->regras();
        $cliente = $a['asaas_cliente_id'] ?? $this->assinaturas->clienteAsaas((int) $a['id_restaurante'], $conta['ambiente']);
        $pendencia = (string) ($a['cobranca_erro'] ?? '');
        $etapa = str_starts_with($pendencia, 'CONFIRMAR_CLIENTE:') ? 'CLIENTE'
            : (str_starts_with($pendencia, 'CONFIRMAR_ASSINATURA:') ? 'ASSINATURA' : null);
        $postEmCurso = false;
        try {
            if ($cliente === null) {
                if ($restaurante === null || $restaurante['cnpj'] === null) {
                    throw new IntegracaoErro('O restaurante não tem CNPJ cadastrado.');
                }
                $refCliente = 'restaurante:' . (int) $a['id_restaurante'];
                $cliente = $this->api->localizarClienteAssinante($conta['chave'], $conta['ambiente'], $refCliente, $restaurante['cnpj']);
                if ($cliente === null) {
                    if ($etapa === 'CLIENTE' || $etapa === 'ASSINATURA') {
                        throw new IntegracaoErro('Cadastro anterior ainda não conciliado. Fale com o suporte.');
                    }
                    $etapa = 'CLIENTE';
                    $this->assinaturas->salvarAsaas($idAssinatura, $conta['ambiente'], null, null, 'CONFIRMAR_CLIENTE: solicitação enviada.');
                    $postEmCurso = true;
                    $cliente = $this->api->criarClienteAssinante($conta['chave'], $conta['ambiente'], $restaurante['nome'], $restaurante['cnpj'],
                        $restaurante['email'], $restaurante['celular'], $refCliente, (bool) $regras['avisos_asaas']);
                    $postEmCurso = false;
                }
                if ($etapa === 'CLIENTE') {
                    $etapa = null;
                }
                $this->assinaturas->salvarAsaas($idAssinatura, $conta['ambiente'], $cliente, null,
                    $etapa === 'ASSINATURA' ? 'CONFIRMAR_ASSINATURA: solicitação anterior.' : null);
            }
            $referencia = 'assinatura:' . $idAssinatura;
            $idAsaas = $this->api->localizarAssinatura($conta['chave'], $conta['ambiente'], $cliente, $referencia);
            if ($idAsaas === null) {
                if ($etapa === 'ASSINATURA') {
                    throw new IntegracaoErro('Cobrança anterior ainda não conciliada. Fale com o suporte antes de emitir outra.');
                }
                $vencimento = $primeiroVencimento ?? $a['vence_em'] ?? $this->hoje()->modify('+' . max(1, $regras['vencimento_dias']) . ' days')->format('Y-m-d');
                $etapa = 'ASSINATURA';
                $this->assinaturas->salvarAsaas($idAssinatura, $conta['ambiente'], $cliente, null, 'CONFIRMAR_ASSINATURA: solicitação enviada.');
                $postEmCurso = true;
                $idAsaas = $this->api->criarAssinatura($conta['chave'], $conta['ambiente'], [
                    'customer' => $cliente,
                    'billingType' => CobrancaAssinaturas::tipoAsaas($regras['formas']),
                    'value' => (float) $a['valor'],
                    'nextDueDate' => $vencimento,
                    'cycle' => CobrancaAssinaturas::CICLOS[$a['periodo']],
                    'description' => mb_substr(marca() . ' - plano ' . $a['plano_nome'] . ' (' . ($restaurante['nome'] ?? '') . ')', 0, 500),
                    'externalReference' => $referencia,
                    ...CobrancaAssinaturas::encargosAsaas($regras),
                    ...self::callbackRetorno(Config::get('APP_URL'), (string) ($restaurante['slug'] ?? '')),
                ]);
                $postEmCurso = false;
            }
        } catch (IntegracaoErro $erro) {
            // Rejeicao explicita da API permite nova tentativa; timeout/5xx nao.
            if ($postEmCurso && in_array($erro->getCode(), [400, 401, 403, 404, 422], true)) {
                $etapa = null;
            }
            $mensagem = ($etapa !== null ? 'CONFIRMAR_' . $etapa . ': ' : '') . $erro->getMessage();
            $this->assinaturas->salvarAsaas($idAssinatura, $conta['ambiente'], $cliente, null, $mensagem);
            return ['ok' => false, 'mensagem' => 'A cobrança não pôde ser confirmada agora. ' . $erro->getMessage()];
        }
        $this->assinaturas->salvarAsaas($idAssinatura, $conta['ambiente'], $cliente, $idAsaas, null);
        return $this->sincronizar($idAssinatura)
            ? ['ok' => true, 'mensagem' => 'Cobrança gerada.']
            : ['ok' => false, 'mensagem' => 'Cobrança registrada no Asaas. Atualize as faturas em instantes; não será criada outra.'];
    }

    /** Traz as faturas da assinatura do Asaas (o aviso tambem traz; aqui e para a tela nao depender dele). */
    public function sincronizar(int $idAssinatura): bool
    {
        $a = $this->assinaturas->buscar($idAssinatura);
        $conta = $this->conta();
        if ($a === null || $a['asaas_assinatura_id'] === null || $conta === null || $conta['ambiente'] !== $a['ambiente']) {
            return false;
        }
        try {
            $faturas = $this->api->faturasDaAssinatura($conta['chave'], $conta['ambiente'], (string) $a['asaas_assinatura_id']);
        } catch (IntegracaoErro $erro) {
            error_log('[Cardapim] Faturas da assinatura nao sincronizadas: ' . $erro->getMessage());
            return false;
        }
        foreach ($faturas as $f) {
            if ($f['assinatura'] === $a['asaas_assinatura_id']) {
                $this->aplicarFatura($a, (string) $a['ambiente'], $f);
            }
        }
        return true;
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
        if ($a === null && $f['assinatura'] !== null && preg_match('/\Aassinatura:(\d{1,18})\z/', $ref, $m) === 1) {
            // O webhook pode chegar antes da resposta do POST; somente uma tentativa registrada
            // no mesmo ambiente, cliente e assinatura vigente pode usar a referencia como fallback.
            $candidato = $this->assinaturas->buscar((int) $m[1]);
            if ($candidato !== null && $candidato['ambiente'] === $conta['ambiente']
                && $candidato['asaas_assinatura_id'] === null && $candidato['asaas_cliente_id'] !== null
                && ($dados['payment']['customer'] ?? null) === $candidato['asaas_cliente_id']
                && in_array($candidato['status'], ['ativa', 'aguardando'], true)
                && str_starts_with((string) ($candidato['cobranca_erro'] ?? ''), 'CONFIRMAR_ASSINATURA:')) {
                $a = $candidato;
            }
        }
        if ($a === null || ($a['asaas_cliente_id'] !== null && isset($dados['payment']['customer'])
            && $dados['payment']['customer'] !== $a['asaas_cliente_id'])) {
            return; // cobranca avulsa da conta da plataforma: so fica registrada no historico de avisos
        }
        if ($evento === 'PAYMENT_DELETED') {
            $f['status'] = 'DELETED';
        }
        // Estorno parcial não revoga o período pago; o valor líquido exige conciliação própria.
        if ($evento === 'PAYMENT_PARTIALLY_REFUNDED' || $evento === 'PAYMENT_REFUND_IN_PROGRESS') {
            return;
        }
        $this->aplicarFatura($a, $conta['ambiente'], $f, $evento);
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
        if ($this->assinaturas->bloquearCanceladaVencida($idRestaurante, $this->hoje()->format('Y-m-d'))) {
            return true;
        }
        $tolerancia = (new CobrancaAssinaturas($this->db))->regras()['tolerancia_dias'];
        $limite = $this->hoje()->modify('-' . (int) $tolerancia . ' days')->format('Y-m-d');
        if ($this->assinaturas->bloquearPeriodoVencido($idRestaurante, $limite)) {
            return true;
        }
        if ($this->assinaturas->faturaAtrasada($idRestaurante, $limite) === null) {
            return false;
        }
        return $this->assinaturas->mudarRestaurante($idRestaurante, 'inadimplente', ['ativo', 'pendente']);
    }

    /** Remove a assinatura no Asaas (cancelamento ou troca para plano gratis). Falha vira mensagem, nunca excecao. */
    public function remover(array $a): ?string
    {
        if ($a['asaas_assinatura_id'] === null) {
            return str_starts_with((string) ($a['cobranca_erro'] ?? ''), 'CONFIRMAR_')
                ? 'Há uma tentativa de cobrança não conciliada. Atualize as faturas ou fale com o suporte antes de cancelar.'
                : null;
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
                'updatePendingPayments' => false,
                ...self::callbackRetorno(Config::get('APP_URL'), (string) ($this->restaurante((int) $a['id_restaurante'])['slug'] ?? '')),
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
    private function aplicarFatura(array $a, string $ambiente, array $f, ?string $evento = null): void
    {
        $idRestaurante = (int) $a['id_restaurante'];
        if ($f['cliente'] !== null && $a['asaas_cliente_id'] !== null && $f['cliente'] !== $a['asaas_cliente_id']) {
            return;
        }
        $status = self::STATUS_FATURA[$f['status']] ?? null;
        if ($status === null) {
            return; // inclui estorno solicitado, ainda não concluído
        }
        $this->db->beginTransaction();
        try {
            $this->assinaturas->travarRestaurante($idRestaurante, \Cardapim\Services\Gerencia\InsumoService::paraAtualizar($this->db));
            $anterior = $this->assinaturas->faturaPorAsaas($ambiente, $f['id']);
            if ($anterior !== null && (int) $anterior['id_restaurante'] !== $idRestaurante) {
                throw new \RuntimeException('Fatura já vinculada a outro restaurante.');
            }
            $antes = $anterior['status'] ?? null;
            // Uma fatura paga não é estornada apenas por um evento de exclusão.
            if ($antes === 'paga' && $status === 'cancelada') {
                $this->db->commit();
                return;
            }
            // Confirma transições conflitantes no estado atual do Asaas. Evita que um
            // PAYMENT_RECEIVED atrasado desfaça um estorno ou uma disputa legítima.
            $conflito = ($antes !== null && $antes !== $status && in_array($antes, ['paga', 'estornada', 'cancelada'], true))
                || $status === 'estornada' || $evento === 'PAYMENT_RECEIVED_IN_CASH_UNDONE';
            if ($conflito) {
                $conta = $this->conta();
                if ($conta === null || $conta['ambiente'] !== $ambiente) {
                    throw new IntegracaoErro('Não foi possível reconciliar a cobrança.');
                }
                $f = $this->api->consultarFaturaAssinatura($conta['chave'], $ambiente, $f['id']);
                if ($f['assinatura'] === null || $f['assinatura'] !== ($a['asaas_assinatura_id'] ?? null)
                    || ($a['asaas_cliente_id'] !== null && $f['cliente'] !== $a['asaas_cliente_id'])) {
                    throw new IntegracaoErro('Vínculo da cobrança divergente na conciliação.');
                }
                $status = self::STATUS_FATURA[$f['status']] ?? null;
                if ($status === null || ($antes === 'paga' && $status === 'cancelada')) {
                    $this->db->commit();
                    return;
                }
            }
            if ($status === 'paga' && (float) $f['valor'] <= 0) {
                $this->db->commit();
                return;
            }
            $origem = $anterior !== null && $anterior['id_assinatura'] !== null
                ? $this->assinaturas->buscar((int) $anterior['id_assinatura']) : $a;
            if ($origem === null || (int) $origem['id_restaurante'] !== $idRestaurante) {
                throw new \RuntimeException('Contrato original da fatura não encontrado.');
            }
            $this->assinaturas->salvarFatura($idRestaurante, (int) $origem['id'], $ambiente, $f, $status);
            $vigente = $this->assinaturas->vigente($idRestaurante);
            $meses = AssinaturaService::MESES[$origem['periodo']] ?? 1;
            $vencimento = DateTimeImmutable::createFromFormat('!Y-m-d', $f['vencimento']) ?: $this->hoje();
            $cobertura = AssinaturaService::somarMeses($vencimento, $meses)->format('Y-m-d');
            if ($vigente !== null && (int) $vigente['id'] === (int) $a['id']
                && in_array($vigente['status'], ['ativa', 'aguardando'], true)) {
                if ($status === 'paga' && $antes !== 'paga') {
                    $this->assinaturas->marcarPaga((int) $vigente['id'], $cobertura);
                    $this->assinaturas->registrarPagamentoRestaurante($idRestaurante);
                    $atualizado = $this->assinaturas->buscar((int) $vigente['id']);
                    $tolerancia = (new CobrancaAssinaturas($this->db))->regras()['tolerancia_dias'];
                    $limite = $this->hoje()->modify('-' . (int) $tolerancia . ' days')->format('Y-m-d');
                    if ($atualizado !== null && $atualizado['vence_em'] !== null && $atualizado['vence_em'] >= $limite
                        && $this->assinaturas->faturaAtrasada($idRestaurante, $limite) === null) {
                        $this->assinaturas->mudarRestaurante($idRestaurante, 'ativo', ['pendente', 'inadimplente']);
                    }
                } elseif ($antes === 'paga' && in_array($status, ['estornada', 'pendente', 'vencida'], true)
                    && $vigente['vence_em'] !== null && $vigente['vence_em'] <= $cobertura
                    && $vigente['asaas_assinatura_id'] !== null) {
                    // Retira somente a cobertura perdida, preservando ciclos pagos posteriores.
                    $ate = min($vencimento->modify('-1 day')->format('Y-m-d'), $this->hoje()->modify('-1 day')->format('Y-m-d'));
                    foreach ($this->assinaturas->faturasPagasRecorrencia($idRestaurante, $ambiente, $vigente['asaas_assinatura_id']) as $paga) {
                        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $paga['vencimento']);
                        if ($data !== false) {
                            $ate = max($ate, AssinaturaService::somarMeses($data, AssinaturaService::MESES[$paga['periodo']] ?? 1)->format('Y-m-d'));
                        }
                    }
                    $this->assinaturas->ajustarVencimento((int) $vigente['id'], $idRestaurante, $ate);
                    if ($ate < $this->hoje()->format('Y-m-d')) {
                        $this->assinaturas->mudarRestaurante($idRestaurante, 'inadimplente', ['ativo', 'pendente']);
                    }
                }
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return array{nome:string, slug:string, cnpj:?string, email:string, celular:string}|null */
    private function restaurante(int $id): ?array
    {
        $q = $this->db->prepare('SELECT r.nome, r.slug, r.cnpj, r.email, r.telefone,
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
            'slug' => (string) $r['slug'],
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
