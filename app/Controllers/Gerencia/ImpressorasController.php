<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\AssetUrl;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Services\Gerencia\Resultado;
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
        ]);
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
        return Response::json(['ok' => true] + $res)->comCabecalhos(['Cache-Control' => 'no-store']);
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
        // Pontos antigos (pedido novo do delivery, garcom ao lancar) ficam vazios: sairam da tela.
        $repo = new ImpressoraRepository($this->db);
        $repo->salvarPontos($this->restaurante['id'], $config);
        if (($_POST['cancelamento_enviado'] ?? '') === '1') {
            $repo->salvarCancelamento($this->restaurante['id'], ($_POST['cancelamento'] ?? '') === '1');
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
