<?php

declare(strict_types=1);

namespace Cardapim\Modules\Restaurante;

use DateTimeImmutable;

/**
 * Regras do horario de funcionamento (classe pura, sem banco).
 * Cada dia (0 = domingo ... 6 = sabado) tem ate 2 turnos. Turno com "fecha" menor que "abre"
 * termina no dia seguinte (ex.: 18:00 as 02:00).
 */
final class HorarioFuncionamento
{
    public const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    public const DIAS_CURTOS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    /**
     * Valida o formulario e devolve os turnos prontos para gravar.
     * @param array<mixed> $dias entrada "dia" do formulario: [d => [aberto, abre1, fecha1, turno2, abre2, fecha2]]
     * @return array{0:list<array{dia_semana:int,turno:int,abre:string,fecha:string}>, 1:array<int,string>} turnos e erros por dia
     */
    public static function validar(array $dias): array
    {
        $turnos = [];
        $erros = [];
        for ($d = 0; $d <= 6; $d++) {
            $dia = is_array($dias[$d] ?? null) ? $dias[$d] : [];
            if (($dia['aberto'] ?? '') !== '1') {
                continue;
            }
            $abre1 = self::hora($dia['abre1'] ?? null);
            $fecha1 = self::hora($dia['fecha1'] ?? null);
            if ($abre1 === null || $fecha1 === null) {
                $erros[$d] = self::DIAS[$d] . ': informe a hora que abre e a hora que fecha.';
                continue;
            }
            if ($abre1 === $fecha1) {
                $erros[$d] = self::DIAS[$d] . ': a hora de abrir e de fechar não podem ser iguais. Para 24 horas, use "Sempre aberto".';
                continue;
            }
            $turnos[] = ['dia_semana' => $d, 'turno' => 1, 'abre' => $abre1, 'fecha' => $fecha1];
            if (($dia['turno2'] ?? '') !== '1') {
                continue;
            }
            $abre2 = self::hora($dia['abre2'] ?? null);
            $fecha2 = self::hora($dia['fecha2'] ?? null);
            if ($abre2 === null || $fecha2 === null) {
                $erros[$d] = self::DIAS[$d] . ': informe os dois horários do segundo turno, ou tire o segundo turno.';
            } elseif ($fecha1 < $abre1) {
                $erros[$d] = self::DIAS[$d] . ': o primeiro turno vai até o dia seguinte, então não dá para ter segundo turno.';
            } elseif ($abre2 <= $fecha1) {
                $erros[$d] = self::DIAS[$d] . ': o segundo turno precisa começar depois que o primeiro termina (' . $fecha1 . ').';
            } elseif ($abre2 === $fecha2) {
                $erros[$d] = self::DIAS[$d] . ': no segundo turno, a hora de abrir e de fechar não podem ser iguais.';
            } else {
                $turnos[] = ['dia_semana' => $d, 'turno' => 2, 'abre' => $abre2, 'fecha' => $fecha2];
            }
        }
        if ($erros === [] && $turnos === []) {
            $erros[-1] = 'Marque pelo menos um dia aberto, ou escolha "Sempre aberto".';
        }
        return [$turnos, $erros];
    }

    /**
     * Esta aberto neste momento?
     * @param list<array{dia_semana:int,turno:int,abre:string,fecha:string}> $turnos
     */
    public static function abertoEm(bool $sempreAberto, array $turnos, DateTimeImmutable $agora): bool
    {
        if ($sempreAberto) {
            return true;
        }
        $hoje = (int) $agora->format('w');
        $ontem = ($hoje + 6) % 7;
        $hora = $agora->format('H:i');
        foreach ($turnos as $t) {
            $abre = substr($t['abre'], 0, 5);
            $fecha = substr($t['fecha'], 0, 5);
            $viraDia = $fecha < $abre;
            if ((int) $t['dia_semana'] === $hoje && $hora >= $abre && ($viraDia || $hora < $fecha)) {
                return true;
            }
            if ((int) $t['dia_semana'] === $ontem && $viraDia && $hora < $fecha) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resumo legivel: "Seg: 11:00-15:00 e 18:00-23:00; Ter: fechado; ..."
     * @param list<array{dia_semana:int,turno:int,abre:string,fecha:string}> $turnos
     */
    public static function resumo(bool $sempreAberto, array $turnos): string
    {
        if ($sempreAberto) {
            return 'Sempre aberto';
        }
        if ($turnos === []) {
            return 'Horário não informado';
        }
        $partes = [];
        foreach (self::porDia($turnos) as $d => $doDia) {
            $partes[] = self::DIAS_CURTOS[$d] . ': ' . ($doDia === [] ? 'fechado' : implode(' e ', array_map(
                static fn (array $t): string => substr($t['abre'], 0, 5) . '-' . substr($t['fecha'], 0, 5),
                $doDia,
            )));
        }
        return implode('; ', $partes);
    }

    /**
     * @param list<array{dia_semana:int,turno:int,abre:string,fecha:string}> $turnos
     * @return array<int, list<array{dia_semana:int,turno:int,abre:string,fecha:string}>> 0..6 => turnos ordenados
     */
    public static function porDia(array $turnos): array
    {
        $dias = array_fill(0, 7, []);
        foreach ($turnos as $t) {
            $dias[(int) $t['dia_semana']][(int) $t['turno']] = $t;
        }
        foreach ($dias as $d => $lista) {
            ksort($lista);
            $dias[$d] = array_values($lista);
        }
        return $dias;
    }

    /** "9:5" nao; "09:05" ou "09:05:00" sim. Devolve "HH:MM" ou null. */
    public static function hora(mixed $valor): ?string
    {
        if (!is_string($valor) || preg_match('/\A([01]\d|2[0-3]):([0-5]\d)(?::00)?\z/', trim($valor), $m) !== 1) {
            return null;
        }
        return $m[1] . ':' . $m[2];
    }
}
