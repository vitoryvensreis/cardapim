<?php
declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Esquema;
use PDO;
use RuntimeException;
use Throwable;

/** Liberação administrativa independente da ativação pelo gerente e do vínculo com a NeXT. */
final class FiscalAcesso
{
    public static function disponivel(PDO $db): bool
    {
        return Esquema::tem($db,'fiscal_liberacoes') && Esquema::tem($db,'fiscal_visibilidade');
    }

    public static function permitido(PDO $db,int $id): bool
    {
        if (!self::disponivel($db)) { return false; }
        try {
            $q=$db->prepare('SELECT 1 FROM fiscal_liberacoes l JOIN fiscal_visibilidade v ON v.id=1 AND v.visivel=1 WHERE l.id_restaurante=? AND l.permitido=1');
            $q->execute([$id]);
            return $q->fetchColumn()!==false;
        } catch (Throwable) { return false; }
    }

    public static function painel(PDO $db,string $busca=''): ?array
    {
        if (!self::disponivel($db)) { return null; }
        $global=(int)$db->query('SELECT visivel FROM fiscal_visibilidade WHERE id=1')->fetchColumn()===1;
        $busca=mb_substr(trim($busca),0,120);
        $producao=Esquema::tem($db,'fiscal_producao') ? 'COALESCE(l.producao,0)' : '0';
        $q=$db->prepare('SELECT r.id,r.nome,r.slug,COALESCE(l.permitido,0) AS permitido,'.$producao.' AS producao FROM restaurantes r LEFT JOIN fiscal_liberacoes l ON l.id_restaurante=r.id WHERE (LENGTH(?)=0 OR LOCATE(?,r.nome)>0 OR LOCATE(?,r.slug)>0) ORDER BY r.nome,r.id LIMIT 101');
        $q->execute([$busca,$busca,$busca]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        return ['global'=>$global,'restaurantes'=>array_slice($rows,0,100),'mais'=>count($rows)>100,'busca'=>$busca];
    }

    private static function admin(PDO $db,UsuarioLogado $autor): void
    {
        if ($autor->cargo!=='adm_master' || !$autor->ativo || !self::disponivel($db)) { throw new RuntimeException('A liberação é exclusiva da administração da plataforma.'); }
    }

    public static function alterar(PDO $db,UsuarioLogado $autor,int $id,bool $permitido): void
    {
        self::admin($db,$autor);
        (new FiscalRepository($db))->serializar($id,static function () use ($db,$autor,$id,$permitido): void {
            $q=$db->prepare('SELECT id FROM restaurantes WHERE id=?');
            $q->execute([$id]);
            if (!$q->fetchColumn()) { throw new RuntimeException('Restaurante não encontrado.'); }
            $db->prepare('INSERT INTO fiscal_liberacoes (id_restaurante,permitido,autor) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permitido=VALUES(permitido),autor=VALUES(autor),atualizado_em=CURRENT_TIMESTAMP')
                ->execute([$id,(int)$permitido,$autor->id]);
        });
        Auditoria::registrar($autor,'Fiscal: acesso administrativo','Restaurante '.$id.'; '.($permitido?'liberado':'ocultado').'. Dados preservados.');
    }

    /** Emissão com valor fiscal (ambiente de produção) liberada pelo superadmin para o restaurante? */
    public static function producaoLiberada(PDO $db,int $id): bool
    {
        if (!Esquema::tem($db,'fiscal_producao') || !self::permitido($db,$id)) { return false; }
        try {
            $q=$db->prepare('SELECT producao FROM fiscal_liberacoes WHERE id_restaurante=?');
            $q->execute([$id]);
            return (int)$q->fetchColumn()===1;
        } catch (Throwable) { return false; }
    }

    /**
     * Libera ou bloqueia a produção. Exige o módulo liberado para o restaurante. Ao bloquear, um restaurante
     * que estava em produção volta para homologação (nenhuma nota com valor fiscal sai depois disso).
     */
    public static function alterarProducao(PDO $db,UsuarioLogado $autor,int $id,bool $liberar): void
    {
        self::admin($db,$autor);
        if (!Esquema::tem($db,'fiscal_producao')) { throw new RuntimeException('Atualização do banco pendente para a produção fiscal.'); }
        $repo=new FiscalRepository($db);
        $repo->serializar($id,static function () use ($db,$repo,$autor,$id,$liberar): void {
            if ($liberar && !self::permitido($db,$id)) { throw new RuntimeException('Libere o módulo fiscal para o restaurante antes da produção.'); }
            $db->prepare('UPDATE fiscal_liberacoes SET producao=?,producao_autor=?,producao_em=NOW() WHERE id_restaurante=?')->execute([(int)$liberar,$autor->id,$id]);
            if (!$liberar) {
                $r=$repo->buscar($id);
                if ($r!==null && (int)($r['dados']['ambiente'] ?? 3)===1) {
                    $r['dados']['ambiente']=2;
                    $repo->salvar($id,$r);
                }
            }
        });
        Auditoria::registrar($autor,'Fiscal: produção','Restaurante '.$id.'; produção '.($liberar?'liberada':'bloqueada').'.');
    }

    public static function global(PDO $db,UsuarioLogado $autor,bool $visivel): void
    {
        self::admin($db,$autor);
        $db->prepare('UPDATE fiscal_visibilidade SET visivel=?,autor=? WHERE id=1')->execute([(int)$visivel,$autor->id]);
        Auditoria::registrar($autor,'Fiscal: visibilidade geral',$visivel?'Restaurantes autorizados podem acessar.':'Fiscal ocultado de todos os restaurantes.');
    }
}
