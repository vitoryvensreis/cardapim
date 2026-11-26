<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\AssetUrl;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Repositories\Desktop\CupomAparenciaRepository;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Impressao\AparenciaCupom;
use Cardapim\Services\Impressao\CupomImagem;
use Cardapim\Services\Impressao\TesteImpressao;
use Cardapim\Services\Auditoria;
use Cardapim\Shared\Esquema;

/**
 * Gerencia > Integracoes > Impressoras. Duas regras:
 * - Impressao por produto: no aceite do delivery, no Lancar do garcom e no "Novos itens" do caixa, cada item sai na
 *   impressora marcada no produto; sem marcacao, na impressora padrao escolhida aqui (ponto "producao").
 * - Cupom completo: Conta (caixa), Comanda inteira (garcom) e Imprimir do pedido do delivery saem inteiros nas
 *   impressoras marcadas aqui (uma lista so, gravada nos pontos caixa_conta, garcom e caixa_delivery).
 * Mais o resumo do caixa (caixa_fechamento).
 */
final class ImpressorasController extends BaseGerencia
{
    /** Pontos que recebem a mesma lista do bloco "Cupom completo". */
    private const COMPLETO = ['caixa_conta', 'garcom', 'caixa_delivery'];

    public function inicio(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $ativo = Esquema::tem($this->db, 'impressoras');
        $porProduto = $ativo && Esquema::tem($this->db, 'impressao_produto');
        $repo = new ImpressoraRepository($this->db);
        $pontos = $ativo ? $repo->pontos($this->restaurante['id']) : [];
        return $this->tela('gerencia/integracoes/impressoras', 'integracoes', 'Impressoras', [
            'ativo' => $ativo,
            'fiscalImpressora' => $this->fiscalImpressora(),
            'porProduto' => $porProduto,
            'impressoras' => $ativo ? $repo->listar($this->restaurante['id']) : [],
            'produtosPorImpressora' => $porProduto ? $repo->produtosPorImpressora($this->restaurante['id']) : [],
            'padraoProducao' => $pontos['producao']['padrao'] ?? ($pontos['producao']['ids'][0] ?? null),
            'completo' => $pontos['caixa_conta'] ?? ['ids' => [], 'padrao' => null],
            'fechamento' => $pontos['caixa_fechamento'] ?? ['ids' => [], 'padrao' => null],
            'agentDownload' => IntegracoesController::AGENT_DOWNLOAD,
            // Testar impressao: produtos cadastrados (nome, categoria e as escolhas de complemento/combo).
            'produtosTeste' => $ativo ? $this->produtosTeste() : [],
            'logoCupom' => AssetUrl::asset('img/logo-preta.svg'),
            // Aviso de cancelamento: null = recurso ainda nao liberado.
            'cancelamentoAtivo' => Esquema::tem($this->db, 'complemento_qtd') ? $repo->cancelamentoAtivo($this->restaurante['id']) : null,
            // Papel informado pelo Agent e margem lateral de cada impressora (cupom em imagem).
            'formatos' => $ativo ? $this->formatos() : [],
            // Total no delivery aceito e nome de quem atendeu: null = recurso ainda nao liberado.
            'cupomOpcoes' => Esquema::tem($this->db, 'cupom_opcoes') ? $repo->opcoesCupom($this->restaurante['id']) : null,
            // Aparencia do cupom: null = recurso ainda nao liberado (migracao) ou servidor sem GD.
            'aparencia' => Esquema::tem($this->db, 'cupom_aparencia') && CupomImagem::disponivel()
                ? AparenciaCupom::doRestaurante($this->db, $this->restaurante['id']) : null,
            'aparenciaOpcoes' => AparenciaCupom::opcoes(),
            'aparenciaPadrao' => AparenciaCupom::PADRAO,
        ]);
    }

    private function fiscalImpressora(): array
    {
        try {
            return (new \Cardapim\Services\Gerencia\FiscalService($this->db, $this->usuario, $this->restaurante['id']))->impressora();
        } catch (\Throwable $e) {
            error_log('[Cardapim] Card fiscal indisponível (' . get_class($e) . ').');
            return ['habilitado' => false];
        }
    }

    public function fiscal(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) { return $r; }
        if (!\Cardapim\Services\Gerencia\FiscalAcesso::permitido($this->db,$this->restaurante['id'])) { return \Cardapim\Services\Auth\Guard::proibido($this->usuario,'Módulo indisponível para este restaurante.'); }
        $url = $this->url('integracoes/impressoras') . '#cupom-fiscal';
        if (!Esquema::tem($this->db, 'fiscal_restaurante')) {
            return $this->voltar($url, 'erro', 'Módulo fiscal indisponível.');
        }
        try {
            $s = new \Cardapim\Services\Gerencia\FiscalService($this->db, $this->usuario, $this->restaurante['id']);
            return $this->responder($s->salvarImpressora($this->post('impressora_fiscal')), $url);
        } catch (\Throwable $e) {
            error_log('[Cardapim] Falha no card fiscal (' . get_class($e) . ').');
            return $this->voltar($url, 'erro', 'Não foi possível salvar a impressora fiscal.');
        }
    }

    /** @return array<int,array{chave:string,id_dispositivo:int,papel:int,logo:bool,margem:int,informado:bool,lateral:?float}> */
    private function formatos(): array
    {
        return (new ImpressoraRepository($this->db))->formatos($this->restaurante['id'],
            Esquema::tem($this->db, 'impressora_papel'), null, Esquema::tem($this->db, 'cupom_aparencia'));
    }

    /**
     * POST: salva a aparencia do cupom (fonte, pesos e tamanho) ou volta ao padrao do Deliveryzim (acao=padrao).
     */
    public function aparencia(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $volta = $this->url('integracoes/impressoras') . '#aparencia-cupom';
        if (!Esquema::tem($this->db, 'cupom_aparencia')) {
            return $this->voltar($volta, 'erro', 'A aparência do cupom ainda está sendo ativada. Tente de novo em alguns minutos.');
        }
        $repo = new CupomAparenciaRepository($this->db);
        if (($_POST['acao'] ?? '') === 'padrao') {
            $repo->apagar($this->restaurante['id']);
            Auditoria::registrar($this->usuario, 'Aparência do cupom', 'Voltou ao padrão do sistema.');
            return $this->voltar($volta, 'sucesso', 'Cupom de volta ao padrão.');
        }
        $a = AparenciaCupom::validar($_POST);
        if (is_string($a)) {
            return $this->voltar($volta, 'erro', $a);
        }
        $repo->salvar($this->restaurante['id'], $a);
        Auditoria::registrar($this->usuario, 'Aparência do cupom', AparenciaCupom::FONTES[$a['fonte']]['nome'] . ', texto '
            . $a['peso_texto'] . ', destaques ' . $a['peso_destaque'] . ', letra ' . AparenciaCupom::TAMANHOS[$a['tamanho']][0] . '.');
        return $this->voltar($volta, 'sucesso', 'Aparência do cupom salva. Os próximos cupons já saem assim.');
    }

    /**
     * POST (JSON): previa em tempo real da aparencia escolhida (ainda sem salvar), no papel pedido (58 ou 80 mm).
     */
    public function previa(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return Response::json(['ok' => false, 'erro' => 'Sua sessão expirou. Atualize a página.'], 401);
        }
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            return Response::json(['ok' => false, 'erro' => 'Sua sessão expirou. Atualize a página e tente de novo.'], 401);
        }
        if (!CupomImagem::disponivel()) {
            return Response::json(['ok' => false, 'erro' => 'Prévia indisponível agora.'], 503);
        }
        $a = AparenciaCupom::validar($_POST);
        if (is_string($a)) {
            return Response::json(['ok' => false, 'erro' => $a], 422);
        }
        $papel = ($_POST['papel'] ?? '') === '58' ? 58 : 80;
        $png = CupomImagem::png(AparenciaCupom::exemplo($this->restaurante['nome']), $papel, CupomImagem::MARGEM_PADRAO, true, $a);
        return Response::json(['ok' => true, 'imagem' => 'data:image/png;base64,' . base64_encode($png), 'papel' => $papel])
            ->comCabecalhos(['Cache-Control' => 'no-store']);
    }

    /** @return list<array{id:int,nome:string,categoria:string,grupos:list<array<string,mixed>>,combo:?array<string,mixed>}> */
    private function produtosTeste(): array
    {
        $produtos = array_values(array_filter((new ProdutoRepository($this->db))->listar($this->restaurante['id']), static fn (array $p): bool => (int) $p['ativo'] === 1));
        $porUnidade = array_map(static fn (array $p): int => (int) $p['id'], array_filter($produtos, static fn (array $p): bool => ($p['unidade_venda'] ?? 'un') === 'un'));
        $escolhas = TesteImpressao::escolhasDosProdutos($this->db, $this->restaurante['id'], array_values($porUnidade));
        return array_map(static fn (array $p): array => [
            'id' => (int) $p['id'], 'nome' => (string) $p['nome'], 'categoria' => (string) ($p['categoria'] ?? 'Sem categoria'),
            'grupos' => $escolhas[(int) $p['id']]['grupos'] ?? [], 'combo' => $escolhas[(int) $p['id']]['combo'] ?? null,
        ], $produtos);
    }

    /**
     * POST (JSON): Testar impressao. Mostra o que sai em cada impressora com produtos cadastrados, sem gravar pedido;
     * com imprimir=1, manda os cupons para o Agent com TESTE no topo.
     */
    public function teste(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return Response::json(['ok' => false, 'erro' => 'Sua sessão expirou. Atualize a página.'], 401);
        }
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            return Response::json(['ok' => false, 'erro' => 'Sua sessão expirou. Atualize a página e tente de novo.'], 401);
        }
        if (!Esquema::tem($this->db, 'impressoras')) {
            return Response::json(['ok' => false, 'erro' => 'As impressoras ainda estão sendo ativadas.'], 409);
        }
        $pedidos = [];
        $ids = is_array($_POST['produto'] ?? null) ? $_POST['produto'] : [];
        $qtds = is_array($_POST['quantidade'] ?? null) ? $_POST['quantidade'] : [];
        $obs = is_array($_POST['observacao'] ?? null) ? $_POST['observacao'] : [];
        $comps = is_array($_POST['complementos'] ?? null) ? $_POST['complementos'] : [];
        foreach (array_slice($ids, 0, TesteImpressao::MAX_ITENS, true) as $k => $v) {
            $id = self::idPost($v);
            $q = is_string($qtds[$k] ?? null) && ctype_digit($qtds[$k]) ? (int) $qtds[$k] : 1;
            if ($id === null) {
                continue;
            }
            $escolhas = self::escolhas($comps[$k] ?? '');
            if ($escolhas === null) {
                return Response::json(['ok' => false, 'erro' => 'Complementos inválidos. Tire o produto e adicione de novo.'], 422);
            }
            $pedidos[] = ['id_produto' => $id, 'quantidade' => $q, 'observacao' => is_string($obs[$k] ?? null) ? mb_substr(trim($obs[$k]), 0, 140) : '',
                'complementos' => $escolhas];
        }
        $imprimir = ($_POST['imprimir'] ?? '') === '1';
        $res = (new TesteImpressao($this->db, $this->restaurante['id'], $this->restaurante['nome'], $this->usuario))
            ->executar((string) ($_POST['situacao'] ?? ''), $pedidos, self::idPost($_POST['impressora'] ?? null), $imprimir);
        if ($res instanceof Resultado) {
            return Response::json(['ok' => false, 'erro' => $res->mensagem], 422)->comCabecalhos(['Cache-Control' => 'no-store']);
        }
        if ($imprimir) {
            Auditoria::registrar($this->usuario, 'Teste de impressão', 'Cupons de teste enviados ao Agent.');
        }
        $res['cupons'] = $this->comImagem($res['cupons'] ?? []);
        return Response::json(['ok' => true] + $res)->comCabecalhos(['Cache-Control' => 'no-store']);
    }

    /**
     * Cupom desenhado como a impressora vai receber (Agent 0.9.0+): papel, logo e espaco antes do corte
     * informados pelo Agent para cada impressora. Sem GD no servidor, a tela desenha so o texto, como antes.
     * @param list<array<string,mixed>> $cupons
     * @return list<array<string,mixed>>
     */
    private function comImagem(array $cupons): array
    {
        if (!CupomImagem::disponivel()) {
            return $cupons;
        }
        $formatos = Esquema::tem($this->db, 'impressoras') ? $this->formatos() : [];
        $aparencia = AparenciaCupom::doRestaurante($this->db, $this->restaurante['id']);
        foreach ($cupons as &$c) {
            if (!is_array($c['blocos'] ?? null)) {
                continue;
            }
            $f = $formatos[(int) ($c['id'] ?? 0)] ?? ['papel' => 80, 'logo' => true, 'margem' => CupomImagem::MARGEM_PADRAO, 'informado' => false, 'lateral' => null];
            $c['imagem'] = 'data:image/png;base64,' . base64_encode(CupomImagem::png($c['blocos'], $f['papel'], $f['margem'], $f['logo'], $aparencia, $f['lateral'] ?? null));
            $c['papel'] = $f['papel'];
            $c['papel_informado'] = $f['informado'];
        }
        unset($c);
        return $cupons;
    }

    public function salvar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $volta = $this->url('integracoes/impressoras');
        if (!Esquema::tem($this->db, 'impressoras')) {
            return $this->voltar($volta, 'erro', 'As impressoras ainda estão sendo ativadas. Tente de novo em alguns minutos.');
        }
        $completo = ['ids' => self::ids($_POST['completo'] ?? null), 'padrao' => self::idPost($_POST['padrao_completo'] ?? null)];
        $config = array_fill_keys(self::COMPLETO, $completo);
        $config['caixa_fechamento'] = ['ids' => self::ids($_POST['fechamento'] ?? null), 'padrao' => self::idPost($_POST['padrao_fechamento'] ?? null)];
        $producao = self::idPost($_POST['producao'] ?? null);
        if ($producao !== null && Esquema::tem($this->db, 'impressao_produto')) {
            $config['producao'] = ['ids' => [$producao], 'padrao' => $producao];
        }
        $laterais = null;
        if (($_POST['laterais_enviado'] ?? '') === '1') {
            $laterais = self::laterais($_POST['lateral'] ?? null);
            if ($laterais === null) {
                return $this->voltar($volta, 'erro', 'Escolha a margem lateral entre 0 e 8 mm.');
            }
        }
        // Pontos antigos (pedido novo do delivery, garcom ao lancar) ficam vazios: sairam da tela.
        $repo = new ImpressoraRepository($this->db);
        $repo->salvarPontos($this->restaurante['id'], $config);
        if (($_POST['cancelamento_enviado'] ?? '') === '1') {
            $repo->salvarCancelamento($this->restaurante['id'], ($_POST['cancelamento'] ?? '') === '1');
        }
        if (($_POST['opcoes_enviado'] ?? '') === '1') {
            $atual = $repo->opcoesCupom($this->restaurante['id']);
            $repo->salvarOpcoesCupom($this->restaurante['id'], [
                'mostrar_atendente' => ($_POST['mostrar_atendente'] ?? '') === '1',
                // Sem a impressao por produto o campo nao aparece na tela: mantem o que estava gravado.
                'delivery_com_preco' => Esquema::tem($this->db, 'impressao_produto') ? ($_POST['delivery_com_preco'] ?? '') === '1' : $atual['delivery_com_preco'],
            ]);
        }
        if ($laterais !== null) {
            $repo->salvarLaterais($this->restaurante['id'], $laterais);
        }
        Auditoria::registrar($this->usuario, 'Impressoras salvas', 'Configuração das impressoras do Agent atualizada.');
        return $this->voltar($volta, 'sucesso', 'Impressoras salvas.');
    }

    /**
     * Escolhas de complemento de um item do teste: "id:quantidade,id:quantidade" (vazio = nenhuma).
     * @return list<array{id:int,quantidade:int}>|null null = formato invalido
     */
    private static function escolhas(mixed $texto): ?array
    {
        if (!is_string($texto) || strlen($texto) > 600) {
            return null;
        }
        $lista = [];
        foreach (array_filter(explode(',', trim($texto)), static fn (string $p): bool => $p !== '') as $parte) {
            if (preg_match('/\A(\d{1,18}):(\d{1,2})\z/', $parte, $m) !== 1) {
                return null;
            }
            $lista[] = ['id' => $m[1], 'quantidade' => $m[2]];
        }
        return Complementos::formato($lista);
    }

    /**
     * Margem lateral de cada impressora vinda da tela: id => mm (vazio = padrao). null = algum valor invalido.
     * @return array<int,?float>|null
     */
    private static function laterais(mixed $lista): ?array
    {
        $saida = [];
        foreach (is_array($lista) ? $lista : [] as $id => $valor) {
            $id = self::idPost((string) $id);
            if ($id === null || !is_string($valor)) {
                return null;
            }
            if ($valor === '') {
                $saida[$id] = null;
                continue;
            }
            $mm = ImpressoraRepository::lateral($valor);
            if ($mm === null) {
                return null;
            }
            $saida[$id] = $mm;
        }
        return $saida;
    }

    /** @return list<int> */
    private static function ids(mixed $lista): array
    {
        $ids = [];
        foreach (is_array($lista) ? $lista : [] as $v) {
            if (($id = self::idPost($v)) !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private static function idPost(mixed $v): ?int
    {
        return is_string($v) && ctype_digit($v) && strlen($v) <= 18 && (int) $v > 0 ? (int) $v : null;
    }
}
