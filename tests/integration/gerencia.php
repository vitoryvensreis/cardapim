<?php

declare(strict_types=1);

// Integracao (MySQL) das abas do painel do gerente: estoque, equipe, perfil, horarios,
// assinatura e verificacao em duas etapas, com os CHECKs/FKs reais e os logs de auditoria.
// Os servicos abrem transacoes proprias, entao os dados sao criados em um restaurante de teste
// e apagados no final. SOMENTE no banco de CI.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Repositories\ContaRepository;
use Cardapim\Repositories\Estoque\InsumoRepository;
use Cardapim\Repositories\Gerencia\EquipeRepository;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Services\Auth\DoisFatores;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Services\Gerencia\CategoriaService;
use Cardapim\Services\Gerencia\EquipeService;
use Cardapim\Services\Gerencia\InsumoService;
use Cardapim\Services\Gerencia\PerfilService;
use Cardapim\Services\Gerencia\ProdutoService;
use Cardapim\Shared\Seguranca\Cripto;
use Cardapim\Shared\Seguranca\Totp;

$db = Database::connect();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cardapim_ci') {
    fwrite(STDERR, "Recusado: teste destrutivo so roda no banco cardapim_ci.\n");
    exit(1);
}
$falhar = static function (string $mensagem): never {
    throw new RuntimeException($mensagem);
};
$exigir = static function (bool $condicao, string $mensagem) use ($falhar): void {
    if (!$condicao) {
        $falhar($mensagem);
    }
};
$valor = static function (string $sql, array $params = []) use ($db): mixed {
    $q = $db->prepare($sql);
    $q->execute($params);
    return $q->fetchColumn();
};

$slug = 'ci-gerencia-integracao';
$idRest = 0;
$idOutro = 0;
$idGerente = 0;
$limpar = static function () use ($db, &$idRest, &$idOutro, &$idGerente): void {
    foreach ([$idRest, $idOutro] as $r) {
        if ($r <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM ifood_itens WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM ifood_catalogo_vinculos WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM produto_porcoes WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM produto_complemento_grupos WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM produto_insumos WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM produtos WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM insumos WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM categorias WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM restaurante_horarios WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM assinaturas WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE l FROM log_user l JOIN `user` u ON u.id = l.id_user WHERE u.id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM `user` WHERE id_restaurante = ?')->execute([$r]);
        $db->prepare('DELETE FROM restaurantes WHERE id = ?')->execute([$r]);
    }
};

try {
    $planos = $db->query('SELECT id FROM planos WHERE ativo = 1 AND visibilidade = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    $exigir(count($planos) === 2, 'Precisa de 2 planos publicos (seed-demo-planos).');
    foreach ([[$slug, 'CI Gerencia Integracao'], [$slug . '-outro', 'CI Gerencia Outro']] as [$s, $nome]) {
        $db->prepare("INSERT INTO restaurantes (nome, slug, status_rest) VALUES (?, ?, 'ativo')")->execute([$nome, $s]);
    }
    $idRest = (int) $valor('SELECT id FROM restaurantes WHERE slug = ?', [$slug]);
    $idOutro = (int) $valor('SELECT id FROM restaurantes WHERE slug = ?', [$slug . '-outro']);
    $db->prepare("INSERT INTO `user` (id_restaurante, cargo, usuario, email, senha_hash, nome, sobrenome) VALUES (?, 'gerente', 'ci_int_gerente', 'ci.int.gerente@example.test', ?, 'Gerente', 'Integracao')")
        ->execute([$idRest, password_hash('Senha#CI2026', PASSWORD_DEFAULT)]);
    $idGerente = (int) $valor('SELECT id FROM `user` WHERE usuario = ?', ['ci_int_gerente']);
    $autor = new UsuarioLogado($idGerente, 'gerente', 'Gerente', 'Integracao', true, $idRest, $slug, 'CI Gerencia Integracao', 'ativo');
    $logs = static fn (): int => (int) $valor('SELECT COUNT(*) FROM log_user WHERE id_user = ?', [$idGerente]);

    /* ---------- Estoque ---------- */
    $insumos = new InsumoService($db, $autor, $idRest);
    $r = $insumos->salvar(null, ['nome' => 'Pão', 'unidade' => 'un', 'estoque_atual' => '10', 'estoque_minimo' => '5', 'custo_unitario' => '1,20', 'ativo' => '1']);
    $exigir($r->ok && $r->id > 0, 'Cadastrar insumo: ' . $r->mensagem . json_encode($r->erros));
    $idPao = (int) $r->id;
    $r = $insumos->salvar(null, ['nome' => 'Carne', 'unidade' => 'kg', 'estoque_atual' => '0,160', 'estoque_minimo' => '1', 'ativo' => '1']);
    $idCarne = (int) $r->id;
    $exigir(!$insumos->salvar(null, ['nome' => 'pão', 'unidade' => 'un'])->ok, 'Nome de insumo repetido (sem diferenciar maiusculas).');
    $exigir(!$insumos->salvar(null, ['nome' => 'Ovo', 'unidade' => 'un', 'estoque_atual' => '1,5'])->ok, 'Unidade nao aceita fracao.');

    $produtos = new ProdutoService($db, $autor, $idRest);
    $r = $produtos->salvar(null, [
        'nome' => 'X-Teste', 'preco' => '24,90', 'categoria' => ProdutoService::CATEGORIA_NOVA, 'nova_categoria' => 'Lanches',
        'controle' => 'estoque', 'disponivel' => '1', 'ficha_insumo' => [(string) $idPao, (string) $idCarne], 'ficha_quantidade' => ['1', '0,080'],
    ]);
    $exigir($r->ok, 'Cadastrar produto com ficha: ' . json_encode($r->erros));
    $idX = (int) $r->id;
    $exigir($valor('SELECT preco FROM produtos WHERE id = ?', [$idX]) === '24.90', 'Preco gravado como DECIMAL.');
    $exigir((int) $valor('SELECT COUNT(*) FROM produto_insumos WHERE id_produto = ?', [$idX]) === 2, 'Ficha tecnica com 2 itens.');
    $disp = (new DisponibilidadeProdutos($db))->verificar($idRest, [$idX]);
    $exigir($disp[$idX]['disponivel'] && $disp[$idX]['porcoes'] === 2, 'Porcoes possiveis = 2 (carne 0,160 / 0,080).');

    // Insumo de outro restaurante na ficha: recusado
    $db->prepare("INSERT INTO insumos (id_restaurante, nome, unidade) VALUES (?, 'Alheio', 'un')")->execute([$idOutro]);
    $idAlheio = (int) $valor('SELECT id FROM insumos WHERE id_restaurante = ?', [$idOutro]);
    $r = $produtos->salvar($idX, ['nome' => 'X-Teste', 'preco' => '24,90', 'controle' => 'estoque', 'ficha_insumo' => [(string) $idAlheio], 'ficha_quantidade' => ['1']]);
    $exigir(!$r->ok && isset($r->erros['ficha']), 'Ficha nao pode usar insumo de outro restaurante.');
    $exigir(!(new InsumoService($db, $autor, $idRest))->excluir($idAlheio)->ok, 'Nao exclui insumo de outro restaurante.');
    $exigir((int) $valor('SELECT COUNT(*) FROM insumos WHERE id = ?', [$idAlheio]) === 1, 'Insumo alheio continua existindo.');

    $exigir(!$insumos->salvar($idCarne, ['nome' => 'Carne', 'unidade' => 'un', 'estoque_atual' => '1', 'ativo' => '1'])->ok, 'Unidade travada quando esta em ficha.');
    $exigir($insumos->repor($idCarne, '0,840')->ok && $valor('SELECT estoque_atual FROM insumos WHERE id = ?', [$idCarne]) === '1.000', 'Repor soma ao estoque.');
    $exigir($produtos->alternarDisponivel($idX)->ok && (int) $valor('SELECT disponivel FROM produtos WHERE id = ?', [$idX]) === 0, 'Pausar produto.');

    $categorias = new CategoriaService($db, $autor, $idRest);
    $idLanches = (int) $valor('SELECT id FROM categorias WHERE id_restaurante = ? AND nome = ?', [$idRest, 'Lanches']);
    $exigir($categorias->salvar($idLanches, 'Lanches da casa')->ok, 'Renomear categoria.');
    $exigir($categorias->excluir($idLanches)->ok && $valor('SELECT id_categoria FROM produtos WHERE id = ?', [$idX]) === null, 'Excluir categoria deixa produto sem categoria.');
    $exigir($produtos->excluir($idX)->ok && (int) $valor('SELECT COUNT(*) FROM produto_insumos WHERE id_produto = ?', [$idX]) === 0, 'Excluir produto apaga a ficha.');
    $exigir($insumos->excluir($idCarne)->ok, 'Insumo fora de fichas pode ser arquivado.');
    $exigir((new InsumoRepository($db))->buscar($idRest, $idCarne) === null &&
        $valor('SELECT excluido_em FROM insumos WHERE id = ?', [$idCarne]) !== null,
        'Insumo excluido deixa de aparecer sem perder a linha historica.');
    $exigir($insumos->salvar(null, ['nome' => 'Carne', 'unidade' => 'kg', 'estoque_atual' => '2'])->ok,
        'Nome de insumo excluido fica liberado para novo cadastro.');

    // Insumo usado em produto ativo: exclusao logica preserva a ficha e torna
    // a venda indisponivel sem apagar dados para possivel devolucao de estoque.
    $r = $insumos->salvar(null, ['nome' => 'CI Coentro', 'unidade' => 'kg', 'estoque_atual' => '5', 'ativo' => '1']);
    $exigir($r->ok, 'Criar insumo de regressao.');
    $idCoentro = (int) $r->id;
    $r = $produtos->salvar(null, ['nome' => 'CI Produto com Coentro', 'preco' => '11,00', 'controle' => 'estoque', 'disponivel' => '1',
        'ficha_insumo' => [(string) $idCoentro], 'ficha_quantidade' => ['0,100']]);
    $exigir($r->ok, 'Criar produto com insumo em uso.');
    $idUsaCoentro = (int) $r->id;
    $exigir((new DisponibilidadeProdutos($db))->verificar($idRest, [$idUsaCoentro])[$idUsaCoentro]['disponivel'] === true,
        'Produto inicialmente a venda.');
    $exigir($insumos->excluir($idCoentro)->ok, 'Excluir insumo usado deve arquivar.');
    $exigir((int) $valor('SELECT ativo FROM insumos WHERE id = ?', [$idCoentro]) === 0
        && (int) $valor('SELECT COUNT(*) FROM produto_insumos WHERE id_produto = ? AND id_insumo = ?', [$idUsaCoentro, $idCoentro]) === 1,
        'Excluir insumo conserva ficha tecnica e desativa ingrediente.');
    $exigir((new InsumoRepository($db))->buscar($idRest, $idCoentro) === null,
        'Insumo arquivado nao aparece no estoque.');
    $disp = (new DisponibilidadeProdutos($db))->verificar($idRest, [$idUsaCoentro]);
    $exigir(!$disp[$idUsaCoentro]['disponivel'] && $disp[$idUsaCoentro]['motivo'] === DisponibilidadeProdutos::MOTIVO_INSUMO_INATIVO,
        'Produto com insumo excluido fica indisponivel.');
    $exigir(!$insumos->excluir($idCoentro)->ok && !$insumos->repor($idCoentro, '1')->ok,
        'Insumo excluido nao pode ser editado ou reposto.');
    $exigir(!(new InsumoService($db, $autor, $idOutro))->excluir($idCoentro)->ok,
        'Insumo de outro restaurante nao pode ser excluido.');

    // Regressao: produto sem pedidos mas vinculado ao iFood nao pode ser apagado
    // (a FK de ifood_itens aponta para ele). Arquivar preserva a ligacao e libera o nome.
    $r = $produtos->salvar(null, ['nome' => 'CI Vinculado iFood', 'preco' => '12,00', 'controle' => 'livre', 'disponivel' => '1']);
    $exigir($r->ok, 'Criar produto vinculado ao iFood.');
    $idIfood = (int) $r->id;
    $db->prepare("INSERT INTO ifood_itens (id_restaurante, ifood_item_id, nome, id_produto, vinculo, visto_em)
        VALUES (?, ?, ?, ?, 'manual', NOW())")->execute([$idRest, 'ci-item-' . $idIfood, 'CI Vinculado iFood', $idIfood]);
    $exigir(!(new ProdutoService($db, $autor, $idOutro))->excluir($idIfood)->ok, 'Outro restaurante nao exclui produto vinculado.');
    $exigir($produtos->excluir($idIfood)->ok, 'Produto vinculado ao iFood deve ser arquivado sem erro de FK.');
    $exigir((int) $valor('SELECT ativo FROM produtos WHERE id = ?', [$idIfood]) === 0,
        'Produto vinculado arquivado e mantido no banco.');
    $exigir((int) $valor('SELECT id_produto FROM ifood_itens WHERE ifood_item_id = ?', ['ci-item-' . $idIfood]) === $idIfood,
        'Ligacao ao iFood continua disponivel para pausar o item.');
    $exigir(!$produtos->excluir($idIfood)->ok, 'Exclusao repetida de produto arquivado recusada.');
    $r = $produtos->salvar(null, ['nome' => 'CI Vinculado iFood', 'preco' => '13,00', 'controle' => 'livre', 'disponivel' => '1']);
    $exigir($r->ok, 'Nome do produto arquivado pode ser reutilizado.');

    // Publicacao do Deliveryzim: ainda que nao haja pedido, o vinculo deve sobreviver
    // ate o cron remover o produto remoto pela API de catalogo.
    $r = $produtos->salvar(null, ['nome' => 'CI Publicado iFood', 'preco' => '15,00', 'controle' => 'livre', 'disponivel' => '1']);
    $exigir($r->ok, 'Criar produto publicado no iFood.');
    $idPub = (int) $r->id;
    (new \Cardapim\Repositories\Gerencia\IfoodVinculoRepository($db, $idRest))->salvar('item', $idPub, [
        'ifood_id' => 'ci-publicado-' . $idPub, 'ifood_produto_id' => 'ci-produto-' . $idPub, 'status' => 'publicado',
    ]);
    $rCategoria = (new CategoriaService($db, $autor, $idRest))->salvar(null, 'CI Categoria Publicada');
    $exigir($rCategoria->ok, 'Criar categoria do produto publicado.');
    $idCategoriaPub = (int) $rCategoria->id;
    $db->prepare('UPDATE produtos SET id_categoria = ? WHERE id = ? AND id_restaurante = ?')
        ->execute([$idCategoriaPub, $idPub, $idRest]);
    $repoCategorias = new \Cardapim\Repositories\Estoque\CategoriaRepository($db);
    $contarCategoria = static function () use ($repoCategorias, $idRest, $idCategoriaPub): int {
        foreach ($repoCategorias->listar($idRest) as $categoria) {
            if ((int) $categoria['id'] === $idCategoriaPub) {
                return (int) $categoria['produtos'];
            }
        }
        return -1;
    };
    $exigir($contarCategoria() === 1, 'Categoria conta produto ativo.');
    $exigir($produtos->excluir($idPub)->ok && (int) $valor('SELECT ativo FROM produtos WHERE id = ?', [$idPub]) === 0,
        'Produto publicado deve ser arquivado ate a exclusao remota.');
    $exigir((int) $valor("SELECT COUNT(*) FROM ifood_catalogo_vinculos WHERE id_restaurante = ? AND tipo = 'item' AND id_local = ?", [$idRest, $idPub]) === 1,
        'Vinculo para exclusao remota continua gravado.');
    $exigir($contarCategoria() === 0, 'Categoria nao conta produto arquivado.');
    $exigir(!str_contains((new CategoriaService($db, $autor, $idRest))->excluir($idCategoriaPub)->mensagem, 'produto(s) ficaram sem categoria'),
        'Excluir categoria nao considera produto arquivado como ativo.');

    /* ---------- Equipe ---------- */
    $equipe = new EquipeService($db, $autor, $idRest);
    $r = $equipe->salvar(null, ['cargo' => 'garcom', 'nome' => 'Ana', 'sobrenome' => 'Teste', 'usuario' => 'ci_int_garcom', 'senha' => 'Mesa-sol-1234', 'whatsapp' => '(11) 91234-5678']);
    $exigir($r->ok, 'Criar garcom: ' . json_encode($r->erros));
    $idAna = (int) $r->id;
    $exigir(str_ends_with((string) $valor('SELECT email FROM `user` WHERE id = ?', [$idAna]), EquipeService::DOMINIO_SEM_EMAIL), 'E-mail tecnico quando nao informado.');
    $exigir(!$equipe->salvar($idAna, ['cargo' => 'gerente', 'nome' => 'Ana', 'sobrenome' => 'Teste', 'usuario' => 'ci_int_garcom'])->ok, 'Gerente nao promove ninguem a gerente.');
    $exigir(!(new EquipeService($db, $autor, $idOutro))->excluir($idAna)->ok, 'Outro restaurante nao exclui a pessoa.');
    $exigir($equipe->alternarAcesso($idAna)->ok && (int) $valor('SELECT ativo FROM `user` WHERE id = ?', [$idAna]) === 0, 'Bloquear acesso.');
    $exigir($equipe->redefinirSenha($idAna, 'Nova-senha-1234')->ok && password_verify('Nova-senha-1234', (string) $valor('SELECT senha_hash FROM `user` WHERE id = ?', [$idAna])), 'Redefinir senha.');
    // Com historico (log_user) a conta e anonimizada; sem historico, apagada.
    $db->prepare("INSERT INTO log_user (titulo, descricao, id_user, ip) VALUES ('Login', 'teste', ?, '127.0.0.1')")->execute([$idAna]);
    $exigir($equipe->excluir($idAna)->ok, 'Excluir garcom com historico.');
    $anon = $db->query('SELECT usuario, cpf, whatsapp, ativo, excluido_em FROM `user` WHERE id = ' . $idAna)->fetch(PDO::FETCH_ASSOC);
    $exigir($anon !== false && $anon['usuario'] === 'removido_' . $idAna && $anon['whatsapp'] === null && (int) $anon['ativo'] === 0 && $anon['excluido_em'] !== null, 'Conta anonimizada e bloqueada.');
    $exigir((new EquipeRepository($db))->buscar($idRest, $idAna) === null, 'Conta excluida some da lista.');
    $r = $equipe->salvar(null, ['cargo' => 'motoqueiro', 'nome' => 'Beto', 'sobrenome' => 'Teste', 'usuario' => 'ci_int_moto', 'senha' => 'Moto-1234-abc']);
    $exigir($r->ok && $equipe->excluir((int) $r->id)->ok && (int) $valor('SELECT COUNT(*) FROM `user` WHERE id = ?', [(int) $r->id]) === 0, 'Conta sem historico e apagada.');

    /* ---------- Perfil e horarios ---------- */
    $perfil = new PerfilService($db, $autor, $idRest);
    $r = $perfil->salvarDados(['nome' => 'CI Gerencia Integracao', 'telefone' => '(11) 3333-4444', 'cep' => '01310-100', 'logradouro' => 'Av. Paulista', 'numero' => '1000', 'bairro' => 'Bela Vista', 'cidade' => 'São Paulo', 'estado' => 'sp']);
    $exigir($r->ok && $valor('SELECT estado FROM restaurantes WHERE id = ?', [$idRest]) === 'SP', 'Endereco salvo respeitando CHECKs (UF maiuscula, CEP 8 digitos): ' . json_encode($r->erros));
    $exigir(!$perfil->salvarDados(['nome' => 'X', 'telefone' => '1', 'cep' => '1'])->ok, 'Perfil invalido recusado.');
    $r = $perfil->salvarHorarios(['modo' => 'semana', 'dia' => [
        1 => ['aberto' => '1', 'abre1' => '11:00', 'fecha1' => '15:00', 'turno2' => '1', 'abre2' => '18:00', 'fecha2' => '23:00'],
        5 => ['aberto' => '1', 'abre1' => '18:00', 'fecha1' => '02:00'],
    ]]);
    $exigir($r->ok && count((new PerfilRepository($db))->horarios($idRest)) === 3, 'Horarios gravados (TIME).');
    $exigir($perfil->salvarHorarios(['modo' => 'sempre'])->ok && (int) $valor('SELECT sempre_aberto FROM restaurantes WHERE id = ?', [$idRest]) === 1, 'Sempre aberto.');
    $exigir(count((new PerfilRepository($db))->horarios($idRest)) === 3, 'Sempre aberto preserva os horarios para depois.');
    $repoPerfil = new PerfilRepository($db);
    $originais = $repoPerfil->horarios($idRest);
    $agora = new DateTimeImmutable('2026-11-13 19:00');
    $publico = new \Cardapim\Services\Cardapio\CardapioPublico($db);
    $exigir($perfil->definirFechamento('2026-11-13', '1', $agora)->ok, 'Fecha data.');
    $exigir(!$publico->informacoes($idRest, $agora)['aberto'], 'Cardapio fechado.');
    $exigir($repoPerfil->horarios($idRest) === $originais, 'Horarios preservados.');
    $exigir($repoPerfil->fechamentos($idOutro) === [], 'Outro restaurante intacto.');
    $exigir($perfil->definirFechamento('2026-11-14', '1', $agora)->ok, 'Fecha segunda data.');
    $exigir($perfil->definirFechamento('2026-11-13', '0', $agora)->ok, 'Reabre primeira data.');
    $exigir($publico->informacoes($idRest, $agora)['aberto'], 'Cardapio reaberto.');
    $exigir($repoPerfil->fechamentos($idRest) === ['2026-11-14'], 'Segunda data preservada.');
    $exigir($perfil->salvarHorarios(['modo' => 'sempre'])->ok && $repoPerfil->fechamentos($idRest) === ['2026-11-14'], 'Salvar horario preserva excecao.');
    $exigir(!$perfil->definirFechamento('2026-11-12', '1', $agora)->ok, 'Data passada recusada.');
    $exigir($publico->informacoes($idRest, new DateTimeImmutable('2026-11-15 19:00'))['aberto'], 'Expiracao sem cron.');

    /* ---------- Assinatura ---------- */
    $assinatura = new AssinaturaService($db, $autor, $idRest);
    $exigir($assinatura->vigente() === null, 'Sem assinatura no inicio.');
    $exigir($assinatura->assinar((int) $planos[0])->ok && $assinatura->vigente()['situacao'] === 'ativa', 'Assinar.');
    $exigir(!$assinatura->assinar((int) $planos[0])->ok, 'Nao assina o mesmo plano duas vezes.');
    $exigir($assinatura->assinar((int) $planos[1])->ok, 'Trocar de plano.');
    $exigir((int) $valor("SELECT COUNT(*) FROM assinaturas WHERE id_restaurante = ? AND status IN ('ativa','cancelada')", [$idRest]) === 1, 'Uma assinatura vigente por vez.');
    $exigir((int) $valor('SELECT plano_id FROM restaurantes WHERE id = ?', [$idRest]) === (int) $planos[1], 'Plano do restaurante atualizado.');
    $exigir($assinatura->cancelar()->ok && $assinatura->vigente()['situacao'] === 'cancelada', 'Cancelar.');
    $exigir($assinatura->reativar()->ok && $assinatura->vigente()['situacao'] === 'ativa', 'Desfazer cancelamento.');
    $assinatura->cancelar();
    $depois = new AssinaturaService($db, $autor, $idRest, new DateTimeImmutable('+2 years'));
    $exigir($depois->vigente() === null && (int) $valor("SELECT COUNT(*) FROM assinaturas WHERE id_restaurante = ? AND status = 'encerrada'", [$idRest]) === 2, 'Cancelada vencida vira encerrada.');

    /* ---------- Verificacao em duas etapas ---------- */
    $doisFatores = new DoisFatores($db);
    $contas = new ContaRepository($db);
    $segredo = Totp::gerarSegredo();
    $conta = $contas->buscar($idGerente);
    $exigir($doisFatores->ativarApp($conta, $segredo, '000000') === null || Totp::verificar($segredo, '000000') !== null, 'Codigo errado nao ativa.');
    $codigos = $doisFatores->ativarApp($conta, $segredo, Totp::codigo($segredo));
    $exigir(is_array($codigos) && count($codigos) === 10, 'Ativar app gera 10 codigos.');
    $conta = $contas->buscar($idGerente);
    $exigir($conta['dois_fatores'] === 'app' && str_starts_with((string) $conta['dois_fatores_segredo'], 'v1:') && Cripto::decifrar((string) $conta['dois_fatores_segredo']) === $segredo, 'Segredo cifrado no banco.');
    $exigir($doisFatores->verificarLogin($conta, Totp::codigo($segredo)) === 'app', 'Login com app.');
    $exigir($doisFatores->verificarLogin($conta, $codigos[0]) === 'recuperacao' && $doisFatores->verificarLogin($conta, $codigos[0]) === null, 'Codigo de recuperacao vale uma vez.');
    $exigir($contas->codigosRestantes($idGerente) === 9, 'Restam 9 codigos.');
    $doisFatores->desativar($idGerente);
    $exigir($contas->buscar($idGerente)['dois_fatores'] === 'desativado' && $contas->codigosRestantes($idGerente) === 0, 'Desativar apaga segredo e codigos.');

    $exigir($logs() >= 20, 'Cada acao gerou log de auditoria (' . $logs() . ').');
    $exigir((int) $valor("SELECT COUNT(*) FROM log_user WHERE id_user = ? AND descricao LIKE '%[CI Gerencia Integracao]%'", [$idGerente]) >= 20, 'Logs com o nome do restaurante.');
    $exigir((int) $valor("SELECT COUNT(*) FROM log_user WHERE id_user = ? AND (descricao LIKE '%Mesa-sol%' OR descricao LIKE '%Nova-senha%')", [$idGerente]) === 0, 'Senhas nunca vao para o log.');

    echo "OK: integracao das abas da gerencia (estoque, equipe, perfil, horarios, assinatura e 2FA).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FALHOU (gerencia): ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    $limpar();
    exit(1);
}
$limpar();
