<?php
declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Fiscal\CadastroProdutoFiscal as Validar;
use Cardapim\Modules\Fiscal\CatalogoNcm;
use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Repositories\Gerencia\FiscalProdutosRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Validation\Documento;
use PDO;
use RuntimeException;
use Throwable;

final class FiscalProdutosService
{
    private readonly FiscalProdutosRepository $repo;
    public function __construct(private readonly PDO $db,private readonly int $id,private readonly UsuarioLogado $usuario)
    { $this->repo=new FiscalProdutosRepository($db,$id); }

    /** Consulta leve e fail-closed: estoque continua disponível se migration/integração fiscal falhar. */
    public static function disponivel(PDO $db,int $id): bool
    {
        if (!FiscalAcesso::permitido($db,$id) || !Esquema::tem($db,'fiscal_restaurante') || !Esquema::tem($db,'fiscal_produtos')) { return false; }
        try {
            $q=$db->prepare('SELECT f.cnpj,r.cnpj AS atual FROM restaurante_fiscal f JOIN restaurantes r ON r.id=f.id_restaurante WHERE f.id_restaurante=? AND f.habilitado=1');
            $q->execute([$id]);
            $r=$q->fetch(PDO::FETCH_ASSOC);
            return $r && $r['cnpj']===Documento::somenteDigitos((string)$r['atual']);
        } catch (Throwable) { return false; }
    }

    private function empresa(): array
    {
        if (!self::disponivel($this->db,$this->id)) { throw new RuntimeException('Ative o módulo fiscal primeiro.'); }
        return (new FiscalRepository($this->db))->buscar($this->id)['dados']['empresa'] ?? [];
    }

    public function estado(): array
    {
        $empresa=$this->empresa();
        $perfis=$this->repo->perfis(); $produtos=$this->repo->produtos(); $categorias=$this->repo->categorias();
        $sugestoes=array_column($categorias,'id_perfil','id');
        foreach ($produtos as &$p) {
            $p['pendencias']=Validar::pendencias($p,$perfis[(int)$p['id_perfil']] ?? null,$empresa);
            $p['sugestao']=$sugestoes[$p['id_categoria']] ?? null;
        }
        unset($p);
        return ['empresa'=>array_intersect_key($empresa,array_flip(['crt','estado'])),'perfis'=>$perfis,'produtos'=>$produtos,'categorias'=>$categorias];
    }

    private function escrever(callable $fn): mixed
    {
        return (new FiscalRepository($this->db))->serializar($this->id,function () use ($fn) {
            $empresa=$this->empresa();
            $this->db->beginTransaction();
            try { $r=$fn($empresa); $this->db->commit(); return $r; }
            catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
        });
    }

    public function perfil(array $p): int
    {
        $id=self::identificador($p['id_perfil'] ?? '');
        $versao=self::identificador($p['versao'] ?? '') ?? 0;
        if ($id===null && ($p['id_perfil'] ?? '')!=='') { throw new RuntimeException('Perfil inválido.'); }
        $r=$this->escrever(function ($empresa) use ($p,$id,$versao) {
            $d=Validar::perfil($p,$empresa);
            if ($id!==null && ($p['confirmar_impacto'] ?? '')!=='1') { throw new RuntimeException('Confirme a criação da nova versão e revisão dos vínculos.'); }
            return $this->repo->salvarPerfil($id,$versao,$d['dados'],$d['revisado'],$this->usuario->id);
        });
        Auditoria::registrar($this->usuario,'Fiscal: perfil salvo','Perfil '.$r.'; versão registrada.');
        return $r;
    }

    public function produto(int $id,array $p): void
    {
        $this->empresa();
        $d=Validar::produto($p);
        $catalogo=CatalogoNcm::evidencia($d['ncm']);
        // Uma consulta exata no salvamento fiscal individual, nunca sob lock/transação ou no cadastro comercial.
        if ($d['ncm']!=='' && $catalogo===null && PHP_SAPI!=='cli'
            && \Cardapim\Core\RateLimiter::padrao(30,60)->permitir('fiscal-ncm|'.$this->id)
            && \Cardapim\Core\RateLimiter::padrao(30,60)->permitir('fiscal-ncm-brasilapi-global')) {
            try { (new CatalogoNcm())->consultar($d['ncm']); $catalogo=CatalogoNcm::evidencia($d['ncm']); }
            catch (Throwable $e) { error_log('[Cardapim] NCM BrasilAPI indisponível: '.get_class($e)); }
        }
        $this->escrever(function ($empresa) use ($id,$p,$d,$catalogo) {
            $perfil=self::identificador($p['id_perfil'] ?? '');
            if ($perfil===null && ($p['id_perfil'] ?? '')!=='') { throw new RuntimeException('Perfil inválido.'); }
            $perfis=$this->repo->perfis();
            if ($perfil!==null && (!isset($perfis[$perfil]) || !Validar::perfilVigente($perfis[$perfil],$empresa))) { throw new RuntimeException('Selecione um perfil revisado compatível.'); }
            $d['ncm_fonte']=isset($catalogo['itens'][$d['ncm']])?$catalogo['versao']:'';
            if ($d['ncm']!=='' && $catalogo!==null && $d['ncm_fonte']==='') { throw new RuntimeException('NCM não localizado como vigente na BrasilAPI. Confira a classificação.'); }
            if ($d['ncm_fonte']==='') { $d['confirmado']=false; }
            if ($d['cest']!=='' && $d['confirmado']) {
                $cest=(new \Cardapim\Modules\Fiscal\CatalogosFiscais($this->db))->vigente('cest');
                if (!$cest) { $d['confirmado']=false; }
                elseif (!\Cardapim\Modules\Fiscal\CatalogosFiscais::contem($cest,$d['cest'],$d['ncm'])) {
                    throw new RuntimeException('CEST não localizado para o NCM. Confira código, descrição e segmento.');
                }
            }
            $this->repo->salvarProduto($id,$perfil,$perfil===null?null:(int)$perfis[$perfil]['versao'],$d,$this->usuario->id);
        });
        Auditoria::registrar($this->usuario,'Fiscal: produto salvo','Produto '.$id.'; cadastro fiscal independente.');
    }

    public static function identificador(mixed $v): ?int
    {
        return is_string($v) && ctype_digit($v) && strlen($v)<=18 && (int)$v>0?(int)$v:null;
    }

    public const CAMPOS_LOTE=['ncm','cest','origem','unidade_comercial','unidade_tributavel','fator'];

    /** Alterações explícitas e restritas; nunca replicar GTIN ou confirmação fiscal individual. */
    public static function loteCampos(array $entrada): array
    {
        $marcados=$entrada['campos'] ?? [];
        if (!is_array($marcados) || count($marcados)>count(self::CAMPOS_LOTE)) { throw new RuntimeException('Seleção de campos inválida.'); }
        $dados=[];
        foreach ($marcados as $k) {
            if (!is_string($k) || !in_array($k,self::CAMPOS_LOTE,true)) { throw new RuntimeException('Campo não permitido em massa.'); }
            $dados[$k]=$entrada[$k] ?? '';
        }
        $validos=Validar::produto($dados);
        $out=[];
        foreach (self::CAMPOS_LOTE as $k) {
            if (array_key_exists($k,$dados)) {
                if ($validos[$k]==='') { throw new RuntimeException('Preencha os campos escolhidos para aplicação.'); }
                $out[$k]=$validos[$k];
            }
        }
        return $out;
    }

    public static function mesclarLote(array $atual,array $campos,bool $sobrescrever,?array $catalogo): array
    {
        $mudancas=[];
        foreach ($campos as $k=>$v) {
            if (!in_array($k,self::CAMPOS_LOTE,true)) { throw new RuntimeException('Campo não permitido.'); }
            $antes=(string)($atual[$k] ?? '');
            if ($antes===$v || (!$sobrescrever && $antes!=='')) { continue; }
            if ($k==='ncm' && $catalogo!==null && !isset($catalogo['itens'][$v])) { throw new RuntimeException('NCM não localizado como vigente na BrasilAPI.'); }
            $mudancas[$k]=['de'=>$antes,'para'=>$v];
            $atual[$k]=$v;
            if ($k==='ncm') { $atual['ncm_fonte']=isset($catalogo['itens'][$v])?$catalogo['versao']:''; }
        }
        if ($mudancas!==[]) { $atual['confirmado']=false; }
        return ['dados'=>$atual,'mudancas'=>$mudancas];
    }

    public function previa(array $ids,?int $perfil,array $entrada=[]): array
    {
        $campos=self::loteCampos($entrada);
        $sobrescrever=($entrada['sobrescrever'] ?? '')==='1';
        $e=$this->estado(); $p=$perfil===null?null:($e['perfis'][$perfil] ?? null);
        if ($perfil!==null && (!$p || !Validar::perfilVigente($p,$e['empresa']))) { throw new RuntimeException('Escolha um perfil revisado e vigente.'); }
        if ($perfil===null && $campos===[]) { throw new RuntimeException('Selecione um perfil ou campos de classificação.'); }
        if ($ids===[] || count($ids)>200) { throw new RuntimeException('Selecione de 1 a 200 produtos.'); }
        $catalogo=isset($campos['ncm'])?CatalogoNcm::evidencia($campos['ncm']):null;
        $itens=[];
        foreach ($ids as $v) {
            $id=self::identificador($v);
            if ($id===null || !isset($e['produtos'][$id])) { throw new RuntimeException('Seleção inválida. Nenhum produto alterado.'); }
            $item=$e['produtos'][$id];
            $m=self::mesclarLote($item['dados'],$campos,$sobrescrever,$catalogo);
            $anterior=$e['perfis'][(int)$item['id_perfil']]['nome'] ?? 'Sem perfil';
            $itens[$id]=['id'=>$id,'nome'=>$item['nome'],'anterior'=>$anterior,'novo'=>$p['nome'] ?? $anterior,
                'assinatura'=>hash('sha256',json_encode($item,JSON_THROW_ON_ERROR)),
                'id_perfil'=>$perfil ?? ($item['id_perfil']===null?null:(int)$item['id_perfil']),
                'versao'=>$p?(int)$p['versao']:($item['versao']===null?null:(int)$item['versao']),
                'dados'=>$m['dados'],'mudancas'=>$m['mudancas']];
        }
        return ['perfil'=>$perfil,'versao'=>$p?(int)$p['versao']:null,'nome'=>$p['nome'] ?? 'Manter perfil atual',
            'entrada'=>['campos'=>array_keys($campos),'sobrescrever'=>$sobrescrever?'1':'0']+$campos,'itens'=>array_values($itens)];
    }

    public function aplicar(array $previa): int
    {
        $n=$this->escrever(function ($empresa) use ($previa) {
            $ids=array_map(static fn($p)=>(string)$p['id'],$previa['itens']);
            $agora=$this->previa($ids,$previa['perfil'],$previa['entrada']);
            if ($agora!==$previa) { throw new RuntimeException('Dados mudaram desde a prévia. Revise novamente.'); }
            foreach ($agora['itens'] as $p) {
                $this->repo->salvarProduto($p['id'],$p['id_perfil'],$p['versao'],$p['dados'],$this->usuario->id);
            }
            return count($ids);
        });
        Auditoria::registrar($this->usuario,'Fiscal: aplicação em massa','Perfil '.($previa['perfil'] ?? 'mantido').'; campos '.implode(',',$previa['entrada']['campos']).'; '.$n.' produtos.');
        return $n;
    }

    /** Apenas projeção; não mantém XML nem dados de fornecedor. */
    public function lerImportacao(string $tipo,string $bytes): array
    {
        $this->empresa();
        $r=(new FiscalRepository($this->db))->buscar($this->id);
        $itens=match($tipo) {
            'xml'=>\Cardapim\Modules\Fiscal\ImportacaoFiscal::xml($bytes,$r['cnpj']),
            'csv'=>\Cardapim\Modules\Fiscal\ImportacaoFiscal::csv($bytes),
            default=>throw new RuntimeException('Tipo de arquivo não suportado.'),
        };
        $produtos=$this->repo->produtos();
        foreach ($itens as &$i) { $i['sugestao']=\Cardapim\Modules\Fiscal\ImportacaoFiscal::sugerir($i,$produtos); }
        unset($i);
        return $itens;
    }

    public function previaImportacao(array $itens,array $destinos,bool $substituir): array
    {
        $e=$this->estado();
        if (count($destinos)>200) { throw new RuntimeException('Limite de 200 itens.'); }
        $out=[]; $vistos=[];
        foreach ($destinos as $i=>$idTexto) {
            if ($idTexto==='') { continue; }
            $id=self::identificador($idTexto);
            if ($id===null || !isset($itens[$i],$e['produtos'][$id]) || isset($vistos[$id])) { throw new RuntimeException('Selecione produtos válidos e não repita o mesmo destino.'); }
            $vistos[$id]=true;
            $p=$e['produtos'][$id];
            $catalogo=CatalogoNcm::evidencia((string)($itens[$i]['dados']['ncm'] ?? ''));
            $m=\Cardapim\Modules\Fiscal\ImportacaoFiscal::mesclar($p['dados'],$itens[$i]['dados'],$substituir,$catalogo);
            if ($m['mudancas']===[]) { continue; }
            $out[]=['id'=>$id,'nome'=>$p['nome'],'origem_nome'=>$itens[$i]['nome'],
                'assinatura'=>hash('sha256',json_encode($p,JSON_THROW_ON_ERROR)),
                'id_perfil'=>$p['id_perfil']===null?null:(int)$p['id_perfil'],
                'versao'=>$p['versao']===null?null:(int)$p['versao'],
                'dados'=>$m['dados'],'mudancas'=>$m['mudancas']];
        }
        if ($out===[]) { throw new RuntimeException('Nenhuma alteração selecionada. Confira os vínculos e os campos existentes.'); }
        return $out;
    }

    public function aplicarImportacao(array $itens,array $destinos,bool $substituir,array $previa): int
    {
        $n=$this->escrever(function () use ($itens,$destinos,$substituir,$previa) {
            $agora=$this->previaImportacao($itens,$destinos,$substituir);
            if ($agora!==$previa) { throw new RuntimeException('Os dados mudaram desde a prévia. Importe novamente.'); }
            foreach ($agora as $p) { $this->repo->salvarProduto($p['id'],$p['id_perfil'],$p['versao'],$p['dados'],$this->usuario->id); }
            return count($agora);
        });
        Auditoria::registrar($this->usuario,'Fiscal: importação de classificação',$n.' produtos; dados comerciais preservados.');
        return $n;
    }

    public function conferencia(): array
    {
        $e=$this->estado();
        $c=new \Cardapim\Modules\Fiscal\ComposicaoFiscal($this->db,$this->id);
        $estruturas=$c->estruturas();$config=$c->configuracoes();
        $cat=new \Cardapim\Modules\Fiscal\CatalogosFiscais($this->db);
        $catalogos=[];
        foreach (\Cardapim\Modules\Fiscal\CatalogosFiscais::TIPOS as $k=>$v) { $catalogos[$k]=$cat->vigente($k); }
        $base=[];
        foreach ($e['produtos'] as $id=>$p) {
            $base[$id]=\Cardapim\Modules\Fiscal\VerificacaoFiscal::produto($p,$e['perfis'][(int)$p['id_perfil']] ?? null,$e['empresa'],$catalogos);
        }
        $verificacao=$base;
        foreach ($estruturas as $id=>$s) {
            if (!isset($e['produtos'][$id])) { continue; }
            // Combo pai não é linha fiscal adicional; componentes usam a classificação individual.
            $r=\Cardapim\Modules\Fiscal\ComposicaoFiscal::pendencias($id,$s,$config[$id] ?? null,$base,$estruturas);
            $verificacao[$id]=array_values(array_unique(array_merge($s['combo']?[]:$base[$id],$r)));
        }
        return $e+['estruturas'=>$estruturas,'configuracoes'=>$config,'verificacao'=>$verificacao];
    }

    public function composicao(int $id): array
    {
        $e=$this->estado();$c=new \Cardapim\Modules\Fiscal\ComposicaoFiscal($this->db,$this->id);
        $estruturas=$c->estruturas();
        if (!isset($e['produtos'][$id],$estruturas[$id])) { throw new RuntimeException('Produto sem composição fiscal configurável.'); }
        return $e+['estruturas'=>$estruturas,'estrutura'=>$estruturas[$id],'produto'=>$e['produtos'][$id],
            'config'=>$c->configuracoes()[$id] ?? null,'assinatura'=>\Cardapim\Modules\Fiscal\ComposicaoFiscal::assinatura($estruturas[$id])];
    }

    public function salvarComposicao(int $id,array $p): void
    {
        $this->escrever(function () use($id,$p) {
            $e=$this->composicao($id);
            $d=\Cardapim\Modules\Fiscal\ComposicaoFiscal::normalizar($id,$e['estrutura'],$e['produtos'],$e['estruturas'],$p);
            (new \Cardapim\Modules\Fiscal\ComposicaoFiscal($this->db,$this->id))->salvar($id,$e['estrutura'],$d,$this->usuario->id);
        });
        Auditoria::registrar($this->usuario,'Fiscal: composição revisada','Produto '.$id.'; sem alteração comercial.');
    }

    public function categoria(int $categoria,?int $perfil): void
    {
        $this->escrever(function ($empresa) use ($categoria,$perfil) {
            if ($perfil!==null) {
                $p=$this->repo->perfis()[$perfil] ?? null;
                if (!$p || !Validar::perfilVigente($p,$empresa)) { throw new RuntimeException('Perfil inválido para esta categoria.'); }
            }
            $this->repo->categoria($categoria,$perfil);
        });
        Auditoria::registrar($this->usuario,'Fiscal: sugestão por categoria','Categoria '.$categoria.'; perfil '.($perfil ?? 'nenhum').'.');
    }
}
