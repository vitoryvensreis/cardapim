<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Plataforma\FiscalNextService;
use Throwable;

final class FiscalController extends BasePlataforma
{
    public function inicio(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        try {
            $fiscal = $this->servico()->estado();
        } catch (Throwable $e) {
            error_log('[Cardapim] Fiscal NeXT: falha ao ler configuração (' . get_class($e) . ').');
            $fiscal = null;
        }
        return $this->tela('plataforma/fiscal/inicio', 'fiscal', 'Central fiscal', [
            'fiscal' => $fiscal, 'restaurantesFiscais' => $this->servico()->restaurantes(), 'pageStyles' => ['css/plataforma-fiscal.css'],
        ]);
    }

    public function aprovarRestaurante(): Response
    {
        if ($r = $this->entrar(true)) { return $r; }
        $id = filter_var($this->post('id_restaurante'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false || $this->post('confirmacao') !== '1' || !\Cardapim\Shared\Esquema::tem($this->db, 'fiscal_restaurante')) {
            return $this->voltar('/painel/fiscal', 'erro', 'Confirme o vínculo e o CNPJ do restaurante.');
        }
        try {
            $s = new \Cardapim\Services\Gerencia\FiscalService($this->db, $this->usuario, $id);
            return $this->responder($s->aprovar($this->post('cnpj')), '/painel/fiscal');
        } catch (Throwable $e) {
            error_log('[Cardapim] Aprovação fiscal indisponível (' . get_class($e) . ').');
            return $this->voltar('/painel/fiscal', 'erro', 'Não foi possível aprovar o vínculo.');
        }
    }

    public function salvar(): Response
    {
        return $this->acao(fn (FiscalNextService $s): Resultado => $s->salvar(
            $this->post('cnpj'), $this->post('razao'), $this->post('token'), $this->post('ambiente'), $this->post('limite')
        ));
    }

    public function testar(): Response
    {
        return $this->acao(fn (FiscalNextService $s): Resultado => $s->testar());
    }

    public function ativar(): Response
    {
        return $this->acao(fn (FiscalNextService $s): Resultado => $s->ativar(true));
    }

    public function desativar(): Response
    {
        return $this->acao(fn (FiscalNextService $s): Resultado => $s->ativar(false));
    }

    private function acao(callable $executar): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        try {
            return $this->responder($executar($this->servico()), '/painel/fiscal');
        } catch (Throwable $e) {
            error_log('[Cardapim] Fiscal NeXT: falha administrativa (' . get_class($e) . ').');
            return $this->voltar('/painel/fiscal', 'erro', 'Não foi possível concluir a operação. Recarregue a página e confira a configuração.');
        }
    }

    private function servico(): FiscalNextService
    {
        return new FiscalNextService($this->db, $this->usuario);
    }
}
