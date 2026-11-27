# Combos

Um combo é um **produto comum** com grupos de complemento (os mesmos de sempre) e uma configuração a mais.
Ao ser lançado, vira **os itens separados**: cada um baixa o próprio estoque, sai na impressora da estação dele e
aparece nos relatórios. O preço do combo é dividido entre os itens.

## Cadastro (Estoque > produto > Combo)

- **Este produto é um combo** (só produto vendido por unidade).
- **Itens inclusos**: produtos que sempre vão, com quantidade (até 12). Já vêm no combo; o cliente não tira.
- **Máximo total de itens** (opcional): inclusos + escolhidos. Ex.: 4 com 2 inclusos = o cliente escolhe até 2.
- **Escolhas do cliente**: os grupos marcados em Complementos. Em cada grupo, **Escolha neste combo: Obrigatória ou
  Opcional** (padrão: opcional), **Pelo menos** (só quando obrigatória) e **No máximo**. Gravado como mínimo/máximo
  (opcional = mínimo 0; obrigatória = mínimo 1 ou mais). Esse limite vale no lugar do mínimo/máximo do grupo de complemento, que pode
  ser usado em outros produtos com outro limite (`combo_grupos`). Em cada opção:
  produto ligado (estoque e impressora do produto) e máximo da opção no combo (ex.: picanha no máximo 2).
  O valor a mais é o preço da opção no grupo. Opção sem produto ligado vira uma linha com o nome dela e baixa
  a ficha de insumos da opção.
- A tela mostra quanto os inclusos somam e o desconto sobre eles.

Banco: `combos` (produto é combo + máximo total), `combo_itens` (inclusos), `combo_opcoes` (produto ligado e máximo
por opção, vale em todos os combos que usam o grupo) e `pedido_itens.combo_lote` / `combo_nome` (linhas do mesmo combo).

## Regra única (`Modules/Catalog/Combo`)

- `conferir()`: inclusos com estoque, mínimo/máximo de cada grupo, máximo de cada opção e o máximo total.
- `montar()`: linhas separadas. O valor (preço do combo x quantidade + adicionais) é dividido proporcionalmente ao
  preço cheio de cada item, ao centavo (o arredondamento vai para os maiores restos). O adicional fica na linha da opção.
- Disponibilidade: o combo fica indisponível se faltar item incluso ou opções num grupo obrigatório.

## Onde funciona

- **Garçom e caixa**: escolhas com − e + (dá para pedir 2 do mesmo sabor), inclusos à mostra. A conta mostra o combo
  agrupado (nome e total, com os itens embaixo). **O combo não pode ser alterado: cancelar tira o combo inteiro**
  (e devolve o estoque de todos os itens).
- **Cardápio online**: a mesma janela de escolhas do produto, com − e +. O pedido grava os itens separados.
- **Impressão**: conta agrupada; as vias de produção saem por estação (cada item na impressora do produto dele).
- **iFood**: a comunicação e a publicação do cardápio não mudaram (o combo vai ao iFood como produto com grupos).
  O pedido que chega é desmembrado **só** quando é 1 unidade e todas as escolhas vêm com o código publicado pelo
  Cardapim (`DZ-C<id>`) ou o código da opção; o valor é o que o iFood cobrou, dividido entre os itens. Qualquer
  dúvida (escolha sem código, quantidade quebrada, personalização), o pedido entra como antes, numa linha só.
  O iFood não aceita limite por opção: o que o cliente escolheu lá entra assim mesmo. A publicação no iFood usa o limite
  do grupo de complemento (não o do combo): para o iFood mostrar o mesmo limite, use um grupo próprio do combo.

## Deploy e testes

Migration `20261121100000_create_combos.php`, `database/verify-combos.php`, `deploy/scripts/apply-combos.sh`
(backup; gatilho `[cardapim-combos]`). Antes da migração a seção não aparece e tudo funciona como antes.
Testes: `tests/unit/combos.php`, `tests/http/combos.sh`.

# Meio a Meio (produto especial)

Na ficha do produto, em **Produtos especiais**, o gerente liga o Meio a Meio (não pode ser combo ao mesmo tempo).
Cada tamanho é um produto Meio a Meio próprio (ex.: "Pizza Grande Meio a Meio"), com seus sabores.

- **Sabores**: todos os produtos ativos de uma categoria **ou** produtos escolhidos um a um. Só entram produtos por
  unidade, que não sejam combo nem outro Meio a Meio. O preço de cada sabor é o preço do próprio produto.
- **Quantidade**: mínimo e máximo de 1 a 4 (mínimo ≤ máximo). Mínimo = máximo obriga aquele número exato
  (ex.: 2 e 2 só sai com 2 sabores). Precisa haver pelo menos tantos sabores quanto o máximo.
- **Preço** (`MeioAMeio::preco`): `fracionado` = soma dos sabores ÷ quantidade (arredondado para o centavo mais
  próximo); `maior` = o sabor mais caro; `personalizado` = o preço do próprio produto Meio a Meio.
  O cardápio mostra "A partir de" o menor preço possível quando a regra não é personalizada.
- **Como funciona**: os sabores viram um grupo de escolhas virtual (`Complementos::carregar`; ids a partir de
  `MeioAMeio::OFFSET`, que não colidem com complementos de verdade). Assim cardápio online, caixa e garçom usam a
  mesma janela de escolhas. O pedido grava **uma linha** com o texto "Sabores: 1/2 X, 1/2 Y" e o preço da regra.
- **Estoque**: cada sabor baixa a sua fração da ficha técnica (2 sabores = metade de cada; 3 = um terço).
  Cancelar devolve o estoque como qualquer item.
- **iFood**: produto Meio a Meio **não é publicado** pelo Cardapim (o aviso aparece na ficha e na tela de publicação,
  e a publicação recusa). Monte a pizza no Portal do Parceiro do iFood. No pedido que chega, as escolhas são
  reconhecidas como sabores pelo código (`DZ-P<id>` ou o código do produto) ou pelo nome (sem o "1/2"), e cada sabor
  baixa a sua fração. Sem reconhecer, o item entra como antes.

Migration `20261201100000_meio_a_meio.php` (tabelas `produto_meio_a_meio` e `produto_meio_sabores`),
`database/verify-meio-a-meio.php`, `deploy/scripts/apply-meio-a-meio.sh` (backup; gatilho `[cardapim-meio-a-meio]`).
Antes da migração a seção não aparece. Testes: `tests/unit/meio-a-meio.php`, `tests/http/meio-a-meio.sh`.
