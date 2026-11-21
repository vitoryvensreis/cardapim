<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Config;
use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Pedidos\SaudacaoWhatsApp;
use Cardapim\Services\WhatsApp\EventoEvolution;
use Throwable;

/**
 * Webhook da Evolution API (VPS de mensageria do Deliveryzim).
 * Protecao em duas camadas:
 * 1. segredo na URL (EVOLUTION_WEBHOOK_TOKEN, 32+ caracteres), igual para todas as instancias;
 * 2. o apikey que a Evolution manda no corpo precisa ser o token guardado da loja (ou a chave global).
 * Eventos que nao sao mensagem recebida respondem 200 e sao ignorados (a Evolution nao reenvia).
 */
final class EvolutionWebhookController
{
    public function mensagem(string $token): Response
    {
        $segredo = Config::get('EVOLUTION_WEBHOOK_TOKEN');
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
            $evento = EventoEvolution::paraSaudacao($payload);
            if ($evento === null) {
                return Response::json(['ok' => true, 'ignorado' => true], 200);
            }
            $db = Database::connect();
            if (!self::tokenConfere($db, (string) $evento['session'], EventoEvolution::tokenInstancia($payload))) {
                \Cardapim\Services\WhatsApp\DiagnosticoSaudacao::registrar((string) $evento['session'], 'token_recusado');
                return Response::json(['erro' => 'Não autorizado'], 401);
            }
            SaudacaoWhatsApp::receber($db, $evento);
            return Response::json(['ok' => true], 200);
        } catch (\JsonException) {
            return Response::json(['erro' => 'JSON inválido'], 400);
        } catch (Throwable $erro) {
            error_log('[Deliveryzim] Webhook Evolution: ' . get_class($erro));
            return Response::json(['erro' => 'Falha temporária'], 500);
        }
    }

    /** Instancia de loja: o token do corpo (quando enviado) tem de ser o da loja. Sem loja, a saudacao ignora. */
    private static function tokenConfere(\PDO $db, string $sessao, ?string $tokenRecebido): bool
    {
        $repo = new IntegracaoRepository($db);
        $id = $repo->restaurantePorSessaoWhatsApp($sessao);
        if ($id === null || $tokenRecebido === null) {
            return true;
        }
        $guardado = $repo->credenciais($id, 'whatsapp')['token'] ?? null;
        if (is_string($guardado) && $guardado !== '' && hash_equals($guardado, $tokenRecebido)) {
            return true;
        }
        // Algumas versoes da Evolution mandam a chave global no lugar do token da instancia.
        $global = Config::get('EVOLUTION_API_KEY');
        return $global !== '' && hash_equals($global, $tokenRecebido);
    }
}
