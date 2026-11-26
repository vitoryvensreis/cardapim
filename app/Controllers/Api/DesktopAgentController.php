<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Api;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Repositories\Desktop\ImpressaoRepository;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Repositories\Desktop\PesagemRepository;
use Cardapim\Services\Desktop\VinculoBalanca;
use Cardapim\Services\Auth\Autenticacao;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Caixa\DeliveryService;
use Cardapim\Services\Pedidos\AvisosWhatsApp;
use Cardapim\Services\Impressao\AparenciaCupom;
use Cardapim\Services\Impressao\CupomImagem;
use Cardapim\Shared\Esquema;
use PDO;
use Throwable;

/**
 * API minima para o agente Windows:
 * - Vinculacao com login de equipe + CSRF, sem armazenar senha no agente;
 * - Token de dispositivo escopado a um unico restaurante, revogavel, hash SHA-256 no banco;
 * - Consulta de eventos "pedido.novo" na outbox; sem acesso cruzado entre tenants.
 * - "Receber" no aviso do agente aceita o pedido como o botao Aceitar do caixa (mesmo DeliveryService);
 * - Situacao dos pedidos avisados, para o agente parar o som quando alguem aceitar em outro lugar;
 * - "Agent conectado" para o navegador nao tocar o som repetido enquanto o agente toca.
 * - Fila de impressao (Agent 0.7.0+): cupons pedidos pelo caixa/garcom vem junto dos eventos (impressoes=1).
 * - Varias impressoras (Agent 0.8.0+): lista com apelidos (impressoras) e cupom destinado a uma delas (impressoras=1).
 */
final class DesktopAgentController
{
    private const CARGOS = ['gerente', 'caixa'];
    private const VALID_DIAS = 90;
    /** Agent consulta os eventos a cada 2,5 s; sem consulta ha 30 s, o navegador volta a tocar. */
    public const CONECTADO_SEGUNDOS = 30;

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
            // Agent 0.8.0+ (impressoras=1): recebe "impressoras": [] e so avisa o pedido novo. A impressao do delivery
            // sai no aceite, por produto (FilaImpressao::producaoPedido); o pedido completo, pelo botao Imprimir do caixa.
            $porImpressora = ($_GET['impressoras'] ?? '') === '1';
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
                    ...($porImpressora ? ['impressoras' => []] : []),
                    'numero' => (int) $p['numero'], 'tipo' => (string) $p['tipo'],
                    'status' => (string) $p['status'], 'origem' => (string) $p['origem'],
                    // "Ver no sistema" do aviso: caminho relativo, o agente junta com o endereco oficial.
                    'url' => '/' . rawurlencode((string) $dispositivo['restaurante_slug']) . '/caixa/delivery/' . (int) $p['id'],
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
            $saida = ['cursor' => $cursor, 'pedidos' => $resposta];
            // Agent 0.7.0+ avisa que sabe imprimir (impressoes=1): so ele reserva cupons da fila.
            // Agent antigo nunca pega um cupom que ele nao saberia imprimir.
            if (($_GET['impressoes'] ?? '') === '1') {
                $saida['impressoes'] = $this->reservarImpressoes($db, $dispositivo, ($_GET['impressoras'] ?? '') === '1');
                // Agent 0.9.0+ (imagem=1): cada cupom vai tambem desenhado, no papel da impressora de destino.
                if (($_GET['imagem'] ?? '') === '1' && $saida['impressoes'] !== []) {
                    $saida['impressoes'] = $this->comImagem($db, $dispositivo, $saida['impressoes'], (string) ($_GET['principal'] ?? ''));
                }
            }
            return Response::json($saida);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: consulta indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Falha ao consultar pedidos.'], 503);
        }
    }

    /**
     * Cupons pedidos pelo caixa/garcom ainda sem dono, reservados para este computador.
     * Falha aqui nunca derruba o aviso de pedidos novos.
     * @param array<string,mixed> $dispositivo
     * @return list<array{id:int,titulo:string,blocos:list<array<string,mixed>>}>
     */
    private function reservarImpressoes(PDO $db, array $dispositivo, bool $porImpressora = false): array
    {
        try {
            if (!Esquema::tem($db, 'impressoes')) {
                return [];
            }
            $repo = new ImpressaoRepository($db);
            $repo->marcarAgente((int) $dispositivo['id']);
            return $repo->reservar((int) $dispositivo['id_restaurante'], (int) $dispositivo['id'], 10,
                $porImpressora, Esquema::tem($db, 'impressoras'));
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: fila de impressao indisponivel (' . get_class($e) . ').');
            return [];
        }
    }

    /**
     * Desenha cada cupom reservado (CupomImagem) com o papel, a logo e o espaco antes do corte que o Agent informou
     * para a impressora de destino. Cupom sem impressora escolhida ("") vai para a principal do Agent ($principal).
     * Se der errado, o cupom segue so com os blocos e o Agent monta como antes: nunca deixa de imprimir.
     * @param array<string,mixed> $dispositivo
     * @param list<array<string,mixed>> $cupons
     * @return list<array<string,mixed>>
     */
    private function comImagem(PDO $db, array $dispositivo, array $cupons, string $principal): array
    {
        if (!CupomImagem::disponivel()) {
            return $cupons;
        }
        try {
            $porChave = [];
            $formatos = (new ImpressoraRepository($db))->formatos((int) $dispositivo['id_restaurante'],
                Esquema::tem($db, 'impressoras') && Esquema::tem($db, 'impressora_papel'), (int) $dispositivo['id']);
            foreach ($formatos as $f) {
                $porChave[$f['chave']] = $f;
            }
        } catch (Throwable) {
            $porChave = [];
        }
        $padrao = ['papel' => 80, 'logo' => true, 'margem' => CupomImagem::MARGEM_PADRAO, 'lateral' => null];
        $aparencia = AparenciaCupom::doRestaurante($db, (int) $dispositivo['id_restaurante']);
        foreach ($cupons as &$c) {
            $chave = (string) ($c['impressora'] ?? '') !== '' ? (string) $c['impressora'] : $principal;
            $f = $porChave[$chave] ?? $padrao;
            try {
                $c['imagem'] = base64_encode(CupomImagem::png($c['blocos'] ?? [], $f['papel'], $f['margem'], $f['logo'], $aparencia, $f['lateral'] ?? null));
                $c['papel'] = $f['papel'];
            } catch (Throwable $e) {
                error_log('[Cardapim] Desktop: cupom em imagem indisponivel (' . get_class($e) . ').');
            }
        }
        unset($c);
        return $cupons;
    }

    /**
     * POST /api/v1/desktop/impressoras - o Agent (0.8.0+) manda a lista completa de impressoras dele:
     * {"impressoras":[{"chave":"a1b2...","apelido":"Cozinha"}, ...]}. O nome da impressora no Windows fica no computador.
     * Quem tirou uma impressora da lista some dos pontos de impressao (ativo = 0).
     */
    public function registrarImpressoras(): Response
    {
        $autorizado = $this->dispositivo();
        if ($autorizado instanceof Response) return $autorizado;
        [$db, $d] = $autorizado;
        if (!RateLimiter::padrao(120, 600)->permitir('desktop-impressoras|' . (int) $d['id'])) {
            return Response::json(['erro' => 'Muitas tentativas.'], 429);
        }
        $corpo = file_get_contents('php://input');
        $dados = is_string($corpo) && strlen($corpo) <= 16384 ? json_decode($corpo, true) : null;
        $lista = is_array($dados) && is_array($dados['impressoras'] ?? null) ? $dados['impressoras'] : null;
        if ($lista === null || count($lista) > ImpressoraRepository::MAX_POR_AGENT) {
            return Response::json(['erro' => 'Lista de impressoras inválida (até ' . ImpressoraRepository::MAX_POR_AGENT . ').'], 422);
        }
        $limpa = [];
        foreach ($lista as $i) {
            $chave = is_array($i) && is_string($i['chave'] ?? null) ? $i['chave'] : '';
            $apelido = is_array($i) && is_string($i['apelido'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $i['apelido'])) : '';
            if (preg_match('/\A[a-z0-9]{8,40}\z/', $chave) !== 1 || $apelido === '' || mb_strlen($apelido) > 40) {
                return Response::json(['erro' => 'Cada impressora precisa de um apelido de até 40 letras.'], 422);
            }
            $item = ['chave' => $chave, 'apelido' => $apelido];
            // Agent 0.9.0+: papel (58/80), logo e espaco antes do corte (0 a 20 linhas) para montar o cupom em imagem.
            if (isset($i['papel'], $i['logo'], $i['margem'])) {
                $papel = filter_var($i['papel'], FILTER_VALIDATE_INT);
                $margem = filter_var($i['margem'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
                if (!in_array($papel, [58, 80], true) || $margem === false || !is_bool($i['logo'])) {
                    return Response::json(['erro' => 'Papel (58 ou 80 mm), logo ou espaço antes do corte inválido.'], 422);
                }
                $item += ['papel' => $papel, 'logo' => $i['logo'], 'margem' => $margem];
            }
            $limpa[$chave] = $item;
        }
        try {
            if (!Esquema::tem($db, 'impressoras')) {
                return Response::json(['ok' => true, 'aguardando' => true]); // migracao ainda nao aplicada
            }
            (new ImpressoraRepository($db))->sincronizar((int) $d['id_restaurante'], (int) $d['id'], array_values($limpa),
                Esquema::tem($db, 'impressora_papel'), Esquema::tem($db, 'cupom_aparencia'));
            return Response::json(['ok' => true]);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: registro de impressoras indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Falha ao registrar as impressoras.'], 503);
        }
    }

    /**
     * POST /api/v1/desktop/impressoes/{id}/resultado - o Agent conta se o Windows aceitou o cupom.
     * Corpo: {"ok": true} ou {"ok": false, "erro": "..."}. So vale para cupom que este computador reservou.
     */
    public function resultadoImpressao(string $id): Response
    {
        $autorizado = $this->dispositivo();
        if ($autorizado instanceof Response) return $autorizado;
        [$db, $d] = $autorizado;
        $idImpressao = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($idImpressao === false) {
            return Response::json(['erro' => 'Impressão não encontrada.'], 404);
        }
        if (!RateLimiter::padrao(600, 600)->permitir('desktop-impressao|' . (int) $d['id'])) {
            return Response::json(['erro' => 'Muitas tentativas.'], 429);
        }
        $corpo = file_get_contents('php://input');
        $dados = is_string($corpo) && strlen($corpo) <= 2048 ? json_decode($corpo, true) : null;
        if (!is_array($dados) || !is_bool($dados['ok'] ?? null)) {
            return Response::json(['erro' => 'Resultado inválido.'], 422);
        }
        $erro = is_string($dados['erro'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $dados['erro'])) : '';
        try {
            if (!Esquema::tem($db, 'impressoes')) {
                return Response::json(['erro' => 'Impressão não encontrada.'], 404);
            }
            $ok = (new ImpressaoRepository($db))->registrarResultado(
                (int) $d['id_restaurante'], (int) $d['id'], (int) $idImpressao, $dados['ok'], $erro !== '' ? $erro : 'Falha no Windows.'
            );
            return $ok ? Response::json(['ok' => true]) : Response::json(['erro' => 'Impressão não encontrada.'], 404);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: resultado de impressao indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Falha ao registrar.'], 503);
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
        if (!RateLimiter::padrao(180, 60)->permitir('desktop-pesagem|' . $d['id'])) {
            return Response::json(['erro' => 'Muitas leituras. Tente novamente.'], 429);
        }

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

    /** O Agent gera o link de associacao no computador da balanca (sem expor o token). */
    public function prepararPareamento(): Response
    {
        $autorizado = $this->dispositivo();
        if ($autorizado instanceof Response) return $autorizado;
        [, $dispositivo] = $autorizado;
        $id = (int) $dispositivo['id'];
        if (!RateLimiter::padrao(10, 600)->permitir('desktop-parear|' . $id)) {
            return Response::json(['erro' => 'Muitas tentativas.'], 429);
        }
        return Response::json([
            'url' => '/api/v1/desktop/parear?ticket=' . VinculoBalanca::gerarTicket(
                (int) $dispositivo['id_restaurante'], $id
            ),
        ]);
    }

    /**
     * Link do Agent: associa este navegador a balanca do computador e abre o painel.
     * Sem login, guarda o pedido na sessao, manda para /login e conclui logo depois de entrar
     * (o Agent abre este link sozinho quando o Windows liga). destino=gerencia abre a gerencia para o gerente.
     */
    public function parearNavegador(): Response
    {
        $semCache = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
        $ticket = (string) ($_GET['ticket'] ?? '');
        $destino = ($_GET['destino'] ?? '') === 'gerencia' ? 'gerencia' : 'caixa';
        if ($ticket === '') {
            $pendente = Session::flash('desktop_pareamento');
            if (is_array($pendente)) {
                $ticket = (string) ($pendente['ticket'] ?? '');
                $destino = ($pendente['destino'] ?? '') === 'gerencia' ? 'gerencia' : 'caixa';
            }
        }
        $usuario = Autenticacao::usuario();
        if ($usuario === null && $ticket !== '' && strlen($ticket) <= 256) {
            Session::set('desktop_pareamento', ['ticket' => $ticket, 'destino' => $destino]);
            Session::set('login_destino', '/api/v1/desktop/parear');
            return Response::redirect('/login', 303)->comCabecalhos($semCache);
        }
        if ($usuario === null || $usuario->idRestaurante === null
            || !in_array($usuario->cargo, self::CARGOS, true)
            || !Autorizacao::contaLiberada($usuario)) {
            return Response::html('Entre no Deliveryzim como gerente ou caixa antes de abrir pelo Agent.', 403)
                ->comCabecalhos($semCache);
        }
        // Gerente vai para a gerencia quando o Agent pede; caixa sempre para o caixa.
        $area = $destino === 'gerencia' && $usuario->cargo === 'gerente' ? 'gerencia' : 'caixa';
        $idRestaurante = (int) $usuario->idRestaurante;
        $idDispositivo = VinculoBalanca::verificarTicket($ticket, $idRestaurante);
        if ($idDispositivo === null) {
            // Link vencido ou de outra loja: abre o painel mesmo assim, sem associar a balanca.
            return Response::redirect($usuario->urlRestaurante($area))->comCabecalhos($semCache);
        }
        try {
            $balancas = (new PesagemRepository(Database::connect()))->listar($idRestaurante);
            $valida = array_filter($balancas, static fn (array $d): bool => (int) $d['id'] === $idDispositivo);
            if ($valida !== []) {
                VinculoBalanca::gravarCookie($idRestaurante, $idDispositivo);
            }
            return Response::redirect($usuario->urlRestaurante($area))->comCabecalhos($semCache);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: pareamento indisponivel (' . get_class($e) . ').');
            return Response::redirect($usuario->urlRestaurante($area))->comCabecalhos($semCache);
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
            $idRestaurante = (int) $usuario->idRestaurante;
            $balancas = (new PesagemRepository($db))->listar($idRestaurante);
            // O id do dispositivo vem de um cookie assinado pelo servidor, nunca do JS.
            $idVinculado = VinculoBalanca::verificarCookie($_COOKIE[VinculoBalanca::COOKIE] ?? null, $idRestaurante);
            $vinculada = null;
            foreach ($balancas as $balanca) {
                if ($idVinculado !== null && $balanca['id'] === $idVinculado) {
                    $vinculada = $balanca;
                    break;
                }
            }
            // Sem vinculo neste navegador: se o restaurante tem UMA so balanca ligada agora, usa ela.
            // Com duas ou mais, cada caixa precisa abrir pelo seu Agent (nunca mistura balancas).
            $automatica = false;
            if ($vinculada === null) {
                $ligadas = array_values(array_filter($balancas, static fn (array $b): bool => $b['ligada']));
                if (count($ligadas) === 1) {
                    $vinculada = $ligadas[0];
                    $automatica = true;
                }
            }
            return Response::json(['balancas' => $balancas, 'selecionada' => $vinculada, 'automatica' => $automatica]);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: leitura de balancas indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Balancas indisponiveis.'], 503);
        }
    }

    /**
     * GET /api/v1/desktop/pedidos/situacao?ids=1,2,3 - situacao atual dos pedidos avisados (ate 50),
     * so do restaurante do dispositivo. Id de outro restaurante simplesmente nao volta.
     */
    public function situacaoPedidos(): Response
    {
        $autorizado = $this->dispositivo();
        if ($autorizado instanceof Response) return $autorizado;
        [$db, $dispositivo] = $autorizado;
        $ids = [];
        foreach (explode(',', (string) ($_GET['ids'] ?? '')) as $parte) {
            $id = filter_var(trim($parte), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) $ids[$id] = $id;
        }
        if ($ids === [] || count($ids) > 50) {
            return Response::json(['erro' => 'Informe de 1 a 50 pedidos.'], 422);
        }
        try {
            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $q = $db->prepare("SELECT id, status FROM pedidos WHERE id_restaurante = ? AND id IN ($marcadores)");
            $q->execute([(int) $dispositivo['id_restaurante'], ...array_values($ids)]);
            $pedidos = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'status' => (string) $r['status']],
                $q->fetchAll(PDO::FETCH_ASSOC));
            return Response::json(['pedidos' => $pedidos]);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: situacao indisponivel (' . get_class($e) . ').');
            return Response::json(['erro' => 'Falha ao consultar pedidos.'], 503);
        }
    }

    /**
     * POST /api/v1/desktop/pedidos/{id}/receber - "Receber" no aviso do Agent = Aceitar do caixa:
     * Novo -> Em preparo, WhatsApp do cliente, tempo real e confirmacao ao iFood. Autor = conta que
     * vinculou o agente (gerente ou caixa ativo, reconferido agora). 409 quando o pedido ja mudou.
     */
    public function receberPedido(string $id): Response
    {
        $autorizado = $this->dispositivo();
        if ($autorizado instanceof Response) return $autorizado;
        [$db, $d] = $autorizado;
        $idPedido = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($idPedido === false) {
            return Response::json(['erro' => 'Pedido não encontrado.'], 404);
        }
        if (!RateLimiter::padrao(120, 600)->permitir('desktop-receber|' . (int) $d['id'])) {
            return Response::json(['erro' => 'Muitas tentativas. Aguarde um pouco.'], 429);
        }
        $idRestaurante = (int) $d['id_restaurante'];
        try {
            $pedido = (new PedidoRepository($db))->buscar($idRestaurante, (int) $idPedido);
            if ($pedido === null || !in_array($pedido['tipo'], ['entrega', 'retirada'], true)) {
                return Response::json(['erro' => 'Pedido não encontrado.'], 404);
            }
            if ($pedido['status'] !== 'recebido') {
                return Response::json([
                    'erro' => 'Este pedido já mudou de situação.', 'status' => (string) $pedido['status'],
                ], 409);
            }
            $autor = new UsuarioLogado(
                (int) $d['id_user'], (string) $d['cargo'], (string) $d['user_nome'], (string) $d['user_sobrenome'],
                true, $idRestaurante, (string) $d['restaurante_slug'], (string) $d['restaurante_nome'],
                (string) $d['restaurante_status'],
            );
            $resultado = (new DeliveryService($db, $idRestaurante, $autor))->avancar((int) $idPedido, 'aceitar');
            if (!$resultado->ok) {
                $atual = (new PedidoRepository($db))->buscar($idRestaurante, (int) $idPedido);
                return Response::json(['erro' => $resultado->mensagem, 'status' => (string) ($atual['status'] ?? '')], 409);
            }
            Auditoria::registrar($autor, 'Pedido aceito pelo Agent', sprintf(
                'Pedido #%d aceito no Deliveryzim Agent (%s).', (int) $pedido['numero'], (string) $d['nome']
            ));
            AvisosWhatsApp::agendarEnvio($idRestaurante);
            return Response::json(['ok' => true, 'status' => 'em_preparo', 'mensagem' => $resultado->mensagem]);
        } catch (Throwable $e) {
            error_log('[Cardapim] Desktop: receber pedido falhou (' . get_class($e) . ').');
            return Response::json(['erro' => 'Não foi possível aceitar agora. Tente pelo caixa.'], 503);
        }
    }

    /**
     * GET /api/v1/desktop/agente-ativo - para o navegador da equipe: ha Agent conectado neste restaurante?
     * Se sim, o som repetido do navegador fica desligado (o Agent toca ate alguem aceitar).
     */
    public function agenteAtivo(): Response
    {
        $usuario = Autenticacao::usuario();
        if ($usuario === null || $usuario->idRestaurante === null
            || !in_array($usuario->cargo, self::CARGOS, true)
            || !Autorizacao::contaLiberada($usuario)) {
            return Response::json(['ativo' => false])->comCabecalhos(['Cache-Control' => 'no-store']);
        }
        try {
            $ativo = self::agenteConectado(Database::connect(), (int) $usuario->idRestaurante);
        } catch (Throwable $e) {
            $ativo = false; // na duvida o navegador toca: nenhum pedido fica sem aviso
        }
        return Response::json(['ativo' => $ativo, 'conferir_em' => self::CONECTADO_SEGUNDOS])
            ->comCabecalhos(['Cache-Control' => 'no-store']);
    }

    public static function agenteConectado(PDO $db, int $idRestaurante): bool
    {
        $q = $db->prepare('SELECT 1 FROM desktop_agent_devices
            WHERE id_restaurante = ? AND revogado_em IS NULL AND expira_em > NOW()
              AND ultimo_acesso_em >= NOW() - INTERVAL ' . self::CONECTADO_SEGUNDOS . ' SECOND LIMIT 1');
        // Mesmo relogio do MySQL que grava ultimo_acesso_em (NOW()), sem misturar fuso do PHP.
        $q->execute([$idRestaurante]);
        return $q->fetchColumn() !== false;
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
            $q = $db->prepare("SELECT d.*, u.ativo, u.cargo, u.excluido_em, u.nome AS user_nome, u.sobrenome AS user_sobrenome,
                    r.status_rest AS restaurante_status, r.slug AS restaurante_slug, r.nome AS restaurante_nome
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
