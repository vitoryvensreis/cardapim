<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia\Integracoes;

use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Ifood\IfoodConta;
use DateTimeImmutable;
use PDO;

/**
 * Loja no iFood (modulo Merchant): lojas autorizadas, detalhes, situacao, pausas e horario de funcionamento.
 * Tudo lido do iFood na hora (o iFood e a fonte desses dados). Guardado aqui so a escolha do horario
 * (integracoes.dados.horario_proprio: false = o iFood segue o horario do Perfil) e o log de auditoria.
 */
final class IfoodLojaService extends BaseIntegracao
{
    public const MAX_TURNOS_DIA = 4;
    public const NOMES_DIAS = ['MONDAY' => 'Segunda', 'TUESDAY' => 'Terça', 'WEDNESDAY' => 'Quarta', 'THURSDAY' => 'Quinta',
        'FRIDAY' => 'Sexta', 'SATURDAY' => 'Sábado', 'SUNDAY' => 'Domingo'];

    /** Ultima pausa criada/removida aqui: o iFood pode levar alguns segundos para refletir na lista. */
    private ?array $pausaCriada = null;
    private ?string $pausaRemovida = null;

    public function __construct(PDO $db, UsuarioLogado $autor, ?int $idRestaurante, private readonly IfoodApi $api = new IfoodApi())
    {
        parent::__construct($db, $autor, $idRestaurante);
    }

    /**
     * Tudo que a tela mostra. Cada parte e buscada separada: se uma falhar, as outras aparecem e a falha vira aviso.
     * @return array{conectada:bool, loja:?string, lojas:array, detalhes:?array, situacao:array, pausas:array, horarios:array, sugestao:array, horario_proprio:bool, perfil:array, erros:array<string,string>}
     */
    public function painel(): array
    {
        $conta = $this->conta();
        $vazio = ['conectada' => false, 'loja' => null, 'lojas' => [], 'detalhes' => null, 'situacao' => [], 'pausas' => [], 'horarios' => [], 'sugestao' => [],
            'horario_proprio' => false, 'perfil' => ['turnos' => [], 'sempre' => false, 'resumo' => '', 'dias' => []], 'erros' => []];
        if (!$conta->conectada()) {
            return $vazio;
        }
        $vazio['horario_proprio'] = $conta->opcao('horario_proprio');
        $vazio['perfil'] = $this->horarioDoPerfil();
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
            'sugestao' => self::paraFormulario($horarios !== [] ? $horarios : $vazio['perfil']['turnos']),
            'horario_proprio' => $vazio['horario_proprio'],
            'perfil' => $vazio['perfil'],
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
        } elseif ($ini < new DateTimeImmutable('-60 minutes')) {
            $erros['inicio'] = 'A pausa não pode começar no passado.';
        } elseif ($ini < new DateTimeImmutable()) {
            $ini = new DateTimeImmutable(); // tela aberta ha alguns minutos: comeca agora
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
            $id = $this->api->criarPausa($token, $loja, $descricao, $ini, $fi);
            $this->pausaCriada = ['id' => $id, 'descricao' => $descricao, 'inicio' => self::utc($ini), 'fim' => self::utc($fi)];
            $this->log('iFood: pausa criada', sprintf('"%s" de %s até %s.', $descricao, $ini->format('d/m H:i'), $fi->format('d/m H:i')));
            return Resultado::sucesso('Pausa criada. A loja fica fechada no iFood de ' . $ini->format('d/m \à\s H:i') . ' até ' . $fi->format('d/m \à\s H:i') . ' e aparece na lista abaixo.');
        });
    }

    /** Valores iniciais do formulario de nova pausa: comeca agora e dura 1 hora. @return array{descricao:string, inicio:string, fim:string} */
    public static function novaPausa(): array
    {
        return ['descricao' => '', 'inicio' => date('Y-m-d\TH:i'), 'fim' => date('Y-m-d\TH:i', time() + 3600)];
    }

    /**
     * Pausas do iFood agora, ja com a que acabou de ser criada ou removida aqui (o iFood pode demorar a mostrar).
     * @return array{pausas: list<array{id:string, descricao:string, inicio:string, fim:string}>, erro: ?string}
     */
    public function pausasAgora(): array
    {
        $conta = $this->conta();
        try {
            $lista = $conta->conectada() ? $this->api->pausas($conta->token(), (string) $conta->loja()) : [];
        } catch (IntegracaoErro $erro) {
            return ['pausas' => $this->pausaCriada !== null ? [$this->pausaCriada] : [], 'erro' => $erro->getMessage()];
        }
        if ($this->pausaCriada !== null && !in_array($this->pausaCriada['id'], array_column($lista, 'id'), true)) {
            $lista[] = $this->pausaCriada;
        }
        if ($this->pausaRemovida !== null) {
            $lista = array_values(array_filter($lista, fn (array $p): bool => $p['id'] !== $this->pausaRemovida));
        }
        return ['pausas' => $lista, 'erro' => null];
    }

    public function removerPausa(string $idPausa): Resultado
    {
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $idPausa) !== 1) {
            return Resultado::falha('Pausa não encontrada.');
        }
        return $this->executar(function (string $token, string $loja) use ($idPausa): Resultado {
            $this->api->removerPausa($token, $loja, $idPausa);
            $this->pausaRemovida = $idPausa;
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
            $this->conta()->salvarOpcoes(['horario_proprio' => true]);
            $resumo = implode('; ', array_map(static fn (array $t): string => self::NOMES_DIAS[$t['dia']] . ' ' . $t['inicio'] . ' (' . $t['duracao'] . ' min)', $lido['turnos']));
            $this->log('iFood: horário diferente enviado', mb_substr($resumo, 0, 900));
            return Resultado::sucesso('Horário do iFood salvo. Mudanças no horário do Perfil não alteram mais o iFood.');
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

    /** O iFood volta a seguir o horario do Perfil (padrao) e recebe esse horario agora. */
    public function usarHorarioDoPerfil(): Resultado
    {
        $perfil = $this->horarioDoPerfil();
        if ($perfil['turnos'] === []) {
            return Resultado::falha('O Perfil ainda não tem horário de funcionamento. Cadastre em Perfil do restaurante e volte aqui.');
        }
        return $this->executar(function (string $token, string $loja) use ($perfil): Resultado {
            $this->api->salvarHorarios($token, $loja, $perfil['turnos']);
            $this->conta()->salvarOpcoes(['horario_proprio' => false]);
            $this->log('iFood: horário do Perfil enviado', mb_substr($perfil['resumo'], 0, 900));
            return Resultado::sucesso('Pronto: o iFood segue o horário do Perfil. Quando você mudar o horário no Perfil, o iFood muda junto.');
        });
    }

    /**
     * Chamado depois de salvar o horario no Perfil: se o iFood segue o Perfil, envia o horario novo.
     * null = nada a fazer (iFood desligado ou com horario diferente). Nunca lanca erro de rede.
     */
    public function acompanharPerfil(): ?Resultado
    {
        $conta = $this->conta();
        if (!$conta->conectada() || $conta->opcao('horario_proprio')) {
            return null;
        }
        $perfil = $this->horarioDoPerfil();
        if ($perfil['turnos'] === []) {
            return null;
        }
        try {
            $this->api->salvarHorarios($conta->token(), (string) $conta->loja(), $perfil['turnos']);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha('O horário foi salvo aqui, mas o iFood não recebeu: ' . $erro->getMessage() . ' Envie de novo em Integrações > iFood > Loja no iFood.');
        }
        $this->log('iFood: horário do Perfil enviado', mb_substr($perfil['resumo'], 0, 900));
        return Resultado::sucesso('O iFood também recebeu o horário novo.');
    }

    /** Horario do Perfil ja convertido para o iFood. @return array{turnos: list<array{dia:string, inicio:string, duracao:int}>, sempre: bool, resumo: string, dias: array<string, list<string>>} */
    public function horarioDoPerfil(): array
    {
        $repo = new PerfilRepository($this->db);
        $restaurante = $repo->buscar((int) $this->idRestaurante) ?? [];
        $sempre = (int) ($restaurante['sempre_aberto'] ?? 0) === 1;
        $lista = $sempre ? [] : $repo->horarios((int) $this->idRestaurante);
        $dias = [];
        $porDia = HorarioFuncionamento::porDia($lista);
        foreach ([1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY', 4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY', 0 => 'SUNDAY'] as $d => $dia) {
            $dias[$dia] = $sempre ? ['Aberto 24 horas'] : array_map(static fn (array $t): string => substr((string) $t['abre'], 0, 5) . ' às ' . substr((string) $t['fecha'], 0, 5), $porDia[$d]);
        }
        return ['turnos' => self::turnosDoPerfil($sempre, $lista), 'sempre' => $sempre, 'resumo' => HorarioFuncionamento::resumo($sempre, $lista), 'dias' => $dias];
    }

    /**
     * Perfil (0 = domingo; turno com "fecha" menor que "abre" vai ate o dia seguinte) para turnos do iFood.
     * O turno que passa da meia-noite vira dois (ate 24:00 e a partir de 00:00 do dia seguinte); turnos encostados se juntam.
     * @param list<array{dia_semana:int|string, abre:string, fecha:string}> $lista
     * @return list<array{dia:string, inicio:string, duracao:int}>
     */
    public static function turnosDoPerfil(bool $sempre, array $lista): array
    {
        $porDia = array_fill(0, 7, []);
        for ($d = 0; $d <= 6 && $sempre; $d++) {
            $porDia[$d][] = [0, 1440];
        }
        foreach ($sempre ? [] : $lista as $h) {
            $d = (int) $h['dia_semana'];
            $a = self::minutos(substr((string) $h['abre'], 0, 5));
            $f = self::minutos(substr((string) $h['fecha'], 0, 5));
            if ($d < 0 || $d > 6 || $a === null || $f === null || $a === $f) {
                continue;
            }
            if ($f > $a) {
                $porDia[$d][] = [$a, $f];
                continue;
            }
            $porDia[$d][] = [$a, 1440];
            if ($f > 0) {
                $porDia[($d + 1) % 7][] = [0, $f];
            }
        }
        $indice = ['SUNDAY' => 0, 'MONDAY' => 1, 'TUESDAY' => 2, 'WEDNESDAY' => 3, 'THURSDAY' => 4, 'FRIDAY' => 5, 'SATURDAY' => 6];
        $turnos = [];
        foreach (IfoodApi::DIAS as $dia) {
            $faixas = $porDia[$indice[$dia]];
            usort($faixas, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
            $juntas = [];
            foreach ($faixas as [$a, $f]) {
                $u = count($juntas) - 1;
                if ($u >= 0 && $a <= $juntas[$u][1]) {
                    $juntas[$u][1] = max($juntas[$u][1], $f);
                } else {
                    $juntas[] = [$a, $f];
                }
            }
            foreach (array_slice($juntas, 0, self::MAX_TURNOS_DIA) as [$a, $f]) {
                $turnos[] = ['dia' => $dia, 'inicio' => sprintf('%02d:%02d', intdiv($a, 60), $a % 60), 'duracao' => $f - $a];
            }
        }
        return $turnos;
    }

    /**
     * Situacao resumida para o botao "Abrir loja no iFood".
     * estado: aberta | pausada (da para abrir: tem pausa ativa) | fechada (fora do horario ou outro motivo do iFood) | erro | desconectada
     * @return array{estado:string, texto:string, detalhe:string, pode_abrir:bool}
     */
    public function situacaoAgora(): array
    {
        $conta = $this->conta();
        if (!$conta->conectada()) {
            return ['estado' => 'desconectada', 'texto' => 'Desconectado', 'detalhe' => 'Conecte o iFood em Integrações.', 'pode_abrir' => false];
        }
        try {
            $token = $conta->token();
            $loja = (string) $conta->loja();
            $situacao = $this->api->situacaoLoja($token, $loja);
            if (array_filter($situacao, static fn (array $s): bool => $s['disponivel']) !== []) {
                return ['estado' => 'aberta', 'texto' => 'Loja aberta', 'detalhe' => 'A loja está recebendo pedidos no iFood.', 'pode_abrir' => false];
            }
            $ativas = self::pausasAtivas($this->api->pausas($token, $loja));
            if ($ativas !== []) {
                $p = $ativas[0];
                $fim = self::dataIfood($p['fim']);
                return ['estado' => 'pausada', 'texto' => 'Abrir loja', 'pode_abrir' => true,
                    'detalhe' => 'Pausada' . ($p['descricao'] !== '' ? ' (' . $p['descricao'] . ')' : '') . ($fim !== null ? ' até ' . $fim->format('d/m H:i') : '') . '. Clique para abrir agora.'];
            }
            $motivo = '';
            foreach ($situacao as $s) {
                $motivo = $s['mensagem'] !== '' ? $s['mensagem'] : ($s['validacoes'][0] ?? '');
                if ($motivo !== '') {
                    break;
                }
            }
            return ['estado' => 'fechada', 'texto' => 'Fora do horário', 'pode_abrir' => false,
                'detalhe' => ($motivo !== '' ? $motivo . '. ' : '') . 'A loja abre sozinha no iFood no horário de funcionamento.'];
        } catch (IntegracaoErro $erro) {
            return ['estado' => 'erro', 'texto' => 'Sem resposta', 'detalhe' => $erro->getMessage(), 'pode_abrir' => false];
        }
    }

    /** Abre a loja no iFood agora: remove as pausas ativas. Fora do horario de funcionamento nao ha o que abrir. */
    public function abrirLoja(): Resultado
    {
        return $this->executar(function (string $token, string $loja): Resultado {
            $ativas = self::pausasAtivas($this->api->pausas($token, $loja));
            if ($ativas === []) {
                $aberta = array_filter($this->api->situacaoLoja($token, $loja), static fn (array $s): bool => $s['disponivel']) !== [];
                return $aberta ? Resultado::sucesso('A loja já está aberta no iFood.')
                    : Resultado::falha('A loja não está pausada: ela está fora do horário de funcionamento do iFood e abre sozinha no horário.');
            }
            foreach ($ativas as $p) {
                $this->api->removerPausa($token, $loja, $p['id']);
            }
            $this->log('iFood: loja aberta', count($ativas) === 1 ? 'Pausa "' . $ativas[0]['descricao'] . '" removida.' : count($ativas) . ' pausas removidas.');
            return Resultado::sucesso('Loja aberta no iFood.');
        });
    }

    /**
     * Pausas valendo agora.
     * @param list<array{id:string, descricao:string, inicio:string, fim:string}> $pausas
     * @return list<array{id:string, descricao:string, inicio:string, fim:string}>
     */
    public static function pausasAtivas(array $pausas, ?DateTimeImmutable $agora = null): array
    {
        $agora ??= new DateTimeImmutable();
        return array_values(array_filter($pausas, static function (array $p) use ($agora): bool {
            $ini = self::dataIfood($p['inicio']);
            $fim = self::dataIfood($p['fim']);
            return $ini !== null && $ini <= $agora && ($fim === null || $fim > $agora);
        }));
    }

    /**
     * Data/hora vinda do iFood, no fuso do sistema. O iFood trabalha em UTC e as vezes devolve sem o fuso
     * ("2026-11-17T12:49:00"): sem fuso, e UTC (nunca o horario local).
     */
    public static function dataIfood(string $valor): ?DateTimeImmutable
    {
        return IfoodApi::data($valor);
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

    /** Formato que o iFood usa: UTC com "Z". */
    public static function utc(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function dataHora(string $valor): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', trim($valor));
        return $d !== false && $d->format('Y-m-d\TH:i') === trim($valor) ? $d : null;
    }
}
