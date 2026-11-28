<?php

declare(strict_types=1);

namespace Cardapim\Shared;

use PDO;

/**
 * Confere se uma parte nova do banco ja foi aplicada (migracao com backup pode ser aplicada depois do deploy do codigo).
 * Enquanto nao estiver, a funcao nova fica escondida e tudo funciona como antes, sem erro.
 * Uma consulta por recurso por requisicao (cache em memoria).
 */
final class Esquema
{
    /** recurso => tabela/coluna que so existe depois da migracao */
    private const RECURSOS = [
        'fechamento_temporario' => ['restaurantes', 'fechamentos_temporarios'],
        'complementos' => ['pedido_itens', 'complementos_texto'],
        // Estoque contado no proprio produto (revenda): insumo proprio ligado ao produto.
        'estoque_produto' => ['insumos', 'id_produto_proprio'],
        // Fila de impressao pelo Agent (tabela impressoes + coluna criada no fim da mesma migracao).
        'impressoes' => ['desktop_agent_devices', 'impressao_em'],
        // Varias impressoras por restaurante e pontos de impressao (coluna criada no fim da migracao).
        'impressoras' => ['impressoes', 'id_impressora'],
        // Impressao por produto / vias de producao (coluna criada no fim da migracao).
        'impressao_produto' => ['impressoes', 'lote'],
        // Combos (itens separados com o preco do combo dividido); coluna criada no fim da migracao.
        'combos' => ['pedido_itens', 'combo_nome'],
        // Limite de escolhas de cada grupo dentro do combo.
        'combo_grupos' => ['combo_grupos', 'maximo'],
        // Produto: nao imprime, so presencial e impressao de cancelamento (coluna criada no fim da migracao).
        'produto_opcoes' => ['produtos', 'vende_delivery'],
        // Perfil garcom: permissao de fechar a conta.
        'garcom_fechar' => ['restaurante_garcom_permissoes', 'fechar_conta'],
        // Quantidade por opcao de complemento e liga/desliga do aviso de cancelamento (tabela criada no fim da migracao).
        'complemento_qtd' => ['restaurante_impressao_config', 'cancelamento'],
        // Cupom em imagem: papel, logo e espaco antes do corte de cada impressora (coluna criada no fim da migracao).
        'impressora_papel' => ['impressoras', 'margem_corte'],
        // Aparencia do cupom por restaurante e papel informado pelo Agent (tabela criada no fim da migracao).
        'cupom_aparencia' => ['restaurante_cupom_aparencia', 'tamanho'],
        // Margem lateral por impressora, nome de quem atendeu e resumo de valores no delivery aceito.
        'cupom_opcoes' => ['restaurante_impressao_config', 'delivery_com_preco'],
        // Endereco e telefone do cliente na via do delivery aceito (telefone criado por ultimo).
        'cupom_contato' => ['restaurante_impressao_config', 'delivery_com_telefone'],
        // Taxa opcional por forma de pagamento no cardapio online (tabela criada no fim da migracao).
        'taxas_pagamento' => ['restaurante_taxas_pagamento', 'valor'],
        // Taxa por forma de pagamento em cada pagamento das contas (mesa, balcao, garcom, Pix da mesa).
        'taxas_contas' => ['pedido_pagamentos', 'taxa_rotulo'],
        // Pix pela chave do restaurante (BR Code estatico), um Pix por vez (pix_whatsapp criada por ultimo).
        'pix_chave' => ['restaurante_entrega_pagamento', 'pix_whatsapp'],
        // Numero do dia do pedido (so visual, zera quando a loja fecha); numero_dia criada por ultimo.
        'numero_dia' => ['pedidos', 'numero_dia'],
        // Popup do cardapio online (banner 1:1 ou texto).
        'popup_cardapio' => ['restaurante_popup', 'versao'],
        // Produto especial Meio a Meio (sabores com fracao do estoque).
        'meio_a_meio' => ['produto_meio_sabores', 'id_sabor'],
        // Avisos da plataforma para as lojas (pop-up do gerente); exibicoes criada por ultimo.
        'avisos_loja' => ['avisos_loja_exibicoes', 'clicou_em'],
        'fiscal_restaurante' => ['restaurante_fiscal', 'dados'],
        'fiscal_produtos' => ['fiscal_produtos', 'dados'],
        'fiscal_visibilidade' => ['fiscal_visibilidade', 'visivel'],
        'fiscal_liberacoes' => ['fiscal_liberacoes', 'permitido'],
        'fiscal_catalogos' => ['fiscal_catalogos', 'dados'],
        'fiscal_composicoes' => ['fiscal_composicoes', 'dados'],
        'fiscal_documentos' => ['fiscal_documentos', 'xml_arquivo'],
        'fiscal_producao' => ['fiscal_liberacoes', 'producao'],
    ];

    /** @var array<string,bool> */
    private static array $cache = [];

    public static function tem(PDO $db, string $recurso): bool
    {
        if (isset(self::$cache[$recurso])) {
            return self::$cache[$recurso];
        }
        [$tabela, $coluna] = self::RECURSOS[$recurso] ?? throw new \InvalidArgumentException('Recurso desconhecido.');
        try {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                $q->execute([$tabela, $coluna]);
                $existe = (int) $q->fetchColumn() === 1;
            } else {
                $existe = false;
                foreach ($db->query('PRAGMA table_info(' . $tabela . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $existe = $existe || ($c['name'] ?? '') === $coluna;
                }
            }
        } catch (\Throwable) {
            $existe = false;
        }
        return self::$cache[$recurso] = $existe;
    }

    /** Para testes e depois de aplicar a migracao no mesmo processo. */
    public static function esquecer(): void
    {
        self::$cache = [];
    }
}
