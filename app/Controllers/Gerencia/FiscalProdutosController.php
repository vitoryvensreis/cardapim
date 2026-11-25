<?php
declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Modules\Fiscal\CadastroProdutoFiscal as Validar;
use Cardapim\Modules\Fiscal\CatalogoNcm;
use Cardapim\Services\Gerencia\FiscalProdutosService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FiscalProdutosController extends BaseGerencia
{
    private function acesso(string $slug,bool $post=false): ?Response
    {
        if ($r=$this->entrar($slug,$post)) { return $r; }
        if (!\Cardapim\Services\Gerencia\FiscalAcesso::permitido($this->db,$this->restaurante['id'])) {
            return \Cardapim\Services\Auth\Guard::proibido($this->usuario,'Módulo indisponível para este restaurante.');
        }
        if (!FiscalProdutosService::disponivel($this->db,$this->restaurante['id'])) {
            return $this->tela('gerencia/fiscal/produtos/indisponivel','fiscal','Cadastro fiscal',[],403);
        }
        return null;
    }

    private function servico(): FiscalProdutosService
    { return new FiscalProdutosService($this->db,$this->restaurante['id'],$this->usuario); }

    private function pagina(string $view,string $titulo,array $dados=[]): Response
    {
        return $this->tela('gerencia/fiscal/produtos/'.$view,'fiscal',$titulo,$dados+[
            'pageStyles'=>['css/gerencia-fiscal.css','css/fiscal-produtos.css'],
            'pageScripts'=>['js/fiscal-produtos.js'],
        ]);
    }

    private function falha(Throwable $e,string $url): Response
    {
        // Não propagar SQL, stack traces ou dados privados ao navegador.
        $msg=$e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !$e instanceof \PDOException) ? $e->getMessage() : 'Não foi possível concluir a configuração fiscal. Confira os dados.';
        if ($e instanceof \PDOException) { $msg='Não foi possível salvar. Confira se o nome do perfil já existe.'; }
        error_log('[Cardapim] Cadastro fiscal de produtos: '.get_class($e));
        return $this->voltar($url,'erro',$msg);
    }

    public function inicio(string $slug): Response
    {
        if ($r=$this->acesso($slug)) { return $r; }
        try { return $this->pagina('inicio','Produtos fiscais',$this->servico()->estado()); }
        catch (Throwable $e) { return $this->falha($e,$this->url('fiscal')); }
    }

    public function perfis(string $slug): Response
    {
        if ($r=$this->acesso($slug)) { return $r; }
        try { return $this->pagina('perfis','Perfis tributários',$this->servico()->estado()); }
        catch (Throwable $e) { return $this->falha($e,$this->url('fiscal')); }
    }

    public function editarPerfil(string $slug,string $idTexto='novo'): Response
    {
        if ($r=$this->acesso($slug)) { return $r; }
        try {
            $e=$this->servico()->estado();
            $id=$idTexto==='novo'?null:self::id($idTexto);
            if ($idTexto!=='novo' && ($id===null || !isset($e['perfis'][$id]))) { return $this->naoEncontrado('Perfil',$this->url('fiscal/perfis')); }
            $perfil=$id===null?null:$e['perfis'][$id];
            $dados=$perfil['dados'] ?? [];
            if ($id===null) {
                $modelo=is_string($_GET['modelo'] ?? null)?$_GET['modelo']:'';
                $copiar=self::id(is_string($_GET['copiar'] ?? null)?$_GET['copiar']:'');
                if (in_array($modelo,Validar::MODELOS,true)) { $dados['nome']=$modelo; }
                if ($copiar!==null && isset($e['perfis'][$copiar])) {
                    $dados=$e['perfis'][$copiar]['dados']; $dados['nome']=mb_substr($dados['nome'],0,112).' (cópia)';
                }
            }
            return $this->pagina('perfil-form','Perfil tributário',['perfil'=>$perfil,'dados'=>$dados,'empresa'=>$e['empresa']]);
        } catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/perfis')); }
    }

    public function salvarPerfil(string $slug): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        try { $id=$this->servico()->perfil($_POST); return $this->voltar($this->url('fiscal/perfis/'.$id),'sucesso','Nova versão do perfil salva. Confira os produtos vinculados.'); }
        catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/perfis/'.(self::id($this->post('id_perfil')) ?? 'novo'))); }
    }

    public function editarProduto(string $slug,string $idTexto): Response
    {
        if ($r=$this->acesso($slug)) { return $r; }
        try {
            $e=$this->servico()->estado(); $id=self::id($idTexto);
            if ($id===null || !isset($e['produtos'][$id])) { return $this->naoEncontrado('Produto',$this->url('fiscal/produtos')); }
            $produto=$e['produtos'][$id];
            $copiar=self::id(is_string($_GET['copiar'] ?? null)?$_GET['copiar']:'');
            $copiaNome=null;
            if ($copiar!==null && $copiar!==$id && isset($e['produtos'][$copiar])) {
                $origem=$e['produtos'][$copiar];
                $produto['dados']=array_intersect_key($origem['dados'],array_flip(['ncm','cest','origem','unidade_comercial','unidade_tributavel','fator']));
                // GTIN é específico da mercadoria: não copiar. Confirmação individual obrigatória.
                $produto['dados']['gtin']=$produto['dados']['gtin_tributavel']='';
                $produto['id_perfil']=$origem['id_perfil'];
                $copiaNome=$origem['nome'];
            }
            return $this->pagina('produto-form','Dados fiscais do produto',$e+['produto'=>$produto,'copiaNome'=>$copiaNome]);
        } catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/produtos')); }
    }

    public function salvarProduto(string $slug,string $idTexto): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        $id=self::id($idTexto);
        if ($id===null) { return $this->naoEncontrado('Produto',$this->url('fiscal/produtos')); }
        $url=$this->url('fiscal/produtos/'.$id);
        try { $this->servico()->produto($id,$_POST); return $this->voltar($url,'sucesso','Dados fiscais salvos. O cadastro comercial não foi alterado.'); }
        catch (Throwable $e) { return $this->falha($e,$url); }
    }

    public function previa(string $slug): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        try {
            $perfil=self::id($this->post('id_perfil'));
            if ($perfil===null && $this->post('id_perfil')!=='') { throw new InvalidArgumentException('Perfil inválido.'); }
            $p=$this->servico()->previa(is_array($_POST['produtos'] ?? null)?$_POST['produtos']:[],$perfil,$_POST);
            $token=bin2hex(random_bytes(24));
            Session::set('fiscal_lote_'.$this->restaurante['id'],['token'=>$token,'autor'=>$this->usuario->id,'expira'=>time()+300,'previa'=>$p]);
            return $this->pagina('previa','Revisar aplicação em massa',['previa'=>$p,'lote'=>$token]);
        } catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/produtos')); }
    }

    public function aplicar(string $slug): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        $url=$this->url('fiscal/produtos');
        try {
            $p=Session::flash('fiscal_lote_'.$this->restaurante['id']);
            if (!is_array($p) || ($p['autor'] ?? null)!==$this->usuario->id || ($p['expira'] ?? 0)<time() || !hash_equals((string)$p['token'],$this->post('lote'))) { throw new InvalidArgumentException('Prévia expirada. Selecione os produtos novamente.'); }
            $n=$this->servico()->aplicar($p['previa']);
            return $this->voltar($url,'sucesso',$n.' produtos processados. Confira as classificações individuais e pendências.');
        } catch (Throwable $e) { return $this->falha($e,$url); }
    }

    public function validacao(string $slug): Response
    {
        if ($r=$this->acesso($slug)) { return $r; }
        try { return $this->pagina('validacao','Conferência fiscal',$this->servico()->conferencia()); }
        catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/produtos')); }
    }

    public function composicao(string $slug,string $idTexto): Response
    {
        if ($r=$this->acesso($slug)) { return $r; }
        try {
            $id=self::id($idTexto);
            if ($id===null) { throw new InvalidArgumentException('Produto inválido.'); }
            return $this->pagina('composicao','Composição fiscal',$this->servico()->composicao($id));
        } catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/produtos/validacao')); }
    }

    public function salvarComposicao(string $slug,string $idTexto): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        try {
            $id=self::id($idTexto);
            if ($id===null) { throw new InvalidArgumentException('Produto inválido.'); }
            $this->servico()->salvarComposicao($id,$_POST);
            return $this->voltar($this->url('fiscal/produtos/validacao'),'sucesso','Composição fiscal registrada. Venda e estoque preservados.');
        } catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/produtos/validacao')); }
    }

    public function catalogos(string $slug): Response
    {
        if ($r=$this->acesso($slug)) { return $r; }
        try {
            $t=\Cardapim\Modules\Fiscal\CatalogosFiscais::TIPOS;
            $tipo=is_string($_GET['tipo'] ?? null)&&isset($t[$_GET['tipo']])?$_GET['tipo']:'cest';
            $q=is_string($_GET['q'] ?? null)?mb_substr(trim($_GET['q']),0,100):'';
            $ncm=is_string($_GET['ncm'] ?? null)&&preg_match('/\A[0-9]{8}\z/',$_GET['ncm'])?$_GET['ncm']:'';
            $cst=is_string($_GET['cst'] ?? null)&&preg_match('/\A[0-9]{3}\z/',$_GET['cst'])?$_GET['cst']:'';
            $d=(new \Cardapim\Modules\Fiscal\CatalogosFiscais($this->db))->vigente($tipo);
            $resultados=$d?\Cardapim\Modules\Fiscal\CatalogosFiscais::pesquisar($d,$q,$ncm,$cst):[];
            return $this->pagina('catalogos','Pesquisar tabelas fiscais',['tipo'=>$tipo,'q'=>$q,'ncm'=>$ncm,'cst'=>$cst,'catalogo'=>$d,'resultados'=>$resultados]);
        } catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/produtos')); }
    }

    public function importar(string $slug): Response
    {
        if ($r=$this->acesso($slug)) { return $r; }
        return $this->pagina('importar','Importar classificação fiscal');
    }

    private function chaveImportacao(): string { return 'fiscal_importacao_'.$this->restaurante['id']; }

    private function sessaoImportacao(string $fase): array
    {
        $s=Session::flash($this->chaveImportacao());
        if (!is_array($s) || ($s['autor'] ?? null)!==$this->usuario->id || ($s['expira'] ?? 0)<time() ||
            ($s['fase'] ?? '')!==$fase || !hash_equals((string)($s['token'] ?? ''),$this->post('importacao'))) {
            throw new InvalidArgumentException('Importação expirada. Comece novamente.');
        }
        return $s;
    }

    public function lerImportacao(string $slug): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        try {
            $f=$_FILES['arquivo'] ?? [];
            if (!is_array($f) || ($f['error'] ?? null)!==UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null) || !is_uploaded_file($f['tmp_name']) || ($f['size'] ?? 0)>\Cardapim\Modules\Fiscal\ImportacaoFiscal::BYTES) {
                throw new InvalidArgumentException('Envie um arquivo de até 2 MB.');
            }
            $bytes=file_get_contents($f['tmp_name'],false,null,0,\Cardapim\Modules\Fiscal\ImportacaoFiscal::BYTES+1);
            if ($bytes===false) { throw new InvalidArgumentException('Não foi possível ler o arquivo.'); }
            $itens=$this->servico()->lerImportacao($this->post('tipo'),$bytes);
            $e=$this->servico()->estado();
            $token=bin2hex(random_bytes(24));
            Session::set($this->chaveImportacao(),['autor'=>$this->usuario->id,'expira'=>time()+900,'token'=>$token,'fase'=>'vincular','itens'=>$itens]);
            return $this->pagina('importar-vincular','Relacionar mercadorias',['itens'=>$itens,'produtos'=>$e['produtos'],'importacao'=>$token]);
        } catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/produtos/importar')); }
    }

    public function previaImportacao(string $slug): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        try {
            $s=$this->sessaoImportacao('vincular');
            $destinos=is_array($_POST['destinos'] ?? null)?$_POST['destinos']:[];
            $substituir=$this->post('sobrescrever')==='1';
            $previa=$this->servico()->previaImportacao($s['itens'],$destinos,$substituir);
            $token=bin2hex(random_bytes(24));
            Session::set($this->chaveImportacao(),array_replace($s,['token'=>$token,'fase'=>'aplicar','destinos'=>$destinos,'substituir'=>$substituir,'previa'=>$previa,'expira'=>time()+600]));
            return $this->pagina('importar-previa','Confirmar importação',['previa'=>$previa,'importacao'=>$token]);
        } catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/produtos/importar')); }
    }

    public function aplicarImportacao(string $slug): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        try {
            $s=$this->sessaoImportacao('aplicar');
            $n=$this->servico()->aplicarImportacao($s['itens'],$s['destinos'],$s['substituir'],$s['previa']);
            return $this->voltar($this->url('fiscal/produtos'),'sucesso',$n.' produtos atualizados. Revise as classificações importadas.');
        } catch (Throwable $e) { return $this->falha($e,$this->url('fiscal/produtos/importar')); }
    }

    public function categoria(string $slug): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        $url=$this->url('fiscal/perfis');
        try {
            $id=self::id($this->post('id_categoria'));
            $perfil=self::id($this->post('id_perfil'));
            if ($id===null || ($perfil===null && $this->post('id_perfil')!=='')) { throw new InvalidArgumentException('Confira a categoria e o perfil.'); }
            $this->servico()->categoria($id,$perfil);
            return $this->voltar($url,'sucesso','Sugestão da categoria salva. Nenhum produto foi reclassificado.');
        } catch (Throwable $e) { return $this->falha($e,$url); }
    }

    public function gtin(string $slug): Response
    {
        if ($r=$this->acesso($slug,true)) { return $r; }
        $headers=['Cache-Control'=>'no-store'];
        try {
            $s=new \Cardapim\Services\Gerencia\FiscalService($this->db,$this->usuario,$this->restaurante['id']);
            return Response::json(['ok'=>true,'dados'=>$s->consultarGtin($this->post('gtin'))],200,$headers);
        } catch (Throwable $e) {
            error_log('[Cardapim] Consulta GTIN indisponível ('.get_class($e).').');
            $msg='Consulta indisponível. Confira o código de barras, certificado A1 e ambiente de homologação.';
            return Response::json(['ok'=>false,'mensagem'=>$msg],422,$headers);
        }
    }

    public function ncm(string $slug): Response
    {
        if ($r=$this->acesso($slug)) { return $r; }
        $q=is_string($_GET['q'] ?? null)?trim($_GET['q']):'';
        $headers=['Cache-Control'=>'no-store'];
        if (mb_strlen($q)<2 || mb_strlen($q)>100) { return Response::json(['ok'=>false,'mensagem'=>'Digite de 2 a 100 caracteres.'],422,$headers); }
        if (!RateLimiter::padrao(30,60)->permitir('fiscal-ncm|'.$this->restaurante['id'])) { return Response::json(['ok'=>false,'mensagem'=>'Limite de consultas. Tente novamente.'],429,$headers); }
        $d=(new CatalogoNcm())->ler();
        if ($d===null && RateLimiter::padrao(3,60)->permitir('fiscal-ncm-download-global')) { $d=(new CatalogoNcm())->ler(true); }
        if ($d===null) { return Response::json(['ok'=>false,'mensagem'=>'Catálogo oficial indisponível. Salve como pendente ou tente novamente.'],503,$headers); }
        return Response::json(['ok'=>true,'fonte'=>$d['versao'],'resultados'=>CatalogoNcm::pesquisar($d,$q)],200,$headers);
    }
}
