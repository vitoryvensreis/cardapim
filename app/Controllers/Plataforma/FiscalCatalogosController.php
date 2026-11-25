<?php
declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Modules\Fiscal\CatalogosFiscais;
use Cardapim\Modules\Fiscal\ImportacaoFiscal;
use RuntimeException;
use Throwable;

final class FiscalCatalogosController extends BasePlataforma
{
    public function inicio(): Response
    {
        if ($r=$this->entrar()) { return $r; }
        return $this->tela('plataforma/fiscal/catalogos','fiscal','Catálogos fiscais',[
            'catalogos'=>(new CatalogosFiscais($this->db))->resumo(),'pageStyles'=>['css/plataforma-fiscal.css','css/fiscal-produtos.css'],
        ]);
    }

    public function importar(): Response
    {
        if ($r=$this->entrar(true)) { return $r; }
        $url='/painel/fiscal/catalogos';
        try {
            $f=$_FILES['arquivo'] ?? [];
            if ($this->post('confirmacao')!=='1' || !is_array($f) || ($f['error'] ?? null)!==UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null) || !is_uploaded_file($f['tmp_name']) || ($f['size'] ?? 0)>ImportacaoFiscal::BYTES) {
                throw new RuntimeException('Confirme a fonte oficial e envie um CSV de até 2 MB.');
            }
            $csv=file_get_contents($f['tmp_name'],false,null,0,ImportacaoFiscal::BYTES+1);
            if ($csv===false) { throw new RuntimeException('Não foi possível ler o arquivo.'); }
            $n=(new CatalogosFiscais($this->db))->importar($this->usuario,$this->post('tipo'),$this->post('versao'),$this->post('vigencia'),$csv);
            return $this->voltar($url,'sucesso',$n.' registros importados. A versão será consultada a partir da vigência informada.');
        } catch (Throwable $e) {
            error_log('[Cardapim] Catálogo fiscal: '.get_class($e));
            return $this->voltar($url,'erro',$e instanceof RuntimeException && !$e instanceof \PDOException?$e->getMessage():'Não foi possível importar. Confira a versão e o formato.');
        }
    }
}
