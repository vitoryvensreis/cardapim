<?php

declare(strict_types=1);

namespace Cardapim\Services\Garcom;

use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Pedidos\TaxaPagamento;
use Cardapim\Shared\PixCopiaECola;

/**
 * Pix pela chave do restaurante na mesa: o codigo copia e cola (BR Code estatico) do que falta pagar + a taxa do
 * Pix pela chave, se houver. Nao existe cobranca externa nem confirmacao automatica: o garcom confere no app do
 * banco e toca em "Recebi o Pix" (entra como Pix na conta, pelas mesmas regras do caixa).
 */
final class ComandaPixChave
{
    public function __construct(private readonly \PDO $db, private readonly int $idRestaurante)
    {
    }

    /** O restaurante ligou o Pix pela chave e preencheu os dados. */
    public function disponivel(): bool
    {
        return (new EntregaPagamentoRepository($this->db))->pixChave($this->idRestaurante)['ligado'];
    }

    /**
     * Codigo do que falta pagar agora. @param array<string,mixed> $c ComandaService::detalhes
     * @return Resultado|array{conta:int,taxa:int,rotulo:?string,total:int,copia_cola:string}
     */
    public function codigo(array $c): Resultado|array
    {
        if (!$c['aberta']) {
            return Resultado::falha('Esta conta já foi fechada.');
        }
        $restante = (int) $c['restante'];
        if ($restante <= 0) {
            return Resultado::falha('Não falta nada para pagar.');
        }
        $dados = (new EntregaPagamentoRepository($this->db))->pixChave($this->idRestaurante);
        if (!$dados['ligado']) {
            return Resultado::falha('O Pix pela chave está desligado. Receba como Pix e confira no app do banco.');
        }
        $taxa = TaxaPagamento::daConta(ComandaService::regrasDasContas($this->db, $this->idRestaurante), 'pix_chave', $restante);
        $total = $restante + $taxa['centavos'];
        return [
            'conta' => $restante,
            'taxa' => $taxa['centavos'],
            'rotulo' => $taxa['rotulo'],
            'total' => $total,
            'copia_cola' => PixCopiaECola::gerar($dados['chave'], $dados['recebedor'], $dados['cidade'], $total, 'CARDAPIMC' . (int) $c['pedido']['numero']),
        ];
    }
}
