<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Core\Database;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Shared\Numero;
use PDO;
use PDOException;
use Throwable;

/**
 * Avisos do pedido para o cliente pelo WhatsApp DO RESTAURANTE (o conectado em Gerencia > Integracoes > WhatsApp).
 *
 * Transactional Outbox: enfileirar() grava o aviso na MESMA transacao da mudanca do pedido (nunca derruba a acao).
 * O envio acontece depois da resposta (o caixa nao espera o WhatsApp), com novas tentativas (1, 3, 10, 30 min) e sem
 * repetir (UNIQUE pedido + evento). Aviso de uma etapa que o pedido ja passou nao e enviado (ex.: "pronto" depois de entregue).
 */
final class AvisosWhatsApp
{
    /** evento => [rotulo na tela do gerente, explicacao] */
    public const EVENTOS = [
        'recebido' => ['Pedido recebido', 'Confirma o pedido e manda o link para acompanhar.'],
        'em_preparo' => ['Em preparo', 'Avisa que a cozinha começou a preparar.'],
        'pronto' => ['Pronto', 'Avisa que ficou pronto (na retirada: que pode vir buscar).'],
        'saiu_entrega' => ['Saiu para entrega', 'Avisa quem vai levar, o valor a pagar e o link do mapa com a moto.'],
        'concluido' => ['Entregue ou retirado', 'Agradece depois da entrega.'],
        'cancelado' => ['Cancelado', 'Avisa o cancelamento e o motivo.'],
    ];

    private const ORDEM = ['aguardando_pagamento' => 0, 'recebido' => 1, 'em_preparo' => 2, 'pronto' => 3, 'saiu_entrega' => 4, 'concluido' => 5, 'cancelado' => 5];
    private const ESPERA_MINUTOS = [1, 3, 10, 30];
    private const MAX_TENTATIVAS = 5;

    /** @var array<int,true> restaurantes com envio marcado para o fim desta requisicao */
    private static array $agendados = [];

    /** Eventos ligados no restaurante (sem configuracao = todos). @return list<string> */
    public static function ligados(PDO $db, int $idRestaurante): array
    {
        try {
            $q = $db->prepare('SELECT eventos FROM restaurante_avisos_whatsapp WHERE id_restaurante = ?');
            $q->execute([$idRestaurante]);
            $v = $q->fetchColumn();
        } catch (Throwable) {
            $v = false;
        }
        if ($v === false) {
            return array_keys(self::EVENTOS);
        }
        return array_values(array_intersect(array_keys(self::EVENTOS), explode(',', (string) $v)));
    }

    /** @param list<string> $eventos */
    public static function salvarLigados(PDO $db, int $idRestaurante, array $eventos): void
    {
        $lista = implode(',', array_values(array_intersect(array_keys(self::EVENTOS), $eventos)));
        $agora = date('Y-m-d H:i:s');
        $u = $db->prepare('UPDATE restaurante_avisos_whatsapp SET eventos = ?, atualizado_em = ? WHERE id_restaurante = ?');
        $u->execute([$lista, $agora, $idRestaurante]);
        $existe = $db->prepare('SELECT 1 FROM restaurante_avisos_whatsapp WHERE id_restaurante = ?');
        $existe->execute([$idRestaurante]);
        if ($existe->fetchColumn() === false) {
            $db->prepare('INSERT INTO restaurante_avisos_whatsapp (id_restaurante, eventos, atualizado_em) VALUES (?, ?, ?)')->execute([$idRestaurante, $lista, $agora]);
        }
    }

    /**
     * Grava o aviso na fila (chamar DENTRO da transacao que muda o pedido). Nunca lanca erro.
     * So para pedidos do cardapio com WhatsApp do cliente, evento ligado e WhatsApp do restaurante configurado.
     */
    public static function enfileirar(PDO $db, int $idRestaurante, int $idPedido, string $evento): bool
    {
        if (!isset(self::EVENTOS[$evento])) {
            return false;
        }
        try {
            $q = $db->prepare("SELECT p.id, p.numero, p.tipo, p.status, p.cliente_nome, p.cliente_whatsapp, p.forma_pagamento, p.pagamento_status,
                    p.total, p.troco_para, p.motivo_cancelamento, r.nome AS restaurante_nome, r.slug AS restaurante_slug, u.nome AS entregador_nome
                FROM pedidos p JOIN restaurantes r ON r.id = p.id_restaurante LEFT JOIN `user` u ON u.id = p.id_entregador
                WHERE p.id = ? AND p.id_restaurante = ?");
            $q->execute([$idPedido, $idRestaurante]);
            $p = $q->fetch(PDO::FETCH_ASSOC);
            if ($p === false || preg_match('/\A[0-9]{10,11}\z/', (string) $p['cliente_whatsapp']) !== 1) {
                return false;
            }
            if (!in_array($evento, self::ligados($db, $idRestaurante), true)) {
                return false;
            }
            // Cancelamento de pedido que nunca foi confirmado (Pix nao pago): o cliente ja viu na tela.
            if ($evento === 'cancelado') {
                $r = $db->prepare("SELECT 1 FROM pedido_eventos WHERE id_pedido = ? AND id_restaurante = ? AND status = 'recebido' LIMIT 1");
                $r->execute([$idPedido, $idRestaurante]);
                if ($r->fetchColumn() === false) {
                    return false;
                }
            }
            $integracao = (new IntegracaoRepository($db))->buscar($idRestaurante, 'whatsapp');
            if (!in_array($integracao['status'], ['conectado', 'erro'], true)) {
                return false;
            }
            $link = BaseIntegracao::urlPublica('/' . $p['restaurante_slug'] . '/pedido/' . (int) $p['id']);
            $agora = date('Y-m-d H:i:s');
            try {
                $db->prepare('INSERT INTO pedido_avisos (id_restaurante, id_pedido, evento, mensagem, status, tentativas, proxima_tentativa_em, criado_em) VALUES (?, ?, ?, ?, ?, 0, ?, ?)')
                    ->execute([$idRestaurante, $idPedido, $evento, self::mensagem($p, $evento, $link), 'pendente', $agora, $agora]);
            } catch (PDOException) {
                return false; // ja existe (mesmo pedido + evento): nao repete
            }
            self::agendarEnvio($idRestaurante);
            return true;
        } catch (Throwable $erro) {
            error_log('[Cardapim] Aviso de WhatsApp nao enfileirado: ' . get_class($erro));
            return false;
        }
    }

    /**
     * Texto do aviso (sem emoji; *negrito* do WhatsApp).
     * @param array<string,mixed> $p pedido com restaurante_nome, cliente_nome, numero, tipo, forma_pagamento...
     */
    public static function mensagem(array $p, string $evento, string $link): string
    {
        $loja = '*' . trim((string) $p['restaurante_nome']) . '*';
        $n = '*#' . (int) $p['numero'] . '*';
        $nome = trim(explode(' ', trim((string) ($p['cliente_nome'] ?? '')))[0] ?? '');
        $retirada = ($p['tipo'] ?? '') === 'retirada';
        switch ($evento) {
            case 'recebido':
                return $loja . "\n" . ($nome !== '' ? 'Olá, ' . $nome . '! ' : '') . 'Recebemos seu pedido ' . $n . '. Vamos avisar por aqui a cada etapa.'
                    . "\n\nAcompanhe: " . $link;
            case 'em_preparo':
                return $loja . "\nSeu pedido " . $n . ' já está sendo preparado.';
            case 'pronto':
                return $loja . "\n" . ($retirada
                    ? 'Seu pedido ' . $n . ' está pronto. Pode vir retirar!'
                    : 'Seu pedido ' . $n . ' está pronto e já vai sair para entrega.');
            case 'saiu_entrega':
                $quem = trim(explode(' ', trim((string) ($p['entregador_nome'] ?? '')))[0] ?? '');
                $texto = $loja . "\nSeu pedido " . $n . ' saiu para entrega' . ($quem !== '' ? ' com ' . $quem : '') . '.';
                if (($p['pagamento_status'] ?? '') !== 'pago' && ($p['forma_pagamento'] ?? '') !== 'pix_online') {
                    $forma = ['dinheiro' => 'dinheiro', 'debito' => 'cartão de débito', 'credito' => 'cartão de crédito', 'pix_entrega' => 'Pix'][(string) $p['forma_pagamento']] ?? '';
                    $troco = ($p['forma_pagamento'] ?? '') === 'dinheiro' && !empty($p['troco_para']) ? ', troco para ' . Numero::reais((string) $p['troco_para']) : '';
                    $texto .= "\nPagamento na entrega: " . Numero::reais((string) $p['total']) . ($forma !== '' ? ' (' . $forma . $troco . ')' : '') . '.';
                }
                return $texto . "\n\nVeja a moto chegando no mapa: " . $link;
            case 'concluido':
                return $loja . "\n" . ($retirada ? 'Pedido ' . $n . ' retirado.' : 'Pedido ' . $n . ' entregue.') . ' Bom apetite e obrigado pela preferência!';
            case 'cancelado':
                $motivo = trim(preg_replace('/\ACancelado pelo restaurante:\s*/u', '', (string) ($p['motivo_cancelamento'] ?? '')) ?? '');
                return $loja . "\nSeu pedido " . $n . ' foi cancelado.' . ($motivo !== '' ? "\nMotivo: " . $motivo : '')
                    . "\n\nSe tiver alguma dúvida, é só responder esta mensagem.";
        }
        return $loja . "\nSeu pedido " . $n . ' foi atualizado: ' . $link;
    }

    /** Envia a fila do restaurante no fim da requisicao, depois de entregar a resposta (nao atrasa a tela). */
    public static function agendarEnvio(int $idRestaurante): void
    {
        if (isset(self::$agendados[$idRestaurante])) {
            return;
        }
        $primeiro = self::$agendados === [];
        self::$agendados[$idRestaurante] = true;
        if (!$primeiro) {
            return;
        }
        register_shutdown_function(static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // libera a sessao para as proximas telas enquanto o WhatsApp responde
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                $db = Database::connect();
                foreach (array_keys(self::$agendados) as $id) {
                    self::enviarPendentes($db, $id);
                }
            } catch (Throwable $erro) {
                error_log('[Cardapim] Envio de avisos de WhatsApp falhou: ' . get_class($erro));
            }
        });
    }

    /** Envia os avisos vencidos do restaurante. @return int quantos foram enviados */
    public static function enviarPendentes(PDO $db, int $idRestaurante, int $limite = 5, ?WppConnectApi $api = null): int
    {
        $agora = date('Y-m-d H:i:s');
        $q = $db->prepare("SELECT a.id, a.evento, a.mensagem, a.tentativas, p.cliente_whatsapp, p.status AS pedido_status
            FROM pedido_avisos a JOIN pedidos p ON p.id = a.id_pedido AND p.id_restaurante = a.id_restaurante
            WHERE a.id_restaurante = ? AND a.status = 'pendente' AND a.proxima_tentativa_em <= ? ORDER BY a.id LIMIT " . max(1, min(20, $limite)));
        $q->execute([$idRestaurante, $agora]);
        $fila = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($fila === []) {
            return 0;
        }
        $repo = new IntegracaoRepository($db);
        $integracao = $repo->buscar($idRestaurante, 'whatsapp');
        $sessao = $integracao['dados']['sessao'] ?? null;
        $token = $repo->credenciais($idRestaurante, 'whatsapp')['token'] ?? null;
        $pronto = WppConnectApi::disponivel() && $integracao['status'] === 'conectado' && is_string($sessao) && is_string($token) && $token !== '';
        $api ??= new WppConnectApi();
        $enviados = 0;
        foreach ($fila as $a) {
            $tentativa = (int) $a['tentativas'] + 1;
            $espera = self::ESPERA_MINUTOS[min($tentativa - 1, count(self::ESPERA_MINUTOS) - 1)];
            // Reserva o aviso (duas telas ao mesmo tempo nao mandam duas vezes).
            $reserva = $db->prepare("UPDATE pedido_avisos SET tentativas = ?, proxima_tentativa_em = ? WHERE id = ? AND status = 'pendente' AND tentativas = ?");
            $reserva->execute([$tentativa, date('Y-m-d H:i:s', time() + $espera * 60), (int) $a['id'], (int) $a['tentativas']]);
            if ($reserva->rowCount() !== 1) {
                continue;
            }
            $evento = (string) $a['evento'];
            $atual = self::ORDEM[(string) $a['pedido_status']] ?? 0;
            // "Recebido" leva o link de acompanhar: vale ate o pedido terminar. As outras etapas, so se ainda forem a atual.
            $passou = $evento === 'recebido' ? $atual >= 5 : ($evento !== 'cancelado' && $evento !== 'concluido' && $atual > self::ORDEM[$evento]);
            if ($passou) {
                self::fechar($db, (int) $a['id'], 'ignorado', 'O pedido já passou desta etapa.');
                continue;
            }
            if (!$pronto) {
                self::falhou($db, (int) $a['id'], $tentativa, 'O WhatsApp do restaurante está desconectado.');
                continue;
            }
            try {
                $api->enviarTexto($sessao, $token, '55' . $a['cliente_whatsapp'], (string) $a['mensagem']);
                $db->prepare("UPDATE pedido_avisos SET status = 'enviado', enviado_em = ?, ultimo_erro = NULL WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int) $a['id']]);
                $enviados++;
            } catch (IntegracaoErro $erro) {
                self::falhou($db, (int) $a['id'], $tentativa, $erro->getMessage());
            } catch (Throwable $erro) {
                self::falhou($db, (int) $a['id'], $tentativa, 'Falha inesperada ao enviar.');
                error_log('[Cardapim] Aviso de WhatsApp: ' . get_class($erro));
            }
        }
        return $enviados;
    }

    /** Ultimos avisos do restaurante (tela do gerente). @return list<array<string,mixed>> */
    public static function recentes(PDO $db, int $idRestaurante, int $limite = 10): array
    {
        try {
            $q = $db->prepare('SELECT a.evento, a.status, a.tentativas, a.ultimo_erro, a.criado_em, a.enviado_em, p.numero
                FROM pedido_avisos a JOIN pedidos p ON p.id = a.id_pedido AND p.id_restaurante = a.id_restaurante
                WHERE a.id_restaurante = ? ORDER BY a.id DESC LIMIT ' . max(1, min(50, $limite)));
            $q->execute([$idRestaurante]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private static function falhou(PDO $db, int $id, int $tentativa, string $erro): void
    {
        if ($tentativa >= self::MAX_TENTATIVAS) {
            self::fechar($db, $id, 'falhou', $erro);
            return;
        }
        $db->prepare('UPDATE pedido_avisos SET ultimo_erro = ? WHERE id = ?')->execute([mb_substr($erro, 0, 255), $id]);
    }

    private static function fechar(PDO $db, int $id, string $status, string $motivo): void
    {
        $db->prepare('UPDATE pedido_avisos SET status = ?, ultimo_erro = ? WHERE id = ?')->execute([$status, mb_substr($motivo, 0, 255), $id]);
    }
}
