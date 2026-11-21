<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
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
        ]);
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
        (new ImpressoraRepository($this->db))->salvarPontos($this->restaurante['id'], $config);
        Auditoria::registrar($this->usuario, 'Impressoras salvas', 'Configuração das impressoras do Agent atualizada.');
        return $this->voltar($volta, 'sucesso', 'Impressoras salvas.');
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
