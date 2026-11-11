<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Repositories\PedidoRepository;
use Cardapim\Shared\Numero;
use PDO;

/**
 * "Meus pedidos" do cliente final: historico paginado (neste restaurante ou em todos) e pedidos em andamento,
 * para o cliente voltar ao acompanhamento pelo link do restaurante. So dados que o proprio cliente ja viu.
 */
final class HistoricoCliente
{
    public const POR_PAGINA = 10;

    private readonly PedidoRepository $repo;

    public function __construct(PDO $db)
    {
        $this->repo = new PedidoRepository($db);
    }

    /** @return array{pedidos:list<array<string,mixed>>,proximo:?int} */
    public function pagina(int $idCliente, ?int $idRestaurante, ?int $antes): array
    {
        $linhas = $this->repo->historicoDoCliente($idCliente, $idRestaurante, $antes, self::POR_PAGINA + 1);
        $temMais = count($linhas) > self::POR_PAGINA;
        $linhas = array_slice($linhas, 0, self::POR_PAGINA);
        $pedidos = $this->formatar($linhas);
        return ['pedidos' => $pedidos, 'proximo' => $temMais && $linhas !== [] ? (int) end($linhas)['id'] : null];
    }

    /** @return list<array<string,mixed>> */
    public function emAndamento(int $idCliente, int $idRestaurante): array
    {
        return $this->formatar($this->repo->emAndamentoDoCliente($idCliente, $idRestaurante));
    }

    /** @param list<array<string,mixed>> $linhas @return list<array<string,mixed>> */
    private function formatar(array $linhas): array
    {
        $itens = $this->repo->itensResumo(array_map(static fn (array $l): int => (int) $l['id'], $linhas));
        $hoje = date('Y-m-d');
        return array_map(static function (array $l) use ($itens, $hoje): array {
            $tipo = (string) $l['tipo'];
            $status = (string) $l['status'];
            $lista = $itens[(int) $l['id']] ?? [];
            return [
                'id' => (int) $l['id'],
                'numero' => (int) $l['numero'],
                'status' => $status,
                'status_rotulo' => StatusPedido::rotulo($status, $tipo),
                'tipo' => $tipo,
                'final' => in_array($status, ['concluido', 'cancelado'], true),
                'quando' => self::quando((string) $l['criado_em'], $hoje),
                'total_centavos' => Numero::centavos((string) $l['total']),
                'resumo' => self::resumo($lista),
                'qtd_itens' => array_sum(array_column($lista, 'quantidade')),
                'restaurante' => ['nome' => (string) $l['restaurante_nome'], 'slug' => (string) $l['restaurante_slug']],
                'url' => '/' . $l['restaurante_slug'] . '/pedido/' . (int) $l['id'],
            ];
        }, $linhas);
    }

    /** "Hoje, 19:40", "Ontem, 12:05", "08/11, 21:10" ou "08/11/2025, 21:10". */
    public static function quando(string $dataHora, string $hoje): string
    {
        $dia = substr($dataHora, 0, 10);
        $hora = substr($dataHora, 11, 5);
        if ($dia === $hoje) {
            return 'Hoje, ' . $hora;
        }
        if ($dia === date('Y-m-d', strtotime($hoje . ' -1 day'))) {
            return 'Ontem, ' . $hora;
        }
        $data = substr($dia, 8, 2) . '/' . substr($dia, 5, 2);
        return (substr($dia, 0, 4) === substr($hoje, 0, 4) ? $data : $data . '/' . substr($dia, 0, 4)) . ', ' . $hora;
    }

    /** "2x Cerveja lata, 1x X-Burger e mais 2". @param list<array{nome:string,quantidade:int}> $itens */
    public static function resumo(array $itens): string
    {
        $partes = array_map(static fn (array $i): string => $i['quantidade'] . 'x ' . $i['nome'], array_slice($itens, 0, 2));
        $resto = count($itens) - 2;
        return implode(', ', $partes) . ($resto > 0 ? ' e mais ' . $resto : '');
    }
}
