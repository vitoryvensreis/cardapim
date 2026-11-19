# Venda por peso (produtos por quilo ou por litro)

O gerente escolhe, produto por produto, como ele é vendido (`produtos.unidade_venda`):

| Valor | Na tela | Preço | Quantidade |
| --- | --- | --- | --- |
| `un` (padrão) | Por unidade | por unidade | inteira, de 1 a 99 |
| `kg` | Por quilo (kg) | do quilo | 3 casas = grama (0,846 kg = 846 g), até 99,999 kg |
| `l` | Por litro (L) | do litro | 3 casas = mililitro (0,350 L = 350 ml), até 99,999 L |

Sem agente vinculado, a equipe continua digitando o peso. A integração com balança utiliza a leitura estável mais recente do Deliveryzim Agent quando ele está online e autorizado; o operador **confere e confirma** o peso antes de lançar.

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


## Balança no caixa (integração preparada no PR #104)

O agente Windows lê a porta COM local (RS-232 ou USB/serial, quando o driver instala COM),
identifica peso estável e, enquanto vinculado a um restaurante, publica o último peso
por `POST /api/v1/desktop/pesagem` usando o token de dispositivo. O backend usa
`id_restaurante` do próprio dispositivo — nunca do payload da requisição.

O navegador, já autenticado como caixa/gerente, obtém apenas as balanças do seu
restaurante em `GET /api/v1/desktop/balancas`. Leituras instáveis, revogadas ou
com mais de **3 segundos** são descartadas. Só existe uma leitura recente por
computador (sem histórico de clientes), preservada em
`desktop_agent_pesagens`, com FK composta para o restaurante.

No Agent Windows, o gerente configura porta, protocolo e unidade. Depois de entrar no
Deliveryzim no navegador do mesmo computador, clica em **Abrir Deliveryzim** no Agent.
O Agent gera um ticket assinado com validade de 2 minutos, vinculado a um único
dispositivo e restaurante. O navegador autenticado valida o ticket e recebe um
cookie assinado HttpOnly, SameSite=Lax, válido por 90 dias. O servidor só devolve
como `selecionada` a balança vinculada àquele navegador; outro equipamento,
mesmo do mesmo restaurante, não é escolhido automaticamente. O vínculo é
verificado em cada consulta e deixa de funcionar após revogação.

No modal de produto em `kg`, com balança vinculada, aparece somente
**Usar balança** (marcado por padrão), o peso e o estado da leitura.
O campo recebe o peso automaticamente enquanto a balança está estável e a
leitura tem menos de 3 segundos. Não há escolha de equipamento, nem botão
**Usar peso da balança**. O operador confere o visor e clica em **Lançar**.
Ao desmarcar o checkbox, o campo fica livre para digitação manual.
Sem vínculo ou com produto por litro/unidade, não há checkbox de balança.
Leituras vencidas, nulas ou instáveis limpam o peso no modo automático;
nenhuma leitura isolada lança um produto. O endpoint de lançamento existente continua validando produto,
quantidade mínima, estoque e cálculo financeiro no PHP. Produtos por litro
(`l`) e unidades (`un`) **não** recebem leitura de balança em kg.

**Limitações de segurança e homologação:** somente modelos cujo protocolo
serial esteja implementado; verificar no equipamento real, respeitar tara/zero
na balança e exigências metrológicas pertinentes. O token do agente permanece
protegido por DPAPI; não existe servidor HTTP local aberto pelo agente ou
acesso direto do navegador ao hardware. A venda manual permanece disponível
caso a balança esteja sem conexão.

**Publicação:** esta atualização de UX e pareamento não adiciona migration. Ela depende das tabelas de dispositivos e pesagens existentes. Após deploy, é necessário obter o EXE Windows atualizado e realizar o vínculo com o navegador pelo botão **Abrir Deliveryzim** no Agent. O funcionamento com uma balança física exige teste no equipamento homologado.
