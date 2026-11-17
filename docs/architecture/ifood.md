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


### Abas do iFood e confirmação visual de pausas

- O iFood tem quatro **abas com rotas próprias**: `/integracoes/ifood/status` (disponibilidade e pausas), `/integracoes/ifood/loja` (vínculo e horários), `/integracoes/ifood/cardapio` (publicação, estoque e opções) e `/integracoes/ifood/dados` (lojas autorizadas, cadastro e diagnóstico Merchant). Apenas a seção da aba solicitada é renderizada. A rota `/integracoes/ifood` e a antiga `/integracoes/ifood/minha-loja` redirecionam para Status. O gerenciamento detalhado de vínculos migrou para `/integracoes/ifood/cardapio/vinculos`, sem alterar os POSTs existentes. A navegação por abas é responsiva e acessível. Na aba Loja, o card de horários fica **sempre expandido** (sem `details/summary`); na aba Dados, os três cards têm espaçamento próprio e consistente.
- `POST /integracoes/ifood/pausas` (ou `/remover`) mantém validação CSRF, tenant e RBAC. O JS bloqueia reenvios, mostra feedback da pausa e mantém o overlay branco translúcido **até verificar o resultado do POST e a confirmação por GET** (ou até esgotar um limite seguro com aviso de incerteza). Se a resposta for incerta (timeout/rede), NÃO repete o POST automaticamente; informa que é preciso consultar antes de tentar novamente.
- Após o POST/DELETE, o painel consulta `GET /integracoes/ifood/pausas/estado?pausa={id}&acao=criar|remover` em intervalos progressivos de 1,5 s, 3,5 s, 6 s, 9 s, 12 s e 15 s (cada GET limitado a 12 s), sem reenviar o POST/DELETE. Na **criação**, consulta `GET /interruptions` e `GET /status`: uma pausa futura requer registro, horários corretos e status consultado, mas não fechamento antecipado; pausa vigente requer também indisponibilidade. Na **remoção**, consulta **somente `GET /interruptions`**: o overlay desaparece automaticamente **apenas quando uma lista válida não contém mais o ID da interrupção removida**, seja uma lista vazia ou uma lista com outras interrupções. A abertura da loja no iFood não é requisito para confirmar a remoção. `lista_consultada` distingue resposta válida vazia de falha de API; falha de GET nunca confirma exclusão. Se o iFood não confirmar no lote de consultas, o overlay permanece com opções **Verificar novamente** (somente novos GETs) e **Sair sem confirmação** (saída manual e alerta). Não há timeout que declare sucesso nem nova exclusão automática.
- **Dois níveis de confirmação:** `201 Created` significa solicitação recebida; o ID aparecer em `GET /interruptions` confirma o registro; somente `GET /status` com operação indisponível comprova fechamento operacional. Pausa futura apenas confirma registro, não exige fechamento prematuro.
- CSS/JS em `public/assets/css/ifood.css` e `public/assets/js/integracoes.js`; spinner via sprite Lucide oficial, movimento reduzido respeitado; overlay com aria-live, sem HTML/JS inline. O cron independente continua responsável pela reconciliação persistida no MySQL.

## Telas

- `Gerência > Integrações > iFood > Status`: situação da loja, pedidos do iFood hoje, última consulta ao iFood (alerta se a
  rotina parar) e pausas. A aba **Cardápio** reúne o resumo do catálogo ligado e as **opções**:
  - *Aceitar pedidos do iFood sozinho* (padrão desligado): entra em preparo e o iFood é avisado na hora.
  - *Atualizar a disponibilidade no iFood pelo estoque* (padrão ligado).
- `Gerência > Integrações > iFood > Cardápio do iFood` (`/integracoes/ifood/cardapio/vinculos`): cada item do iFood com o produto
  daqui (ligação automática pelo **código PDV = código do produto** ou pelo mesmo nome; manual por lista), situação no
  iFood, estoque daqui, último envio e erros. Botões: *Buscar cardápio do iFood* e *Enviar disponibilidade agora*.
  Itens que chegam em pedidos e ainda não estão no cardápio buscado também aparecem para ligar.
  Item que chega com outro id (o iFood recria itens, e o gerador de pedidos de teste usa ids novos a cada pedido) usa a
  ligação de outro item com o mesmo código PDV ou o mesmo nome, desde que todos apontem para o mesmo produto, e já fica ligado.
- `Caixa > Delivery`: selo `iFood #código` na lista e na fila lateral; no pedido, avisos, entregador do iFood, código de
  coleta, pagamento ("Pago pelo app do iFood", "Cobrar na entrega: Dinheiro, troco para R$ 100,00", "O cliente paga ao
  entregador do iFood") e cancelamento com motivo do iFood.

## Cardápio publicado pelo sistema (módulo Catalog)

`Gerência > Integrações > iFood > Publicar cardápio no iFood` (`/integracoes/ifood/publicar`). O Deliveryzim é a fonte:

- **Publicar** (produtos marcados): cria a categoria no iFood na primeira vez (`POST /catalog/v2.0/merchants/{id}/catalogs/{catalogo}/categories`;
  produto sem categoria vai para "Outros"), envia as fotos (`POST .../image/upload`, JPG gerado da foto daqui) e o item completo
  (`PUT .../items`: item, produto, grupos de complementos com mínimo/máximo e complementos com foto e preço). Ids do iFood (UUID)
  criados aqui e guardados antes do envio em `ifood_catalogo_vinculos` (nunca duplica).
- **Depois de publicado** (rotina `bin/ifood.php` a cada minuto ou botão "Enviar mudanças agora"):
  preço do item só por `PATCH /items/price`; preço do complemento por `PATCH /options/price`; pausa do complemento (manual ou sem
  insumo) por `PATCH /options/status`; nome, descrição, foto e grupos pelo cadastro do item (`PUT /items`, com o preço do último
  envio, seguido do PATCH do preço novo). Pausa do item segue o estoque (`PATCH /items/status`, `IfoodCatalogo`).
- O item publicado entra em `ifood_itens` ligado ao produto: pedido do iFood baixa o estoque e a disponibilidade segue o estoque.
- Falha fica na tela com a resposta do iFood e é tentada de novo com espera crescente (1, 2, 4... até 60 min).
- Produto por quilo/litro não é publicado por aqui (vende pelo Portal do Parceiro).
- Pendência: complementos que chegam nos pedidos do iFood ainda entram na observação do item (não baixam o estoque do complemento).

| Método | Rota | O que faz |
| --- | --- | --- |
| GET | `/{slug}/gerencia/integracoes/ifood/publicar` | Produtos por categoria com a situação no iFood |
| POST | `/{slug}/gerencia/integracoes/ifood/publicar` | Publica `produtos[]` (até 50) |
| POST | `/{slug}/gerencia/integracoes/ifood/publicar/sincronizar` | Envia agora as mudanças dos publicados |

## Loja no iFood (módulo Merchant)

As quatro abas compartilham os mesmos serviços e permissões, mas exibem apenas o conteúdo pertinente. O último status operacional continua armazenado no MySQL:

- **Lojas vinculadas** (`GET /merchant/v1.0/merchants`), marcando a ligada ao restaurante.
- **Dados da loja** (`GET /merchants/{id}`): nome, razão social, endereço, operações e canais, e a resposta completa do iFood.
- **Disponibilidade agora** (`GET /merchants/{id}/status`) por operação, com as validações do iFood.
- **Pausas** (`GET|POST /merchants/{id}/interruptions`, `DELETE .../interruptions/{id}`): motivo obrigatório de até **255 caracteres**; duração de **1 minuto a 7 dias**; intervalos não sobrepostos. Não há requisito documentado de antecedência mínima, mas não foi adicionado modo "Começar imediatamente": permanece o formulário existente com início/fim e início predefinido para o horário atual da loja.
  - **Fuso fixo**: todas as pausas usam `America/Sao_Paulo` (horário de Brasília, UTC−03:00) independentemente do endereço do estabelecimento. O usuário não recebe seletor de fuso nem rota para alterá-lo. Preferências antigas eventualmente armazenadas no JSON são ignoradas.
  - Os campos `datetime-local` usam o horário de **Brasília**, não o relógio do PHP-FPM/navegador. No POST, o módulo Merchant envia a hora de parede sem offset, por exemplo `2026-11-17T15:40:00`, **sem somar/subtrair horas**: este foi o formato que o usuário testou no Postman e confirmou fechar sua loja TEST. Isto difere do exemplo oficial com `Z` e **não garante compatibilidade com toda loja**: validar com `GET /status` após a criação, respeitando processamento assíncrono. O GET pode retornar datas locais sem offset ou instantes com `Z`/offset; `IfoodApi::pausas(..., fuso)` normaliza somente os valores sem offset como horários locais daquela loja, deixando os demais intocados. `IfoodApi::data()` continua interpretando datas gerais sem offset como UTC (inclusive pedidos); essa regra não foi alterada.
  - Os relógios digitais comparativos foram removidos por solicitação de UX. O formulário mantém início/fim em horário local e o processamento AJAX, em arquivos CSS/JS externos.
  - **Sem ajuste manual:** o POST de fuso foi removido, o formulário técnico não mostra configuração e o cron usa a mesma constante `America/Sao_Paulo` da tela.
  - **Importante:** o endpoint Merchant de detalhes não expõe o fuso IANA configurado internamente. O horário fixo de Brasília é uma decisão do Deliveryzim, não uma leitura direta da configuração do iFood. Em lojas que utilizem outra região, é necessário conferir a interpretação no Portal do Parceiro. Por isso, comparar **o corpo do POST, o valor devolvido em GET /interruptions, a configuração no Portal e o GET /status**. Nunca declarar loja fechada quando o Merchant permanece `available=true`/`state=OK`.
  - Validação prévia de sobreposição via `GET /interruptions`, sem confiar apenas no `409` de uma loja TEST. Cada POST 201 salva ID e período UTC auditado. `POST 201` não é confirmação de indisponibilidade; aguardar processamento assíncrono. Persistindo `OK` e sem validação `unavailabilities`, abrir chamado de homologação com logs da API, especialmente em lojas `TEST`/`DISABLED`.

  O POST envia somente `description`, `start` e `end` e exige **HTTP 201 com ID devolvido pelo iFood**;
  não se inventa ID localmente. O DELETE exige **HTTP 204**. Depois de cada comando a tela consulta
  novamente `GET /interruptions` e mostra apenas o que a API retornou, sem inserir ou ocultar pausas
  artificialmente. Se a API ainda não refletiu a alteração, o aviso fica **aguardando confirmação**;
  o estado operacional pode levar alguns segundos para se propagar. Em falha de consulta, nunca se
  exibe "Nenhuma pausa ativa" como se a ausência estivesse confirmada.
- **Horário de funcionamento** (`GET|PUT /merchants/{id}/opening-hours`, turnos `dayOfWeek` + `start` + `duration` em minutos):
  - **Padrão: o iFood segue o horário do Perfil.** Ao salvar o horário no Perfil, o iFood recebe o horário novo na hora
    (`IfoodLojaService::acompanharPerfil`, chamado pelo `PerfilController`; falha vira aviso, o Perfil já fica salvo).
    Turno que passa da meia-noite vira dois (até 24:00 e a partir de 00:00 do dia seguinte); "sempre aberto" = 24 h todo dia.
  - **"Horário diferente para o iFood"** (alternador): mostra o formulário com até 4 horários por dia, sem sobreposição,
    00:00 = meia-noite. Salvar marca `integracoes.dados.horario_proprio = true` e o Perfil deixa de mudar o iFood.
    Desligar e salvar volta ao horário do Perfil e o envia ao iFood. Nunca altera o Perfil (que aceita 2 turnos por dia).
- Cada parte é buscada separada: se uma falhar, as outras aparecem e a falha vira aviso com a resposta do iFood.

| Método | Rota | O que faz |
| --- | --- | --- |
| GET | `/{slug}/gerencia/integracoes/ifood` | Redireciona para `/status` |
| GET | `/{slug}/gerencia/integracoes/ifood/status` | Disponibilidade, pausas, métricas |
| GET | `/{slug}/gerencia/integracoes/ifood/loja` | Conta vinculada e horários |
| GET | `/{slug}/gerencia/integracoes/ifood/cardapio` | Resumo, publicação, estoque e opções |
| GET | `/{slug}/gerencia/integracoes/ifood/cardapio/vinculos` | Ligação detalhada de itens aos produtos |
| GET | `/{slug}/gerencia/integracoes/ifood/dados` | Lojas vinculadas, cadastro e diagnósticos |
| GET | `/{slug}/gerencia/integracoes/ifood/minha-loja` | Alias legado que redireciona para Status |
| POST | `/{slug}/gerencia/integracoes/ifood/pausas` | Cria pausa (`descricao`, `inicio`, `fim` em `Y-m-d\TH:i`) |
| POST | `/{slug}/gerencia/integracoes/ifood/pausas/remover` | Remove pausa (`pausa` = id) |
| POST | `/{slug}/gerencia/integracoes/ifood/horarios` | Sem `horario_proprio`: usa o horário do Perfil. Com `horario_proprio=1`: envia `h[MONDAY][0][abre]`, `h[MONDAY][0][fecha]`... |

### Diagnóstico das respostas da API Merchant

Na aba **Dados**, o gerente encontra **Diagnóstico da comunicação com o iFood**. Os últimos 30 registros podem ser consultados nessa própria tela depois de atualizá-la. São mostrados apenas os registros do restaurante logado, com controle de acesso já existente na gerência. O log inclui:

- Horário UTC, origem (cron ou painel), restaurante e loja, método, rota, ID da pausa (quando aplicável), tempo de resposta e **código HTTP real**.
- Corpo de envio seguro e resposta JSON sanitizada de `GET /status`, `GET /interruptions`, `POST /interruptions`, `DELETE /interruptions/{id}` e `GET|PUT /opening-hours`.
- Em falha de rede, o motivo genérico, sem token. Nenhuma chamada OAuth, pedido, dados do cliente, bearer ou credencial é registrada.
- Arquivos persistidos fora de `public/`: `storage/logs/ifood-merchant/restaurante-{id}-AAAA-MM-DD.jsonl`. Em produção, `storage/` é compartilhado entre releases. Permissões 0700 no diretório criado e 0600 nos arquivos novos. Logs anteriores a 14 dias são apagados automaticamente.
- A API informa **201** no POST e **204** no DELETE; a disponibilidade não é garantida por isso. Compare com os GET subsequentes e com `state`, `available`, `validations` e `reopenable`. Nunca considerar a loja aberta ou fechada sem a confirmação do Merchant.

**Diagnóstico de homologação:** se o POST retorna 201 com ID mas a interrupção não aparece no GET ou o status permanece OK, registrar a sequência e verificar no Portal do Parceiro. O iFood documenta processamento assíncrono; investigar autorizações e merchantId se persistir por mais de um ciclo de polling. A disponibilidade na loja de testes pode diferir da loja real. Não repetir POST indiscriminadamente (risco de pausa duplicada).

### Botão "Abrir loja no iFood" (topo da gerência e do modo caixa)

Ao lado do "Abrir caixa", só quando o iFood está conectado (`BotaoLojaIfood::config`). Funciona por JSON, sem recarregar
(`public/assets/js/ifood-botao.js`): consulta o estado compartilhado no MySQL a cada 10 segundos, imediatamente após mudanças e ao voltar à aba. A consulta ao iFood é centralizada no cron a cada 30 segundos. Há cache de sessão de até 20 segundos.

| Estado | Botão | Quando |
| --- | --- | --- |
| `aberta` | Desativado, "Loja aberta" (verde) | O iFood diz que a loja está recebendo pedidos |
| `pausada` | Ativo, "Abrir loja" (laranja) | O Merchant confirma indisponibilidade e existe pausa própria vigente; o clique solicita remoção apenas dessa pausa |
| `divergente` | Desativado, "iFood: loja aberta" (aviso) | Existe pausa vigente cadastrada, mas o Merchant confirma `available=true` e `state=OK/WARNING`; não anunciar fechamento falso |
| `fechada` | Desativado, "Loja fechada" | Merchant confirma indisponibilidade por horário ou outra restrição |
| `erro` | Desativado, "Sem resposta" | O iFood não respondeu |

O iFood não tem um comando "abrir": o botão remove a pausa criada pelo Deliveryzim e aguarda a confirmação externa, sem inventar o estado aberto. Pausas externas podem ser removidas explicitamente na lista de interrupções. Bloqueios administrativos nunca são removidos automaticamente.

| Método | Rota | Quem |
| --- | --- | --- |
| GET | `/{slug}/gerencia/integracoes/ifood/botao-loja/situacao` | Gerente |
| POST | `/{slug}/gerencia/integracoes/ifood/botao-loja/abrir` (header `X-CSRF-Token`) | Gerente |
| GET | `/{slug}/caixa/ifood/situacao` | Caixa, gerente |
| POST | `/{slug}/caixa/ifood/abrir` (header `X-CSRF-Token`) | Caixa, gerente |

### Sincronização de disponibilidade Deliveryzim ↔ iFood

- **Criar pausa:** após resposta de sucesso ao `POST /interruptions`, o Deliveryzim grava a pausa com início/fim/ID em `integracoes.dados.pausa_sincronizada`. Se já começou, o cardápio público e o checkout passam a bloquear **novos** pedidos. Pedidos existentes não são cancelados. A tela de gerência e caixa recebe evento no canal `geral` (outbox/WebSocket), e outras abas atualizam pelo endpoint local.
- **iFood → Deliveryzim:** `RotinaIfood`, executada a cada 30s, lê `GET /status` e `GET /interruptions` e grava `integracoes.dados.situacao_sincronizada`. **Somente o status operacional Merchant determina `situacao_sincronizada.fechado`**: interrupções são registradas separadamente como `pausada` e `divergente` quando a API ainda confirma aberta. Se nenhuma operação está disponível, o cardápio público é fechado. Um erro de comunicação não zera o último fechamento (fail-closed). A proteção de uma pausa solicitada pelo Deliveryzim continua independente para novos pedidos locais até remoção/expiração (não declarar falso estado fechado do iFood).
- **Atualização das telas:** botões de gerência/caixa consultam o banco a cada 10s, sem chamadas externas por navegador. A tela da loja mostra um aviso quando pausa vigente e status operacional aberto divergem, com destaque separado para cadastro `TEST`/`DISABLED`. O cliente no cardápio usa `GET /api/v1/restaurantes/{slug}/situacao` a cada 10s para atualizar o selo e o aviso de loja fechada. A criação de pedidos sempre confere o bloqueio no servidor, **inclusive dentro da transação** após bloquear a linha do restaurante.
- **Concorrência e isolamento:** as chaves de disponibilidade são atualizadas por `JSON_SET` atomicamente em `integracoes.dados`, com `id_restaurante` e `loja_id` para isolar a unidade. Não há migração nova. A escrita trava a linha do restaurante para serializar com o checkout. Alterar opções de integração não sobrescreve o snapshot.
- **Limitação da API:** alterações feitas no Portal do iFood são detectadas na próxima consulta (até ~30s mais processamento externo); não se promete sincronização instantânea com servidor remoto. Um `DELETE /interruptions` aceito não equivale a loja aberta: status/horários/validações continuam sendo a fonte externa. Na loja TEST cadastralmente `DISABLED`, o retorno `state=OK` com pausas vigentes pode divergir do esperado: o painel deve informar a divergência, **não forçar o estado Merchant para fechado**. No checkout do Deliveryzim, a pausa solicitada localmente permanece como bloqueio de segurança separado.
- **Atenção:** modo `horario_proprio` continua configurando turnos independentes, mas um fechamento efetivo do iFood também bloqueia o cardápio público local, conforme política de sincronização bidirecional.
- Falhas do Merchant são registradas separadamente, não interrompendo o polling/ack dos pedidos iFood. É necessário manter o cron `bin/ifood.php` ativo.

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
2. No painel da plataforma, em `Integrações > Aplicativos nos marketplaces > iFood`, colar o Client ID e o Client Secret
   (guardados cifrados no banco). O modo antigo, `IFOOD_CLIENT_ID=` e `IFOOD_CLIENT_SECRET=` no `shared/.env`, continua valendo
   quando nada foi salvo no painel. Nunca no GitHub.
3. Cron do usuário do site (CloudPanel > Cron Jobs), a cada minuto:
   `php8.4 /home/<usuario>/apps/cardapim/current/bin/ifood.php >> /home/<usuario>/apps/cardapim/shared/storage/logs/ifood.log 2>&1`
4. Cada gerente: `Integrações > iFood > Gerar código de vínculo`, autorizar no Portal do Parceiro, colar o código,
   conferir o **Cardápio do iFood** e escolher as opções.

Referências oficiais: [Portal iFood Developer](https://developer.ifood.com.br/pt-BR),
[fluxo de pedidos](https://developer.ifood.com.br/en-US/docs/food/guides/modules/order/workflow) e
[eventos de pedidos](https://developer.ifood.com.br/en-US/docs/food/guides/modules/order/events).
