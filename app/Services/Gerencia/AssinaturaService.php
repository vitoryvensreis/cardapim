<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Numero;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Assinatura do restaurante: ver o plano, assinar, trocar de plano, cancelar e desfazer o cancelamento.
 * A cobranca automatica (Asaas) ainda nao existe: aqui so registramos o plano escolhido e as datas.
 */
final class AssinaturaService
{
    public const MESES = ['mensal' => 1, 'semestral' => 6, 'anual' => 12];
    public const ROTULO_PERIODO = ['mensal' => 'por mês', 'semestral' => 'por semestre', 'anual' => 'por ano'];

    private readonly AssinaturaRepository $assinaturas;

    public function __construct(
        private readonly PDO $db,
        private readonly UsuarioLogado $autor,
        private readonly int $idRestaurante,
        private readonly ?DateTimeImmutable $hoje = null,
    ) {
        $this->assinaturas = new AssinaturaRepository($db);
    }

    /**
     * Assinatura vigente com a situacao calculada. Cancelada com periodo vencido vira "encerrada".
     * @return array<string,mixed>|null com 'situacao' = ativa | vencida | cancelada
     */
    public function vigente(): ?array
    {
        $a = $this->assinaturas->vigente($this->idRestaurante);
        if ($a === null) {
            return null;
        }
        $hoje = $this->hoje()->format('Y-m-d');
        if ($a['status'] === 'cancelada' && $a['vence_em'] < $hoje) {
            $this->assinaturas->mudarStatus($this->idRestaurante, (int) $a['id'], 'encerrada', $this->agora());
            return null;
        }
        $a['situacao'] = $a['status'] === 'ativa' && $a['vence_em'] < $hoje ? 'vencida' : $a['status'];
        return $a;
    }

    public function assinar(int $planoId): Resultado
    {
        $plano = (new PlanoRepository($this->db))->buscarPublicoPorId($planoId);
        if ($plano === null) {
            return Resultado::falha('Este plano não está disponível. Escolha outro da lista.');
        }
        $atual = $this->vigente();
        if ($atual !== null && (int) $atual['plano_id'] === (int) $plano['id']) {
            return $atual['status'] === 'cancelada'
                ? $this->reativar()
                : Resultado::falha('Você já está no plano ' . $plano['nome'] . '.');
        }

        $inicio = $this->hoje();
        $vence = self::somarMeses($inicio, self::MESES[$plano['periodo']] ?? 1);
        $this->db->beginTransaction();
        try {
            $this->assinaturas->travarRestaurante($this->idRestaurante, InsumoService::paraAtualizar($this->db));
            if ($atual !== null) {
                $this->assinaturas->mudarStatus($this->idRestaurante, (int) $atual['id'], 'encerrada', $this->agora());
            }
            $this->assinaturas->criar($this->idRestaurante, [
                'plano_id' => (int) $plano['id'],
                'valor' => (string) $plano['valor'],
                'periodo' => (string) $plano['periodo'],
                'inicio_em' => $inicio->format('Y-m-d'),
                'vence_em' => $vence->format('Y-m-d'),
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $preco = Numero::reais((string) $plano['valor']) . ' ' . (self::ROTULO_PERIODO[$plano['periodo']] ?? '');
        if ($atual === null) {
            Auditoria::registrar($this->autor, 'Assinatura contratada', sprintf('Plano %s (%s), válido até %s.', $plano['nome'], $preco, $vence->format('d/m/Y')));
            return Resultado::sucesso('Pronto! Você assinou o plano ' . $plano['nome'] . '.');
        }
        Auditoria::registrar($this->autor, 'Plano trocado', sprintf('De %s para %s (%s), novo período até %s.', $atual['plano_nome'], $plano['nome'], $preco, $vence->format('d/m/Y')));
        return Resultado::sucesso('Plano trocado para ' . $plano['nome'] . '. O novo período começa hoje.');
    }

    public function cancelar(): Resultado
    {
        $atual = $this->vigente();
        if ($atual === null || $atual['status'] !== 'ativa') {
            return Resultado::falha('Não há assinatura ativa para cancelar.');
        }
        $this->assinaturas->mudarStatus($this->idRestaurante, (int) $atual['id'], 'cancelada', $this->agora());
        $ate = self::data((string) $atual['vence_em']);
        Auditoria::registrar($this->autor, 'Assinatura cancelada', sprintf('Plano %s. Acesso mantido até %s.', $atual['plano_nome'], $ate));
        return Resultado::sucesso('Assinatura cancelada. Você continua usando tudo até ' . $ate . '. Mudou de ideia? É só desfazer.');
    }

    public function reativar(): Resultado
    {
        $atual = $this->vigente();
        if ($atual === null || $atual['status'] !== 'cancelada') {
            return Resultado::falha('Não há cancelamento para desfazer.');
        }
        $this->assinaturas->mudarStatus($this->idRestaurante, (int) $atual['id'], 'ativa', $this->agora());
        Auditoria::registrar($this->autor, 'Cancelamento desfeito', sprintf('Plano %s continua ativo (período até %s).', $atual['plano_nome'], self::data((string) $atual['vence_em'])));
        return Resultado::sucesso('Que bom que você ficou! O plano ' . $atual['plano_nome'] . ' continua ativo.');
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
