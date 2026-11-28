<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Config;
use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Modules\Integracoes\WahaApi;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Pedidos\SaudacaoWhatsApp;
use Cardapim\Services\WhatsApp\DiagnosticoSaudacao;
use Cardapim\Services\WhatsApp\EventoWaha;
use Cardapim\Services\WhatsApp\TesteWhatsApp;
use Throwable;

/**
 * Webhook do WAHA (VPS de mensageria do Deliveryzim). Protecao em duas camadas:
 * 1. segredo na URL (WAHA_WEBHOOK_TOKEN, 32+ caracteres), igual para todas as sessoes;
 * 2. assinatura X-Webhook-Hmac (HMAC-SHA512 do corpo) com o token guardado da loja dona da sessao.
 * Eventos que nao sao mensagem recebida, ou de sessao sem loja ativa, respondem 200 e sao ignorados
 * (o WAHA nao reenvia).
 */
final class WahaWebhookController
{
    public function mensagem(string $token): Response
    {
        $segredo = Config::get('WAHA_WEBHOOK_TOKEN');
        if (strlen($segredo) < 32 || !hash_equals($segredo, $token)) {
            return Response::json(['erro' => 'Não autorizado'], 401);
        }
        $corpo = (string) file_get_contents('php://input', false, null, 0, 262145);
        if ($corpo === '' || strlen($corpo) > 262144) {
            return Response::json(['erro' => 'Corpo inválido'], 400);
        }
        try {
            $payload = json_decode($corpo, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                return Response::json(['erro' => 'Evento inválido'], 400);
            }
            $evento = EventoWaha::paraSaudacao($payload);
            if ($evento === null) {
                return Response::json(['ok' => true, 'ignorado' => true], 200);
            }
            $sessao = (string) $evento['session'];
            $db = Database::connect();
            $repo = new IntegracaoRepository($db);
            $id = $repo->restaurantePorSessaoWhatsApp($sessao);
            if ($id === null) {
                DiagnosticoSaudacao::registrar($sessao, 'sessao_sem_restaurante_ativo');
                return Response::json(['ok' => true, 'ignorado' => true], 200);
            }
            $guardado = $repo->credenciais($id, 'whatsapp')['token'] ?? null;
            if (!is_string($guardado) || !WahaApi::assinaturaValida($corpo, $guardado, $_SERVER['HTTP_X_WEBHOOK_HMAC'] ?? null)) {
                DiagnosticoSaudacao::registrar($sessao, 'token_recusado');
                return Response::json(['erro' => 'Não autorizado'], 401);
            }
            // Mensagem do teste automatico (WhatsApp da plataforma -> loja): confirma o recebimento, sem boas-vindas.
            if (($evento['fromMe'] ?? null) === false && (new TesteWhatsApp($db))->recebido($id, $evento)) {
                return Response::json(['ok' => true, 'teste' => true], 200);
            }
            SaudacaoWhatsApp::receber($db, $evento);
            return Response::json(['ok' => true], 200);
        } catch (\JsonException) {
            return Response::json(['erro' => 'JSON inválido'], 400);
        } catch (Throwable $erro) {
            error_log('[Deliveryzim] Webhook WAHA: ' . get_class($erro));
            return Response::json(['erro' => 'Falha temporária'], 500);
        }
    }
}
