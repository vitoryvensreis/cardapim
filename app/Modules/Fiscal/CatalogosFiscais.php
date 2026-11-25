<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Auditoria;
use Cardapim\Shared\Esquema;
use PDO;
use RuntimeException;

/** Cópias administrativas versionadas de tabelas oficiais normalizadas; não motor tributário. */
final class CatalogosFiscais
{
    public const PORTAL='https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D';
    public const MOC='https://moc.sped.fazenda.pr.gov.br/NFe_NFCe/NFe_NFCe/VisaoGeral.html';
    public const CONFAZ='https://www.confaz.fazenda.gov.br/legislacao/convenios/2018/CV142_18';
    public const TIPOS=[
        'cest'=>['CEST',7,self::CONFAZ],
        'cfop'=>['CFOP',4,self::PORTAL],
        'cst_icms'=>['CST ICMS',2,self::MOC],
        'csosn'=>['CSOSN',3,self::MOC],
        'cst_pis'=>['CST PIS',2,self::MOC],
        'cst_cofins'=>['CST COFINS',2,self::MOC],
        'cst_ibs_cbs'=>['CST IBS/CBS',3,self::PORTAL],
        'cclass_trib'=>['cClassTrib IBS/CBS',6,self::PORTAL],
        'cst_is'=>['CST IS',3,self::PORTAL],
        'cclass_is'=>['cClassTrib IS',6,self::PORTAL],
    ];
    public function __construct(private readonly PDO $db) {}

    public static function normalizar(string $tipo,string $csv): array
    {
        $def=self::TIPOS[$tipo] ?? null;
        if (!$def) { throw new RuntimeException('Tabela fiscal não reconhecida.'); }
        $linhas=ImportacaoFiscal::csvLinhas($csv,10000);
        $out=[];
        foreach ($linhas as $r) {
            $codigo=CadastroProdutoFiscal::campo($r,'codigo',16);
            $codigo=str_replace('.','',$codigo);
            $desc=CadastroProdutoFiscal::campo($r,'descricao',1000);
            $ncm=str_replace('.','',CadastroProdutoFiscal::campo($r,'ncm',12));
            $cst=CadastroProdutoFiscal::campo($r,'cst',3);
            $nfce=CadastroProdutoFiscal::campo($r,'nfce',3);
            if (!preg_match('/\A[0-9]{'.$def[1].'}\z/',$codigo) || $desc==='') { throw new RuntimeException('Código ou descrição inválida na tabela.'); }
            if ($tipo==='cest' && !preg_match('/\A[0-9]{2,8}\z/',$ncm)) { throw new RuntimeException('CEST exige um prefixo NCM válido por linha.'); }
            if (in_array($tipo,['cclass_trib','cclass_is'],true) && (!preg_match('/\A[0-9]{3}\z/',$cst) || !in_array($nfce,['sim','nao'],true))) {
                throw new RuntimeException('Classificação tributária exige CST e indicador NFC-e sim/nao conforme tabela oficial.');
            }
            $item=['codigo'=>$codigo,'descricao'=>$desc,'ncm'=>$tipo==='cest'?$ncm:'','cst'=>in_array($tipo,['cclass_trib','cclass_is'],true)?$cst:'','nfce'=>in_array($tipo,['cclass_trib','cclass_is'],true)?$nfce:''];
            $key=$codigo.'|'.$item['ncm'].'|'.$item['cst'];
            if (isset($out[$key]) && $out[$key]!==$item) { throw new RuntimeException('Linhas conflitantes no catálogo. Confira a normalização.'); }
            $out[$key]=$item;
        }
        return array_values($out);
    }

    public function importar(UsuarioLogado $autor,string $tipo,string $versao,string $vigencia,string $csv): int
    {
        if (!$autor->ativo || $autor->cargo!=='adm_master' || !Esquema::tem($this->db,'fiscal_catalogos')) { throw new RuntimeException('A atualização de catálogos é exclusiva da plataforma.'); }
        $versao=CadastroProdutoFiscal::campo(['versao'=>$versao],'versao',100);
        if ($versao==='' || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/',$vigencia,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) { throw new RuntimeException('Informe a versão oficial e data de vigência.'); }
        $dados=self::normalizar($tipo,$csv);
        $json=json_encode($dados,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $this->db->prepare('INSERT INTO fiscal_catalogos(tipo,versao,vigencia,dados,sha256,autor) VALUES (?,?,?,?,?,?)')
            ->execute([$tipo,$versao,$vigencia,$json,hash('sha256',$csv),$autor->id]);
        Auditoria::registrar($autor,'Fiscal: catálogo importado',$tipo.'; versão '.$versao.'; '.count($dados).' registros.');
        return count($dados);
    }

    public function vigente(string $tipo): ?array
    {
        if (!isset(self::TIPOS[$tipo]) || !Esquema::tem($this->db,'fiscal_catalogos')) { return null; }
        $q=$this->db->prepare('SELECT tipo,versao,vigencia,dados,sha256 FROM fiscal_catalogos WHERE tipo=? AND vigencia<=? ORDER BY vigencia DESC,id DESC LIMIT 1');
        $q->execute([$tipo,date('Y-m-d')]);
        $d=$q->fetch(PDO::FETCH_ASSOC);
        if (!$d) { return null; }
        $d['itens']=json_decode($d['dados'],true,512,JSON_THROW_ON_ERROR);
        unset($d['dados']);
        return $d;
    }

    public function resumo(): array
    {
        if (!Esquema::tem($this->db,'fiscal_catalogos')) { return []; }
        return $this->db->query('SELECT tipo,versao,vigencia,criado_em,sha256 FROM fiscal_catalogos ORDER BY tipo,vigencia DESC,id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function pesquisar(array $d,string $q='',string $ncm='',string $cst='',int $limite=35): array
    {
        $q=TextoLocal::chave($q);
        $cod=preg_replace('/[^0-9]/','',$q);
        $out=[];
        foreach ($d['itens'] ?? [] as $r) {
            if ($d['tipo']==='cfop' && !str_starts_with($r['codigo'],'5')) { continue; } // escopo NFC-e interna; não implica CFOP permitido
            if ($ncm!=='' && $r['ncm']!=='' && !str_starts_with($ncm,$r['ncm'])) { continue; }
            if ($cst!=='' && $r['cst']!=='' && $cst!==$r['cst']) { continue; }
            if ($r['nfce']==='nao') { continue; }
            if ($q!=='' && !str_contains(TextoLocal::chave($r['descricao']),$q) && !($cod!=='' && preg_match('/\A[0-9 ]+\z/',$q) && str_starts_with($r['codigo'],$cod))) { continue; }
            $out[]=$r;
            if (count($out)>=$limite) { break; }
        }
        return $out;
    }

    public static function contem(?array $d,string $codigo,string $ncm='',string $cst=''): bool
    {
        if ($d===null || $codigo==='') { return false; }
        foreach ($d['itens'] ?? [] as $r) {
            if ($r['codigo']!==$codigo || $r['nfce']==='nao') { continue; }
            if ($ncm!=='' && $r['ncm']!=='' && !str_starts_with($ncm,$r['ncm'])) { continue; }
            if ($cst!=='' && $r['cst']!=='' && $cst!==$r['cst']) { continue; }
            return true;
        }
        return false;
    }
}
