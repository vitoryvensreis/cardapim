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
    /** @var array{inicio:string, fim:string}|null */
    private ?array $ultimoPeriodo = null;

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
        $fuso = FusoLojaIfood::PADRAO;
        $horarios = $buscar('horarios', fn (): array => $this->api->horarios($token, $loja), []);
        $situacao = $buscar('situacao', fn (): array => $this->api->situacaoLoja($token, $loja), []);
        $pausas = $buscar('pausas', fn (): array => $this->api->pausas($token, $loja, $fuso), []);
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
     * Cria interrupcao. O datetime-local e interpretado em America/Sao_Paulo;
     * a API Merchant continua recebendo a hora de parede esperada pelo iFood.
     * A API trabalha com instantes ISO8601 UTC e responde de forma assincrona.
     */
    public function criarPausa(string $descricao, string $inicio, string $fim): Resultado
    {
        $descricao = trim((string) preg_replace('/\s+/u', ' ', $descricao));
        if ($descricao === '' || mb_strlen($descricao) > 255) {
            return Resultado::falha('Confira os campos da pausa.', [
                'descricao' => 'Informe o motivo da pausa (até 255 caracteres).',
            ]);
        }

        return $this->executar(function (string $token, string $loja) use ($descricao, $inicio, $fim): Resultado {
            $detalhes = $this->api->detalhesLoja($token, $loja);
            $fuso = FusoLojaIfood::PADRAO;
            $zona = new DateTimeZone($fuso);
            $ini = self::dataHora($inicio, $zona);
            $fi = self::dataHora($fim, $zona);
            $agora = new DateTimeImmutable('now', $zona);
            $campos = [];
            if ($ini === null) {
                $campos['inicio'] = 'Informe o início no horário de Brasília.';
            } elseif ($ini->getTimestamp() < $agora->getTimestamp() - 60) {
                $campos['inicio'] = 'O início da pausa não pode ficar no passado.';
            } elseif ($ini < $agora) {
                // O minuto apresentado pelo formulário pode ter acabado enquanto o gerente preenchia.
                $ini = $agora;
            }
            if ($fi === null) {
                $campos['fim'] = 'Informe o término no horário de Brasília.';
            } elseif ($ini !== null) {
                $duracao = $fi->getTimestamp() - $ini->getTimestamp();
                if ($duracao < 60) {
                    $campos['fim'] = 'A pausa deve durar pelo menos 1 minuto.';
                } elseif ($duracao > 7 * 86400) {
                    $campos['fim'] = 'A pausa pode durar no máximo 7 dias.';
                }
            }
            if ($campos !== []) {
                return Resultado::falha('Confira os horários da pausa.', $campos);
            }

            // O ambiente de teste pode aceitar sobreposicoes em vez do HTTP 409 documentado.
            // Validar o intervalo de instantes no backend antes de enviar o POST.
            foreach ($this->api->pausas($token, $loja, $fuso) as $pausa) {
                $inicioExistente = IfoodApi::data((string) ($pausa['inicio'] ?? ''))?->getTimestamp();
                $fimExistente = IfoodApi::data((string) ($pausa['fim'] ?? ''))?->getTimestamp();
                if ($inicioExistente !== null && $fimExistente !== null
                    && $inicioExistente < $fi->getTimestamp() && $fimExistente > $ini->getTimestamp()) {
                    return Resultado::falha('Já existe uma pausa no iFood nesse período. Ajuste as datas ou remova a pausa existente.', [
                        'inicio' => 'Há uma interrupção sobreposta.',
                    ]);
                }
            }

            $id = $this->api->criarPausa($token, $loja, $descricao, $ini, $fi);
            $this->ultimaCriadaId = $id;
            EstadoLoja::criarPausa($this->db, (int) $this->idRestaurante, $id, $ini, $fi);
            $this->log('iFood: pausa criada', sprintf(
                '"%s" | loja=%s | fuso=%s | de=%s ate=%s | UTC=%s ate=%s',
                $descricao, $loja, $fuso, $ini->format('d/m/Y H:i:s'), $fi->format('d/m/Y H:i:s'),
                self::utc($ini), self::utc($fi)
            ));
            return Resultado::sucesso('O iFood recebeu a solicitação de pausa de '
                . $ini->format('d/m \à\s H:i') . ' até ' . $fi->format('d/m \à\s H:i')
                . ' (horário de Brasília). Aguarde a confirmação na lista e no status operacional do iFood.');
        });
    }

    /** Pausa rapida "so o iFood": o link proprio continua recebendo pelo horario do Perfil (cardapio independe do iFood). */
    public const PAUSAS_RAPIDAS = [
        '30min' => '30 minutos',
        '1h' => '1 hora',
        '2h' => '2 horas',
        'fim_do_dia' => 'Até o fim do dia',
    ];
    public const MOTIVO_PAUSA_RAPIDA = 'Pausa do iFood pelo Deliveryzim (link próprio segue aberto)';

    /**
     * Periodo da pausa rapida no horario de Brasilia, no formato do formulario (Y-m-d\TH:i:s).
     * Comeca 10 s a frente (como a pausa manual). "Ate o fim do dia" termina 23:59:59 de hoje.
     * @return array{inicio:string, fim:string}|null null = opcao desconhecida ou dia ja no fim
     */
    public static function periodoRapido(string $duracao, ?DateTimeImmutable $agora = null): ?array
    {
        if (!isset(self::PAUSAS_RAPIDAS[$duracao])) {
            return null;
        }
        $zona = FusoLojaIfood::timezone(FusoLojaIfood::PADRAO);
        $inicio = ($agora ?? new DateTimeImmutable('now', $zona))->setTimezone($zona)->modify('+10 seconds');
        $fim = match ($duracao) {
            '30min' => $inicio->modify('+30 minutes'),
            '1h' => $inicio->modify('+1 hour'),
            '2h' => $inicio->modify('+2 hours'),
            'fim_do_dia' => $inicio->setTime(23, 59, 59),
        };
        if ($fim->getTimestamp() - $inicio->getTimestamp() < 60) {
            return null;
        }
        return ['inicio' => $inicio->format('Y-m-d\TH:i:s'), 'fim' => $fim->format('Y-m-d\TH:i:s')];
    }

    /** Cria a pausa rapida no iFood pelas mesmas regras da pausa manual (sobreposicao, 201 com ID, log). */
    public function pausaRapida(string $duracao): Resultado
    {
        $periodo = self::periodoRapido($duracao);
        if ($periodo === null) {
            return Resultado::falha(isset(self::PAUSAS_RAPIDAS[$duracao])
                ? 'Falta menos de um minuto para o fim do dia. Escolha outro tempo.'
                : 'Escolha por quanto tempo pausar o iFood.', ['duracao' => 'Escolha uma das opções.']);
        }
        $this->ultimoPeriodo = $periodo;
        $res = $this->criarPausa(self::MOTIVO_PAUSA_RAPIDA, $periodo['inicio'], $periodo['fim']);
        if ($res->ok) {
            return Resultado::sucesso('iFood pausado (' . mb_strtolower(self::PAUSAS_RAPIDAS[$duracao])
                . '). Seu link próprio continua recebendo pedidos no horário do Perfil. Aguarde a confirmação do iFood abaixo.');
        }
        return $res;
    }

    /** @return array{inicio:string, fim:string}|null periodo da ultima pausa rapida (para a tela acompanhar a confirmacao) */
    public function periodoDaUltimaRapida(): ?array
    {
        return $this->ultimoPeriodo;
    }

    /** Valores iniciais em Brasilia: 10 segundos no futuro, ate o JS assumir o relogio. */
    public static function novaPausa(string $fuso = 'America/Sao_Paulo'): array
    {
        $inicio = (new DateTimeImmutable('now', FusoLojaIfood::timezone($fuso)))->modify('+10 seconds');
        return ['descricao' => '', 'inicio' => $inicio->format('Y-m-d\TH:i:s'),
            'fim' => $inicio->modify('+1 hour')->format('Y-m-d\TH:i:s')];
    }

    /** Toda operacao Merchant usa o horario oficial de Brasilia, independente do endereco. */
    public function fusoDaLoja(): string
    {
        return FusoLojaIfood::PADRAO;
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
            $lista = $conta->conectada() ? $this->api->pausas($conta->token(), (string) $conta->loja(), $this->fusoDaLoja()) : [];
        } catch (IntegracaoErro $erro) {
            return ['pausas' => [], 'erro' => $erro->getMessage()];
        }
        return ['pausas' => $lista, 'erro' => null];
    }

    /** Identificadores retornados pelo POST/DELETE; nao confundir com fechamento operacional. */
    public function idCriado(): ?string
    {
        return $this->ultimaCriadaId;
    }

    public function idRemovido(): ?string
    {
        return $this->ultimaRemovidaId;
    }

    /**
     * Consultas leves somente-leitura para acompanhar criacao/remocao, sem fingir
     * confirmacao quando o iFood falha ou ainda devolve status antigo.
     * Para remocao, consultar somente GET /interruptions: GET /status nao condiciona
     * a remocao, pois outra restricao pode manter a loja fechada.
     * @return array{pausas:array,situacao:array,erros:array,divergencia:bool}
     */
    public function acompanharPausa(bool $consultarSituacao = true): array
    {
        $conta = $this->conta();
        $erros = [];
        $pausas = [];
        $situacao = [];
        if (!$conta->conectada()) {
            return ['pausas' => [], 'situacao' => [], 'erros' => ['geral' => 'iFood desconectado.'], 'divergencia' => false];
        }
        try {
            $token = $conta->token();
            $loja = (string) $conta->loja();
            try {
                $pausas = $this->api->pausas($token, $loja, $this->fusoDaLoja());
            } catch (IntegracaoErro $e) {
                $erros['pausas'] = $e->getMessage();
            }
            if ($consultarSituacao) {
                try {
                    $situacao = $this->api->situacaoLoja($token, $loja);
                } catch (IntegracaoErro $e) {
                    $erros['situacao'] = $e->getMessage();
                }
            }
        } catch (IntegracaoErro $e) {
            $erros['geral'] = $e->getMessage();
        }
        $divergencia = $pausas !== [] && $situacao !== [] && !isset($erros['pausas'])
            && EstadoLoja::resumoOperacional($situacao, $pausas)['divergente'];
        return compact('pausas', 'situacao', 'erros', 'divergencia');
    }

    /**
     * Apos confirmar a lista de interrupcoes, consulta o GET /status real
     * e atualiza o snapshot MySQL usado pelo botao do cabecalho.
     * A falha da disponibilidade nao desfaz uma remocao ja confirmada.
     * @param list<array<string,mixed>> $pausas Validadas pelo GET /interruptions
     * @return array{situacao:array, erro:?string}
     */
    public function atualizarDisponibilidadeConfirmada(array $pausas): array
    {
        try {
            $conta = $this->conta();
            if (!$conta->conectada()) {
                return ['situacao' => [], 'erro' => 'A conexão com o iFood não está ativa.'];
            }
            $situacao = $this->api->situacaoLoja($conta->token(), (string) $conta->loja());
            if ($situacao === []) {
                return ['situacao' => [], 'erro' => 'O iFood não informou a disponibilidade da loja.'];
            }
            EstadoLoja::reconciliar($this->db, (int) $this->idRestaurante, $situacao, $pausas);
            return ['situacao' => $situacao, 'erro' => null];
        } catch (IntegracaoErro $erro) {
            return ['situacao' => [], 'erro' => $erro->getMessage()];
        }
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
                    $pausas = $this->api->pausas($token, (string) $conta->loja(), $this->fusoDaLoja());
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
            $ativas = self::pausasAtivas($this->api->pausas($token, $loja, $this->fusoDaLoja()));
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
        // Compatibilidade com formularios existentes (minutos) e novo modo
        // automatico do datetime-local (segundos exatos).
        $valor = trim($valor);
        $formato = preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\z/', $valor) === 1
            ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i';
        $d = DateTimeImmutable::createFromFormat('!' . $formato, $valor, $zona);
        return $d !== false && $d->format($formato) === $valor ? $d : null;
    }
}
