<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use DateTimeImmutable;

final class FiltrosRelatorios
{
    public const VISOES = ['produto'=>'Por produto','hora'=>'Por horário','dia'=>'Por dia','pedido'=>'Pedidos e comandas'];
    public const SITUACOES = ['todos'=>'Todas','vendido'=>'Finalizados','aberto'=>'Em andamento','cancelado'=>'Cancelados'];
    public const DATAS = ['lancamento'=>'Lançamento do item','fechamento'=>'Fechamento do pedido'];
    public const POR_PAGINA = 50;
    public const MAX_EXPORTAR = 10000;

    public function __construct(
        public readonly PeriodoFinanceiro $periodo,
        public readonly array $f,
        public readonly ?string $aviso,
        public readonly int $pagina,
    ) {}

    public static function daRequisicao(array $get, ?DateTimeImmutable $hoje = null): self
    {
        $texto = static fn(string $k, int $limite): string => is_string($get[$k] ?? null)
            ? mb_substr(trim($get[$k]), 0, $limite) : '';
        $opcao = static fn(string $v, array $lista, string $padrao): string => isset($lista[$v]) ? $v : $padrao;
        $id = static fn(string $v): string => preg_match('/\A[1-9][0-9]{0,17}\z/', $v) === 1 ? $v : '';
        $hora = static fn(string $v): string => preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $v) === 1 ? $v : '';
        $p = PeriodoFinanceiro::daRequisicao($get + ['periodo'=>'hoje'], $hoje);
        $aviso = $p->aviso;
        if ((new DateTimeImmutable($p->de))->diff(new DateTimeImmutable($p->ate))->days > 92) {
            $p = PeriodoFinanceiro::daRequisicao(['periodo'=>'personalizado',
                'de'=>(new DateTimeImmutable($p->ate))->modify('-92 days')->format('Y-m-d'), 'ate'=>$p->ate], $hoje);
            $aviso = 'Para manter a consulta rápida, o período foi limitado aos últimos 93 dias do intervalo.';
        }
        $f = [
            'visao'=>$opcao($texto('visao',20),self::VISOES,'produto'),
            'situacao'=>$opcao($texto('situacao',20),self::SITUACOES,'todos'),
            'data_por'=>$opcao($texto('data_por',20),self::DATAS,'lancamento'),
            'busca'=>$texto('busca',120),
            'produto'=>$id($texto('produto',18)),
            'categoria'=>$id($texto('categoria',18)),
            'pedido'=>$id($texto('pedido',18)),
            'comanda'=>$texto('comanda',60),
            'mesa'=>$id($texto('mesa',18)),
            'origem'=>$texto('origem',30),
            'funcionario'=>$id($texto('funcionario',18)),
            'hora_de'=>$hora($texto('hora_de',5)),
            'hora_ate'=>$hora($texto('hora_ate',5)),
        ];
        if ($f['data_por']==='fechamento' && $f['situacao']!=='vendido') {
            $f['situacao']='vendido';
            $aviso=trim(($aviso ?? '') . ' Por fechamento, são exibidos somente itens vendidos em pedidos finalizados.');
        }
        return new self($p,$f,$aviso,max(1,min(2000,(int)$id($texto('pagina',18)))));
    }

    public function query(array $alteracoes=[]): string
    {
        return http_build_query(array_replace($this->periodo->parametros(),$this->f,['pagina'=>$this->pagina],$alteracoes));
    }

    public static function csvSeguro(string $texto): string
    {
        return preg_match('/\A[\s\p{Z}\x{FEFF}]*[=+\-@]|\A[\t\r\n]/u',$texto) === 1 ? "'".$texto : $texto;
    }
}
