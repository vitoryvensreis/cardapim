# Venda por peso (produtos por quilo ou por litro)

O gerente escolhe, produto por produto, como ele é vendido (`produtos.unidade_venda`):

| Valor | Na tela | Preço | Quantidade |
| --- | --- | --- | --- |
| `un` (padrão) | Por unidade | por unidade | inteira, de 1 a 99 |
| `kg` | Por quilo (kg) | do quilo | 3 casas = grama (0,846 kg = 846 g), até 99,999 kg |
| `l` | Por litro (L) | do litro | 3 casas = mililitro (0,350 L = 350 ml), até 99,999 L |

Sem integração com balança: a equipe digita o peso.

## Regras

- **Conta:** valor = preço × quantidade, arredondado ao centavo com meio centavo para cima. Exemplo:
  0,846 kg × R$ 59,90 = R$ 50,6754, cobrado **R$ 50,68**. Mesma conta no PHP (`QuantidadeVenda::totalCentavos`, em
  centavos × milésimos ÷ 1000), no navegador e no banco (`CHECK total = ROUND(preco_unitario * quantidade, 2)`).
- **Venda mínima** (`produtos.venda_minima`, opcional, só por quilo/litro): o caixa e o garçom não lançam menos que
  isso; o produto fica "Acabou" quando o estoque não dá mais a venda mínima.
- **Porções do cardápio online** (`produto_porcoes`, até 6 por produto): o cliente escolhe uma delas (e quantas).
  Produto por peso **sem porção** não aparece no cardápio online: vende só no caixa e no garçom. O pedido só aceita
  porção cadastrada (porção que sumiu = 409 e a sacola tira a linha).
- **Ficha técnica:** vale para 1 unidade de venda (1 un, 1 kg ou 1 L). A baixa é proporcional ao vendido e
  arredondada ao milésimo do insumo (`BaixaEstoque::baixarMilesimos`); consumo que arredonda para zero não baixa.
  A devolução no cancelamento usa o consumo guardado, então volta exatamente o que saiu.
- **Item do pedido** (`pedido_itens`): `quantidade DECIMAL(10,3)` e `unidade` copiada na hora da venda, como o nome e o
  preço. Por unidade continua inteiro (CHECK). Pedidos antigos ficaram com `un`.

## Onde aparece

- **Gerência > Estoque > Produto:** "Como você vende" (unidade, quilo ou litro), "Preço do quilo/litro", quantidade
  mínima e as porções (com o preço de cada uma calculado na hora). A lista mostra "R$ 59,90/kg".
- **Caixa (comanda):** produto por peso abre o campo "Peso (kg)" com a máscara `peso` (`forms.js`): digita só os
  números do visor da balança, 8 4 6 → 0,846, e o botão mostra "Lançar 846 g · R$ 50,68".
- **Garçom:** no cardápio da comanda, produto por peso tem um campo de peso no lugar do + / −; conta como 1 item.
- **Cardápio online:** janela do produto com as porções ("250 g · R$ 14,98"); sacola e finalizar com o peso.
- **Textos:** `QuantidadeVenda::texto` dá "2x", "846 g", "1,250 kg", "350 ml", "1,500 L" (comanda, conta impressa,
  delivery do caixa, motoqueiro, acompanhamento, histórico do cliente). Itens da mesa e do histórico contam o produto
  pesado como 1 item. "Mais vendidos" mostra "12,400 kg em 9 vendas".
- **Estoque/disponibilidade:** `DisponibilidadeProdutos` devolve `restante` (milésimos) e `unidade`; telas mostram
  "Restam 846 g". O iFood recebe só ligado/desligado para produto por peso (o estoque do iFood conta unidades).
- **iFood:** item do iFood com unidade KG/G/L/ML ligado a um produto por quilo/litro entra com o peso real e baixa o
  estoque proporcional (quando o total do iFood fecha ao centavo com algum preço do quilo; senão entra como 1 unidade).

## API do cardápio online

Linha da sacola / pedido / prévia de desconto: `{id, quantidade, porcao?, observacao?}`. `porcao` em milésimos
(500 = 500 g), obrigatória no produto por peso e proibida no produto por unidade. `POST .../sacola` devolve também
`unidade` e `porcoes` de cada produto. `GET /api/v1/pedidos/{id}` traz `itens[].quantidade_texto` ("500 g").

## Banco e publicação

Migration `20261114100000_venda_por_peso` (2 etapas: schema com o gatilho `[cardapim-peso]`, backup e
`verify-peso.php`; depois o código). Testes: `tests/unit/peso.php`, `tests/integration/peso.php` (arredondamento do PHP
igual ao `ROUND` do MySQL), `tests/http/peso.sh`.
