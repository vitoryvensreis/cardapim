<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Config;
use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Services\Pedidos\SaudacaoWhatsApp;
use Throwable;

/**
 * Endpoint publico apenas para a instancia WPPConnect do Cardapim.
 * O segredo aleatorio nao depende de sessao de navegador; todas as lojas usam a mesma
 * URL no WPPConnect, mas cada evento e vinculado a uma sessao unica no MySQL.
 */
final class WppConnectWebhookController
{
    public function mensagem(string $token): Response
    {
        $segredo = Config::get('WPPCONNECT_WEBHOOK_TOKEN');
        if (strlen($segredo) < 32 || !hash_equals($segredo, $token)) {
            return Response::json(['erro' => 'Não autorizado'], 401);
        }
        $corpo = (string) file_get_contents('php://input', false, null, 0, 262145);
        if ($corpo === '' || strlen($corpo) > 262144) {
            return Response::json(['erro' => 'Corpo inválido'], 400);
        }
        try {
            $evento = json_decode($corpo, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($evento)) {
                return Response::json(['erro' => 'Evento inválido'], 400);
            }
            SaudacaoWhatsApp::receber(Database::connect(), $evento);
            return Response::json(['ok' => true], 200);
        } catch (\JsonException) {
            return Response::json(['erro' => 'JSON inválido'], 400);
        } catch (Throwable $erro) {
            error_log('[Cardapim] Webhook WPPConnect: ' . get_class($erro));
            return Response::json(['erro' => 'Falha temporária'], 500);
        }
    }
}
