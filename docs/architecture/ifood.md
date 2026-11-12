# iFood: pedidos, entregador e estoque sincronizado

Cada restaurante conecta a própria loja do iFood (aplicativo **distribuído** do Cardapim, ver
[integracoes.md](integracoes.md)). Depois de conectado:

- **Pedidos do iFood entram sozinhos** no Delivery do caixa (mesma fila do cardápio online), com o aviso de pedido novo
  (luz, tremida e som), itens com complementos e observações, cupons, endereço, telefone do iFood (0800 + código),
  CPF/CNPJ na nota, pedido agendado e código do pedido no iFood (`iFood #1234`).
- **Entregador do iFood**: etapa (a caminho da loja, chegou na loja, pegou o pedido, chegou no cliente), nome, telefone
  e veículo quando o iFood envia, e o **código de coleta** para conferir com o entregador. Pedido entregue pelo iFood
  não mostra "Quem vai levar?": sai sozinho quando o entregador pega.
- **O que a loja faz aqui vai para o iFood**: Aceitar → `confirm`; Pedido pronto → `readyToPickup` (obrigatório no iFood);
  Despachar (entrega da própria loja) → `dispatch`; Cancelar → `requestCancellation` com um motivo da lista do iFood.
  O pedido só é cancelado aqui (e o estoque volta) quando o iFood confirma; se o iFood recusar, aparece um aviso.
- **O que acontece no iFood vem para cá**: aceito, pronto, saiu, concluído e cancelado (inclusive pelo Gestor de
  Pedidos do iFood). Mudanças vindas do iFood nunca geram ação de volta (sem idas e voltas).
- **Estoque sincronizado**: a venda no iFood baixa o estoque dos produtos ligados (ficha técnica, igual ao cardápio
  online; se faltar, o pedido entra mesmo assim com aviso). A disponibilidade do produto (estoque da ficha técnica,
  pausado, arquivado) vai para o iFood: sem estoque fica indisponível, com estoque volta a vender. Quando o produto
  controla estoque, a quantidade também é enviada (se a loja não usar o controle de quantidade do iFood, só a
  disponibilidade é enviada).

## Telas

- `Gerência > Integrações > iFood`: situação da loja, pedidos do iFood hoje, última consulta ao iFood (alerta se a
  rotina parar), resumo do cardápio ligado e **opções**:
  - *Aceitar pedidos do iFood sozinho* (padrão desligado): entra em preparo e o iFood é avisado na hora.
  - *Atualizar a disponibilidade no iFood pelo estoque* (padrão ligado).
- `Gerência > Integrações > iFood > Cardápio do iFood` (`/integracoes/ifood/cardapio`): cada item do iFood com o produto
  daqui (ligação automática pelo **código PDV = código do produto** ou pelo mesmo nome; manual por lista), situação no
  iFood, estoque daqui, último envio e erros. Botões: *Buscar cardápio do iFood* e *Enviar disponibilidade agora*.
  Itens que chegam em pedidos e ainda não estão no cardápio buscado também aparecem para ligar.
  Item que chega com outro id (o iFood recria itens, e o gerador de pedidos de teste usa ids novos a cada pedido) usa a
  ligação de outro item com o mesmo código PDV ou o mesmo nome, desde que todos apontem para o mesmo produto, e já fica ligado.
- `Caixa > Delivery`: selo `iFood #código` na lista e na fila lateral; no pedido, avisos, entregador do iFood, código de
  coleta, pagamento ("Pago pelo app do iFood", "Cobrar na entrega: Dinheiro, troco para R$ 100,00", "O cliente paga ao
  entregador do iFood") e cancelamento com motivo do iFood.

## Rotas novas (gerência, CSRF, só gerente)

| Método | Rota | O que faz |
| --- | --- | --- |
| POST | `/{slug}/gerencia/integracoes/ifood/opcoes` | Salva as opções |
| GET | `/{slug}/gerencia/integracoes/ifood/cardapio` | Cardápio do iFood (`?filtro=soltos` ou `erros`) |
| POST | `/{slug}/gerencia/integracoes/ifood/cardapio/buscar` | Busca os itens no iFood e liga sozinho |
| POST | `/{slug}/gerencia/integracoes/ifood/cardapio/ligar` | Salva as ligações (`produto[id_item]=id_produto`) |
| POST | `/{slug}/gerencia/integracoes/ifood/cardapio/sincronizar` | Envia a disponibilidade de todos os itens ligados |

`POST /{slug}/caixa/delivery/{id}/cancelar` aceita `motivo_ifood` (código do iFood) para pedidos do iFood.

## Como funciona por dentro

- **Rotina `bin/ifood.php`** (cron a cada minuto, duas rodadas por execução = consulta a cada 30 s, como o iFood exige
  para manter a loja aberta; nunca mais rápido). Uma execução por vez (trava em arquivo). Sem `IFOOD_CLIENT_ID/SECRET`
  ela sai sem fazer nada. Para cada restaurante conectado (`Services/Ifood/RotinaIfood`):
  1. `GET /events/v1.0/events:polling` com `x-polling-merchants` = loja do restaurante;
  2. **guarda** cada aviso em `integracao_eventos` (sem repetir pelo id do aviso) e **só então** confirma ao iFood
     (`POST /events/v1.0/events/acknowledgment`, obrigatório);
  3. trata os guardados em ordem (`EventosIfood`); o que falhar é tratado de novo (até 10 vezes, `tentativas`/`erro`),
     mantendo a ordem de cada pedido;
  4. envia a fila `ifood_acoes` (`IfoodAcoes`): esperas de 30 s a 30 min; recusa definitiva (400/404/409/422) vira
     aviso no pedido;
  5. envia a disponibilidade dos itens ligados que mudou (`IfoodCatalogo::sincronizar`);
  6. grava `integracoes.verificado_em` (última consulta) ou o erro.
- **Pedido novo** (`PLACED`): `GET /order/v1.0/orders/{id}` uma única vez → `PedidoIfood::converter` (função pura) →
  `ImportarPedidoIfood` (transação: pedido, itens, baixa de estoque, linha do tempo, evento `pedido.novo`).
  `pedidos.ifood_id` é ÚNICO por restaurante: o mesmo pedido nunca entra duas vezes. Aviso de pedido que o sistema não
  tem (PLACED perdido) busca e grava o pedido antes de aplicar o aviso.
- **Valores**: centavos; `total` = `orderAmount` do iFood; `taxa_entrega` = entrega + taxas extras; `desconto` = cupons;
  diferença de arredondamento entra como taxa ou desconto (o CHECK `total = subtotal + taxa - desconto` sempre fecha).
  Item por peso ou com total que não divide pela quantidade vira 1 linha com a quantidade no nome.
- **Pagamento**: pago no app ou cobrado pelo entregador do iFood → `forma_pagamento = 'ifood'`, já pago (o dinheiro vem
  no repasse do iFood). Cobrado pela loja (entrega própria ou retirada) → dinheiro (com troco), débito, crédito, Pix ou
  vale (maquininha), pendente até concluir no caixa. Ao concluir, `pedido_pagamentos.forma = 'ifood'` para os pagos no app.
- **WhatsApp**: pedidos do iFood não têm `cliente_whatsapp` (o telefone do iFood é 0800); nenhum aviso vai para o cliente.
- **Ações do caixa**: `DeliveryService` grava a ação na mesma transação da mudança do pedido e
  `IfoodAcoes::agendarEnvio` envia depois da resposta da tela (o caixa não espera o iFood); a rotina repete se falhar.
- **Entregador**: avisos `ASSIGN_DRIVER`, `GOING_TO_ORIGIN`, `ARRIVED_AT_ORIGIN`, `COLLECTED`, `ARRIVED_AT_DESTINATION`
  e `DELIVERY_DRIVER_DEALLOCATED` atualizam `ifood_dados.entregador` (lido de forma tolerante: o iFood pode não mandar
  todos os campos). `COLLECTED`/`DISPATCHED` passam o pedido para "Saiu".
- **Cliente pediu cancelamento / reclamação (disputa)**: aparece como aviso no pedido para responder pelo Gestor de
  Pedidos do iFood (responder disputas por aqui não está nesta versão).

## Tabelas (migration `20261112140000_ifood_pedidos_estoque.php`)

- `pedidos`: `ifood_id` (UNIQUE com o restaurante), `ifood_display_id`, `ifood_entregue_por` (`ifood`/`loja`),
  `ifood_codigo_coleta`, `ifood_dados` (JSON sem segredo: pagamento, cupons, telefone do iFood, documento, agendamento,
  entregador, avisos, cancelamento); `forma_pagamento` ganha `ifood`; CHECK `ifood_id` só com `origem = 'ifood'`.
- `pedido_pagamentos.forma` ganha `ifood`; `pedido_itens.observacao` passa a 400 caracteres (complementos).
- `integracao_eventos`: `tentativas`, `erro` e índice de pendentes.
- `ifood_itens`: item do iFood ↔ produto (`vinculo` automático/manual), último status/quantidade aceitos pelo iFood.
- `ifood_acoes`: fila de ações para o iFood (`confirmar`, `preparar`, `pronto`, `despachar`, `cancelar`).

## Testes

- `tests/unit/ifood.php`: códigos dos avisos e conversão do pedido (valores, complementos, peso, pagamento, troco,
  retirada, agendado, endereço).
- `tests/http/ifood.sh` + `tests/http/verificar-ifood-ci.php`: contra o iFood **falso** (`tests/fakes/integracoes-fake.php`):
  conectar e buscar cardápio, ligar item, pedido novo pelo polling com confirmação de leitura, entregador, aceitar/pronto/
  cancelar indo para o iFood, despachar recusado para entregador do iFood, saiu/concluído/cancelado vindos do iFood,
  aviso repetido sem duplicar, aceitar sozinho e disponibilidade enviada.

## Para ligar em produção

1. No [Portal do Desenvolvedor iFood](https://developer.ifood.com.br/pt-BR), criar o aplicativo **distribuído** do
   Cardapim com os módulos de **pedidos (order + events)**, **merchant** e **catálogo**, e homologar com o iFood.
2. No servidor, em `shared/.env`: `IFOOD_CLIENT_ID=` e `IFOOD_CLIENT_SECRET=` (nunca no GitHub).
3. Cron do usuário do site (CloudPanel > Cron Jobs), a cada minuto:
   `php8.4 /home/<usuario>/apps/cardapim/current/bin/ifood.php >> /home/<usuario>/apps/cardapim/shared/storage/logs/ifood.log 2>&1`
4. Cada gerente: `Integrações > iFood > Gerar código de vínculo`, autorizar no Portal do Parceiro, colar o código,
   conferir o **Cardápio do iFood** e escolher as opções.

Referências oficiais: [Portal iFood Developer](https://developer.ifood.com.br/pt-BR),
[fluxo de pedidos](https://developer.ifood.com.br/en-US/docs/food/guides/modules/order/workflow) e
[eventos de pedidos](https://developer.ifood.com.br/en-US/docs/food/guides/modules/order/events).
