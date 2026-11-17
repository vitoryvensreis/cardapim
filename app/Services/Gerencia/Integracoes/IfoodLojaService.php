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
use Cardapim\Services\Ifood\EstadoLoja;
use Cardapim\Services\Ifood\FusoLojaIfood;
use DateTimeZone;
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

    private ?string $ultimaCriadaId = null;
    private ?string $ultimaRemovidaId = null;

    public function __construct(PDO $db, UsuarioLogado $autor, ?int $idRestaurante, private readonly IfoodApi $api = new IfoodApi())
    {
        parent::__construct($db, $autor, $idRestaurante);
        if ($idRestaurante !== null) {
            $this->api->identificarRestaurante($idRestaurante);
        }
    }

    /**
     * Tudo que a tela mostra. Cada parte e buscada separada: se uma falhar, as outras aparecem e a falha vira aviso.
     * @return array{conectada:bool, loja:?string, lojas:array, detalhes:?array, situacao:array, pausas:array, horarios:array, sugestao:array, horario_proprio:bool, perfil:array, erros:array<string,string>}
     */
    public function painel(): array
    {
        $conta = $this->conta();
        $vazio = ['conectada' => false, 'loja' => null, 'lojas' => [], 'detalhes' => null, 'fuso' => 'America/Sao_Paulo', 'situacao' => [], 'pausas' => [], 'divergencia' => false, 'horarios' => [], 'sugestao' => [],
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
        $detalhes = $buscar('detalhes', fn (): array => $this->api->detalhesLoja($token, $loja), null);
        $fuso = $conta->fusoHorario() ?? FusoLojaIfood::sugerir(is_array($detalhes) ? $detalhes : []);
        $horarios = $buscar('horarios', fn (): array => $this->api->horarios($token, $loja), []);
        $situacao = $buscar('situacao', fn (): array => $this->api->situacaoLoja($token, $loja), []);
        $pausas = $buscar('pausas', fn (): array => $this->api->pausas($token, $loja), []);
        $divergencia = $situacao !== [] && !isset($erros['pausas'])
            && EstadoLoja::resumoOperacional($situacao, $pausas)['divergente'];
        return [
            'conectada' => true,
            'loja' => $loja,
            'lojas' => $buscar('lojas', fn (): array => $this->api->lojas($token), []),
            'detalhes' => $detalhes,
            'fuso' => $fuso,
            'situacao' => $situacao,
            'pausas' => $pausas,
            'divergencia' => $divergencia,
            'horarios' => $horarios,
            'sugestao' => self::paraFormulario($horarios !== [] ? $horarios : $vazio['perfil']['turnos']),
            'horario_proprio' => $vazio['horario_proprio'],
            'perfil' => $vazio['perfil'],
            'erros' => $erros,
        ];
    }

    /**
     * Cria interrupção Merchant com início imediato ou agendado.
     * A antecedência mínima não consta do contrato; já a duração é de 1 min a 7 dias.
     * datetime-local pertence ao fuso selecionado da LOJA, nunca ao do servidor.
     */
    public function criarPausa(string $descricao, string $inicio, string $fim, string $fuso = '', bool $imediata = false): Resultado
    {
        $descricao = trim((string) preg_replace('/\s+/u', ' ', $descricao));
        $erros = [];
        if ($descricao === '' || mb_strlen($descricao) > 255) {
            $erros['descricao'] = 'Informe um motivo (até 255 caracteres).';
        }
        if ($fuso !== '' && !FusoLojaIfood::valido($fuso)) {
            $erros['fuso'] = 'Selecione um fuso horário válido da loja.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos da pausa.', $erros);
        }

        return $this->executar(function (string $token, string $loja) use ($descricao, $inicio, $fim, $fuso, $imediata): Resultado {
            // Compatibilidade com requisições antigas: obter a região da loja, em vez de
            // supor o horário local do servidor.
            $fuso = $fuso !== '' ? $fuso : ($this->conta()->fusoHorario() ?? FusoLojaIfood::sugerir($this->api->detalhesLoja($token, $loja)));
            $zona = new DateTimeZone($fuso);
            $agora = new DateTimeImmutable('now', $zona);
            $ini = $imediata ? $agora : self::dataHora($inicio, $zona);
            $fi = self::dataHora($fim, $zona);
            $campos = [];

            if ($ini === null) {
                $campos['inicio'] = 'Informe uma data e hora de início válidas.';
            } elseif (!$imediata && $ini->getTimestamp() < $agora->getTimestamp() - 59) {
                $campos['inicio'] = 'O início agendado não pode estar no passado.';
            } elseif (!$imediata && $ini < $agora) {
                // Segundos decorridos entre abrir a tela e clicar em criar.
                $ini = $agora;
            }

            if ($fi === null) {
                $campos['fim'] = 'Informe uma data e hora de término válidas.';
            } elseif ($ini !== null) {
                $duracao = $fi->getTimestamp() - $ini->getTimestamp();
                if ($duracao < 60) {
                    $campos['fim'] = 'A pausa precisa durar pelo menos 1 minuto (regra do iFood).';
                } elseif ($duracao > 7 * 86400) {
                    $campos['fim'] = 'A pausa pode durar no máximo 7 dias (regra do iFood).';
                }
            }
            if ($campos !== []) {
                return Resultado::falha('Confira os horários da pausa.', $campos);
            }

            // iFood também valida conflitos; antecipamos o erro ao operador.
            $existentes = $this->api->pausas($token, $loja);
            foreach ($existentes as $pausa) {
                $pInicio = IfoodApi::data((string) ($pausa['inicio'] ?? ''))?->getTimestamp();
                $pFim = IfoodApi::data((string) ($pausa['fim'] ?? ''))?->getTimestamp();
                if ($pInicio !== null && $pFim !== null
                    && $pInicio < $fi->getTimestamp() && $pFim > $ini->getTimestamp()) {
                    return Resultado::falha('Já existe uma interrupção nesse período. Ajuste o horário ou remova a pausa anterior.', [
                        'inicio' => 'O horário se sobrepõe a outra pausa no iFood.',
                    ]);
                }
            }

            $id = $this->api->criarPausa($token, $loja, $descricao, $ini, $fi);
            $this->ultimaCriadaId = $id;
            try {
                $this->conta()->salvarFusoHorario($fuso);
            } catch (\Throwable) {
                // Não transformar POST 201 bem-sucedido em erro e induzir repetição da pausa.
                error_log('[Cardapim] Nao foi possivel salvar o fuso de preferencia do iFood.');
            }
            EstadoLoja::criarPausa($this->db, (int) $this->idRestaurante, $id, $ini, $fi);
            $this->log('iFood: pausa criada', sprintf('"%s" de %s até %s (%s).', $descricao, $ini->format('d/m H:i'), $fi->format('d/m H:i'), $fuso));
            return Resultado::sucesso('Solicitação recebida pelo iFood: de ' . $ini->format('d/m \à\s H:i') . ' até '
                . $fi->format('d/m \à\s H:i') . ' (horário da loja). O bloqueio local já foi aplicado quando cabível; aguarde a confirmação operacional do iFood.');
        });
    }

    /** Valores iniciais no fuso da loja: imediato, por 1 hora. */
    public static function novaPausa(string $fuso = 'America/Sao_Paulo'): array
    {
        $agora = new DateTimeImmutable('now', FusoLojaIfood::timezone($fuso));
        return [
            'descricao' => '',
            'inicio_agora' => '1',
            'inicio' => $agora->format('Y-m-d\TH:i'),
            'fim' => $agora->modify('+1 hour')->format('Y-m-d\TH:i'),
            'fuso' => $fuso,
        ];
    }

    /**
     * Consulta apenas as pausas devolvidas pelo iFood: jamais adicionar/remover
     * linhas artificialmente antes da confirmação externa.
     * @return array{pausas: list<array{id:string, descricao:string, inicio:string, fim:string}>, erro: ?string}
     */
    public function pausasAgora(): array
    {
        $conta = $this->conta();
        try {
            $lista = $conta->conectada() ? $this->api->pausas($conta->token(), (string) $conta->loja()) : [];
        } catch (IntegracaoErro $erro) {
            return ['pausas' => [], 'erro' => $erro->getMessage()];
        }
        return ['pausas' => $lista, 'erro' => null];
    }

    /**
     * Confirma uma alteracao apenas quando o GET do iFood refletir o resultado.
     * Nao altera a lista exibida: serve somente para distinguir sucesso de pendencia visual.
     * @param list<array{id:string, descricao:string, inicio:string, fim:string}> $pausas
     */
    public function alteracaoConfirmada(array $pausas): bool
    {
        $ids = array_column($pausas, 'id');
        if ($this->ultimaCriadaId !== null) {
            return in_array($this->ultimaCriadaId, $ids, true);
        }
        if ($this->ultimaRemovidaId !== null) {
            return !in_array($this->ultimaRemovidaId, $ids, true);
        }
        return true;
    }

    public function removerPausa(string $idPausa): Resultado
    {
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $idPausa) !== 1) {
            return Resultado::falha('Pausa não encontrada.');
        }
        return $this->executar(function (string $token, string $loja) use ($idPausa): Resultado {
            $this->api->removerPausa($token, $loja, $idPausa);
            $this->ultimaRemovidaId = $idPausa;
            EstadoLoja::removerPausa($this->db, (int) $this->idRestaurante, $idPausa);
            $this->log('iFood: pausa removida', 'Pausa ' . $idPausa . ' removida.');
            return Resultado::sucesso('O iFood recebeu a solicitação de remoção. Aguarde a confirmação na lista e no estado da loja; a reabertura depende do horário e das demais restrições.');
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
     * estado: aberta | divergente (API aberta com pausa vigente) | pausada | fechada | erro | desconectada
     * @return array{estado:string, texto:string, detalhe:string, pode_abrir:bool}
     */
    public function situacaoAgora(): array
    {
        $conta = $this->conta();
        if (!$conta->conectada()) {
            return ['estado' => 'desconectada', 'texto' => 'Desconectado', 'detalhe' => 'Conecte o iFood em Integrações.', 'pode_abrir' => false];
        }
        // Uma consulta ao MySQL, sem uma chamada por aba à API Merchant. O cron já consulta a cada 30s.
        $estado = EstadoLoja::consultar($this->db, (int) $this->idRestaurante);
        if ($estado['remoto'] === null) {
            // Bootstrap único antes da primeira rodada do cron; depois disso somente consulta MySQL.
            try {
                $token = $conta->token();
                $operacoes = $this->api->situacaoLoja($token, (string) $conta->loja());
                if ($operacoes !== []) {
                    $pausas = $this->api->pausas($token, (string) $conta->loja());
                    EstadoLoja::reconciliar($this->db, (int) $this->idRestaurante, $operacoes, $pausas);
                    $estado = EstadoLoja::consultar($this->db, (int) $this->idRestaurante);
                }
            } catch (IntegracaoErro) {
                // Uma falha na primeira consulta nunca declara a loja aberta.
            }
        }
        $pausa = $estado['pausa'];
        $remoto = $estado['remoto'];
        $agora = time();
        $localAtiva = $pausa !== null
            && (int) ($pausa['inicio'] ?? 0) <= $agora
            && (int) ($pausa['fim'] ?? 0) > $agora;
        if ($remoto !== null) {
            $ultima = (int) ($remoto['atualizado_em'] ?? 0);
            if ($ultima < $agora - 120) {
                return ['estado' => 'erro', 'texto' => 'Aguardando iFood', 'pode_abrir' => false,
                    'detalhe' => 'Último status externo antigo. Verifique a rotina do iFood; a proteção local de pedidos continua ativa.'];
            }

            $fechadaNoIfood = ($remoto['fechado'] ?? false) === true;
            // O snapshot anterior a uma pausa criada aqui ainda nao confirma o novo comando.
            if (!$fechadaNoIfood && $localAtiva && $ultima < (int) ($pausa['criada_em'] ?? 0)) {
                return ['estado' => 'erro', 'texto' => 'Verificando iFood', 'pode_abrir' => false,
                    'detalhe' => 'Pausa solicitada. Aguardando a proxima verificacao do status operacional do iFood.'];
            }
            if (!$fechadaNoIfood && ($localAtiva || ($remoto['divergente'] ?? false) === true)) {
                return ['estado' => 'divergente', 'texto' => 'iFood: loja aberta', 'pode_abrir' => false,
                    'detalhe' => $localAtiva
                        ? 'O iFood informa a loja aberta, apesar da pausa vigente. O Deliveryzim continua bloqueando novos pedidos locais por precaucao. Remova a pausa em Integracoes > iFood.'
                        : 'O iFood informa a loja aberta, apesar de existir uma pausa vigente cadastrada. Confira a disponibilidade no Portal do Parceiro.'];
            }
            if ($fechadaNoIfood) {
                if ($localAtiva) {
                    return ['estado' => 'pausada', 'texto' => 'Abrir loja', 'pode_abrir' => true,
                        'detalhe' => 'O iFood confirma a indisponibilidade. Clique para remover a pausa criada no Deliveryzim.'];
                }
                return ['estado' => 'fechada', 'texto' => 'Loja fechada', 'pode_abrir' => false,
                    'detalhe' => (string) ($remoto['motivo'] ?? '') ?: 'Loja indisponível no iFood.'];
            }
            return ['estado' => 'aberta', 'texto' => 'Loja aberta', 'pode_abrir' => false,
                'detalhe' => 'Disponibilidade confirmada pela última consulta ao iFood.'];
        }
        return ['estado' => 'erro', 'texto' => 'Verificando iFood', 'pode_abrir' => false,
            'detalhe' => $localAtiva
                ? 'Pausa solicitada: novos pedidos locais continuam bloqueados, mas ainda nao ha status do iFood.'
                : 'Aguardando a primeira consulta de disponibilidade ao iFood (até 30 segundos).'];
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
            $local = EstadoLoja::consultar($this->db, (int) $this->idRestaurante)['pausa'];
            $id = (string) ($local['id'] ?? '');
            if ($id === '') {
                return Resultado::falha('A pausa foi criada fora do Deliveryzim. Para evitar remover outra restrição sem autorização, use a lista de pausas em Integrações > iFood.');
            }
            $encontrada = false;
            foreach ($ativas as $p) {
                if ($p['id'] === $id) {
                    $this->api->removerPausa($token, $loja, $id);
                    $encontrada = true;
                    break;
                }
            }
            if (!$encontrada) {
                return Resultado::falha('Esta pausa não está mais ativa no iFood. Atualize a situação para confirmar o funcionamento.');
            }
            EstadoLoja::removerPausa($this->db, (int) $this->idRestaurante, $id);
            $this->log('iFood: pausa local removida', 'Pausa ' . $id . ' removida.');
            return Resultado::sucesso('Pausa removida. Aguardando o iFood confirmar a reabertura.');
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

    private static function dataHora(string $valor, DateTimeZone $zona): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', trim($valor), $zona);
        return $d !== false && $d->format('Y-m-d\TH:i') === trim($valor) ? $d : null;
    }
}
