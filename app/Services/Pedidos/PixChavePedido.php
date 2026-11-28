<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Shared\Numero;
use Cardapim\Shared\PixCopiaECola;
use PDO;
use Throwable;

/**
 * Pix pela chave do restaurante no cardapio online (forma "pix_comprovante"):
 * - O pedido nasce "recebido" com o copia e cola (BR Code estatico): o restaurante e avisado na hora.
 * - O caixa aceita (a producao imprime com "SOLICITAR COMPROVANTE") e confirma o Pix quando receber o comprovante
 *   (DeliveryService::confirmarPix). "Pix nao recebido" cancela o pedido com o motivo.
 * - O cliente fica na tela do QR (com "Enviar comprovante", que abre o WhatsApp do restaurante) ate o restaurante
 *   confirmar; depois a tela mostra o acompanhamento.
 * - Pedidos antigos, feitos antes desta regra, ainda podem estar "aguardando pagamento": o comprovante os manda
 *   para o restaurante e o prazo os cancela (bin/expirar-pix.php), como antes.
 * Nao ha confirmacao automatica: a conferencia e do restaurante.
 */
final class PixChavePedido
{
    public const PRAZO_MINUTOS = 60;

    /**
     * Monta o codigo do total do pedido e guarda no pedido. Sem prazo: o restaurante ja ve o pedido e decide.
     * @param array<string,mixed> $pedido @param array<string,mixed> $chave EntregaPagamentoRepository::pixChave
     */
    public static function gerar(PDO $db, array $pedido, array $chave): void
    {
        $codigo = PixCopiaECola::gerar((string) $chave['chave'], (string) $chave['recebedor'], (string) $chave['cidade'],
            Numero::centavos((string) $pedido['total']), 'CARDAPIM' . (int) $pedido['numero']);
        $db->prepare('UPDATE pedidos SET pix_copia_cola = ?, pix_expira_em = NULL, atualizado_em = ? WHERE id = ? AND id_restaurante = ?')
            ->execute([$codigo, date('Y-m-d H:i:s'), (int) $pedido['id'], (int) $pedido['id_restaurante']]);
    }

    /** O cliente ainda precisa pagar ou o restaurante ainda nao confirmou o Pix. @param array<string,mixed> $p */
    public static function esperandoConfirmacao(array $p): bool
    {
        return ($p['forma_pagamento'] ?? '') === 'pix_comprovante' && ($p['pagamento_status'] ?? '') === 'pendente'
            && !in_array($p['status'] ?? '', ['concluido', 'cancelado'], true);
    }

    /**
     * "Enviar comprovante": avisa o restaurante (uma vez). Repetido nao faz nada de novo.
     * @param array<string,mixed> $pedido do cliente
     * @return string enviado | repetido | expirado | invalido
     */
    public static function comprovante(PDO $db, array $pedido): string
    {
        if (($pedido['forma_pagamento'] ?? '') !== 'pix_comprovante') {
            return 'invalido';
        }
        if (!empty($pedido['comprovante_enviado_em']) || ($pedido['pagamento_status'] ?? '') === 'pago') {
            return 'repetido';
        }
        if ($pedido['status'] !== 'aguardando_pagamento') {
            return self::esperandoConfirmacao($pedido) ? self::avisarComprovante($db, $pedido) : 'invalido';
        }
        if (!empty($pedido['pix_expira_em']) && strtotime((string) $pedido['pix_expira_em']) <= time()) {
            return 'expirado';
        }
        $idRest = (int) $pedido['id_restaurante'];
        $idPedido = (int) $pedido['id'];
        $agora = date('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $q = $db->prepare("UPDATE pedidos SET status = 'recebido', comprovante_enviado_em = ?, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ? AND status = 'aguardando_pagamento' AND forma_pagamento = 'pix_comprovante' AND comprovante_enviado_em IS NULL");
            $q->execute([$agora, $agora, $idPedido, $idRest]);
            if ($q->rowCount() !== 1) {
                $db->rollBack();
                return 'repetido';
            }
            (new PedidoRepository($db))->registrarEvento($idRest, $idPedido, 'recebido',
                'Cliente enviou o comprovante do Pix pelo WhatsApp. Confira no app do banco antes de aceitar.', null, (int) $pedido['id_cliente']);
            AvisosWhatsApp::enfileirar($db, $idRest, $idPedido, 'recebido');
            TempoReal::registrar($db, $idRest, 'pedidos', 'pedido.novo', ['id' => $idPedido, 'numero' => \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido)]);
            $db->commit();
            return 'enviado';
        } catch (Throwable $erro) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $erro;
        }
    }

    /**
     * Pedido que ja esta com o restaurante: so marca que o cliente mandou o comprovante (o caixa ve na hora).
     * @param array<string,mixed> $pedido
     */
    private static function avisarComprovante(PDO $db, array $pedido): string
    {
        $idRest = (int) $pedido['id_restaurante'];
        $idPedido = (int) $pedido['id'];
        $agora = date('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $q = $db->prepare("UPDATE pedidos SET comprovante_enviado_em = ?, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ? AND forma_pagamento = 'pix_comprovante' AND pagamento_status = 'pendente'
                    AND comprovante_enviado_em IS NULL AND status NOT IN ('concluido', 'cancelado', 'aguardando_pagamento')");
            $q->execute([$agora, $agora, $idPedido, $idRest]);
            if ($q->rowCount() !== 1) {
                $db->rollBack();
                return 'repetido';
            }
            (new PedidoRepository($db))->registrarEvento($idRest, $idPedido, (string) $pedido['status'],
                'Cliente avisou que mandou o comprovante do Pix pelo WhatsApp. Confira no app do banco e confirme o Pix.', null, (int) $pedido['id_cliente']);
            TempoReal::registrar($db, $idRest, 'pedidos', 'pedido.atualizado',
                ['id' => $idPedido, 'numero' => \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido), 'status' => (string) $pedido['status']]);
            $db->commit();
            return 'enviado';
        } catch (Throwable $erro) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $erro;
        }
    }

    /** Pedido antigo sem comprovante no prazo: cancela e devolve o estoque. @return string cancelado | ignorado */
    public static function expirar(PDO $db, int $idRestaurante, int $idPedido): string
    {
        $p = (new PedidoRepository($db))->buscar($idRestaurante, $idPedido);
        if ($p === null || $p['status'] !== 'aguardando_pagamento' || $p['forma_pagamento'] !== 'pix_comprovante'
            || empty($p['pix_expira_em']) || strtotime((string) $p['pix_expira_em']) > time()) {
            return 'ignorado';
        }
        return (new CancelarPedido($db))->cancelar($idRestaurante, $idPedido, 'O comprovante do Pix não foi enviado em ' . self::PRAZO_MINUTOS . ' minutos.', null, null, true)
            ? 'cancelado' : 'ignorado';
    }

    /**
     * Link do WhatsApp do restaurante com a mensagem pronta do comprovante (ou null sem telefone).
     * @param array<string,mixed> $pedido
     */
    public static function linkComprovante(PDO $db, array $pedido): ?string
    {
        $idRest = (int) $pedido['id_restaurante'];
        $numero = (new EntregaPagamentoRepository($db))->pixChave($idRest)['whatsapp'];
        if ($numero === '') {
            $q = $db->prepare('SELECT telefone FROM restaurantes WHERE id = ?');
            $q->execute([$idRest]);
            $numero = preg_replace('/\D/', '', (string) $q->fetchColumn()) ?? '';
        }
        if (strlen($numero) < 10) {
            return null;
        }
        $numero = strlen($numero) <= 11 ? '55' . $numero : $numero;
        $nome = trim((string) ($pedido['cliente_nome'] ?? ''));
        $texto = sprintf('Olá! Segue o comprovante do Pix do pedido #%d, %s%s.', \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido), Numero::reais((string) $pedido['total']),
            $nome !== '' ? ', em nome de ' . $nome : '');
        return 'https://wa.me/' . $numero . '?text=' . rawurlencode($texto);
    }
}
