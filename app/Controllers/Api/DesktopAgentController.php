<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Api;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Repositories\Desktop\PesagemRepository;
use Cardapim\Services\Auth\Autenticacao;
use Cardapim\Services\Auth\Autorizacao;
use PDO;
use Throwable;

/**
 * API minima para o agente Windows:
 * - Vinculacao com login de equipe + CSRF, sem armazenar senha no agente;
 * - Token de dispositivo escopado a um unico restaurante, revogavel, hash SHA-256 no banco;
 * - Consulta de eventos "pedido.novo" na outbox; sem acesso cruzado entre tenants.
 * O botao "Receber" do agente apenas reconhece o aviso local; nao aceita o pedido comercialmente.
 */
final class DesktopAgentController
{
    private const CARGOS = ['gerente', 'caixa'];
    private const VALID_DIAS = 90;

    public function csrf(): Response
    {
        $usuario = Autenticacao::usuario();
        if ($usuario === null || !in_array($usuario->cargo, self::CARGOS, true)
            || $usuario->idRestaurante === null || !Autorizacao::contaLiberada($usuario)) {
            return Response::json(['erro' => 'Acesso negado. Entre com conta de gerente ou caixa.'], 401);
        }
        return Response::json(['csrf' => Session::tokenCsrf(), 'restaurante' => $usuario->restauranteNome]);
    }

    public function vincular(): Response
    {
        $usuario = Autenticacao::usuario();
        if ($usuario === null || $usuario->idRestaurante === null || !in_array($usuario->cargo, self::CARGOS, true)
            || !Autorizacao::contaLiberada($usuario)) {
            return Response::json(['erro' => 'Acesso negado.'], 403);
        }
        if (!Session::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            return Response::json(['erro' => 'Sessao expirada. Entre novamente.'], 419);
        }
        if (!RateLimiter::padrao(10, 3600)->permitir('desktop-vincular|' . $usuario->id)) {
            return Response::json(['erro' => 'Limite de dispositivos. Tente novamente mais tarde.'], 429);
        }
        $dados = json_decode(file_get_contents('php://input') ?: '', true);
        $nome = is_array($dados) && is_string($dados['nome'] ?? null) ? trim($dados['nome']) : '';
        $nome = mb_substr($nome, 0, 90);
        if (mb_strlen($nome) < 2) {
            return Response::json(['erro' => 'Informe o nome deste computador.'], 422);
        }
        $chave = 'dzag_' . bin2hex(random_bytes(32));
        $hash = hash('sha256', $chave);
        $expira = date('Y-m-d H:i:s', time() + self::VALID_DIAS * 86400);
        try {
            $db = Database::connect();
            $q = $db->prepare('INSERT INTO desktop_agent_devices (id_restaurante, id_user, nome, token_hash, expira_em) VALUES (?, ?, ?, ?, ?)');
            $q->execute([$usuario->idRestaurante, $usuario->id, $nome, $hash, $expira]);
            $id = (int) $db->lastInsertId();
            // Ponto de partida: eventos anteriores ao cadastro nao geram impressoes de surpresa.
            $s = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM outbox_eventos WHERE id_restaurante = ? AND canal = 'pedidos' AND tipo = 'pedido.novo'");
            $s->execute([$usuario->idRestaurante]);
            $cursor = (int) $s->fetchColumn();
            return Response::json([
                'token' => $chave, 'device_id' => $id, 'cursor' => $cursor,
                'restaurante' => $usuario->restauranteNome, 'expira_em' => $expira,
            ], 201);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: vinculacao indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Nao foi possivel vincular este computador.'], 503);
        }
    }

    public function eventos(): Response
    {
        $autorizado = $this->dispositivo();
        if ($autorizado instanceof Response) return $autorizado;
        [$db, $dispositivo] = $autorizado;
        $cursor = filter_var($_GET['desde'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($cursor === false || $cursor === null) {
            return Response::json(['erro' => 'Cursor invalido.'], 422);
        }
        try {
            $q = $db->prepare("SELECT id, dados FROM outbox_eventos
                WHERE id_restaurante = ? AND canal = 'pedidos' AND tipo = 'pedido.novo' AND id > ?
                ORDER BY id LIMIT 100");
            $q->execute([(int) $dispositivo['id_restaurante'], $cursor]);
            $pedidos = new PedidoRepository($db);
            $resposta = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $evento) {
                $cursor = (int) $evento['id'];
                $dados = json_decode((string) $evento['dados'], true);
                $id = is_array($dados) ? (int) ($dados['id'] ?? 0) : 0;
                if ($id < 1) continue;
                $p = $pedidos->buscar((int) $dispositivo['id_restaurante'], $id);
                if (!$p || !in_array($p['tipo'], ['entrega', 'retirada'], true)
                    || !in_array($p['status'], ['recebido', 'em_preparo', 'pronto'], true)) {
                    continue;
                }
                $resposta[] = [
                    'evento_id' => $cursor, 'id' => (int) $p['id'],
                    'numero' => (int) $p['numero'], 'tipo' => (string) $p['tipo'],
                    'status' => (string) $p['status'], 'origem' => (string) $p['origem'],
                    'cliente' => (string) ($p['cliente_nome'] ?? ''),
                    'endereco' => array_filter([
                        $p['entrega_logradouro'] ?? null, $p['entrega_numero'] ?? null,
                        $p['entrega_complemento'] ?? null, $p['entrega_bairro'] ?? null,
                        $p['entrega_cidade'] ?? null,
                    ], static fn ($v) => is_string($v) && trim($v) !== ''),
                    'observacao' => (string) ($p['observacao'] ?? ''),
                    'pagamento' => (string) ($p['forma_pagamento'] ?? ''),
                    'total' => (string) $p['total'],
                    'criado_em' => (string) $p['criado_em'],
                    'itens' => array_map(static fn (array $item): array => [
                        'nome' => (string) $item['nome'],
                        'quantidade' => (string) $item['quantidade'],
                        'observacao' => (string) ($item['observacao'] ?? ''),
                        'complementos' => (string) ($item['complementos_texto'] ?? ''),
                        'total' => (string) $item['total'],
                    ], $pedidos->itens((int) $dispositivo['id_restaurante'], $id)),
                ];
            }
            return Response::json(['cursor' => $cursor, 'pedidos' => $resposta]);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: consulta indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Falha ao consultar pedidos.'], 503);
        }
    }

    /**
     * Leitura recente enviada SOMENTE por agente autenticado. Nunca recebe um preco,
     * identificador de restaurante ou ID de dispositivo no corpo da requisicao.
     */
    public function registrarPesagem(): Response
    {
        $autorizado = $this->dispositivo();
        if ($autorizado instanceof Response) return $autorizado;
        [$db, $d] = $autorizado;

        $body = file_get_contents('php://input');
        if (!is_string($body) || strlen($body) > 1024) {
            return Response::json(['erro' => 'Dados de pesagem invalidos.'], 422);
        }
        $input = json_decode($body, true);
        if (!is_array($input) || !is_bool($input['estavel'] ?? null)) {
            return Response::json(['erro' => 'Estado de pesagem invalido.'], 422);
        }
        $estavel = $input['estavel'];
        $kg = $input['peso_kg'] ?? null;
        if ($kg !== null && (!is_string($kg)
            || preg_match('/\A(?:\d{1,2})(?:\.\d{1,3})?\z/D', $kg) !== 1
            || (float) $kg > 99.999)) {
            return Response::json(['erro' => 'Peso fora do formato permitido.'], 422);
        }
        if ($estavel && $kg === null) {
            return Response::json(['erro' => 'Falta o peso estavel.'], 422);
        }
        // Se o monitor perdeu a balanca, apaga-se o peso em vez de manter valor antigo.
        if (!$estavel) $kg = null;
        try {
            (new PesagemRepository($db))->registrar(
                (int) $d['id_restaurante'], (int) $d['id'], $kg, $estavel
            );
            return Response::json(['ok' => true]);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: registro de pesagem indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Registro de peso indisponivel.'], 503);
        }
    }

    /** Apenas a propria equipe autenticada consulta as balancas do restaurante. */
    public function balancas(): Response
    {
        $usuario = Autenticacao::usuario();
        if ($usuario === null || $usuario->idRestaurante === null
            || !in_array($usuario->cargo, self::CARGOS, true)
            || !Autorizacao::contaLiberada($usuario)) {
            return Response::json(['erro' => 'Acesso negado.'], 403);
        }
        try {
            $db = Database::connect();
            return Response::json([
                'balancas' => (new PesagemRepository($db))->listar((int) $usuario->idRestaurante),
            ]);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: leitura de balancas indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Balancas indisponiveis.'], 503);
        }
    }

    public function desconectar(): Response
    {
        $autorizado = $this->dispositivo();
        if ($autorizado instanceof Response) return $autorizado;
        [$db, $d] = $autorizado;
        $q = $db->prepare('UPDATE desktop_agent_devices SET revogado_em = NOW() WHERE id = ? AND revogado_em IS NULL');
        $q->execute([(int) $d['id']]);
        return Response::json(['ok' => true]);
    }

    /**
     * @return array{0:PDO,1:array<string,mixed>}|Response
     */
    private function dispositivo(): array|Response
    {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($auth === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower((string) $k) === 'authorization') $auth = (string) $v;
            }
        }
        if (!preg_match('/\ABearer (dzag_[a-f0-9]{64})\z/', $auth, $m)) {
            return Response::json(['erro' => 'Dispositivo nao autenticado.'], 401);
        }
        try {
            $db = Database::connect();
            $q = $db->prepare("SELECT d.*, u.ativo, u.cargo, u.excluido_em, r.status AS restaurante_status
                FROM desktop_agent_devices d
                JOIN `user` u ON u.id = d.id_user AND u.id_restaurante = d.id_restaurante
                JOIN restaurantes r ON r.id = d.id_restaurante
                WHERE d.token_hash = ? AND d.revogado_em IS NULL AND d.expira_em > NOW() LIMIT 1");
            $q->execute([hash('sha256', $m[1])]);
            $device = $q->fetch(PDO::FETCH_ASSOC);
            if (!$device || (int) $device['ativo'] !== 1 || $device['excluido_em'] !== null
                || !in_array((string) $device['cargo'], self::CARGOS, true)
                || !in_array((string) $device['restaurante_status'], Autorizacao::STATUS_COM_ACESSO, true)) {
                return Response::json(['erro' => 'Dispositivo revogado, expirado ou sem acesso.'], 401);
            }
            $db->prepare('UPDATE desktop_agent_devices SET ultimo_acesso_em = NOW() WHERE id = ?')->execute([$device['id']]);
            return [$db, $device];
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: autenticacao indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Autenticacao temporariamente indisponivel.'], 503);
        }
    }
}
