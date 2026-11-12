<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Core\Database;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use PDO;
use Throwable;

/**
 * Fila do que o restaurante faz no sistema e precisa chegar ao iFood (aceitar, pronto, despachar, cancelar).
 * Gravada junto com a mudanca do pedido, enviada logo depois da resposta da tela e repetida pela rotina
 * bin/ifood.php quando o iFood nao responde. A ordem de cada pedido e mantida.
 */
final class IfoodAcoes
{
    /** Acao do sistema => acao da API do iFood. */
    public const API = [
        'confirmar' => 'confirm',
        'preparar' => 'startPreparation',
        'pronto' => 'readyToPickup',
        'despachar' => 'dispatch',
        'cancelar' => 'requestCancellation',
    ];

    /** Motivos de cancelamento aceitos pelo iFood (codigo => texto para o gerente). */
    public const MOTIVOS = [
        '503' => 'Item do pedido acabou',
        '509' => 'Problema na cozinha ou na loja',
        '504' => 'Sem entregador disponível',
        '506' => 'Endereço fora da área de entrega',
        '513' => 'A loja fechou mais cedo',
        '508' => 'Fora do horário de funcionamento',
        '505' => 'Cardápio desatualizado',
        '502' => 'Pedido repetido',
        '507' => 'Pedido falso (trote)',
        '511' => 'Área de risco',
        '501' => 'Problema no sistema',
    ];

    private const ESPERAS = [30, 60, 120, 300, 600, 900, 1800];

    /** @var array<int,true> */
    private static array $agendados = [];

    /** @param array<string,string>|null $dados */
    public static function enfileirar(PDO $db, int $idRestaurante, int $idPedido, string $acao, ?array $dados = null): void
    {
        if (!isset(self::API[$acao])) {
            throw new \InvalidArgumentException('Acao do iFood invalida.');
        }
        $agora = date('Y-m-d H:i:s');
        $db->prepare('INSERT INTO ifood_acoes (id_restaurante, id_pedido, acao, dados, status, tentativas, proxima_em, criado_em) VALUES (?, ?, ?, ?, \'pendente\', 0, ?, ?)')
            ->execute([$idRestaurante, $idPedido, $acao, $dados !== null ? json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null, $agora, $agora]);
    }

    /** Envia a fila do restaurante depois que a tela ja respondeu (sem deixar o caixa esperando o iFood). */
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
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                $db = Database::connect();
                foreach (array_keys(self::$agendados) as $id) {
                    $conta = new IfoodConta($db, $id);
                    if ($conta->conectada()) {
                        (new self())->enviar($db, $conta);
                    }
                }
            } catch (Throwable $erro) {
                error_log('[Cardapim] Envio ao iFood falhou: ' . get_class($erro));
            }
        });
    }

    /** Envia as acoes pendentes do restaurante. @return int quantas foram aceitas pelo iFood */
    public function enviar(PDO $db, IfoodConta $conta, int $limite = 30): int
    {
        $q = $db->prepare("SELECT a.id, a.id_pedido, a.acao, a.dados, a.tentativas, a.proxima_em, p.ifood_id, p.numero
            FROM ifood_acoes a JOIN pedidos p ON p.id = a.id_pedido AND p.id_restaurante = a.id_restaurante
            WHERE a.id_restaurante = ? AND a.status = 'pendente' ORDER BY a.id LIMIT " . max(1, min(100, $limite)));
        $q->execute([$conta->idRestaurante]);
        $acoes = $q->fetchAll(PDO::FETCH_ASSOC);
        $travados = [];
        $feitas = 0;
        $token = null;
        $agora = time();
        foreach ($acoes as $a) {
            $idPedido = (int) $a['id_pedido'];
            if (isset($travados[$idPedido])) {
                continue; // uma acao anterior deste pedido ainda nao foi: mantem a ordem
            }
            if ((int) strtotime((string) $a['proxima_em']) > $agora) {
                $travados[$idPedido] = true;
                continue;
            }
            if (!is_string($a['ifood_id']) || $a['ifood_id'] === '') {
                $this->finalizar($db, (int) $a['id'], 'falhou', 'Pedido sem código do iFood.');
                continue;
            }
            $dados = json_decode((string) ($a['dados'] ?? ''), true);
            $corpo = null;
            if ($a['acao'] === 'cancelar') {
                $codigo = is_array($dados) && isset(self::MOTIVOS[(string) ($dados['codigo'] ?? '')]) ? (string) $dados['codigo'] : '509';
                $corpo = ['reason' => mb_substr((string) ($dados['motivo'] ?? self::MOTIVOS[$codigo]), 0, 250), 'cancellationCode' => $codigo];
            }
            try {
                $token ??= $conta->token();
                $conta->api()->acaoPedido($token, (string) $a['ifood_id'], self::API[$a['acao']], $corpo);
                $this->finalizar($db, (int) $a['id'], 'feito', null);
                $feitas++;
            } catch (IntegracaoErro $erro) {
                $codigo = $erro->getCode();
                $definitivo = in_array($codigo, [400, 404, 409, 422], true) || (int) $a['tentativas'] + 1 >= count(self::ESPERAS);
                if ($definitivo) {
                    $this->finalizar($db, (int) $a['id'], 'falhou', $erro->getMessage());
                    $this->avisarNoPedido($db, $conta->idRestaurante, $idPedido, (string) $a['acao'], $erro->getMessage());
                } else {
                    $espera = self::ESPERAS[min((int) $a['tentativas'], count(self::ESPERAS) - 1)];
                    $db->prepare('UPDATE ifood_acoes SET tentativas = tentativas + 1, ultimo_erro = ?, proxima_em = ? WHERE id = ?')
                        ->execute([mb_substr($erro->getMessage(), 0, 255), date('Y-m-d H:i:s', $agora + $espera), (int) $a['id']]);
                    $travados[$idPedido] = true;
                }
                if ($codigo === 401 || $codigo === 429 || $codigo === 0) {
                    break; // sem acesso, limite de chamadas ou iFood fora: tenta tudo na proxima rodada
                }
            }
        }
        return $feitas;
    }

    private function finalizar(PDO $db, int $id, string $status, ?string $erro): void
    {
        $db->prepare('UPDATE ifood_acoes SET status = ?, tentativas = tentativas + 1, ultimo_erro = ?, feito_em = ? WHERE id = ?')
            ->execute([$status, $erro !== null ? mb_substr($erro, 0, 255) : null, date('Y-m-d H:i:s'), $id]);
    }

    /** O iFood recusou: o caixa ve o aviso no pedido para resolver pelo Gestor de Pedidos do iFood. */
    private function avisarNoPedido(PDO $db, int $idRestaurante, int $idPedido, string $acao, string $erro): void
    {
        $nomes = ['confirmar' => 'o aceite', 'preparar' => 'o início do preparo', 'pronto' => 'o pedido pronto', 'despachar' => 'a saída para entrega', 'cancelar' => 'o cancelamento'];
        DadosPedidoIfood::adicionarAviso($db, $idRestaurante, $idPedido, 'O iFood não aceitou ' . ($nomes[$acao] ?? 'a mudança') . ': ' . $erro . ' Confira no Gestor de Pedidos do iFood.');
        if ($acao === 'cancelar') {
            DadosPedidoIfood::alterar($db, $idRestaurante, $idPedido, static function (array $d): array {
                $d['cancelamento'] = null;
                return $d;
            });
        }
    }
}
