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
            // Repetido tambem responde 200: o Asaas entrega "pelo menos uma vez".
            return self::texto('ok', 200);
        } catch (Throwable $exception) {
            error_log('[Cardapim] Falha no webhook Asaas: ' . get_class($exception));
            return self::texto('Erro temporário.', 500);
        }
    }

    private static function texto(string $mensagem, int $status): Response
    {
        return new Response($mensagem, $status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
