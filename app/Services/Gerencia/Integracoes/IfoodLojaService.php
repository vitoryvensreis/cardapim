<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia\Integracoes;

use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Ifood\IfoodConta;
use DateTimeImmutable;
use PDO;

/**
 * Loja no iFood (modulo Merchant): lojas autorizadas, detalhes, situacao, pausas e horario de funcionamento.
 * Tudo lido do iFood na hora (o iFood e a fonte desses dados); nada fica guardado aqui alem do log de auditoria.
 */
final class IfoodLojaService extends BaseIntegracao
{
    public const MAX_TURNOS_DIA = 4;
    public const NOMES_DIAS = ['MONDAY' => 'Segunda', 'TUESDAY' => 'Terça', 'WEDNESDAY' => 'Quarta', 'THURSDAY' => 'Quinta',
        'FRIDAY' => 'Sexta', 'SATURDAY' => 'Sábado', 'SUNDAY' => 'Domingo'];

    public function __construct(PDO $db, UsuarioLogado $autor, ?int $idRestaurante, private readonly IfoodApi $api = new IfoodApi())
    {
        parent::__construct($db, $autor, $idRestaurante);
    }

    /**
     * Tudo que a tela mostra. Cada parte e buscada separada: se uma falhar, as outras aparecem e a falha vira aviso.
     * @return array{conectada:bool, loja:?string, lojas:array, detalhes:?array, situacao:array, pausas:array, horarios:array, sugestao:array, erros:array<string,string>}
     */
    public function painel(): array
    {
        $conta = $this->conta();
        $vazio = ['conectada' => false, 'loja' => null, 'lojas' => [], 'detalhes' => null, 'situacao' => [], 'pausas' => [], 'horarios' => [], 'sugestao' => [], 'erros' => []];
        if (!$conta->conectada()) {
            return $vazio;
        }
        $loja = (string) $conta->loja();
        $erros = [];
        try {
            $token = $conta->token();
        } catch (IntegracaoErro $erro) {
            return ['conectada' => true, 'loja' => $loja, 'erros' => ['geral' => $erro->getMessage()]] + $vazio;
        }
        $buscar = static function (string $parte, callable $f, mixed $padrao) use (&$erros): mixed {
            try {
                return $f();
            } catch (IntegracaoErro $erro) {
                $erros[$parte] = $erro->getMessage();
                return $padrao;
            }
        };
        $horarios = $buscar('horarios', fn (): array => $this->api->horarios($token, $loja), []);
        return [
            'conectada' => true,
            'loja' => $loja,
            'lojas' => $buscar('lojas', fn (): array => $this->api->lojas($token), []),
            'detalhes' => $buscar('detalhes', fn (): array => $this->api->detalhesLoja($token, $loja), null),
            'situacao' => $buscar('situacao', fn (): array => $this->api->situacaoLoja($token, $loja), []),
            'pausas' => $buscar('pausas', fn (): array => $this->api->pausas($token, $loja), []),
            'horarios' => $horarios,
            'sugestao' => $horarios !== [] ? self::paraFormulario($horarios) : $this->horariosDoRestaurante(),
            'erros' => $erros,
        ];
    }

    /** Fecha a loja no iFood por um periodo. Datas no formato do campo datetime-local (2026-11-17T14:30). */
    public function criarPausa(string $descricao, string $inicio, string $fim): Resultado
    {
        $descricao = trim((string) preg_replace('/\s+/u', ' ', $descricao));
        $erros = [];
        if (mb_strlen($descricao) < 3 || mb_strlen($descricao) > 100) {
            $erros['descricao'] = 'Conte o motivo da pausa (de 3 a 100 letras).';
        }
        $ini = self::dataHora($inicio);
        $fi = self::dataHora($fim);
        if ($ini === null) {
            $erros['inicio'] = 'Escolha quando a pausa começa.';
        } elseif ($ini < new DateTimeImmutable('-5 minutes')) {
            $erros['inicio'] = 'A pausa não pode começar no passado.';
        }
        if ($fi === null) {
            $erros['fim'] = 'Escolha quando a pausa termina.';
        } elseif ($ini !== null && $fi <= $ini) {
            $erros['fim'] = 'O fim precisa ser depois do começo.';
        } elseif ($ini !== null && $fi > $ini->modify('+30 days')) {
            $erros['fim'] = 'A pausa pode durar no máximo 30 dias.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos da pausa.', $erros);
        }
        return $this->executar(function (string $token, string $loja) use ($descricao, $ini, $fi): Resultado {
            $this->api->criarPausa($token, $loja, $descricao, $ini, $fi);
            $this->log('iFood: pausa criada', sprintf('"%s" de %s até %s.', $descricao, $ini->format('d/m H:i'), $fi->format('d/m H:i')));
            return Resultado::sucesso('Pausa criada. A loja fica fechada no iFood de ' . $ini->format('d/m \à\s H:i') . ' até ' . $fi->format('d/m \à\s H:i') . '.');
        });
    }

    public function removerPausa(string $idPausa): Resultado
    {
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $idPausa) !== 1) {
            return Resultado::falha('Pausa não encontrada.');
        }
        return $this->executar(function (string $token, string $loja) use ($idPausa): Resultado {
            $this->api->removerPausa($token, $loja, $idPausa);
            $this->log('iFood: pausa removida', 'Pausa ' . $idPausa . ' removida.');
            return Resultado::sucesso('Pausa removida. A loja volta a seguir o horário de funcionamento no iFood.');
        });
    }

    /**
     * Envia o horario de funcionamento ao iFood. $entrada: dia (MONDAY...) => lista de ['abre' => 'HH:MM', 'fecha' => 'HH:MM'].
     * "fecha" 00:00 = meia-noite. Turnos de um dia nao podem se sobrepor.
     * @param array<mixed> $entrada
     */
    public function salvarHorarios(array $entrada): Resultado
    {
        $lido = self::lerHorarios($entrada);
        if ($lido['erros'] !== []) {
            return Resultado::falha('Confira os horários destacados.', $lido['erros']);
        }
        if ($lido['turnos'] === []) {
            return Resultado::falha('Preencha pelo menos um horário.', ['_horarios' => 'Preencha pelo menos um horário.']);
        }
        return $this->executar(function (string $token, string $loja) use ($lido): Resultado {
            $this->api->salvarHorarios($token, $loja, $lido['turnos']);
            $resumo = implode('; ', array_map(static fn (array $t): string => self::NOMES_DIAS[$t['dia']] . ' ' . $t['inicio'] . ' (' . $t['duracao'] . ' min)', $lido['turnos']));
            $this->log('iFood: horários enviados', mb_substr($resumo, 0, 900));
            return Resultado::sucesso('Horários enviados ao iFood. Confira abaixo como o iFood guardou.');
        });
    }

    /**
     * Le e valida o formulario de horarios (sem rede).
     * @param array<mixed> $entrada
     * @return array{turnos: list<array{dia:string, inicio:string, duracao:int}>, erros: array<string,string>}
     */
    public static function lerHorarios(array $entrada): array
    {
        $turnos = [];
        $erros = [];
        foreach (IfoodApi::DIAS as $dia) {
            $linhas = is_array($entrada[$dia] ?? null) ? array_slice(array_values($entrada[$dia]), 0, self::MAX_TURNOS_DIA) : [];
            $doDia = [];
            foreach ($linhas as $i => $l) {
                $abre = is_array($l) && is_string($l['abre'] ?? null) ? trim($l['abre']) : '';
                $fecha = is_array($l) && is_string($l['fecha'] ?? null) ? trim($l['fecha']) : '';
                if ($abre === '' && $fecha === '') {
                    continue;
                }
                $campo = $dia . '.' . $i;
                $ma = self::minutos($abre);
                $mf = self::minutos($fecha);
                if ($ma === null || $mf === null) {
                    $erros[$campo] = self::NOMES_DIAS[$dia] . ': preencha a abertura e o fechamento (ex.: 10:00 e 19:00).';
                    continue;
                }
                if ($mf === 0) {
                    $mf = 1440; // fecha a meia-noite
                }
                if ($mf <= $ma) {
                    $erros[$campo] = self::NOMES_DIAS[$dia] . ': o fechamento precisa ser depois da abertura.';
                    continue;
                }
                $doDia[] = [$ma, $mf, $campo];
            }
            usort($doDia, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            foreach ($doDia as $k => [$ma, $mf, $campo]) {
                if ($k > 0 && $ma < $doDia[$k - 1][1]) {
                    $erros[$campo] = self::NOMES_DIAS[$dia] . ': os horários se sobrepõem.';
                    continue;
                }
                $turnos[] = ['dia' => $dia, 'inicio' => sprintf('%02d:%02d', intdiv($ma, 60), $ma % 60), 'duracao' => $mf - $ma];
            }
        }
        return ['turnos' => $turnos, 'erros' => $erros];
    }

    /** Turnos do iFood no formato do formulario. @param list<array{dia:string, inicio:string, duracao:int}> $turnos @return array<string, list<array{abre:string, fecha:string}>> */
    public static function paraFormulario(array $turnos): array
    {
        $saida = [];
        foreach ($turnos as $t) {
            $ini = self::minutos($t['inicio']) ?? 0;
            $fim = ($ini + $t['duracao']) % 1440;
            $saida[$t['dia']][] = ['abre' => $t['inicio'], 'fecha' => sprintf('%02d:%02d', intdiv($fim, 60), $fim % 60)];
        }
        return $saida;
    }

    /** Horarios cadastrados no Deliveryzim (Perfil), como sugestao quando o iFood ainda nao tem nenhum. @return array<string, list<array{abre:string, fecha:string}>> */
    private function horariosDoRestaurante(): array
    {
        $dias = [1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY', 4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY', 0 => 'SUNDAY'];
        $saida = [];
        try {
            foreach ((new PerfilRepository($this->db))->horarios((int) $this->idRestaurante) as $h) {
                $dia = $dias[(int) $h['dia_semana']] ?? null;
                if ($dia !== null) {
                    $saida[$dia][] = ['abre' => substr((string) $h['abre'], 0, 5), 'fecha' => substr((string) $h['fecha'], 0, 5)];
                }
            }
        } catch (\Throwable) {
            return [];
        }
        return $saida;
    }

    /** @param callable(string,string):Resultado $f */
    private function executar(callable $f): Resultado
    {
        $conta = $this->conta();
        if (!$conta->conectada()) {
            return Resultado::falha('Conecte o iFood primeiro.');
        }
        try {
            return $f($conta->token(), (string) $conta->loja());
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
    }

    private function conta(): IfoodConta
    {
        return new IfoodConta($this->db, (int) $this->idRestaurante, $this->api);
    }

    private static function minutos(string $hhmm): ?int
    {
        if (preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/', $hhmm, $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    private static function dataHora(string $valor): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', trim($valor));
        return $d !== false && $d->format('Y-m-d\TH:i') === trim($valor) ? $d : null;
    }
}
