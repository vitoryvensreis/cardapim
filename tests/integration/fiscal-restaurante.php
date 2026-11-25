<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/bootstrap/app.php';
use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Shared\Seguranca\Cripto;
$db = \Cardapim\Core\Database::connect();
Cripto::usarChave(str_repeat('f',32));
$ids = $db->query('SELECT id FROM restaurantes ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) < 1) { throw new RuntimeException('Restaurante de teste ausente.'); }
$id = (int) $ids[0];
$r = new FiscalRepository($db);
$qcnpj = $db->prepare('SELECT cnpj FROM restaurantes WHERE id=?');
$qcnpj->execute([$id]);
$cnpj = (string) $qcnpj->fetchColumn();
$db->beginTransaction();
try {
    $db->prepare('INSERT INTO fiscal_liberacoes (id_restaurante,permitido,autor) VALUES (?,1,1)')->execute([$id]);
    $r->serializar($id, function () use ($r,$id,$cnpj): void {
        $r->salvar($id,['cnpj'=>$cnpj,'habilitado'=>true,'aprovado'=>false,'id_impressora'=>null,'dados'=>['token'=>'segredo-fiscal-teste','empresa'=>['cidade'=>'Fortaleza','estado'=>'CE','ibge'=>'2304400','ibge_verificado'=>true]]]);
    });
    $row = $r->buscar($id);
    if (!$row['habilitado'] || $row['dados']['token'] !== 'segredo-fiscal-teste') { throw new RuntimeException('Configuração não persistida.'); }
    $q=$db->prepare('SELECT dados FROM restaurante_fiscal WHERE id_restaurante=?'); $q->execute([$id]);
    if (str_contains($q->fetchColumn(),'segredo-fiscal-teste')) { throw new RuntimeException('Credencial exposta.'); }
    if (isset($ids[1]) && $r->buscar((int)$ids[1]) !== null) { throw new RuntimeException('Isolamento fiscal falhou.'); }
    $admin = new \Cardapim\Services\Auth\UsuarioLogado(1, 'adm_master', 'CI', 'Fiscal', true, null, null, null, null);
    $plataforma = new \Cardapim\Services\Plataforma\FiscalNextService($db, $admin);
    $resumo = $plataforma->restaurantes();
    if (!is_array($resumo) || !in_array($id, array_column($resumo, 'id'), true)) {
        throw new RuntimeException('Central fiscal não consultou o resumo pelo PDO injetado.');
    }
    if (str_contains(json_encode($resumo),'segredo-fiscal-teste')) { throw new RuntimeException('Credencial exposta no resumo.'); }
    $servico = new \Cardapim\Services\Gerencia\FiscalService($db,$admin,$id);
    $res = $servico->empresa(['razao'=>'Empresa CI','nome'=>'Nome editado','email'=>'','cep'=>'60060440','logradouro'=>'Rua Teste','numero'=>'1','complemento'=>'','bairro'=>'Centro','cidade'=>'Fortaleza','estado'=>'CE','crt'=>'4','ie_situacao'=>'pendente','ibge'=>'9999999']);
    $dados = $r->buscar($id)['dados'];
    if (!$res->ok || $dados['empresa']['ie'] !== '' || $dados['empresa']['ibge'] !== '2304400' || $dados['empresa']['nome'] !== 'Nome editado' || $dados['token'] !== 'segredo-fiscal-teste' || $servico->estado()['empresa_pronta']) {
        throw new RuntimeException('Cadastro pendente editável, município ou preservação do token falhou.');
    }
    if (!$servico->ambiente('2')->ok || !$servico->configurar(['serie'=>'7','numero'=>'85','id_csc'=>'12','csc'=>'teste-homologacao'])->ok) {
        throw new RuntimeException('Configuração de homologação falhou.');
    }
    $hom = $r->buscar($id)['dados']['config'][2];
    $r->serializar($id,function () use ($r,$id): void {
        $atual = $r->buscar($id);
        $atual['dados']['config'][3] = ['serie'=>1,'numero'=>42,'id_csc'=>'99','csc'=>'antigo'];
        $r->salvar($id,$atual);
    });
    if (!$servico->ambiente('3')->ok || $servico->configurar(['serie'=>'8','numero'=>'1','id_csc'=>'1','csc'=>'nao-aceitar'])->ok || $servico->ambiente('1')->ok) {
        throw new RuntimeException('Simulação editável ou produção liberada.');
    }
    $amb = $r->buscar($id)['dados']['config'];
    if ($amb[2] !== $hom || $amb[3] !== ['serie'=>999,'numero'=>42,'id_csc'=>'','csc'=>''] || $servico->estado()['tem_csc']) {
        throw new RuntimeException('Separação dos ambientes ou numeração de teste falhou.');
    }
    $servico->ambiente('2');
    $servico->ambiente('3');
    if ($r->buscar($id)['dados']['config'] !== $amb) {
        throw new RuntimeException('Troca de ambiente alterou parâmetros.');
    }
} finally { $db->rollBack(); Cripto::usarChave(null); }
echo "Persistência e projeção fiscal confirmadas.\n";
