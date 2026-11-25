<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Gerencia\FiscalAcesso;
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
        try { $liberacoes=FiscalAcesso::painel($this->db,is_string($_GET['busca_restaurante'] ?? null)?$_GET['busca_restaurante']:''); }
        catch (Throwable $e) { $liberacoes=null; }
        return $this->tela('plataforma/fiscal/inicio', 'fiscal', 'Central fiscal', [
            'fiscal' => $fiscal, 'liberacoes'=>$liberacoes, 'restaurantesFiscais' => $this->servico()->restaurantes(), 'pageStyles' => ['css/plataforma-fiscal.css'],
        ]);
    }

    public function liberarRestaurante(): Response
    {
        if ($r=$this->entrar(true)) { return $r; }
        $id=filter_var($this->post('id_restaurante'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $v=$this->post('permitido');
        try {
            if ($id===false || !in_array($v,['0','1'],true)) { throw new \RuntimeException('Confira a autorização.'); }
            FiscalAcesso::alterar($this->db,$this->usuario,$id,$v==='1');
            return $this->voltar('/painel/fiscal#liberacao-fiscal','sucesso',$v==='1'?'Fiscal liberado para o restaurante.':'Fiscal ocultado. Configurações preservadas.');
        } catch (Throwable $e) { return $this->voltar('/painel/fiscal#liberacao-fiscal','erro','Não foi possível alterar a autorização.'); }
    }

    public function visibilidade(): Response
    {
        if ($r=$this->entrar(true)) { return $r; }
        $v=$this->post('visivel');
        try {
            if (!in_array($v,['0','1'],true)) { throw new \RuntimeException('Valor inválido.'); }
            FiscalAcesso::global($this->db,$this->usuario,$v==='1');
            return $this->voltar('/painel/fiscal#liberacao-fiscal','sucesso',$v==='1'?'Visibilidade restaurada para restaurantes autorizados.':'Fiscal ocultado de todos. Autorizações preservadas.');
        } catch (Throwable $e) { return $this->voltar('/painel/fiscal#liberacao-fiscal','erro','Não foi possível alterar a visibilidade.'); }
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
