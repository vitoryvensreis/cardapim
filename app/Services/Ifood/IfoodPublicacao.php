<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Modules\Catalog\ImagemCardapio;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Repositories\Gerencia\IfoodVinculoRepository;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Publica o cardapio do restaurante no iFood (o Deliveryzim e a fonte): categoria, item com foto e preco,
 * grupos de complementos e complementos com foto e preco. Depois de publicado, mantem igual:
 * - preco do item e do complemento mudam SO por PATCH (/items/price, /options/price);
 * - pausa do complemento por PATCH (/options/status); a do item segue pelo estoque (IfoodCatalogo);
 * - nome, descricao, foto e grupos mudam pelo cadastro do item (PUT /items), com o preco do ultimo envio.
 * Tudo que falha fica com o motivo na tela e e tentado de novo pela rotina bin/ifood.php (espera crescente).
 */
final class IfoodPublicacao
{
    private readonly IfoodVinculoRepository $vinculos;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante)
    {
        $this->vinculos = new IfoodVinculoRepository($db, $idRestaurante);
    }

    public static function disponivel(PDO $db): bool
    {
        return Esquema::tem($db, 'complementos');
    }

    /**
     * Produtos por categoria com a situacao no iFood, para a tela.
     * @return list<array{nome:string, produtos:list<array<string,mixed>>}>
     */
    public function painel(): array
    {
        $produtos = $this->produtos([]);
        $vinc = $this->vinculos->todos('item');
        $grupos = Complementos::carregar($this->db, $this->idRestaurante, array_keys($produtos));
        $categorias = [];
        foreach ($produtos as $id => $p) {
            $chave = $p['categoria'] ?? 'Sem categoria';
            $v = $vinc[$id] ?? null;
            $categorias[$chave]['nome'] = $chave;
            $categorias[$chave]['produtos'][] = [
                'id' => $id, 'nome' => $p['nome'], 'preco' => Numero::reais($p['preco']), 'imagem' => $p['imagem_path'],
                'por_unidade' => $p['unidade_venda'] === 'un',
                'grupos' => array_map(static fn (array $g): string => $g['nome'] . ' (' . count($g['opcoes']) . ')', $grupos[$id] ?? []),
                'situacao' => $v === null ? 'nao' : (string) $v['status'],
                'erro' => $v['ultimo_erro'] ?? null,
                'publicado_em' => $v['publicado_em'] ?? null,
            ];
        }
        return array_values($categorias);
    }

    /**
     * Publica (ou atualiza) os produtos escolhidos agora.
     * @param list<int> $ids
     * @return array{publicados:int, erros:list<string>}
     */
    public function publicar(IfoodConta $conta, array $ids): array
    {
        $saida = ['publicados' => 0, 'erros' => []];
        $produtos = $this->produtos($ids);
        if ($produtos === []) {
            return $saida;
        }
        $token = $conta->token();
        foreach ($produtos as $id => $p) {
            if ($p['unidade_venda'] !== 'un') {
                $saida['erros'][] = $p['nome'] . ': produto vendido por quilo ou litro ainda não pode ser publicado no iFood por aqui.';
                continue;
            }
            try {
                $this->publicarProduto($conta, $token, $p, true);
                $saida['publicados']++;
            } catch (IntegracaoErro $erro) {
                $this->vinculos->falhou('item', $id, $erro->getMessage());
                $saida['erros'][] = $p['nome'] . ': ' . $erro->getMessage();
                if ($erro->getCode() === 401 || $erro->getCode() === 429) {
                    break;
                }
            }
        }
        return $saida;
    }

    /**
     * Mantem o iFood igual ao cadastro daqui: reenvia o cadastro do que mudou e manda preco/pausa por PATCH.
     * @return array{enviados:int, erros:int, ultimo_erro:?string}
     */
    public function sincronizar(IfoodConta $conta): array
    {
        $resumo = ['enviados' => 0, 'erros' => 0, 'ultimo_erro' => null];
        $ids = $this->vinculos->itensParaConferir();
        if ($ids === []) {
            return $resumo;
        }
        $produtos = $this->produtos($ids);
        $token = null;
        foreach ($produtos as $id => $p) {
            try {
                $token ??= $conta->token();
                $resumo['enviados'] += $this->publicarProduto($conta, $token, $p, false);
            } catch (IntegracaoErro $erro) {
                $this->vinculos->falhou('item', $id, $erro->getMessage());
                $resumo['erros']++;
                $resumo['ultimo_erro'] = $erro->getMessage();
                if ($erro->getCode() === 401 || $erro->getCode() === 429 || $erro->getCode() === 0) {
                    break;
                }
            }
        }
        return $resumo;
    }

    /**
     * Envia um produto. $forcar = reenvia o cadastro mesmo sem mudanca (botao "Publicar").
     * @param array<string,mixed> $p
     * @return int chamadas feitas ao iFood (0 = nada mudou)
     */
    private function publicarProduto(IfoodConta $conta, string $token, array $p, bool $forcar): int
    {
        $api = $conta->api();
        $loja = (string) $conta->loja();
        $id = (int) $p['id'];
        $chamadas = 0;
        $grupos = $p['unidade_venda'] === 'un' ? (Complementos::carregar($this->db, $this->idRestaurante, [$id])[$id] ?? []) : [];
        $grupos = array_values(array_filter($grupos, static fn (array $g): bool => $g['opcoes'] !== []));
        $disp = (new DisponibilidadeProdutos($this->db))->verificar($this->idRestaurante, [$id])[$id] ?? ['disponivel' => false];

        // Ids no iFood: criados aqui (UUID) e guardados antes do envio, para nunca duplicar o item.
        $item = $this->vinculos->buscar('item', $id);
        if ($item === null) {
            $this->vinculos->salvar('item', $id, ['ifood_id' => IfoodApi::uuid(), 'ifood_produto_id' => IfoodApi::uuid(), 'status' => 'pendente']);
            $item = (array) $this->vinculos->buscar('item', $id);
        }
        $vGrupos = $this->vinculos->todos('grupo');
        $vOpcoes = $this->vinculos->todos('complemento');
        foreach ($grupos as $g) {
            if (!isset($vGrupos[$g['id']])) {
                $this->vinculos->salvar('grupo', $g['id'], ['ifood_id' => IfoodApi::uuid(), 'status' => 'pendente']);
            }
            foreach ($g['opcoes'] as $o) {
                if (!isset($vOpcoes[$o['id']])) {
                    $this->vinculos->salvar('complemento', $o['id'], ['ifood_id' => IfoodApi::uuid(), 'ifood_produto_id' => IfoodApi::uuid(), 'status' => 'pendente']);
                }
            }
        }
        $vGrupos = $this->vinculos->todos('grupo');
        $vOpcoes = $this->vinculos->todos('complemento');

        $idCategoria = $this->categoria($conta, $token, $p);
        $precoAtual = round((float) $p['preco'], 2);
        $statusAtual = $disp['disponivel'] ? 'AVAILABLE' : 'UNAVAILABLE';
        $codigoItem = $p['codigo'] !== null && $p['codigo'] !== '' ? (string) $p['codigo'] : 'DZ-P' . $id;

        $hash = sha1((string) json_encode([
            $p['nome'], (string) ($p['descricao'] ?? ''), $p['imagem_path'], $idCategoria, $codigoItem,
            array_map(static fn (array $g): array => [$g['id'], $g['nome'], $g['minimo'], $g['maximo'],
                array_map(static fn (array $o): array => [$o['id'], $o['nome'], (string) ($o['descricao'] ?? ''), $o['imagem_path'], $o['codigo']], $g['opcoes'])], $grupos),
        ]));

        if ($forcar || $item['hash_cadastro'] !== $hash || $item['status'] !== 'publicado') {
            // Fotos: so envia de novo quando mudou aqui.
            $imagemItem = $this->imagem($api, $token, $loja, 'item', $id, $p['imagem_path'], $item);
            $chamadas += $imagemItem['enviou'] ? 1 : 0;
            $produtosIfood = [];
            $gruposIfood = [];
            $opcoesIfood = [];
            $refsGrupos = [];
            $precoPut = $item['enviado_preco'] !== null && $item['status'] === 'publicado' ? round((float) $item['enviado_preco'], 2) : $precoAtual;
            foreach ($grupos as $g) {
                $vg = $vGrupos[$g['id']];
                $idsOpcoes = [];
                foreach ($g['opcoes'] as $ordem => $o) {
                    $vo = $vOpcoes[$o['id']];
                    $img = $this->imagem($api, $token, $loja, 'complemento', $o['id'], $o['imagem_path'], $vo);
                    $chamadas += $img['enviou'] ? 1 : 0;
                    $publicada = $vo['status'] === 'publicado';
                    $produtosIfood[] = array_filter([
                        'id' => $vo['ifood_produto_id'], 'name' => $o['nome'], 'description' => (string) ($o['descricao'] ?? ''),
                        'externalCode' => $o['codigo'] ?? ('DZ-C' . $o['id']), 'imagePath' => $img['caminho'],
                    ], static fn ($v): bool => $v !== null);
                    $opcoesIfood[] = [
                        'id' => $vo['ifood_id'], 'productId' => $vo['ifood_produto_id'], 'index' => $ordem,
                        'status' => $publicada && $vo['enviado_status'] !== null ? $vo['enviado_status'] : ($o['vendavel'] ? 'AVAILABLE' : 'UNAVAILABLE'),
                        'price' => self::preco($publicada && $vo['enviado_preco'] !== null ? (float) $vo['enviado_preco'] : (float) $o['preco']),
                        'externalCode' => $o['codigo'] ?? ('DZ-C' . $o['id']),
                    ];
                    $idsOpcoes[] = $vo['ifood_id'];
                }
                $gruposIfood[] = ['id' => $vg['ifood_id'], 'name' => $g['nome'], 'status' => 'AVAILABLE', 'externalCode' => 'DZ-G' . $g['id'],
                    'optionGroupType' => 'DEFAULT', 'optionIds' => $idsOpcoes];
                $refsGrupos[] = ['id' => $vg['ifood_id'], 'min' => $g['minimo'], 'max' => $g['maximo']];
            }
            array_unshift($produtosIfood, array_filter([
                'id' => $item['ifood_produto_id'], 'name' => $p['nome'], 'description' => (string) ($p['descricao'] ?? ''),
                'externalCode' => $codigoItem, 'imagePath' => $imagemItem['caminho'], 'optionGroups' => $refsGrupos,
            ], static fn ($v): bool => $v !== null));
            $api->salvarItem($token, $loja, [
                'item' => [
                    'id' => $item['ifood_id'], 'type' => 'DEFAULT', 'categoryId' => $idCategoria, 'productId' => $item['ifood_produto_id'],
                    'status' => $item['status'] === 'publicado' && $item['enviado_status'] !== null ? $item['enviado_status'] : $statusAtual,
                    'price' => self::preco($precoPut), 'externalCode' => $codigoItem, 'index' => (int) ($p['ordem'] ?? 0),
                ],
                'products' => $produtosIfood,
                'optionGroups' => $gruposIfood,
                'options' => $opcoesIfood,
            ]);
            $chamadas++;
            $agora = date('Y-m-d H:i:s');
            $this->vinculos->salvar('item', $id, ['hash_cadastro' => $hash, 'status' => 'publicado', 'ultimo_erro' => null, 'tentativas' => 0, 'proxima_em' => null,
                'publicado_em' => $agora, 'enviado_preco' => number_format($precoPut, 2, '.', ''),
                'enviado_status' => $item['status'] === 'publicado' && $item['enviado_status'] !== null ? $item['enviado_status'] : $statusAtual]);
            foreach ($grupos as $g) {
                $this->vinculos->salvar('grupo', $g['id'], ['status' => 'publicado', 'publicado_em' => $agora, 'ultimo_erro' => null]);
                foreach ($g['opcoes'] as $i => $o) {
                    $vo = $vOpcoes[$o['id']];
                    $enviada = $opcoesIfood[array_search($vo['ifood_id'], array_column($opcoesIfood, 'id'), true)];
                    $this->vinculos->salvar('complemento', $o['id'], ['status' => 'publicado', 'publicado_em' => $agora, 'ultimo_erro' => null,
                        'enviado_preco' => number_format((float) $enviada['price']['value'], 2, '.', ''), 'enviado_status' => $enviada['status']]);
                }
            }
            $this->ligarAoEstoque($item, $p, $codigoItem, $precoAtual, $statusAtual);
            $item = (array) $this->vinculos->buscar('item', $id);
            $vOpcoes = $this->vinculos->todos('complemento');
        }

        // Preco do item: so por PATCH.
        if ($item['enviado_preco'] === null || round((float) $item['enviado_preco'], 2) !== $precoAtual) {
            $api->precoItem($token, $loja, (string) $item['ifood_id'], $precoAtual);
            $this->vinculos->salvar('item', $id, ['enviado_preco' => number_format($precoAtual, 2, '.', '')]);
            $chamadas++;
        }
        // Complementos: preco e pausa so por PATCH.
        foreach ($grupos as $g) {
            foreach ($g['opcoes'] as $o) {
                $vo = $vOpcoes[$o['id']] ?? null;
                if ($vo === null || $vo['status'] !== 'publicado') {
                    continue;
                }
                $preco = round((float) $o['preco'], 2);
                if ($vo['enviado_preco'] === null || round((float) $vo['enviado_preco'], 2) !== $preco) {
                    $api->precoComplemento($token, $loja, (string) $vo['ifood_id'], $preco);
                    $this->vinculos->salvar('complemento', $o['id'], ['enviado_preco' => number_format($preco, 2, '.', '')]);
                    $chamadas++;
                }
                $status = $o['vendavel'] ? 'AVAILABLE' : 'UNAVAILABLE';
                if ($vo['enviado_status'] !== $status) {
                    $api->statusComplemento($token, $loja, (string) $vo['ifood_id'], $status === 'AVAILABLE');
                    $this->vinculos->salvar('complemento', $o['id'], ['enviado_status' => $status]);
                    $chamadas++;
                }
            }
        }
        return $chamadas;
    }

    /** Categoria do produto no iFood (cria na primeira vez). Produto sem categoria vai para "Outros". */
    private function categoria(IfoodConta $conta, string $token, array $p): string
    {
        $idCat = $p['id_categoria'] !== null ? (int) $p['id_categoria'] : 0;
        $v = $this->vinculos->buscar('categoria', $idCat);
        if ($v !== null && $v['ifood_id'] !== '') {
            return (string) $v['ifood_id'];
        }
        $loja = (string) $conta->loja();
        $catalogos = $conta->api()->catalogos($token, $loja);
        if ($catalogos === []) {
            throw new IntegracaoErro('A loja não tem cardápio no iFood. Crie o cardápio no Portal do Parceiro e tente de novo.');
        }
        $idIfood = $conta->api()->criarCategoria($token, $loja, $catalogos[0], $p['categoria'] ?? 'Outros', 'DZ-CAT' . $idCat, (int) ($p['categoria_ordem'] ?? 0));
        $this->vinculos->salvar('categoria', $idCat, ['ifood_id' => $idIfood, 'status' => 'publicado', 'publicado_em' => date('Y-m-d H:i:s')]);
        return $idIfood;
    }

    /**
     * Foto no iFood: reaproveita a ja enviada; envia de novo so quando a foto daqui mudou.
     * @param array<string,mixed> $vinculo
     * @return array{caminho:?string, enviou:bool}
     */
    private function imagem(IfoodApi $api, string $token, string $loja, string $tipo, int $idLocal, ?string $local, array $vinculo): array
    {
        if ($local === null || $local === '') {
            return ['caminho' => null, 'enviou' => false];
        }
        if ($vinculo['imagem_enviada'] === $local && (string) $vinculo['ifood_imagem'] !== '') {
            return ['caminho' => (string) $vinculo['ifood_imagem'], 'enviou' => false];
        }
        $jpeg = ImagemCardapio::jpeg($local);
        if ($jpeg === null) {
            return ['caminho' => null, 'enviou' => false]; // arquivo sumiu: publica sem foto
        }
        $caminho = $api->enviarImagem($token, $loja, $jpeg);
        $this->vinculos->salvar($tipo, $idLocal, ['ifood_imagem' => $caminho, 'imagem_enviada' => $local]);
        return ['caminho' => $caminho, 'enviou' => true];
    }

    /** O item publicado entra no "Cardapio do iFood" ligado ao produto: pedidos baixam o estoque e a disponibilidade segue o estoque. */
    private function ligarAoEstoque(array $item, array $p, string $codigo, float $preco, string $status): void
    {
        $catalogo = new IfoodCatalogo($this->db, $this->idRestaurante);
        $catalogo->guardar((string) $item['ifood_id'], (string) $p['nome'], $codigo, (string) $item['ifood_produto_id'], $p['categoria'] ?? null, $preco, $status);
        $q = $this->db->prepare('SELECT id, id_produto FROM ifood_itens WHERE id_restaurante = ? AND ifood_item_id = ?');
        $q->execute([$this->idRestaurante, (string) $item['ifood_id']]);
        $linha = $q->fetch(PDO::FETCH_ASSOC);
        if ($linha !== false && (int) ($linha['id_produto'] ?? 0) !== (int) $p['id']) {
            $catalogo->ligar((int) $linha['id'], (int) $p['id']);
        }
    }

    /**
     * Produtos ativos (todos ou os ids pedidos), com a categoria.
     * @param list<int> $ids
     * @return array<int, array<string,mixed>>
     */
    private function produtos(array $ids): array
    {
        $sql = 'SELECT p.id, p.nome, p.descricao, p.preco, p.codigo, p.imagem_path, p.unidade_venda, p.ordem, p.id_categoria, c.nome AS categoria, c.ordem AS categoria_ordem
            FROM produtos p LEFT JOIN categorias c ON c.id = p.id_categoria AND c.id_restaurante = p.id_restaurante
            WHERE p.id_restaurante = ? AND p.ativo = 1';
        $params = [$this->idRestaurante];
        if ($ids !== []) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $sql .= ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        $q = $this->db->prepare($sql . ' ORDER BY c.ordem IS NULL, c.ordem, c.nome, p.ordem, p.nome');
        $q->execute($params);
        $saida = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $saida[(int) $p['id']] = $p;
        }
        return $saida;
    }

    /** @return array{value:float, originalValue:float} */
    private static function preco(float $valor): array
    {
        return ['value' => round($valor, 2), 'originalValue' => round($valor, 2)];
    }
}
