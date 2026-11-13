# Tempo real (WebSocket)

Atualizações ao vivo para todas as telas do Cardapim: pedidos, cozinha, caixa, mesas, entregas,
estoque, integrações e (depois) conversas do WhatsApp. Segue a regra do README: **o MySQL é a fonte
de verdade; o WebSocket só transmite o que já foi gravado**.

```text
Ação no PHP (HTTP) ──> MySQL: regra de negócio + outbox_eventos (mesma transação quando crítico)
                       └─> fim da requisição: PUBLISH cardapim:outbox "novo" (Redis)
bin/tempo-real.php  <── SUBSCRIBE cardapim:outbox  (sem Redis: busca no banco a cada 1 s)
   └─> lê as linhas novas da outbox ──> entrega a cada navegador do MESMO restaurante,
                                        que assinou o canal e cujo cargo pode ouvi-lo
Navegador (public/assets/js/tempo-real.js) ──> CustomEvent "cardapim:tempo-real" + aviso na tela
```

## Peças

| Peça | Arquivo | Papel |
| --- | --- | --- |
| Outbox | tabela `outbox_eventos` (migration `20261110090000`) | Guarda todo evento antes de transmitir. Apagado pelo servidor após 7 dias. |
| Publicar | `app/Realtime/TempoReal.php` | `registrar(PDO, ...)` dentro da transação (crítico) ou `publicar(...)` (aviso de tela, nunca derruba a ação). |
| Canais e permissões | `app/Realtime/Canais.php` | Lista de canais e o que cada cargo pode ouvir (fonte única). |
| Ticket | `app/Realtime/Ticket.php` + `GET /api/v1/tempo-real/ticket?restaurante={slug}` | HMAC-SHA256 (chave derivada da APP_KEY), 60 s, uso único. |
| Servidor | `bin/tempo-real.php` + `app/Realtime/Servidor.php` | Processo PHP próprio (systemd), `stream_select`, RFC 6455, sem dependências. |
| Redis | `app/Realtime/RedisConexao.php` | Cliente RESP mínimo (AUTH, PUBLISH, SUBSCRIBE). Opcional. |
| Navegador | `public/assets/js/tempo-real.js` | Ticket, conexão, reconexão, deduplicação, indicador "Ao vivo" e aviso "Atualizar agora". |

## Canais

| Canal | Quem ouve |
| --- | --- |
| `geral` | toda a equipe com acesso ao tempo real |
| `pedidos`, `cozinha`, `mesas` | gerente, caixa, garçom |
| `caixa`, `estoque` | gerente, caixa |
| `entregas` | gerente, caixa, entregador |
| `equipe`, `integracoes`, `whatsapp`, `financeiro` | gerente |

O `adm_master` ouve tudo de qualquer restaurante (suporte). Cliente final ainda não tem canal
(o acompanhamento de pedido/entrega dele entra com o módulo de pedidos).

## Eventos já publicados

| Tipo | Canal | Quando | Dados |
| --- | --- | --- | --- |
| `estoque.alterado` | `estoque` | produto, insumo ou categoria criado/editado/excluído, reposição, pausa | `area`, `acao`, `id`, `autor` |
| `integracao.asaas.aviso` | `integracoes` | webhook novo do Asaas (não repetido) | `evento` (ex.: `PAYMENT_RECEIVED`) |
| `integracao.whatsapp.estado` | `whatsapp` | WhatsApp conectou, caiu no celular ou foi desconectado pelo painel | `estado`, `autor` |

Os dados são **mínimos e sem segredos ou dados pessoais**: a tela busca o restante pela API/página.

### Como publicar um evento novo (ex.: módulo de pedidos)

```php
$db->beginTransaction();
// ... grava o pedido ...
TempoReal::registrar($db, $idRestaurante, 'pedidos', 'pedido.criado', ['id' => $idPedido]);
$db->commit(); // sem commit, o evento some junto (Transactional Outbox)
```

Para avisos que não são críticos, depois da ação: `TempoReal::publicar($idRestaurante, 'estoque', 'estoque.alterado', [...])`.
No painel do gerente há o atalho `BaseGerencia::avisar(Resultado, canal, tipo, dados)`.

Regras: canal precisa existir em `Canais::TODOS`; tipo no formato `area.acao` (minúsculas, até 80);
JSON até 8 KB. Na tela, outro script pode ouvir com:

```js
document.addEventListener("cardapim:tempo-real", (ev) => {
  if (ev.detail.tipo !== "pedido.criado") return;
  ev.preventDefault(); // "eu tratei": não mostra o aviso genérico
  // atualizar a lista...
});
window.CardapimTempoReal.assinar("pedidos"); // canal extra (o servidor confere a permissão)
```

## Aviso de pedido novo

Na gerencia (todas as abas) e no modo caixa (`layouts/gerencia.php` e `layouts/caixa.php` passam `alerta_pedido` para
`layouts/painel.php`, que carrega `js/alerta-pedido.js` e `css/alerta-pedido.css` quando o tempo real esta ligado).
Garcom e entregador nao recebem (nao tratam pedidos do delivery).

- Ouve `pedido.novo` no canal `pedidos` (a gerencia pede o canal com `CardapimTempoReal.assinar("pedidos")`, porque nem toda aba ouve).
- **Visual**: luz laranja piscando em volta da tela (3 pulsos), tremida leve do sistema (0,5 s) e faixa no topo com
  "Pedido novo! #123", **Ver pedido** (abre `/caixa/delivery/{id}`) e **Ok, vi**. Varios pedidos juntos: "3 pedidos novos".
  O titulo da aba vira "(1) Pedido novo" e o celular vibra. Com "reduzir movimento" a luz fica parada e nada treme.
- **Som**: campainha "ding-dong" gerada no navegador (Web Audio, sem arquivo), repetida a cada 20 s (ate 6 vezes) enquanto a
  faixa estiver aberta. O navegador so libera som depois do primeiro toque na pagina, EXCETO no app instalado (PWA) ou
  com "Som: Permitir" nas configuracoes do site (cadeado da barra de endereco > Configuracoes do site > Som). O script
  tenta ligar o som ja ao abrir a pagina; se nao der, liga no primeiro toque. A faixa nao mostra aviso de toque.
  Botao **Som ligado/desligado** no topo, lembrado no aparelho (`localStorage`); ao ligar, toca uma amostra.
- Pedido aceito (`pedido.atualizado` com outra etapa) ou cancelado em outro aparelho sai da faixa sozinho.
- Para testar no navegador sem pedido real: `CardapimAlertaPedido.simular()` no console.
- Testes: `tests/http/alerta-pedido.sh` (onde aparece e onde nao aparece) e `tests/http/verificar-alerta-ci.php`
  (o pedido feito pelo cardapio grava `pedido.novo` com id e numero do proprio restaurante).

## Protocolo (mensagens JSON)

Navegador → servidor: `{"acao":"assinar","canais":["estoque"],"desde":123|null}` e `{"acao":"ping"}`.

Servidor → navegador:
- `sistema.conectado` (canais permitidos e último id), `sistema.assinado`, `sistema.pong`, `sistema.erro`;
- `sistema.recarregar` quando a reconexão perdeu mais de 200 eventos (a tela pede para atualizar);
- eventos: `{"id":456,"tipo":"estoque.alterado","canal":"estoque","dados":{...},"em":"2026-11-10 11:00:00"}`.

Códigos de fechamento: `1012` servidor reiniciando (deploy), `4003` acesso encerrado (conta bloqueada,
removida ou restaurante suspenso: conferido ao conectar e a cada 60 s), `1008` mensagens demais, `1009` grande demais.

## Segurança e limites

- Ticket assinado (não usa o cookie de sessão no WebSocket), 60 s, uso único; restaurante e cargo
  reconferidos no MySQL na conexão e a cada minuto. O navegador nunca escolhe o restaurante.
- `Origin` precisa ser o `APP_URL` (ou `WS_ORIGENS`). Servidor só em `127.0.0.1`; a internet chega pelo Nginx (`wss://.../ws`).
- Até 900 conexões por processo (limite do `stream_select`), 10 por usuário, 60 por IP, 30 mensagens/10 s,
  mensagens de até 4 KB, fila de saída de até 1 MB por conexão (navegador lento demais é desconectado).
- Navegador: reconexão com espera crescente (1 s a 30 s, com sorteio), ping a cada 30 s, reenvio do
  que perdeu (`desde`), deduplicação por `id`.

## Configuração (`shared/.env`)

| Variável | Uso |
| --- | --- |
| `WS_ATIVO=1` | Liga o tempo real nas telas (deixe vazio até o servidor e o Nginx estarem prontos). |
| `WS_PORTA=8091` | Porta local do servidor (só 127.0.0.1). |
| `WS_URL` | Opcional. Padrão: `wss://{host do APP_URL}/ws`. |
| `WS_ORIGENS` | Opcional. Origens extras permitidas, separadas por vírgula. |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Redis do Cardapim. `REDIS_PORT` vazio = sem Redis (busca a cada 1 s). |

## Operação

- `GET /status` (JSON, logado como adm master) mostra `tempo_real.situacao`: `operacional`, `parado` ou `nao_iniciado` (não altera o HTTP 200/503).
- Logs: `journalctl -u cardapim-tempo-real -f`.
- Deploy: o servidor percebe a troca do link `current`, fecha com `1012` e sai; o systemd sobe a versão nova.
- Instalação na VPS: `docs/deploy/tempo-real.md`.

## Testes

- `tests/unit/tempo-real.php`: ticket, canais, protocolo (RFC 6455), validação da outbox.
- `tests/http/tempo-real.sh` + `tests/realtime/cliente.php`: ponta a ponta no CI (MySQL real), com Redis e sem Redis:
  permissões por cargo e restaurante, origem, ticket repetido/adulterado, entrega em menos de 3 s,
  reenvio na reconexão, ping e status.
