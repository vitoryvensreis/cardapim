<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Modules\Integracoes\IntegracaoErro;
use PDO;
use Throwable;

/**
 * Uma rodada da integracao para um restaurante (chamada pela rotina bin/ifood.php a cada 30 segundos):
 * recebe os avisos do iFood, trata, envia as acoes pendentes e a disponibilidade dos itens.
 */
final class RotinaIfood
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{restaurante:int, avisos:int, tratados:int, acoes:int, itens:int, erro:?string} */
    public function rodar(int $idRestaurante): array
    {
        $conta = new IfoodConta($this->db, $idRestaurante);
        $resumo = ['restaurante' => $idRestaurante, 'avisos' => 0, 'tratados' => 0, 'acoes' => 0, 'itens' => 0, 'erro' => null];
        if (!$conta->conectada()) {
            return $resumo;
        }
        try {
            $token = $conta->token();
            // A rotina já roda a cada 30s. O painel consulta SOMENTE o snapshot no MySQL.
            // Uma falha do Merchant não interrompe a recepção/ack dos pedidos.
            try {
                $operacoes = $conta->api()->situacaoLoja($token, (string) $conta->loja());
                // O cron e a interface usam exclusivamente o horario de Brasilia
                // para normalizar interrupcoes sem offset (nunca altera datas de pedidos).
                $pausas = $conta->api()->pausas($token, (string) $conta->loja(), FusoLojaIfood::PADRAO);
                EstadoLoja::reconciliar($this->db, $idRestaurante, $operacoes, $pausas);
            } catch (IntegracaoErro $erroMerchant) {
                error_log('[Cardapim] Estado iFood nao atualizado, mantendo ultimo estado conhecido: ' . $idRestaurante . ' ' . get_class($erroMerchant));
            } catch (Throwable $erroMerchant) {
                error_log('[Cardapim] Falha ao reconciliar disponibilidade iFood: ' . $idRestaurante . ' ' . get_class($erroMerchant));
            }
            $eventos = new EventosIfood($this->db, $conta);
            $resumo['avisos'] = $eventos->receber($token);
            $resumo['tratados'] = $eventos->tratarPendentes($token);
            $resumo['acoes'] = (new IfoodAcoes())->enviar($this->db, $conta);
            // Cardapio publicado por aqui: nome, foto e grupos pelo cadastro; preco e pausa de complemento por PATCH.
            if (IfoodPublicacao::disponivel($this->db)) {
                $pub = (new IfoodPublicacao($this->db, $idRestaurante))->sincronizar($conta);
                $resumo['itens'] += $pub['enviados'];
            }
            // Exclusoes devem pausar itens vinculados mesmo quando o gerente
            // desligou a sincronizacao automatica de estoque.
            $sinc = (new IfoodCatalogo($this->db, $idRestaurante))->sincronizar(
                $conta, false, !$conta->opcao('sincronizar_estoque'));
            $resumo['itens'] += $sinc['enviados'];
            $conta->registrarConsulta();
        } catch (IntegracaoErro $erro) {
            $resumo['erro'] = $erro->getMessage();
            $conta->registrarFalha($erro->getMessage());
        } catch (Throwable $erro) {
            $resumo['erro'] = get_class($erro);
            error_log('[Cardapim] Rotina do iFood falhou no restaurante ' . $idRestaurante . ': ' . get_class($erro) . ': ' . $erro->getMessage());
        }
        return $resumo;
    }
}
