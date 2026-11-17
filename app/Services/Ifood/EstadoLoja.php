<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Realtime\TempoReal;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Estado operacional compartilhado (MySQL e fonte de verdade).
 * Não cria tabela: usa caminhos independentes em integracoes.dados para não substituir
 * as credenciais/opções dos restaurantes. O iFood apenas informa a situação externa.
 */
final class EstadoLoja
{
    /** @return array{fechado:bool,motivo:string,pausa:?array,remoto:?array} */
    public static function consultar(PDO $db, int $idRestaurante): array
    {
        $q = $db->prepare("SELECT dados FROM integracoes WHERE id_restaurante = ? AND provedor = 'ifood'
            AND status IN ('conectado','erro') LIMIT 1");
        $q->execute([$idRestaurante]);
        $row = $q->fetchColumn();
        $dados = is_string($row) ? json_decode($row, true) : null;
        if (!is_array($dados)) {
            return ['fechado' => false, 'motivo' => '', 'pausa' => null, 'remoto' => null];
        }
        $pausa = is_array($dados['pausa_sincronizada'] ?? null) ? $dados['pausa_sincronizada'] : null;
        $remoto = is_array($dados['situacao_sincronizada'] ?? null) ? $dados['situacao_sincronizada'] : null;
        $agora = time();
        // Pausa recém-enviada: protege a janela em que o iFood ainda não propagou o fechamento.
        // Depois de 120s, a consulta periódica de status passa a ser a fonte para o fechamento remoto.
        $local = $pausa !== null
            && (int) ($pausa['inicio'] ?? 0) <= $agora
            && (int) ($pausa['fim'] ?? 0) > $agora
            && (int) ($pausa['criada_em'] ?? 0) + 120 > $agora;
        $externo = ($remoto['fechado'] ?? false) === true;
        return [
            'fechado' => $local || $externo,
            'motivo' => $local ? 'Pausa ativa, sincronizando com o iFood.' : ($externo ? (string) ($remoto['motivo'] ?? 'Loja fechada no iFood.') : ''),
            'pausa' => $pausa,
            'remoto' => $remoto,
        ];
    }

    /** Escreve só a chave da pausa, sem substituir opções ou tokens. */
    public static function criarPausa(PDO $db, int $idRestaurante, string $id, DateTimeImmutable $inicio, DateTimeImmutable $fim): void
    {
        self::escrever($db, $idRestaurante, 'pausa_sincronizada', [
            'id' => $id, 'inicio' => $inicio->getTimestamp(), 'fim' => $fim->getTimestamp(), 'criada_em' => time(),
        ]);
        self::avisar($idRestaurante, 'pausa_criada');
    }

    public static function removerPausa(PDO $db, int $idRestaurante, string $id): void
    {
        $atual = self::consultar($db, $idRestaurante);
        if (($atual['pausa']['id'] ?? null) !== $id) {
            return; // não libera uma pausa diferente, inclusive pausas criadas no Portal
        }
        $db->prepare("UPDATE integracoes SET dados = JSON_REMOVE(COALESCE(NULLIF(dados,''),'{}'), '$.pausa_sincronizada')
            WHERE id_restaurante = ? AND provedor = 'ifood'")->execute([$idRestaurante]);
        self::avisar($idRestaurante, 'pausa_removida');
    }

    /**
     * Reconciliado pela rotina única do iFood a cada 30s, não a cada aba do navegador.
     * Falha de rede NÃO limpa o último fechamento conhecido (fail-closed).
     * @param list<array<string,mixed>> $operacoes
     * @param list<array<string,mixed>> $pausas
     */
    public static function reconciliar(PDO $db, int $idRestaurante, array $operacoes, array $pausas): void
    {
        if ($operacoes === []) {
            return; // nunca interpretar lista vazia/erro como loja aberta
        }
        $agora = time();
        $pausada = false;
        foreach ($pausas as $p) {
            $inicio = \Cardapim\Modules\Integracoes\IfoodApi::data((string) ($p['inicio'] ?? ''))?->getTimestamp();
            $fim = \Cardapim\Modules\Integracoes\IfoodApi::data((string) ($p['fim'] ?? ''))?->getTimestamp();
            if ($inicio !== null && $inicio <= $agora && ($fim === null || $fim > $agora)) {
                $pausada = true;
                break;
            }
        }
        $algumaDisponivel = false;
        $motivo = '';
        foreach ($operacoes as $op) {
            if (($op['disponivel'] ?? false) === true) {
                $algumaDisponivel = true;
            } elseif ($motivo === '') {
                $motivo = trim((string) ($op['mensagem'] ?? ''));
            }
        }
        $fechado = $pausada || !$algumaDisponivel;
        if ($pausada) {
            $motivo = 'Loja pausada no iFood.';
        }
        $atual = self::consultar($db, $idRestaurante);
        self::escrever($db, $idRestaurante, 'situacao_sincronizada', [
            'fechado' => $fechado, 'pausada' => $pausada, 'motivo' => $fechado ? ($motivo ?: 'Loja indisponível no iFood.') : '',
            'atualizado_em' => $agora,
        ]);
        if ($atual['fechado'] !== self::consultar($db, $idRestaurante)['fechado']) {
            self::avisar($idRestaurante, $fechado ? 'fechada' : 'aberta');
        }
    }

    /** Escrita serializada com o INSERT do checkout para evitar pedidos após o fechamento confirmado. */
    private static function escrever(PDO $db, int $idRestaurante, string $chave, array $valor): void
    {
        $propria = !$db->inTransaction();
        if ($propria) {
            $db->beginTransaction();
        }
        try {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $db->prepare('SELECT id FROM restaurantes WHERE id = ? FOR UPDATE')->execute([$idRestaurante]);
            }
            $db->prepare("UPDATE integracoes
                SET dados = JSON_SET(COALESCE(NULLIF(dados,''),'{}'), '$." . $chave . "', CAST(? AS JSON))
                WHERE id_restaurante = ? AND provedor = 'ifood'")
                ->execute([json_encode($valor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $idRestaurante]);
            if ($propria) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($propria && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function avisar(int $idRestaurante, string $acao): void
    {
        TempoReal::publicar($idRestaurante, 'geral', 'loja.disponibilidade', ['acao' => $acao]);
    }
}
