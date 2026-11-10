# Cardapio, insumos e ficha tecnica

Migrations `20261110040000` a `20261110040300`. Toda linha pertence a um restaurante (`id_restaurante`).

## Tabelas

### `categorias`
`id`, `id_restaurante`, `nome` (unico no restaurante), `descricao`, `ordem`, `ativo`, `criado_em`, `atualizado_em`.

### `insumos`
| Coluna | Tipo | Regra |
| --- | --- | --- |
| `nome` | VARCHAR(120) | unico no restaurante |
| `unidade` | ENUM(`un`, `kg`, `l`) | definida no insumo; a ficha tecnica usa sempre esta unidade |
| `estoque_atual` | DECIMAL(14,3) | `1` = 1 un / 1 kg / 1 L; `0.080` = 80 g ou 80 ml; **nunca negativo** (CHECK) |
| `estoque_minimo` | DECIMAL(14,3) | alerta de reposicao |
| `custo_unitario` | DECIMAL(14,4) NULL | custo por un, kg ou L (base para custo e margem do produto) |
| `ativo` | TINYINT | insumo inativo deixa indisponiveis os produtos que o usam |

### `produtos`
| Coluna | Tipo | Regra |
| --- | --- | --- |
| `id_categoria` | FK opcional | sempre do mesmo restaurante (FK composta) |
| `nome` | VARCHAR(150) | unico no restaurante |
| `preco` | DECIMAL(10,2) | >= 0 |
| `codigo` | VARCHAR(40) NULL | codigo interno, unico no restaurante |
| `imagem_path` | VARCHAR(500) NULL | foto |
| `controla_estoque` | TINYINT | 1 = baixa pela ficha tecnica; 0 = sem controle (sempre disponivel se ativo) |
| `disponivel` | TINYINT | pausa manual |
| `ativo` | TINYINT | arquivar; produtos nao devem ser apagados |
| `ordem` | SMALLINT | ordem no cardapio |

### `produto_insumos` (ficha tecnica)
`id_restaurante`, `id_produto`, `id_insumo`, `quantidade DECIMAL(12,3) > 0` = quanto do insumo vai em **1** unidade do produto. Um insumo aparece uma vez por produto.

## Isolamento entre restaurantes no banco

`categorias`, `insumos` e `produtos` tem `UNIQUE (id, id_restaurante)`. As FKs compostas
`produtos (id_categoria, id_restaurante)`, `produto_insumos (id_produto, id_restaurante)` e
`produto_insumos (id_insumo, id_restaurante)` fazem o proprio MySQL recusar um produto do
restaurante A usando categoria ou insumo do restaurante B.

## Regra de venda (decisao do produto)

- **Sem estoque negativo.** Se faltar qualquer insumo da ficha, o produto fica **indisponivel** e a venda e recusada.
- Produto indisponivel tambem quando `ativo = 0` (arquivado), `disponivel = 0` (pausado) ou insumo da ficha inativo.
- Produto com `controla_estoque = 1` e sem ficha tecnica nao tem o que faltar.
- Fator de correcao e historico de movimentacoes **nao** fazem parte desta etapa (decisao registrada em 10/11/2026).

## Codigo (`app/Modules/Inventory`)

- `DisponibilidadeProdutos::verificar($idRestaurante, $ids)`: disponivel, motivo (`arquivado`, `pausado`, `sem_insumo`, `insumo_inativo`), quantas unidades ainda podem ser vendidas (`porcoes`) e quais insumos faltam.
- `BaixaEstoque::baixar($idRestaurante, [id_produto => qtd])`: soma a ficha de todos os itens, trava os insumos (`FOR UPDATE`, sempre na mesma ordem), recusa tudo com `EstoqueInsuficiente` se faltar algo e baixa em uma unica transacao. Retorna o consumo por insumo.
- `BaixaEstoque::devolver($idRestaurante, $consumo)`: devolve o consumo (cancelamento).
- `Quantidade`: contas em milesimos inteiros (sem erro de ponto flutuante). `UnidadeMedida`: `un`, `kg`, `l` e formatacao (`0,08 kg`, `0,5 L`).

**Integracao futura com pedidos:** como nao ha historico de movimentacoes, o modulo de pedidos deve
guardar no item do pedido o consumo retornado por `baixar()` e marcar o item como baixado. Isso permite
devolver o estoque no cancelamento e impede baixar o mesmo pedido duas vezes.

## Aplicacao em producao

`deploy/scripts/apply-cardapio-estoque.sh`, acionado uma unica vez pelo CI com `[cardapim-cardapio-estoque]`:
aceita somente estas quatro migrations pendentes, faz backup `mysqldump` validado, aplica e roda
`database/verify-cardapio-estoque.php`.
