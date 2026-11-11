# Avisos do pedido pelo WhatsApp do restaurante

O cliente que pede pelo cardápio online recebe mensagens **do WhatsApp do próprio restaurante** (o conectado em
Gerência > Integrações > WhatsApp) a cada etapa do pedido, sempre com o link para acompanhar.

## Etapas e textos

| Evento | Quando | Conteúdo |
| --- | --- | --- |
| `recebido` | pedido criado (ou Pix confirmado) | saudação, número do pedido e link para acompanhar |
| `em_preparo` | caixa aceita o pedido | "já está sendo preparado" |
| `pronto` | caixa marca pronto | entrega: "já vai sair"; retirada: "pode vir retirar" |
| `saiu_entrega` | despachado para o motoqueiro | nome do motoqueiro, valor a pagar na entrega (forma e troco) e link do mapa |
| `concluido` | entregue / retirado | agradecimento |
| `cancelado` | cancelado (só se o aviso `recebido` existiu) | motivo e convite para responder |

Textos em `Services/Pedidos/AvisosWhatsApp::mensagem()` (testados em `tests/unit/entrega.php`).
O gerente liga/desliga cada etapa na seção **Avisos automáticos para os clientes** da tela do WhatsApp
(`POST /{slug}/gerencia/integracoes/whatsapp/avisos`, com CSRF e log de auditoria). Sem configuração salva, todas ficam ligadas.
A mesma tela mostra os **últimos avisos** (Enviado, Na fila, Não precisou, Não enviado e o motivo).

## Fila (transactional outbox)

- Tabela `pedido_avisos` (migration `20261111140000_create_pedido_avisos.php`): um aviso por pedido e evento (`UNIQUE id_pedido, evento`),
  `status` pendente/enviado/falhou/ignorado, tentativas, último erro e próxima tentativa. O telefone **não** é copiado: é lido de
  `pedidos.cliente_whatsapp` na hora do envio.
- Tabela `restaurante_avisos_whatsapp`: etapas ligadas por restaurante.
- `enfileirar()` grava o aviso dentro do fluxo do pedido e **nunca lança erro** (o pedido não falha por causa do WhatsApp).
- O envio acontece **depois da resposta** (`register_shutdown_function` + `fastcgi_finish_request`), sem atrasar a tela do caixa.
- Sem conexão ou com erro: nova tentativa em 1, 3, 10 e 30 min (máx. 5). A fila do delivery do caixa (consulta a cada 60 s) e a tela do
  WhatsApp disparam as tentativas pendentes, então ao reconectar o WhatsApp os avisos atrasados saem sozinhos.
- Aviso de uma etapa que o pedido já passou (ex.: "Em preparo" quando já saiu) é marcado como `ignorado`, para não confundir o cliente.
- Isolamento: tudo filtrado por `id_restaurante`; cada restaurante só envia pela própria sessão do WPPConnect. O WhatsApp da plataforma
  (códigos de login) nunca é usado para avisos de pedido.

## Implantação

Commit com `[cardapim-avisos]` roda `deploy/scripts/apply-avisos.sh` (backup do MySQL antes, migration e `database/verify-avisos.php`).
Gatilho removido no commit seguinte.

## Testes

- `tests/http/avisos.sh`: pedido completo (recebido, preparo, pronto, saiu com valor e mapa, entregue), etapa desligada, cancelamento com
  motivo, WhatsApp desconectado sem quebrar o pedido e menu do gerente sem Entregas/Mesas.
