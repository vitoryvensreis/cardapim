<?php

declare(strict_types=1);

namespace Cardapim\Services\WhatsApp;

use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\ProvedorWhatsApp;
use Cardapim\Modules\Integracoes\WhatsAppProvedores;
use PDO;

/**
 * Fecha no servidor do WhatsApp (WAHA) as sessoes do Deliveryzim que nenhum restaurante usa mais.
 *
 * Por que existe: instancia que ficou para tras (QR Code que ninguem leu, desconectar com o servidor
 * fora do ar, reconexao com nome novo) continua guardada no servidor e ocupa recursos a toa.
 *
 * Regras de seguranca:
 * - So mexe em instancias com nome do Deliveryzim (deliveryzim_r{id}_... e deliveryzim_plataforma_...).
 *   Instancias de outros projetos no mesmo servidor nunca sao tocadas.
 * - Nunca mexe numa sessao gravada no banco (conectada, aguardando QR Code ou com erro).
 * - So fecha uma sessao abandonada na SEGUNDA rodada em que ela aparece (rodadas com 10+ minutos
 *   de intervalo), para nao derrubar uma conexao que acabou de ser iniciada e ainda nao foi gravada.
 */
final class SessoesAbandonadas
{
    public const CARENCIA_SEGUNDOS = 600;

    public function __construct(private readonly PDO $db, private readonly ProvedorWhatsApp $api, private readonly string $arquivoEstado)
    {
    }

    /** Sessao com nome do Deliveryzim? */
    public static function doDeliveryzim(string $sessao): bool
    {
        return WhatsAppProvedores::doDeliveryzim($sessao);
    }

    /**
     * Quais sessoes do servidor estao abandonadas e ja passaram da carencia.
     * @param list<string> $noServidor
     * @param list<string> $emUso sessoes gravadas no banco
     * @param array<string,int> $vistas sessao => quando foi vista abandonada pela primeira vez (unix)
     * @return array{fechar: list<string>, aguardando: list<string>, vistas: array<string,int>}
     */
    public static function decidir(array $noServidor, array $emUso, array $vistas, int $agora): array
    {
        $usadas = array_fill_keys($emUso, true);
        $fechar = [];
        $aguardando = [];
        $novasVistas = [];
        foreach (array_unique($noServidor) as $s) {
            if (!self::doDeliveryzim($s) || isset($usadas[$s])) {
                continue;
            }
            $desde = $vistas[$s] ?? $agora;
            $novasVistas[$s] = $desde;
            if ($agora - $desde >= self::CARENCIA_SEGUNDOS) {
                $fechar[] = $s;
            } else {
                $aguardando[] = $s;
            }
        }
        return ['fechar' => $fechar, 'aguardando' => $aguardando, 'vistas' => $novasVistas];
    }

    /** @return list<string> sessoes gravadas no banco (qualquer restaurante e a plataforma) */
    public function emUso(): array
    {
        $q = $this->db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(dados, '$.sessao')) FROM integracoes
            WHERE provedor = 'whatsapp' AND status <> 'desconectado' AND dados IS NOT NULL");
        return array_values(array_filter(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)), static fn (string $s): bool => $s !== '' && $s !== 'null'));
    }

    /**
     * Uma rodada. $fecharDeVerdade = false so mostra o que faria.
     * $semEspera = true ignora a carencia (uso manual, com o servidor sem ninguem conectando agora).
     * @return array{servidor: list<string>, em_uso: list<string>, fechadas: list<string>, falharam: list<string>, aguardando: list<string>}
     * @throws IntegracaoErro servidor do WhatsApp fora do ar
     */
    public function rodar(bool $fecharDeVerdade, bool $semEspera = false, ?int $agora = null): array
    {
        $agora ??= time();
        $servidor = $this->api->sessoesNoServidor();
        $emUso = $this->emUso();
        $vistasAntes = $semEspera ? array_fill_keys($servidor, $agora - self::CARENCIA_SEGUNDOS) : $this->lerVistas();
        $decisao = self::decidir($servidor, $emUso, $vistasAntes, $agora);
        $fechadas = [];
        $falharam = [];
        $vistas = $decisao['vistas'];
        if ($fecharDeVerdade) {
            foreach ($decisao['fechar'] as $s) {
                try {
                    $ok = $this->api->encerrar($s);
                } catch (IntegracaoErro) {
                    $ok = false;
                }
                if ($ok) {
                    $fechadas[] = $s;
                    unset($vistas[$s]);
                } else {
                    $falharam[] = $s; // tenta de novo na proxima rodada
                }
            }
            $this->gravarVistas($vistas);
        }
        return ['servidor' => $servidor, 'em_uso' => $emUso, 'fechadas' => $fechadas, 'falharam' => $falharam,
            'aguardando' => $fecharDeVerdade ? $decisao['aguardando'] : [...$decisao['aguardando'], ...$decisao['fechar']]];
    }

    /** @return array<string,int> */
    private function lerVistas(): array
    {
        $d = is_file($this->arquivoEstado) ? json_decode((string) file_get_contents($this->arquivoEstado), true) : null;
        if (!is_array($d)) {
            return [];
        }
        $saida = [];
        foreach ($d as $s => $t) {
            if (is_string($s) && is_int($t) && self::doDeliveryzim($s)) {
                $saida[$s] = $t;
            }
        }
        return $saida;
    }

    /** @param array<string,int> $vistas */
    private function gravarVistas(array $vistas): void
    {
        $tmp = $this->arquivoEstado . '.tmp';
        if (@file_put_contents($tmp, json_encode($vistas, JSON_THROW_ON_ERROR)) !== false) {
            @rename($tmp, $this->arquivoEstado);
        }
    }
}
