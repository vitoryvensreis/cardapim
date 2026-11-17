# Complementos (adicionais, bordas, molhos...)

Escolhas que o cliente faz em um produto vendido por unidade. Cadastro em `Gerência > Estoque > Complementos`.
Produto sem grupo de complementos funciona exatamente como antes.

## Conceitos

- **Grupo** (`complemento_grupos`): nome, mínimo e máximo de escolhas. Mínimo 0 = opcional; 1 ou mais = obrigatório.
  Reutilizável: o mesmo grupo ("Bordas") pode estar em vários produtos (`produto_complemento_grupos`, com ordem).
- **Opção** (`complementos`): nome, preço a mais (0 = grátis), foto, código, pausa (`disponivel`) e ficha técnica
  (`complemento_insumos`, quanto de cada insumo vai em 1 unidade da opção). Nunca apagada: arquivada (`ativo = 0`).
- **No pedido** (`pedido_item_complementos`): cópia de grupo, nome, preço, quantidade e total (por 1 unidade do item).
  `pedido_itens.preco_unitario` = preço base + complementos; `pedido_itens.complementos_texto` guarda o resumo legível
  ("Borda: Catupiry · Adicionais: 2x Bacon"). Subtotal, financeiro, relatórios, cupons e fidelidade não mudaram.

## Regra única (`Modules/Catalog/Complementos`)

Usada por cardápio online, sacola, pedido, caixa e garçom (o preço vem sempre do banco; o navegador manda só ids):

- `carregar()`: grupos de cada produto com as opções e se cada opção pode ser vendida (pausa + insumo para 1 unidade).
- `conferir()`: escolha existe no produto, está disponível, respeita mínimo/máximo; devolve preço, texto e consumo.
- `bloqueia()`: grupo obrigatório sem opções vendáveis deixa o produto indisponível
  (`DisponibilidadeProdutos::MOTIVO_SEM_COMPLEMENTO`), o que vale também para o caixa, o garçom e o iFood.
- A baixa de estoque inclui as fichas das opções na mesma transação (`BaixaEstoque::baixarMilesimos(..., $extras)`);
  cancelar devolve tudo (consumo guardado no pedido ou no item da comanda).
- No navegador, `public/assets/js/complementos.js` repete a regra só para mostrar preço e avisos na hora.

## Telas

- Gerência > Estoque > Complementos: lista dos grupos com as opções (pausar/vender com um toque) e formulário do grupo
  (opções com preço, foto, código, descrição e insumo gasto). Produto: "Grupos de complementos" com ordem e foto do produto.
- Cardápio online: janela do produto com as escolhas (escolha única quando o máximo é 1), preço ao vivo, aviso de
  escolha obrigatória; sacola mostra as escolhas e reconfere preços e disponibilidade ao abrir.
- Caixa (comanda) e garçom: chips de escolha na folha do produto; a conta mostra as escolhas.
- Cozinha, delivery, entregador, acompanhamento do cliente e impressão mostram as escolhas destacadas.

## Rotas (gerência, CSRF, só gerente)

| Método | Rota | O que faz |
| --- | --- | --- |
| GET | `/{slug}/gerencia/estoque/complementos` | Lista dos grupos |
| GET/POST | `/{slug}/gerencia/estoque/complementos/novo` · `/estoque/complementos` | Novo grupo |
| GET/POST | `/{slug}/gerencia/estoque/complementos/{id}` | Editar grupo e opções (multipart: `opcao_foto[i]`) |
| POST | `/{slug}/gerencia/estoque/complementos/{id}/excluir` | Arquivar grupo (sai de todos os produtos) |
| POST | `/{slug}/gerencia/estoque/complementos/opcoes/{id}/pausar` | Pausar / voltar a vender uma opção |
| GET | `/midia/cardapio/{arquivo}` | Fotos de produtos e complementos (`ImagemCardapio`, até 5 MB, mínimo 300 x 275, regravadas) |

API: itens da sacola e do pedido aceitam `complementos: [{id, quantidade}]`; `POST /api/v1/restaurantes/{slug}/sacola`
devolve `grupos` (dados públicos, sem ficha nem código) e `GET /api/v1/pedidos/{id}` devolve `itens[].complementos`.

## Banco e deploy

Migração `20261117100000_create_complementos.php` (só tabelas e colunas novas; inclui `ifood_catalogo_vinculos` do
cardápio publicado no iFood). Aplicar com `deploy/scripts/apply-complementos.sh` (backup verificado antes) e conferir com
`php8.4 database/verify-complementos.php --verify`. Enquanto não for aplicada, `Shared/Esquema` esconde a função e
tudo funciona como antes (a aba mostra "Os complementos estão sendo liberados").

## Testes

`tests/unit/complementos.php`, `tests/integration/complementos.php` e `tests/http/complementos.sh` (CI).
