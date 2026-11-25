<?php
declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;
use RuntimeException;

final class FiscalProdutosRepository
{
    public function __construct(private readonly PDO $db,private readonly int $id) {}

    private function linhas(string $sql,array $args=[]): array
    {
        $q=$this->db->prepare($sql);
        $q->execute([$this->id,...$args]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function perfis(): array
    {
        $rows=$this->linhas('SELECT p.id,p.nome,p.versao,v.dados,v.revisado,v.criado_em,(SELECT COUNT(*) FROM fiscal_produtos f JOIN produtos pr ON pr.id=f.id_produto AND pr.id_restaurante=f.id_restaurante AND pr.ativo=1 WHERE f.id_restaurante=p.id_restaurante AND f.id_perfil=p.id) AS vinculados FROM fiscal_perfis p JOIN fiscal_perfil_versoes v ON v.id_restaurante=p.id_restaurante AND v.id_perfil=p.id AND v.versao=p.versao WHERE p.id_restaurante=? ORDER BY p.nome');
        $out=[];
        foreach ($rows as $r) { $r['dados']=json_decode($r['dados'],true,512,JSON_THROW_ON_ERROR); $out[(int)$r['id']]=$r; }
        return $out;
    }

    public function produtos(): array
    {
        $out=[];
        foreach ($this->linhas('SELECT p.id,p.nome,p.codigo,p.unidade_venda,p.id_categoria,c.nome AS categoria,f.id_perfil,f.versao,f.dados,f.atualizado_em FROM produtos p LEFT JOIN categorias c ON c.id=p.id_categoria AND c.id_restaurante=p.id_restaurante LEFT JOIN fiscal_produtos f ON f.id_produto=p.id AND f.id_restaurante=p.id_restaurante WHERE p.id_restaurante=? AND p.ativo=1 ORDER BY p.nome') as $r) {
            $r['dados']=$r['dados']===null?[]:json_decode($r['dados'],true,512,JSON_THROW_ON_ERROR);
            $out[(int)$r['id']]=$r;
        }
        return $out;
    }

    public function categorias(): array
    {
        return $this->linhas('SELECT c.id,c.nome,f.id_perfil FROM categorias c LEFT JOIN fiscal_categoria_perfil f ON f.id_categoria=c.id AND f.id_restaurante=c.id_restaurante WHERE c.id_restaurante=? ORDER BY c.nome');
    }

    public function salvarPerfil(?int $id,int $versao,array $d,bool $rev,int $autor): int
    {
        if ($id===null) {
            $this->db->prepare('INSERT INTO fiscal_perfis (id_restaurante,nome,versao) VALUES (?,?,1)')->execute([$this->id,$d['nome']]);
            $id=(int)$this->db->lastInsertId();
            $versao=1;
        } else {
            $q=$this->db->prepare('UPDATE fiscal_perfis SET nome=?,versao=versao+1 WHERE id_restaurante=? AND id=? AND versao=?');
            $q->execute([$d['nome'],$this->id,$id,$versao]);
            if ($q->rowCount()!==1) { throw new RuntimeException('Perfil mudou. Atualize a página.'); }
            $versao++;
        }
        $this->db->prepare('INSERT INTO fiscal_perfil_versoes (id_restaurante,id_perfil,versao,dados,revisado,autor) VALUES (?,?,?,?,?,?)')
            ->execute([$this->id,$id,$versao,json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$rev,$autor]);
        return $id;
    }

    public function salvarProduto(int $id,?int $perfil,?int $versao,array $dados,int $autor): void
    {
        // Revalida e bloqueia a linha do produto antes de vincular, sem mudar o cadastro comercial.
        $q=$this->db->prepare('SELECT id FROM produtos WHERE id_restaurante=? AND id=? AND ativo=1 FOR UPDATE');
        $q->execute([$this->id,$id]);
        if (!$q->fetchColumn()) { throw new RuntimeException('Produto não disponível neste restaurante.'); }
        $this->db->prepare('INSERT INTO fiscal_produtos (id_restaurante,id_produto,id_perfil,versao,dados,autor) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id_perfil=VALUES(id_perfil),versao=VALUES(versao),dados=VALUES(dados),autor=VALUES(autor),atualizado_em=CURRENT_TIMESTAMP')
            ->execute([$this->id,$id,$perfil,$versao,json_encode($dados,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$autor]);
    }

    public function categoria(int $categoria,?int $perfil): void
    {
        $q=$this->db->prepare('SELECT id FROM categorias WHERE id_restaurante=? AND id=? FOR UPDATE');
        $q->execute([$this->id,$categoria]);
        if (!$q->fetchColumn()) { throw new RuntimeException('Categoria não disponível.'); }
        if ($perfil===null) {
            $this->db->prepare('DELETE FROM fiscal_categoria_perfil WHERE id_restaurante=? AND id_categoria=?')->execute([$this->id,$categoria]);
        } else {
            $this->db->prepare('INSERT INTO fiscal_categoria_perfil (id_restaurante,id_categoria,id_perfil) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id_perfil=VALUES(id_perfil)')->execute([$this->id,$categoria,$perfil]);
        }
    }
}
