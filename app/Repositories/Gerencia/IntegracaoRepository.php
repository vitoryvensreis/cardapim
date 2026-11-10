<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use Cardapim\Shared\Seguranca\Cripto;
use PDO;

/**
 * Integracoes de cada restaurante. As credenciais sao gravadas e lidas sempre cifradas
 * (Cripto); "dados" guarda so informacoes sem segredo (para exibir na tela).
 */
final class IntegracaoRepository
{
    public const PROVEDORES = ['ifood', 'asaas', 'whatsapp'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{id:?int,provedor:string,status:string,ambiente:?string,dados:array<string,mixed>,webhook_id:?string,
     *   ultimo_erro:?string,conectado_em:?string,verificado_em:?string,tem_credenciais:bool}
     */
    public function buscar(int $idRestaurante, string $provedor): array
    {
        $query = $this->db->prepare('SELECT id, provedor, status, ambiente, credenciais, dados, webhook_id, ultimo_erro, conectado_em, verificado_em
            FROM integracoes WHERE id_restaurante = ? AND provedor = ?');
        $query->execute([$idRestaurante, $provedor]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [
                'id' => null, 'provedor' => $provedor, 'status' => 'desconectado', 'ambiente' => null, 'dados' => [],
                'webhook_id' => null, 'ultimo_erro' => null, 'conectado_em' => null, 'verificado_em' => null, 'tem_credenciais' => false,
            ];
        }
        $dados = json_decode((string) ($row['dados'] ?? ''), true);
        return [
            'id' => (int) $row['id'],
            'provedor' => (string) $row['provedor'],
            'status' => (string) $row['status'],
            'ambiente' => $row['ambiente'] !== null ? (string) $row['ambiente'] : null,
            'dados' => is_array($dados) ? $dados : [],
            'webhook_id' => $row['webhook_id'] !== null ? (string) $row['webhook_id'] : null,
            'ultimo_erro' => $row['ultimo_erro'] !== null ? (string) $row['ultimo_erro'] : null,
            'conectado_em' => $row['conectado_em'] !== null ? (string) $row['conectado_em'] : null,
            'verificado_em' => $row['verificado_em'] !== null ? (string) $row['verificado_em'] : null,
            'tem_credenciais' => !empty($row['credenciais']),
        ];
    }

    /** @return array<string, array<string,mixed>> provedor => integracao */
    public function todas(int $idRestaurante): array
    {
        $todas = [];
        foreach (self::PROVEDORES as $provedor) {
            $todas[$provedor] = $this->buscar($idRestaurante, $provedor);
        }
        return $todas;
    }

    /** @return array<string,mixed> credenciais decifradas ([] se nao houver) */
    public function credenciais(int $idRestaurante, string $provedor): array
    {
        $query = $this->db->prepare('SELECT credenciais FROM integracoes WHERE id_restaurante = ? AND provedor = ?');
        $query->execute([$idRestaurante, $provedor]);
        $cifrado = $query->fetchColumn();
        if (!is_string($cifrado) || $cifrado === '') {
            return [];
        }
        $dados = json_decode(Cripto::decifrar($cifrado), true);
        return is_array($dados) ? $dados : [];
    }

    /**
     * Grava (cria ou atualiza) a integracao. Campos ausentes em $campos ficam como estao.
     * @param array{status?:string,ambiente?:?string,credenciais?:?array,dados?:array,webhook_id?:?string,ultimo_erro?:?string,conectado_em?:?string,verificado_em?:?string} $campos
     */
    public function salvar(int $idRestaurante, string $provedor, array $campos): void
    {
        if (!in_array($provedor, self::PROVEDORES, true)) {
            throw new \InvalidArgumentException('Provedor invalido.');
        }
        $existe = $this->db->prepare('SELECT id FROM integracoes WHERE id_restaurante = ? AND provedor = ?');
        $existe->execute([$idRestaurante, $provedor]);
        if ($existe->fetchColumn() === false) {
            $this->db->prepare('INSERT INTO integracoes (id_restaurante, provedor) VALUES (?, ?)')->execute([$idRestaurante, $provedor]);
        }
        $sets = [];
        $valores = [];
        foreach ($campos as $campo => $valor) {
            if (!in_array($campo, ['status', 'ambiente', 'credenciais', 'dados', 'webhook_id', 'ultimo_erro', 'conectado_em', 'verificado_em'], true)) {
                continue;
            }
            if ($campo === 'credenciais') {
                $valor = $valor === null || $valor === [] ? null : Cripto::cifrar(json_encode($valor, JSON_THROW_ON_ERROR));
            } elseif ($campo === 'dados') {
                $valor = json_encode($valor ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif ($campo === 'ultimo_erro' && is_string($valor)) {
                $valor = mb_substr($valor, 0, 255);
            }
            $sets[] = $campo . ' = ?';
            $valores[] = $valor;
        }
        if ($sets === []) {
            return;
        }
        $valores[] = $idRestaurante;
        $valores[] = $provedor;
        $this->db->prepare('UPDATE integracoes SET ' . implode(', ', $sets) . ' WHERE id_restaurante = ? AND provedor = ?')->execute($valores);
    }

    /** Volta ao estado inicial (apaga credenciais e dados). */
    public function limpar(int $idRestaurante, string $provedor): void
    {
        $this->db->prepare("UPDATE integracoes SET status = 'desconectado', ambiente = NULL, credenciais = NULL, dados = NULL,
                webhook_id = NULL, ultimo_erro = NULL, conectado_em = NULL, verificado_em = NULL
            WHERE id_restaurante = ? AND provedor = ?")->execute([$idRestaurante, $provedor]);
    }

    /** @return array{id_restaurante:int, credenciais:array<string,mixed>}|null integracao dona do webhook */
    public function porWebhook(string $provedor, string $webhookId): ?array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $webhookId) !== 1) {
            return null;
        }
        $query = $this->db->prepare("SELECT id_restaurante FROM integracoes WHERE provedor = ? AND webhook_id = ? AND status <> 'desconectado'");
        $query->execute([$provedor, $webhookId]);
        $id = $query->fetchColumn();
        if ($id === false) {
            return null;
        }
        return ['id_restaurante' => (int) $id, 'credenciais' => $this->credenciais((int) $id, $provedor)];
    }

    /** Guarda o aviso recebido. false = ja tinha recebido este aviso (id_externo repetido). */
    public function registrarEvento(int $idRestaurante, string $provedor, string $evento, ?string $idExterno, string $payload): bool
    {
        try {
            $this->db->prepare('INSERT INTO integracao_eventos (id_restaurante, provedor, evento, id_externo, payload, recebido_em) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$idRestaurante, $provedor, mb_substr($evento, 0, 80), $idExterno !== null ? mb_substr($idExterno, 0, 120) : null, $payload, date('Y-m-d H:i:s')]);
            return true;
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }
            throw $exception;
        }
    }

    /** @return list<array{evento:string,id_externo:?string,recebido_em:string}> */
    public function ultimosEventos(int $idRestaurante, string $provedor, int $limite = 5): array
    {
        $query = $this->db->prepare('SELECT evento, id_externo, recebido_em FROM integracao_eventos
            WHERE id_restaurante = ? AND provedor = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limite)));
        $query->execute([$idRestaurante, $provedor]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
