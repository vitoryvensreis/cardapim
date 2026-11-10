<?php

declare(strict_types=1);

namespace Cardapim\Realtime;

use Cardapim\Repositories\OutboxRepository;
use Cardapim\Services\Auth\Autorizacao;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Servidor WebSocket proprio do Cardapim (processo PHP separado do PHP-FPM, rodando pelo systemd).
 *
 * Fluxo: o PHP grava o evento em outbox_eventos -> avisa o Redis -> este servidor le as linhas novas
 * -> entrega a cada navegador do MESMO restaurante que assinou aquele canal e tem permissao para ele.
 *
 * Seguranca: ticket assinado de 60 s e uso unico, Origin conferido, usuario reconferido no banco ao
 * conectar e a cada minuto (conta bloqueada sai na hora), limites de conexoes e de mensagens.
 * Disponibilidade: sem Redis, busca no banco a cada 1 s; banco fora, tenta de novo sem derrubar ninguem;
 * deploy novo, fecha as conexoes com o codigo 1012 e o systemd sobe a versao nova (o navegador reconecta).
 */
final class Servidor
{
    private const MAX_HANDSHAKE = 8192;
    private const MAX_MENSAGEM = 4096;
    private const MAX_SAIDA = 1_048_576;
    private const INTERVALO_PING = 25.0;
    private const LIMITE_SILENCIO = 70.0;
    private const LIMITE_HANDSHAKE = 10.0;
    private const REENVIO_MAXIMO = 200;
    private const RETENCAO_DIAS = 7;
    private const AGUARDANDO_BANCO = 4242;

    /** @var resource|null */
    private $escuta = null;
    /** @var array<int, Conexao> */
    private array $conexoes = [];
    private ?RedisConexao $redis = null;
    private ?PDO $db = null;
    private int $ultimoId = -1;
    /** @var array<string,int> nonce do ticket => validade */
    private array $ticketsUsados = [];
    private bool $rodando = true;
    private bool $buscarAgora = true;
    private float $iniciadoEm;
    private float $proximaBusca = 0.0;
    private float $proximoPing = 0.0;
    private float $proximaRevalidacao = 0.0;
    private float $proximaLimpeza = 0.0;
    private float $proximoStatus = 0.0;
    private float $proximaChecagemVersao = 0.0;
    private float $proximaTentativaRedis = 0.0;
    private float $proximaTentativaBanco = 0.0;
    private int $entregues = 0;

    /**
     * @param array{
     *   host:string, porta:int, origens:list<string>, max_conexoes:int, max_por_usuario:int, max_por_ip:int,
     *   arquivo_status:?string, versao:string, checar_versao:?Closure, redis:bool
     * } $cfg
     * @param Closure():PDO $abrirBanco
     * @param Closure(string):void $log
     */
    public function __construct(private readonly array $cfg, private readonly Closure $abrirBanco, private readonly Closure $log)
    {
        $this->iniciadoEm = microtime(true);
    }

    public function parar(): void
    {
        $this->rodando = false;
    }

    public function rodar(): int
    {
        $endereco = 'tcp://' . $this->cfg['host'] . ':' . $this->cfg['porta'];
        $contexto = stream_context_create(['socket' => ['backlog' => 256, 'so_reuseport' => false]]);
        $escuta = @stream_socket_server($endereco, $codigo, $mensagem, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $contexto);
        if ($escuta === false) {
            ($this->log)('Nao foi possivel abrir ' . $endereco . ': ' . $mensagem);
            return 1;
        }
        stream_set_blocking($escuta, false);
        $this->escuta = $escuta;
        ($this->log)('Tempo real ouvindo em ' . $endereco . ' (versao ' . $this->cfg['versao'] . ', redis ' . ($this->cfg['redis'] ? 'ligado' : 'desligado') . ').');

        while ($this->rodando) {
            $this->umaVolta();
        }

        foreach ($this->conexoes as $c) {
            $this->enviarAgora($c, Quadro::fechar(1012, 'Servidor reiniciando'));
            @fclose($c->socket);
        }
        $this->conexoes = [];
        $this->redis?->fechar();
        @fclose($escuta);
        $this->escreverStatus(true);
        ($this->log)('Tempo real encerrado.');
        return 0;
    }

    private function umaVolta(): void
    {
        $ler = [$this->escuta];
        $escrever = [];
        foreach ($this->conexoes as $c) {
            $ler[] = $c->socket;
            if ($c->saida !== '') {
                $escrever[] = $c->socket;
            }
        }
        if ($this->redis !== null) {
            $ler[] = $this->redis->socket();
        }
        $excecao = null;
        $prontos = @stream_select($ler, $escrever, $excecao, 0, 250_000);
        if ($prontos === false) {
            usleep(50_000); // interrompido por sinal
            return;
        }
        foreach ($ler as $socket) {
            if ($socket === $this->escuta) {
                $this->aceitar();
            } elseif ($this->redis !== null && $socket === $this->redis->socket()) {
                $this->lerRedis();
            } else {
                $c = $this->conexoes[(int) $socket] ?? null;
                if ($c !== null) {
                    $this->lerConexao($c);
                }
            }
        }
        foreach ($escrever as $socket) {
            $c = $this->conexoes[(int) $socket] ?? null;
            if ($c !== null) {
                $this->escreverPendente($c);
            }
        }
        $this->tarefas(microtime(true));
    }

    // ---------------------------------------------------------------- conexoes

    private function aceitar(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @stream_socket_accept($this->escuta, 0, $remoto);
            if ($socket === false) {
                return;
            }
            if (count($this->conexoes) >= $this->cfg['max_conexoes']) {
                @fwrite($socket, self::respostaHttp(503, 'Servidor cheio. Tente de novo em instantes.'));
                @fclose($socket);
                continue;
            }
            stream_set_blocking($socket, false);
            $ip = is_string($remoto) ? (string) preg_replace('/:\d+\z/', '', $remoto) : '';
            $this->conexoes[(int) $socket] = new Conexao($socket, (int) $socket, $ip);
        }
    }

    private function lerConexao(Conexao $c): void
    {
        $dados = @fread($c->socket, 65536);
        if ($dados === false || ($dados === '' && feof($c->socket))) {
            $this->remover($c);
            return;
        }
        if ($dados === '') {
            return;
        }
        $c->entrada .= $dados;
        if (!$c->aberta) {
            $this->apertoDeMao($c);
            return;
        }
        $lido = Quadro::ler($c->entrada, true, self::MAX_MENSAGEM);
        $c->entrada = $lido['resto'];
        foreach ($lido['quadros'] as $q) {
            $this->tratarQuadro($c, $q);
            if (!isset($this->conexoes[$c->id]) || $c->encerrando) {
                return;
            }
        }
        if ($lido['erro'] !== null) {
            $this->encerrar($c, $lido['erro'], $lido['erro'] === 1009 ? 'Mensagem grande demais' : 'Protocolo invalido');
        }
    }

    private function apertoDeMao(Conexao $c): void
    {
        $fim = strpos($c->entrada, "\r\n\r\n");
        if ($fim === false) {
            if (strlen($c->entrada) > self::MAX_HANDSHAKE) {
                $this->recusar($c, 431, 'Cabecalhos grandes demais.');
            }
            return;
        }
        $linhas = explode("\r\n", substr($c->entrada, 0, $fim));
        $c->entrada = (string) substr($c->entrada, $fim + 4);
        if (preg_match('~\AGET (/[^\s]*) HTTP/1\.1\z~', (string) array_shift($linhas), $m) !== 1) {
            $this->recusar($c, 400, 'Requisicao invalida.');
            return;
        }
        $cabecalhos = [];
        foreach ($linhas as $linha) {
            $partes = explode(':', $linha, 2);
            if (count($partes) === 2) {
                $cabecalhos[strtolower(trim($partes[0]))] = trim($partes[1]);
            }
        }
        // Atras do Nginx o IP real vem no cabecalho (so confiamos nele quando a conexao vem da propria maquina).
        if (in_array($c->ip, ['127.0.0.1', '[::1]'], true) && preg_match('/\A[0-9a-fA-F.:]{3,45}\z/', $cabecalhos['x-real-ip'] ?? '') === 1) {
            $c->ip = $cabecalhos['x-real-ip'];
        }
        $caminho = (string) parse_url($m[1], PHP_URL_PATH);
        parse_str((string) parse_url($m[1], PHP_URL_QUERY), $query);
        if ($caminho !== '/ws') {
            $this->recusar($c, 404, 'Endereco nao encontrado.');
            return;
        }
        if (strtolower($cabecalhos['upgrade'] ?? '') !== 'websocket' || !str_contains(strtolower($cabecalhos['connection'] ?? ''), 'upgrade')
            || ($cabecalhos['sec-websocket-version'] ?? '') !== '13' || !Quadro::chaveClienteValida($cabecalhos['sec-websocket-key'] ?? '')) {
            $this->recusar($c, 400, 'Esperado um pedido WebSocket.');
            return;
        }
        if (!in_array(rtrim($cabecalhos['origin'] ?? '', '/'), $this->cfg['origens'], true)) {
            $this->recusar($c, 403, 'Origem nao permitida.');
            ($this->log)('Conexao recusada: origem nao permitida (' . substr(preg_replace('/[^\x20-\x7e]/', '', $cabecalhos['origin'] ?? '-') ?? '', 0, 80) . ').');
            return;
        }
        $ticket = Ticket::validar(is_string($query['ticket'] ?? null) ? $query['ticket'] : '');
        if ($ticket === null || isset($this->ticketsUsados[$ticket['n']])) {
            $this->recusar($c, 401, 'Ticket invalido ou vencido.');
            return;
        }
        $this->ticketsUsados[$ticket['n']] = $ticket['exp'];
        $usuario = $this->conferirUsuario($ticket['u'], $ticket['r']);
        if ($usuario === false) {
            unset($this->ticketsUsados[$ticket['n']]); // nao foi culpa do navegador: o mesmo ticket pode tentar de novo
            $this->recusar($c, 503, 'Servidor temporariamente indisponivel.');
            return;
        }
        if ($usuario === null) {
            $this->recusar($c, 403, 'Acesso nao permitido.');
            return;
        }
        $doUsuario = 0;
        $doIp = 0;
        foreach ($this->conexoes as $outra) {
            if ($outra->aberta && $outra->idUsuario === $ticket['u']) {
                $doUsuario++;
            }
            if ($outra->ip === $c->ip) {
                $doIp++;
            }
        }
        if ($doUsuario >= $this->cfg['max_por_usuario'] || $doIp > $this->cfg['max_por_ip']) {
            $this->recusar($c, 429, 'Conexoes demais. Feche outras abas do Cardapim.');
            return;
        }

        $c->aberta = true;
        $c->idUsuario = $ticket['u'];
        $c->idRestaurante = $ticket['r'];
        $c->cargo = $usuario['cargo'];
        $c->permitidos = Canais::permitidos($usuario['cargo']);
        $c->ultimoSinal = microtime(true);
        $this->enviar($c, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . Quadro::chaveAceite($cabecalhos['sec-websocket-key']) . "\r\n\r\n");
        $this->enviarJson($c, ['tipo' => 'sistema.conectado', 'dados' => ['canais' => $c->permitidos, 'ultimo_id' => max(0, $this->ultimoId)]]);
        if ($c->entrada !== '') {
            $this->lerConexaoBuffer($c);
        }
    }

    /** Dados que chegaram junto com o aperto de mao. */
    private function lerConexaoBuffer(Conexao $c): void
    {
        $lido = Quadro::ler($c->entrada, true, self::MAX_MENSAGEM);
        $c->entrada = $lido['resto'];
        foreach ($lido['quadros'] as $q) {
            $this->tratarQuadro($c, $q);
        }
    }

    /** @param array{fin:bool,opcode:int,dados:string} $q */
    private function tratarQuadro(Conexao $c, array $q): void
    {
        $c->ultimoSinal = microtime(true);
        switch ($q['opcode']) {
            case Quadro::PING:
                $this->enviar($c, Quadro::montar($q['dados'], Quadro::PONG));
                return;
            case Quadro::PONG:
                return;
            case Quadro::FECHAR:
                $codigo = strlen($q['dados']) >= 2 ? unpack('n', substr($q['dados'], 0, 2))[1] : 1000;
                $this->encerrar($c, in_array($codigo, [1000, 1001], true) ? $codigo : 1000, '');
                return;
            case Quadro::BINARIO:
                $this->encerrar($c, 1003, 'Somente texto');
                return;
            case Quadro::TEXTO:
                if (!$q['fin']) {
                    $c->fragmentos = $q['dados'];
                    $c->opcodeFragmentado = Quadro::TEXTO;
                    return;
                }
                $this->tratarMensagem($c, $q['dados']);
                return;
            case Quadro::CONTINUACAO:
                if ($c->opcodeFragmentado !== Quadro::TEXTO) {
                    $this->encerrar($c, 1002, 'Protocolo invalido');
                    return;
                }
                $c->fragmentos .= $q['dados'];
                if (strlen($c->fragmentos) > self::MAX_MENSAGEM) {
                    $this->encerrar($c, 1009, 'Mensagem grande demais');
                    return;
                }
                if ($q['fin']) {
                    $texto = $c->fragmentos;
                    $c->fragmentos = '';
                    $c->opcodeFragmentado = 0;
                    $this->tratarMensagem($c, $texto);
                }
                return;
            default:
                $this->encerrar($c, 1002, 'Protocolo invalido');
        }
    }

    private function tratarMensagem(Conexao $c, string $texto): void
    {
        $agora = microtime(true);
        $c->mensagens = array_values(array_filter($c->mensagens, static fn (float $t): bool => $t > $agora - 10));
        $c->mensagens[] = $agora;
        if (count($c->mensagens) > 30) {
            $this->encerrar($c, 1008, 'Mensagens demais');
            return;
        }
        $msg = json_decode($texto, true);
        if (!is_array($msg) || !is_string($msg['acao'] ?? null)) {
            $this->enviarJson($c, ['tipo' => 'sistema.erro', 'dados' => ['mensagem' => 'Mensagem invalida.']]);
            return;
        }
        if ($msg['acao'] === 'ping') {
            $this->enviarJson($c, ['tipo' => 'sistema.pong']);
            return;
        }
        if ($msg['acao'] !== 'assinar') {
            $this->enviarJson($c, ['tipo' => 'sistema.erro', 'dados' => ['mensagem' => 'Acao desconhecida.']]);
            return;
        }
        $canais = Canais::filtrar($c->cargo, is_array($msg['canais'] ?? null) ? array_slice($msg['canais'], 0, 20) : []);
        $c->assinados = array_fill_keys($canais, true);
        $desde = is_int($msg['desde'] ?? null) && $msg['desde'] >= 0 ? $msg['desde'] : null; // null = so o que vier depois
        $this->enviarJson($c, ['tipo' => 'sistema.assinado', 'dados' => ['canais' => $canais, 'ultimo_id' => max(0, $this->ultimoId)]]);
        if ($desde !== null && $canais !== []) {
            $this->reenviarPerdidos($c, $canais, $desde);
        }
    }

    /** Navegador que reconectou recebe o que perdeu (ou e avisado para recarregar, se foi muita coisa). */
    private function reenviarPerdidos(Conexao $c, array $canais, int $desde): void
    {
        try {
            $eventos = (new OutboxRepository($this->banco()))->doRestauranteDepoisDe($c->idRestaurante, $canais, $desde, self::REENVIO_MAXIMO + 1);
        } catch (Throwable $e) {
            $this->falhaBanco($e);
            $this->enviarJson($c, ['tipo' => 'sistema.recarregar', 'dados' => ['motivo' => 'indisponivel']]);
            return;
        }
        if (count($eventos) > self::REENVIO_MAXIMO) {
            $this->enviarJson($c, ['tipo' => 'sistema.recarregar', 'dados' => ['motivo' => 'muitos_eventos']]);
            return;
        }
        foreach ($eventos as $evento) {
            if ($this->ultimoId < 0 || $evento['id'] <= $this->ultimoId) { // os mais novos chegam pela busca normal
                $this->enviar($c, Quadro::montar(self::mensagemEvento($evento)));
            }
        }
    }

    private function encerrar(Conexao $c, int $codigo, string $motivo): void
    {
        if ($c->aberta) {
            $this->enviar($c, Quadro::fechar($codigo, $motivo));
        }
        $c->encerrando = true;
        if ($c->saida === '') {
            $this->remover($c);
        }
    }

    private function recusar(Conexao $c, int $status, string $mensagem): void
    {
        $this->enviar($c, self::respostaHttp($status, $mensagem));
        $c->encerrando = true;
        if ($c->saida === '') {
            $this->remover($c);
        }
    }

    private function remover(Conexao $c): void
    {
        @fclose($c->socket);
        unset($this->conexoes[$c->id]);
    }

    private function enviarJson(Conexao $c, array $dados): void
    {
        $this->enviar($c, Quadro::montar(json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }

    private function enviar(Conexao $c, string $bytes): void
    {
        if (strlen($c->saida) + strlen($bytes) > self::MAX_SAIDA) {
            ($this->log)('Conexao lenta demais removida (usuario ' . $c->idUsuario . ').');
            $this->remover($c);
            return;
        }
        $c->saida .= $bytes;
        $this->escreverPendente($c);
    }

    private function escreverPendente(Conexao $c): void
    {
        if ($c->saida === '' || !isset($this->conexoes[$c->id])) {
            return;
        }
        $n = @fwrite($c->socket, $c->saida);
        if ($n === false) {
            $this->remover($c);
            return;
        }
        $c->saida = (string) substr($c->saida, $n);
        if ($c->saida === '' && $c->encerrando) {
            $this->remover($c);
        }
    }

    /** Envio final (no desligamento): tenta mandar sem esperar o laco. */
    private function enviarAgora(Conexao $c, string $bytes): void
    {
        if ($c->aberta) {
            @stream_set_blocking($c->socket, true);
            @stream_set_timeout($c->socket, 1);
            @fwrite($c->socket, $c->saida . $bytes);
        }
    }

    // ---------------------------------------------------------------- eventos

    private function tarefas(float $agora): void
    {
        if ($this->buscarAgora || $agora >= $this->proximaBusca) {
            $this->buscarAgora = false;
            $this->buscarEventos();
            $this->proximaBusca = $agora + ($this->redis !== null ? 2.0 : 1.0);
        }
        if ($this->cfg['redis'] && $this->redis === null && $agora >= $this->proximaTentativaRedis) {
            $this->conectarRedis();
        }
        if ($agora >= $this->proximoPing) {
            $this->proximoPing = $agora + self::INTERVALO_PING;
            foreach ($this->conexoes as $c) {
                if ($c->aberta && $agora - $c->ultimoSinal > self::LIMITE_SILENCIO) {
                    $this->remover($c); // navegador sumiu sem avisar (internet caiu)
                } elseif ($c->aberta) {
                    $this->enviar($c, Quadro::montar('', Quadro::PING));
                }
            }
        }
        foreach ($this->conexoes as $c) {
            if (!$c->aberta && $agora - $c->criadaEm > self::LIMITE_HANDSHAKE) {
                $this->remover($c);
            }
        }
        if ($agora >= $this->proximaRevalidacao) {
            $this->proximaRevalidacao = $agora + 60.0;
            $this->revalidarUsuarios();
            $limite = time();
            $this->ticketsUsados = array_filter($this->ticketsUsados, static fn (int $exp): bool => $exp >= $limite - 5);
        }
        if ($agora >= $this->proximaLimpeza) {
            $this->proximaLimpeza = $agora + 3600.0;
            $this->limparAntigos();
        }
        if ($agora >= $this->proximoStatus) {
            $this->proximoStatus = $agora + 10.0;
            $this->escreverStatus(false);
        }
        if ($this->cfg['checar_versao'] !== null && $agora >= $this->proximaChecagemVersao) {
            $this->proximaChecagemVersao = $agora + 5.0;
            if (($this->cfg['checar_versao'])() === false) {
                ($this->log)('Nova versao publicada: reiniciando para carregar o codigo novo.');
                $this->rodando = false;
            }
        }
    }

    private function buscarEventos(): void
    {
        try {
            $repo = new OutboxRepository($this->banco());
            if ($this->ultimoId < 0) {
                $this->ultimoId = $repo->ultimoId(); // ao iniciar, so o que acontecer daqui para frente
                return;
            }
            for ($volta = 0; $volta < 10; $volta++) {
                $eventos = $repo->depoisDe($this->ultimoId, 500);
                if ($eventos === []) {
                    return;
                }
                $de = $this->ultimoId;
                foreach ($eventos as $evento) {
                    $this->distribuir($evento);
                    $this->ultimoId = $evento['id'];
                }
                $repo->marcarPublicados($de, $this->ultimoId, date('Y-m-d H:i:s'));
                if (count($eventos) < 500) {
                    return;
                }
            }
        } catch (Throwable $e) {
            $this->falhaBanco($e);
        }
    }

    /** @param array{id:int,id_restaurante:?int,canal:string,tipo:string,dados:string,criado_em:string} $evento */
    private function distribuir(array $evento): void
    {
        if ($evento['id_restaurante'] === null) {
            return; // eventos da plataforma: reservado para o painel do adm_master
        }
        $quadro = null;
        foreach ($this->conexoes as $c) {
            if ($c->aberta && !$c->encerrando && $c->idRestaurante === $evento['id_restaurante'] && isset($c->assinados[$evento['canal']])) {
                $quadro ??= Quadro::montar(self::mensagemEvento($evento));
                $this->enviar($c, $quadro);
                $this->entregues++;
            }
        }
    }

    /** @param array{id:int,canal:string,tipo:string,dados:string,criado_em:string} $evento */
    private static function mensagemEvento(array $evento): string
    {
        $dados = json_decode($evento['dados'], true);
        return json_encode([
            'id' => $evento['id'],
            'tipo' => $evento['tipo'],
            'canal' => $evento['canal'],
            'dados' => is_array($dados) ? $dados : new \stdClass(),
            'em' => $evento['criado_em'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------- banco e redis

    private function banco(): PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }
        if (microtime(true) < $this->proximaTentativaBanco) {
            throw new RuntimeException('Banco aguardando nova tentativa.', self::AGUARDANDO_BANCO);
        }
        return $this->db = ($this->abrirBanco)();
    }

    private function falhaBanco(Throwable $e): void
    {
        if ($e instanceof RuntimeException && $e->getCode() === self::AGUARDANDO_BANCO) {
            return; // ainda no intervalo entre tentativas: nao adiar mais
        }
        if ($this->db !== null || microtime(true) >= $this->proximaTentativaBanco) {
            ($this->log)('Banco indisponivel (' . get_class($e) . '); nova tentativa em 5 s.');
        }
        $this->db = null;
        $this->proximaTentativaBanco = microtime(true) + 5.0;
    }

    /** @return array{cargo:string}|false|null null = sem acesso; false = banco fora (tentar de novo) */
    private function conferirUsuario(int $idUsuario, int $idRestaurante): array|false|null
    {
        try {
            $q = $this->banco()->prepare(
                'SELECT u.cargo, u.ativo, u.id_restaurante, u.excluido_em, r.status_rest FROM user u'
                . ' LEFT JOIN restaurantes r ON r.id = ? WHERE u.id = ?',
            );
            $q->execute([$idRestaurante, $idUsuario]);
            $u = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->falhaBanco($e);
            return false;
        }
        return is_array($u) && self::usuarioLiberado($u, $idRestaurante) ? ['cargo' => (string) $u['cargo']] : null;
    }

    /** @param array<string,mixed> $u */
    private static function usuarioLiberado(array $u, int $idRestaurante): bool
    {
        if ((int) $u['ativo'] !== 1 || $u['excluido_em'] !== null || $u['status_rest'] === null || Canais::permitidos((string) $u['cargo']) === []) {
            return false;
        }
        if ($u['cargo'] === Autorizacao::CARGO_MASTER) {
            return true;
        }
        return (int) $u['id_restaurante'] === $idRestaurante && in_array($u['status_rest'], Autorizacao::STATUS_COM_ACESSO, true);
    }

    /** Conta bloqueada, removida ou restaurante suspenso: a conexao cai em ate 1 minuto. */
    private function revalidarUsuarios(): void
    {
        $abertas = array_filter($this->conexoes, static fn (Conexao $c): bool => $c->aberta);
        if ($abertas === []) {
            return;
        }
        $pares = [];
        foreach ($abertas as $c) {
            $pares[$c->idUsuario . ':' . $c->idRestaurante] = [$c->idUsuario, $c->idRestaurante];
        }
        try {
            $q = $this->banco()->prepare(
                'SELECT u.cargo, u.ativo, u.id_restaurante, u.excluido_em, r.status_rest FROM user u'
                . ' LEFT JOIN restaurantes r ON r.id = ? WHERE u.id = ?',
            );
            $liberados = [];
            foreach (array_slice($pares, 0, 2000, true) as $chave => [$idUsuario, $idRestaurante]) {
                $q->execute([$idRestaurante, $idUsuario]);
                $u = $q->fetch(PDO::FETCH_ASSOC);
                $liberados[$chave] = is_array($u) && self::usuarioLiberado($u, $idRestaurante) ? (string) $u['cargo'] : null;
            }
        } catch (Throwable $e) {
            $this->falhaBanco($e);
            return;
        }
        foreach ($abertas as $c) {
            $chave = $c->idUsuario . ':' . $c->idRestaurante;
            if (!array_key_exists($chave, $liberados)) {
                continue; // acima do limite desta volta: confere na proxima
            }
            $cargo = $liberados[$chave];
            if ($cargo === null) {
                $this->encerrar($c, 4003, 'Acesso encerrado');
            } elseif ($cargo !== $c->cargo) {
                // Cargo mudou: aplica as permissoes novas sem derrubar a conexao.
                $c->cargo = $cargo;
                $c->permitidos = Canais::permitidos($cargo);
                $c->assinados = array_fill_keys(Canais::filtrar($cargo, array_keys($c->assinados)), true);
            }
        }
    }

    private function limparAntigos(): void
    {
        try {
            $apagados = (new OutboxRepository($this->banco()))->apagarAntesDe(date('Y-m-d H:i:s', time() - self::RETENCAO_DIAS * 86400));
            if ($apagados > 0) {
                ($this->log)('Limpeza: ' . $apagados . ' evento(s) antigo(s) apagado(s).');
            }
        } catch (Throwable $e) {
            $this->falhaBanco($e);
        }
    }

    private function conectarRedis(): void
    {
        try {
            $redis = RedisConexao::conectar(1.0);
            $redis->assinar(RedisConexao::canal('outbox'));
            stream_set_timeout($redis->socket(), 1);
            $this->redis = $redis;
            $this->buscarAgora = true; // pode ter chegado algo enquanto estava sem Redis
            ($this->log)('Redis conectado.');
        } catch (Throwable $e) {
            $this->proximaTentativaRedis = microtime(true) + 10.0;
            ($this->log)('Redis indisponivel (' . $e->getMessage() . '); seguindo com busca a cada 1 s.');
        }
    }

    private function lerRedis(): void
    {
        try {
            do {
                $this->redis?->lerMensagem();
            } while ($this->redis !== null && $this->redis->temDadosPendentes());
            $this->buscarAgora = true;
        } catch (Throwable) {
            ($this->log)('Conexao com o Redis caiu; seguindo com busca a cada 1 s.');
            $this->redis?->fechar();
            $this->redis = null;
            $this->proximaTentativaRedis = microtime(true) + 5.0;
        }
    }

    // ---------------------------------------------------------------- status

    private function escreverStatus(bool $parado): void
    {
        $arquivo = $this->cfg['arquivo_status'];
        if ($arquivo === null) {
            return;
        }
        $status = [
            'situacao' => $parado ? 'parado' : 'operacional',
            'versao' => $this->cfg['versao'],
            'pid' => getmypid(),
            'iniciado_em' => date('c', (int) $this->iniciadoEm),
            'atualizado_em' => time(),
            'conexoes' => count(array_filter($this->conexoes, static fn (Conexao $c): bool => $c->aberta)),
            'eventos_entregues' => $this->entregues,
            'redis' => !$this->cfg['redis'] ? 'desligado' : ($this->redis !== null ? 'conectado' : 'indisponivel'),
            'banco' => $this->db !== null ? 'conectado' : 'indisponivel',
        ];
        $temporario = $arquivo . '.tmp';
        if (@file_put_contents($temporario, json_encode($status, JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($temporario, $arquivo);
        }
    }

    private static function respostaHttp(int $status, string $mensagem): string
    {
        $textos = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 503 => 'Service Unavailable'];
        return 'HTTP/1.1 ' . $status . ' ' . ($textos[$status] ?? 'Error') . "\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . 'Content-Length: ' . strlen($mensagem) . "\r\nConnection: close\r\n\r\n" . $mensagem;
    }
}
