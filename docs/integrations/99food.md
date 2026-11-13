# 99Food: passo a passo da integração (planejada)

Situação em 12/11/2026: **só a vitrine está pronta.** A 99Food aparece como "Em breve" na landing (canais) e em
`Gerência > Integrações` (cartão sem link, botão desativado). Nada de back-end, tabela, rota ou credencial existe
ainda. Este documento é o roteiro para construir a integração depois, no mesmo molde da do iFood
([../architecture/ifood.md](../architecture/ifood.md)).

> O pedido original falava em "99 pop". O produto de delivery de comida da 99 é a **99Food** (a 99 Pop é o
> transporte de passageiros). A integração planejada é com a 99Food.

## 1. Como a 99Food se integra com sistemas de gestão

- A 99Food adotou o padrão **Open Delivery** (Abrasel), o mesmo usado pela Keeta. A especificação pública fica em
  <https://abrasel-nacional.github.io/docs/>. A integração é feita contra a API Open Delivery da 99Food, com os
  ajustes que a 99Food documenta no próprio portal.
- Há um **portal de desenvolvedor** da 99Food (`developer-food.99app.com`, "Gerenciamento de Aplicativo"), onde a
  empresa de software cadastra o aplicativo e vincula as lojas, e o **portal da loja** (`merchant.99app.com`), onde o
  restaurante opera e autoriza integradoras.
- Fluxo que os integradores descrevem publicamente (PDV Legal, Linx, Netcontroll e outros):
  1. o restaurante tem a loja ativa na 99Food;
  2. a empresa de software gera, no portal de desenvolvedor, um **link de vinculação** da loja
     (`Gerenciamento da Loja > Vincular Estabelecimento`);
  3. o responsável pela loja abre o link logado na 99Food, escolhe a loja e **autoriza a integradora**;
  4. o portal gera o identificador da loja no aplicativo (**AppShopId**);
  5. o sistema obtém um **token OAuth2** (client credentials) com o ClientId do app + AppShopId e o ClientSecret do app,
     e passa a consumir os eventos e enviar os status.
- Pedidos chegam como **eventos** (polling obrigatório, webhook opcional): `CREATED`, `CONFIRMED`, `READY_FOR_PICKUP`,
  `DISPATCHED`, `DELIVERED`, `CONCLUDED`, `CANCELLED` e pedidos de cancelamento/reembolso. O sistema confirma o
  recebimento (acknowledgment) e responde com `confirm`, `readyForPickup`, `dispatch`, `requestCancellation` etc.
- O **cardápio** pode ser sincronizado pela API (merchant/menu) ou mantido no portal da 99Food com o **código PDV** de
  cada item, como fazemos hoje no iFood.

Pontos que **só o portal de desenvolvedor confirma** (consultar antes de programar, porque a 99Food ajusta o padrão):
URLs de produção e de testes, formato exato do ClientId, tempo de vida do token, intervalo mínimo do polling,
assinatura do webhook, regras de homologação, campos de pagamento e de entregador da 99.

> Segurança: algumas páginas públicas de integradores chegam a mostrar ClientSecret e senha de portal em texto. Nunca
> copiar credenciais de terceiros. As nossas ficam só no `shared/.env` da VPS (do app) e criptografadas no banco (da loja).

## 2. Passo a passo administrativo (antes do código)

1. **Conta de desenvolvedor**: pedir acesso ao portal de desenvolvedor da 99Food como empresa de software
   (Escotilha Produtora, CNPJ 64.069.224/0001-32), pelo canal de parceiros/integradores da 99Food.
2. **Criar o aplicativo** no portal e anotar App ID e ClientSecret. Guardar como `NOVENOVEFOOD_APP_ID` e
   `NOVENOVEFOOD_CLIENT_SECRET` no `shared/.env` (digitados com `read -rsp`, nunca no chat nem no GitHub).
3. **Loja de testes**: conseguir uma loja de testes (ou de um restaurante parceiro) para homologar.
4. **Checklist de homologação** da 99Food: ler e copiar para este documento os cenários exigidos (aceitar, pronto,
   despachar, cancelar, cancelamento pelo cliente, polling contínuo, idempotência).
5. Atualizar as páginas legais (Privacidade, Tratamento de Dados): incluir a 99Food entre os serviços que trocam dados de
   pedidos, quando a integração for ao ar.

## 3. Passo a passo técnico (reaproveitando o iFood)

| Etapa | O que fazer | Base no código atual |
| --- | --- | --- |
| 1. Migração | Provedor `99food` em `integracoes` (dados: `app_shop_id`, `loja_nome`), origem `99food` em `pedidos.origem` e `pedidos.codigo_externo`. Mudança de schema em dois commits com gatilho `[cardapim-x]`. | `integracoes`, `integracao_eventos`, colunas do iFood em `pedidos` |
| 2. Cliente HTTP | `Modules/Integracoes/NoveNoveFoodApi` com token OAuth2 em cache (renovar antes de vencer), timeout curto, log sem dados pessoais. | `Modules/Integracoes/IfoodApi`, `Services/Ifood/IfoodConta` |
| 3. Conectar | Tela `Gerência > Integrações > 99Food`: botão "Conectar" gera/mostra o link de vinculação e um campo para o AppShopId (ou recebe pela API, se o portal permitir). Só gerente, CSRF, auditoria. | `Gerencia/IntegracoesController` (ações do iFood), `Gerencia/Integracoes/IfoodService`, `gerencia/integracoes/ifood.php` |
| 4. Rotina | `bin/99food.php` no cron (mesmo esquema de trava e duas rodadas por minuto do iFood, respeitando o intervalo que a 99Food exigir): polling → guarda em `integracao_eventos` → acknowledgment → trata em ordem com até 10 tentativas. | `bin/ifood.php`, `RotinaIfood`, `EventosIfood` |
| 5. Pedido | Converter o pedido Open Delivery para o pedido do Cardapim (itens, complementos, observações, desconto, taxa, pagamento, entrega própria ou da 99). Selo `99Food #código` no caixa e na cozinha. Baixa de estoque pela ficha técnica. | `Ifood/ImportarPedidoIfood`, `Ifood/DadosPedidoIfood`, `Pedidos/CriarPedido` |
| 6. Status de volta | Aceitar → `confirm`; Pronto → `readyForPickup`; Despachar → `dispatch`; Cancelar → `requestCancellation` com motivo. Mudanças vindas da 99Food não geram ação de volta. | `Ifood/IfoodAcoes`, `Pedidos/StatusPedido` |
| 7. Cardápio | Ligação item da 99Food ↔ produto por código PDV ou nome; envio de disponibilidade pelo estoque. | `Ifood/IfoodCatalogo`, `gerencia/integracoes/ifood-cardapio.php` |
| 8. Tempo real | Eventos `pedido.criado`/`pedido.atualizado` pela Outbox, como os do iFood. | `OutboxRepository`, `bin/tempo-real.php` |
| 9. Avisos | Cliente da 99Food **não** recebe aviso por WhatsApp (mesma regra do iFood). | `Pedidos/AvisosWhatsApp` |
| 10. Testes | Servidor falso da API (como o iFood em `tests/fakes/integracoes-fake.php`), `tests/http/99food.sh` no CI, unitários do conversor. | `tests/http/ifood.sh` |
| 11. Docs | `docs/architecture/99food.md` (rotas, tabelas, permissões), README, landing e cartão sem "Em breve". | `docs/architecture/ifood.md` |

## 4. Telas quando ficar pronto

- Landing: tirar o "Em breve" de `$canais` em `app/Views/marketing/home.php`.
- `Gerência > Integrações`: trocar o cartão estático de `gerencia/integracoes/inicio.php` pelo cartão do laço
  `$cartoes`, com status (conectado, com erro, desconectado) e link para `/integracoes/99food`.
- Caixa e cozinha: selo `99Food #código`, entregador da 99 quando a entrega for da plataforma.

## Fontes

- Especificação Open Delivery (Abrasel): <https://abrasel-nacional.github.io/docs/>
- 99Food e Keeta no Open Delivery: <https://www.abcdacomunicacao.com.br/99food-e-keeta-concluem-integracao-ao-open-delivery/>
- Fluxo de vinculação e autorização da loja (PDV Legal): <https://ajuda.pdvlegal.com.br/integracoes/integracao-com-99food-o-que-preciso-saber>
- Link de vinculação e AppShopId (central de ajuda de integrador): <https://treinamentosdesistema.freshdesk.com/support/solutions/articles/150000224833-99-food-cadastrar-e-vincular-restaurante-na-99-food>
- ClientId/ClientSecret e obtenção de token (Netcontroll): <https://netcontroll.gitbook.io/xmenu-tecnico/integracao-com-parceiros/open-delivery>
- Exemplo de API Open Delivery de marketplace (Keeta, eventos por polling e webhook): <https://api-docs.mykeeta.com/apis/opendelivery/orderssection.md>
- Cadastro de restaurantes na 99Food (portal da loja): <https://mercadoeconsumo.com.br/29/04/2025/foodservice/99food-abre-cadastro-para-restaurantes-sem-taxas-nem-comissoes/amp>
