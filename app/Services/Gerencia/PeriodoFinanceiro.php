<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use DateTimeImmutable;

/**
 * Periodo escolhido nas telas do Financeiro (?periodo=hoje|ontem|7dias|mes|mes_passado|personalizado&de=&ate=).
 * Atalhos cobrem o dia a dia; "personalizado" aceita ate 366 dias. Entrada invalida cai em "Este mes".
 */
final class PeriodoFinanceiro
{
    public const ATALHOS = [
        'hoje' => 'Hoje',
        'ontem' => 'Ontem',
        '7dias' => 'Últimos 7 dias',
        'mes' => 'Este mês',
        'mes_passado' => 'Mês passado',
    ];
    private const MAX_DIAS = 366;

    private function __construct(
        public readonly string $chave,
        public readonly string $de,   // Y-m-d (inclusivo)
        public readonly string $ate,  // Y-m-d (inclusivo)
        public readonly ?string $aviso = null,
    ) {
    }

    /** @param array<string,mixed> $get */
    public static function daRequisicao(array $get, ?DateTimeImmutable $hoje = null): self
    {
        $hoje ??= new DateTimeImmutable('today');
        $chave = is_string($get['periodo'] ?? null) ? $get['periodo'] : 'mes';
        $d = static fn (DateTimeImmutable $x): string => $x->format('Y-m-d');
        return match ($chave) {
            'hoje' => new self('hoje', $d($hoje), $d($hoje)),
            'ontem' => new self('ontem', $d($hoje->modify('-1 day')), $d($hoje->modify('-1 day'))),
            '7dias' => new self('7dias', $d($hoje->modify('-6 days')), $d($hoje)),
            'mes_passado' => new self('mes_passado', $d($hoje->modify('first day of last month')), $d($hoje->modify('last day of last month'))),
            'personalizado' => self::personalizado($get, $hoje),
            default => new self('mes', $d($hoje->modify('first day of this month')), $d($hoje)),
        };
    }

    /** @param array<string,mixed> $get */
    private static function personalizado(array $get, DateTimeImmutable $hoje): self
    {
        $de = self::data($get['de'] ?? null);
        $ate = self::data($get['ate'] ?? null);
        if ($de === null || $ate === null) {
            $padrao = self::daRequisicao(['periodo' => 'mes'], $hoje);
            return new self('mes', $padrao->de, $padrao->ate, 'Escolha as duas datas para ver um período personalizado. Mostrando este mês.');
        }
        if ($de > $ate) {
            [$de, $ate] = [$ate, $de];
        }
        $aviso = null;
        if ($de->diff($ate)->days >= self::MAX_DIAS) {
            $de = $ate->modify('-' . (self::MAX_DIAS - 1) . ' days');
            $aviso = 'O período máximo é de 1 ano. Mostrando os 366 dias até ' . $ate->format('d/m/Y') . '.';
        }
        return new self('personalizado', $de->format('Y-m-d'), $ate->format('Y-m-d'), $aviso);
    }

    private static function data(mixed $valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $valor) !== 1) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        return $d !== false && $d->format('Y-m-d') === $valor && (int) $d->format('Y') >= 2000 && (int) $d->format('Y') <= 2100 ? $d : null;
    }

    /** Inicio do periodo para comparar com DATETIME. */
    public function inicio(): string
    {
        return $this->de . ' 00:00:00';
    }

    /** Fim exclusivo (00:00 do dia seguinte ao ultimo). */
    public function fimExclusivo(): string
    {
        return (new DateTimeImmutable($this->ate))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    public function umDia(): bool
    {
        return $this->de === $this->ate;
    }

    /** "Hoje", "Este mês" ou "01/11/2026 a 15/11/2026". */
    public function rotulo(): string
    {
        $data = static fn (string $d): string => date('d/m/Y', (int) strtotime($d));
        $intervalo = $this->umDia() ? $data($this->de) : $data($this->de) . ' a ' . $data($this->ate);
        return isset(self::ATALHOS[$this->chave]) ? self::ATALHOS[$this->chave] . ' (' . $intervalo . ')' : $intervalo;
    }

    /** Parametros para manter o periodo nos links. @return array<string,string> */
    public function parametros(): array
    {
        return $this->chave === 'personalizado' ? ['periodo' => 'personalizado', 'de' => $this->de, 'ate' => $this->ate] : ['periodo' => $this->chave];
    }

    public function query(): string
    {
        return http_build_query($this->parametros());
    }
}
