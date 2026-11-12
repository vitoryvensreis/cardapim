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
            $eventos = new EventosIfood($this->db, $conta);
            $resumo['avisos'] = $eventos->receber($token);
            $resumo['tratados'] = $eventos->tratarPendentes($token);
            $resumo['acoes'] = (new IfoodAcoes())->enviar($this->db, $conta);
            if ($conta->opcao('sincronizar_estoque')) {
                $sinc = (new IfoodCatalogo($this->db, $idRestaurante))->sincronizar($conta);
                $resumo['itens'] = $sinc['enviados'];
            }
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
