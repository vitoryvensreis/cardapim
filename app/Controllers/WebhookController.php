<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Throwable;

/**
 * Recebe avisos (webhooks) dos provedores. Regras:
 * - a URL tem um identificador aleatorio por restaurante e o token secreto e conferido em tempo constante;
 * - o aviso e guardado (sem repetir o mesmo id) e respondemos 200 rapido; o processamento vem depois.
 */
final class WebhookController
{
    private const TAMANHO_MAXIMO = 262144; // 256 KB

    public function asaas(string $webhookId): Response
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $limite = RateLimiter::padrao(30, 300);
        if ($limite->excedido('webhook-falha|' . $ip)) {
            return self::texto('Muitas tentativas.', 429);
        }
        try {
            $integracao = (new IntegracaoRepository(Database::connect()))->porWebhook('asaas', $webhookId);
            $esperado = (string) ($integracao['credenciais']['token_webhook'] ?? '');
            $recebido = (string) ($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '');
            if ($integracao === null || $esperado === '' || !hash_equals($esperado, $recebido)) {
                $limite->permitir('webhook-falha|' . $ip);
                return self::texto('Não autorizado.', 401);
            }
            $corpo = (string) file_get_contents('php://input', false, null, 0, self::TAMANHO_MAXIMO + 1);
            if ($corpo === '' || strlen($corpo) > self::TAMANHO_MAXIMO) {
                return self::texto('Aviso inválido.', 400);
            }
            $dados = json_decode($corpo, true);
            if (!is_array($dados) || !is_string($dados['event'] ?? null)) {
                return self::texto('Aviso inválido.', 400);
            }
            $evento = preg_replace('/[^A-Z0-9_]/', '', strtoupper($dados['event'])) ?: 'DESCONHECIDO';
            $idExterno = is_string($dados['id'] ?? null) ? $dados['id'] : null;
            // id_restaurante null = Asaas da PLATAFORMA (pagamento das assinaturas dos restaurantes).
            $novo = (new IntegracaoRepository(Database::connect()))->registrarEvento($integracao['id_restaurante'], 'asaas', $evento, $idExterno, $corpo);
            if ($novo && $integracao['id_restaurante'] !== null) {
                // A tela de Integracoes aberta mostra "chegou aviso" na hora (so o nome do evento; nada do pagamento).
                \Cardapim\Realtime\TempoReal::publicar($integracao['id_restaurante'], 'integracoes', 'integracao.asaas.aviso', ['evento' => $evento]);
            }
            if ($integracao['id_restaurante'] !== null) {
                $this->pagamentoDePedido((int) $integracao['id_restaurante'], $evento, $dados, $idExterno);
            } else {
                // Asaas da plataforma: faturas das assinaturas (paga = libera o restaurante; repetido nao muda nada).
                (new \Cardapim\Services\Plataforma\AssinaturaCobranca(Database::connect()))->processarAviso($evento, $dados);
            }
            // Repetido tambem responde 200: o Asaas entrega "pelo menos uma vez".
            return self::texto('ok', 200);
        } catch (Throwable $exception) {
            error_log('[Cardapim] Falha no webhook Asaas: ' . get_class($exception));
            return self::texto('Erro temporário.', 500);
        }
    }

    /**
     * Pix pago (pedido do cardapio ou comanda da mesa): confirma (idempotente; o aviso repetido nao muda nada).
     * Falha aqui nao derruba o aviso: a tela do cliente tambem confere a cobranca enquanto espera.
     * @param array<string,mixed> $dados
     */
    private function pagamentoDePedido(int $idRestaurante, string $evento, array $dados, ?string $idExterno): void
    {
        if (!in_array($evento, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true)) {
            return;
        }
        $pagamento = is_array($dados['payment'] ?? null) ? $dados['payment'] : [];
        $referencia = (string) ($pagamento['externalReference'] ?? '');
        $idCobranca = (string) ($pagamento['id'] ?? '');
        if (preg_match('/\A(pedido|comanda):\d{1,18}\z/', $referencia) !== 1 || preg_match('/\A[A-Za-z0-9_-]{3,60}\z/', $idCobranca) !== 1) {
            return;
        }
        $valor = number_format((float) ($pagamento['value'] ?? 0), 2, '.', '');
        try {
            // "comanda:{id}" = Pix com QR Code na mesa (painel do garcom); "pedido:{id}" = Pix do cardapio online.
            $resultado = str_starts_with($referencia, 'comanda:')
                ? (new \Cardapim\Services\Garcom\ComandaPix(Database::connect(), $idRestaurante))->confirmar($idCobranca, $valor, 'webhook')
                : (new \Cardapim\Services\Pedidos\PixPedido(Database::connect()))->confirmar($idRestaurante, $idCobranca, $valor, 'webhook');
            if ($resultado !== 'nao_encontrado' && $idExterno !== null) {
                (new IntegracaoRepository(Database::connect()))->marcarProcessado('asaas', $idExterno);
            }
        } catch (Throwable $exception) {
            error_log('[Cardapim] Pix do pedido nao confirmado pelo aviso: ' . get_class($exception));
        }
    }

    private static function texto(string $mensagem, int $status): Response
    {
        return new Response($mensagem, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
