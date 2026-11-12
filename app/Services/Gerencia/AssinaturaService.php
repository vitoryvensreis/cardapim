<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Plataforma\AssinaturaCobranca;
use Cardapim\Services\Plataforma\CobrancaAssinaturas;
use Cardapim\Shared\Numero;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Assinatura do restaurante (aba do gerente): ver o plano e as faturas, pagar, assinar, trocar de plano, cancelar e
 * desfazer o cancelamento. A cobranca recorrente e feita pelo Asaas da plataforma (AssinaturaCobranca).
 * Plano gratis (valor 0, ex.: parceiro vitalicio) nunca gera cobranca.
 */
final class AssinaturaService
{
    public const MESES = ['mensal' => 1, 'semestral' => 6, 'anual' => 12, 'vitalicio' => 0];
    public const ROTULO_PERIODO = ['mensal' => 'por mês', 'semestral' => 'por semestre', 'anual' => 'por ano', 'vitalicio' => 'para sempre'];

    private readonly AssinaturaRepository $assinaturas;
    private readonly AssinaturaCobranca $cobranca;

    public function __construct(
        private readonly PDO $db,
        private readonly UsuarioLogado $autor,
        private readonly int $idRestaurante,
        private readonly ?DateTimeImmutable $hoje = null,
        ?AssinaturaCobranca $cobranca = null,
    ) {
        $this->assinaturas = new AssinaturaRepository($db);
        $this->cobranca = $cobranca ?? new AssinaturaCobranca($db, hoje: $hoje);
    }

    /**
     * Assinatura vigente com a situacao calculada. Cancelada com periodo vencido vira "encerrada".
     * situacao: aguardando | ativa | gratis | atrasada | vencida | cancelada
     * @return array<string,mixed>|null
     */
    public function vigente(): ?array
    {
        $a = $this->assinaturas->vigente($this->idRestaurante);
        if ($a === null) {
            return null;
        }
        $hoje = $this->hoje()->format('Y-m-d');
        if ($a['status'] === 'cancelada' && ($a['vence_em'] === null || $a['vence_em'] < $hoje)) {
            $this->assinaturas->mudarStatus($this->idRestaurante, (int) $a['id'], 'encerrada', $this->agora());
            // Sem plano: o gerente continua entrando, mas so para escolher um plano novo.
            $this->assinaturas->mudarRestaurante($this->idRestaurante, 'inadimplente', ['ativo', 'pendente']);
            return null;
        }
        $aberta = $this->assinaturas->faturaEmAberto($this->idRestaurante);
        $a['fatura_aberta'] = $aberta;
        $a['situacao'] = match (true) {
            $a['status'] === 'cancelada' => 'cancelada',
            $a['status'] === 'aguardando' => 'aguardando',
            (float) $a['valor'] <= 0 => 'gratis',
            $aberta !== null && $aberta['vencimento'] < $hoje => 'atrasada',
            $a['vence_em'] !== null && $a['vence_em'] < $hoje && $a['asaas_assinatura_id'] === null => 'vencida',
            default => 'ativa',
        };
        return $a;
    }

    /** @return list<array<string,mixed>> */
    public function faturas(): array
    {
        return $this->assinaturas->faturas($this->idRestaurante);
    }

    /** Planos que o gerente pode escolher: os publicos (o privado atual continua aparecendo como "seu plano"). @return list<array<string,mixed>> */
    public function planos(): array
    {
        return (new PlanoRepository($this->db))->listarPublicos();
    }

    /** $incluirPrivados: a administracao da plataforma pode colocar o restaurante em qualquer plano ativo (ex.: parceiro). */
    public function assinar(int $planoId, bool $incluirPrivados = false): Resultado
    {
        $plano = $incluirPrivados ? (new PlanoRepository($this->db))->buscar($planoId) : (new PlanoRepository($this->db))->buscarPublicoPorId($planoId);
        if ($plano !== null && (int) $plano['ativo'] !== 1) {
            $plano = null;
        }
        if ($plano === null) {
            return Resultado::falha('Este plano não está disponível. Escolha outro da lista.');
        }
        $atual = $this->vigente();
        if ($atual !== null && (int) $atual['plano_id'] === (int) $plano['id']) {
            return $atual['status'] === 'cancelada'
                ? $this->reativar()
                : Resultado::falha('Você já está no plano ' . $plano['nome'] . '.');
        }
        $gratis = (float) $plano['valor'] <= 0;
        $regras = (new CobrancaAssinaturas($this->db))->regras();
        $primeiro = $this->hoje()->modify('+' . max(1, $regras['vencimento_dias']) . ' days');
        $statusRest = $this->assinaturas->statusRestaurante($this->idRestaurante);

        // Situacao da nova linha: gratis = ativa para sempre; pago = mantem o periodo ja pago (troca) ou
        // usa ate o 1o vencimento (restaurante ja liberado) ou aguarda o 1o pagamento (restaurante novo/bloqueado).
        if ($gratis) {
            [$status, $vence] = ['ativa', null];
        } elseif ($atual !== null && $atual['status'] !== 'aguardando' && (float) $atual['valor'] > 0 && $atual['vence_em'] !== null) {
            [$status, $vence] = ['ativa', $atual['vence_em']];
        } elseif ($statusRest === 'ativo') {
            [$status, $vence] = ['ativa', $primeiro->format('Y-m-d')];
        } else {
            [$status, $vence] = ['aguardando', null];
        }

        // Troca pago -> pago com assinatura no Asaas: so muda valor/ciclo la (as faturas seguem na mesma assinatura).
        $reaproveitar = null;
        if ($atual !== null && !$gratis && $atual['asaas_assinatura_id'] !== null && $atual['status'] !== 'cancelada') {
            $erro = $this->cobranca->atualizar($atual, (string) $plano['valor'], (string) $plano['periodo'], (string) $plano['nome']);
            if ($erro === null) {
                $reaproveitar = $atual;
            } elseif ($erro !== 'sem-asaas') {
                return Resultado::falha('Não foi possível trocar o plano agora. ' . $erro);
            }
        }
        if ($atual !== null && $reaproveitar === null && $atual['asaas_assinatura_id'] !== null) {
            if (($erro = $this->cobranca->remover($atual)) !== null) {
                return Resultado::falha('Não foi possível trocar o plano agora. ' . $erro);
            }
        }

        $this->db->beginTransaction();
        try {
            $this->assinaturas->travarRestaurante($this->idRestaurante, InsumoService::paraAtualizar($this->db));
            if ($atual !== null) {
                $this->assinaturas->mudarStatus($this->idRestaurante, (int) $atual['id'], 'encerrada', $this->agora());
            }
            $id = $this->assinaturas->criar($this->idRestaurante, [
                'plano_id' => (int) $plano['id'],
                'valor' => (string) $plano['valor'],
                'periodo' => (string) $plano['periodo'],
                'status' => $status,
                'inicio_em' => $this->hoje()->format('Y-m-d'),
                'vence_em' => $vence,
                'ambiente' => $reaproveitar['ambiente'] ?? null,
                'asaas_cliente_id' => $reaproveitar['asaas_cliente_id'] ?? null,
                'asaas_assinatura_id' => $reaproveitar['asaas_assinatura_id'] ?? null,
            ]);
            if ($gratis) {
                $this->assinaturas->mudarRestaurante($this->idRestaurante, 'ativo', ['pendente', 'inadimplente']);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $cobranca = ['ok' => true, 'mensagem' => ''];
        if (!$gratis && $reaproveitar === null) {
            $proximo = $vence !== null && $vence > $primeiro->format('Y-m-d') ? $vence : $primeiro->format('Y-m-d');
            $cobranca = $this->cobranca->gerar($id, $proximo);
        }
        $preco = self::preco((string) $plano['valor'], (string) $plano['periodo']);
        Auditoria::registrar($this->autor, $atual === null ? 'Assinatura contratada' : 'Plano trocado', $atual === null
            ? sprintf('Plano %s (%s).', $plano['nome'], $preco)
            : sprintf('De %s para %s (%s).', $atual['plano_nome'], $plano['nome'], $preco));
        if (!$cobranca['ok']) {
            return Resultado::sucesso('Plano ' . $plano['nome'] . ' escolhido. ' . $cobranca['mensagem']);
        }
        if ($gratis) {
            return Resultado::sucesso('Pronto! Você está no plano ' . $plano['nome'] . ', sem cobrança.');
        }
        return Resultado::sucesso($atual === null
            ? 'Pronto! Você escolheu o plano ' . $plano['nome'] . '. A fatura já está disponível logo abaixo.'
            : 'Plano trocado para ' . $plano['nome'] . '. O novo valor vale a partir da próxima fatura.');
    }

    public function cancelar(): Resultado
    {
        $atual = $this->vigente();
        if ($atual === null || $atual['status'] !== 'ativa' || (float) $atual['valor'] <= 0) {
            return Resultado::falha('Não há assinatura paga ativa para cancelar.');
        }
        if (($erro = $this->cobranca->remover($atual)) !== null) {
            return Resultado::falha('Não foi possível cancelar agora. ' . $erro);
        }
        $this->assinaturas->mudarStatus($this->idRestaurante, (int) $atual['id'], 'cancelada', $this->agora());
        $this->assinaturas->salvarAsaas((int) $atual['id'], $atual['ambiente'], $atual['asaas_cliente_id'], null, null);
        $ate = $atual['vence_em'] !== null ? self::data((string) $atual['vence_em']) : 'hoje';
        Auditoria::registrar($this->autor, 'Assinatura cancelada', sprintf('Plano %s. Acesso mantido até %s.', $atual['plano_nome'], $ate));
        return Resultado::sucesso('Assinatura cancelada. Não haverá novas cobranças e você continua usando tudo até ' . $ate . '. Mudou de ideia? É só desfazer.');
    }

    public function reativar(): Resultado
    {
        $atual = $this->vigente();
        if ($atual === null || $atual['status'] !== 'cancelada') {
            return Resultado::falha('Não há cancelamento para desfazer.');
        }
        $this->assinaturas->mudarStatus($this->idRestaurante, (int) $atual['id'], 'ativa', $this->agora());
        // A cobranca volta a partir do fim do periodo ja pago.
        $proximo = $atual['vence_em'] !== null && $atual['vence_em'] > $this->hoje()->format('Y-m-d') ? (string) $atual['vence_em'] : null;
        $cobranca = $this->cobranca->gerar((int) $atual['id'], $proximo);
        Auditoria::registrar($this->autor, 'Cancelamento desfeito', sprintf('Plano %s continua ativo.', $atual['plano_nome']));
        return Resultado::sucesso('Que bom que você ficou! O plano ' . $atual['plano_nome'] . ' continua ativo.' . ($cobranca['ok'] ? '' : ' ' . $cobranca['mensagem']));
    }

    /** "Gerar cobranca de novo" (quando o Asaas falhou ao assinar) e "Atualizar faturas". */
    public function gerarCobranca(): Resultado
    {
        $atual = $this->vigente();
        if ($atual === null || (float) $atual['valor'] <= 0 || $atual['status'] === 'cancelada') {
            return Resultado::falha('Não há cobrança para gerar.');
        }
        $r = $this->cobranca->gerar((int) $atual['id']);
        return $r['ok'] ? Resultado::sucesso('Faturas atualizadas.') : Resultado::falha($r['mensagem']);
    }

    public static function preco(string $valor, string $periodo): string
    {
        return (float) $valor <= 0 ? 'Grátis' : Numero::reais($valor) . ' ' . (self::ROTULO_PERIODO[$periodo] ?? '');
    }

    /** 31/01 + 1 mes = 28/02 (ou 29/02), nunca 03/03. */
    public static function somarMeses(DateTimeImmutable $data, int $meses): DateTimeImmutable
    {
        $primeiro = $data->modify('first day of this month')->modify('+' . $meses . ' months');
        $dia = min((int) $data->format('d'), (int) $primeiro->format('t'));
        return $primeiro->setDate((int) $primeiro->format('Y'), (int) $primeiro->format('m'), $dia);
    }

    public static function data(string $iso): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($iso, 0, 10));
        return $d === false ? $iso : $d->format('d/m/Y');
    }

    private function hoje(): DateTimeImmutable
    {
        return ($this->hoje ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    private function agora(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }
}
