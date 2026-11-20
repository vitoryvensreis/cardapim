# WhatsApp pela Evolution API (VPS de mensageria do Deliveryzim)

A Evolution API roda numa VPS separada, que pode atender outros projetos da Escotilha Produtora.
O Deliveryzim fala com ela por HTTPS. Cada loja tem uma instância própria, criada pelo próprio sistema
quando o gerente clica em **Conectar WhatsApp**.

| Item | Valor |
|---|---|
| Endereço | `https://wpp.escotilhaprodutora.com` (painel em `/manager`) |
| Servidor | VPS KVM 2, Ubuntu, Docker: `evolution` (v2.3.7, canal Baileys), `postgres`, `redis`, `caddy` (HTTPS) |
| Pasta | `/opt/evolution` (`.env` com permissão 600, `docker-compose.yml`, `Caddyfile`) |
| Backup | `/opt/evolution/backup.sh` todo dia às 4h, 7 dias em `/opt/backups/evolution` |
| Instâncias do Deliveryzim | `deliveryzim_r{id}_{8 hex}` (lojas) e `deliveryzim_plataforma_{8 hex}` (códigos de verificação) |

Por que Evolution: não abre um navegador por loja (o WPPConnect abre um Chrome por sessão), então cada
WhatsApp conectado custa bem menos memória. O risco de banimento é o mesmo do WPPConnect: os dois usam o
WhatsApp Web, que não é oficial.

## Variáveis no `shared/.env` do Deliveryzim

```env
EVOLUTION_BASE_URL=https://wpp.escotilhaprodutora.com
EVOLUTION_API_KEY=<AUTHENTICATION_API_KEY do /opt/evolution/.env>
EVOLUTION_WEBHOOK_TOKEN=<openssl rand -hex 32>
WHATSAPP_PROVEDOR_PADRAO=evolution
```

- `EVOLUTION_API_KEY` é a chave global. Fica só no servidor; nunca no Git, no navegador ou em print.
- `EVOLUTION_WEBHOOK_TOKEN` entra na URL que cada instância chama:
  `https://deliveryzim.com.br/webhooks/evolution/{token}`. Trocar o valor exige reconectar as lojas
  (o endereço fica gravado em cada instância).
- `WHATSAPP_PROVEDOR_PADRAO` vale só para conexões **novas**. Se o servidor escolhido não estiver
  configurado, o sistema usa o outro.

## Como o sistema escolhe o servidor

- `integracoes.dados.provedor` grava o servidor de cada loja (`evolution` ou `wppconnect`).
  Sessão antiga sem esse campo é do WPPConnect.
- Avisos de pedido, saudação, mensagem de teste e desconectar usam sempre o servidor gravado na loja.
- **Migrar uma loja**: no painel da loja, **Desconectar** e **Conectar** de novo. A sessão do WPPConnect
  é encerrada e a nova instância nasce na Evolution.
- O WhatsApp da plataforma (códigos de verificação) migra do mesmo jeito, em `/painel/integracoes/whatsapp`.

## O que o Deliveryzim chama na Evolution

| Ação | Chamada | Chave |
|---|---|---|
| Conectar (instância nova) | `POST /instance/create` (Baileys, ignora grupos, sem histórico, webhook `MESSAGES_UPSERT`) | global |
| Gerar QR Code | `GET /instance/connect/{instancia}` | da loja |
| Estado | `GET /instance/connectionState/{instancia}` (`open`, `connecting`, `close`) | da loja |
| Número conectado | `GET /instance/fetchInstances?instanceName=` | da loja |
| Enviar texto | `POST /message/sendText/{instancia}` | da loja |
| Desconectar | `DELETE /instance/logout/{instancia}` e `DELETE /instance/delete/{instancia}` | da loja / global |
| Limpeza | `GET /instance/fetchInstances` e `DELETE /instance/delete/` | global |

A "chave da loja" é o `hash` devolvido na criação da instância, guardado cifrado em `integracoes.credenciais`.

## Webhook

`POST /webhooks/evolution/{EVOLUTION_WEBHOOK_TOKEN}` (`EvolutionWebhookController`):

1. Confere o segredo da URL (32+ caracteres, comparação em tempo constante).
2. Converte `messages.upsert` para o formato da saudação (`Services/WhatsApp/EventoEvolution`).
   Contato com LID usa o número real quando a Evolution informa (`remoteJidAlt` ou `senderPn`).
3. Se a instância for de uma loja, o `apikey` enviado no corpo precisa ser o token guardado da loja (ou a chave global, conforme a versão da Evolution).
4. A regra de boas-vindas é a mesma do WPPConnect ([saudacao-whatsapp.md](../architecture/saudacao-whatsapp.md)).

Outros eventos respondem 200 e são ignorados.

## Limpeza de instâncias abandonadas

`bin/whatsapp-sessoes.php --limpar` roda para cada servidor configurado. Só mexe em instâncias com nome do
Deliveryzim (`deliveryzim_...` ou o antigo `cardapim_...`). Instâncias de outros projetos no mesmo
servidor (por exemplo `teste-escotilha` ou `taskesc-...`) nunca são tocadas.

## Testes

- `php tests/unit/whatsapp-evolution.php`: escolha do servidor, nomes, cliente da Evolution sem rede e
  conversão do webhook (inclusive LID, grupo, status e mensagens da própria loja).

## Problemas comuns

| Sintoma | Verificar |
|---|---|
| "O WhatsApp ainda não está disponível" | `EVOLUTION_BASE_URL` e `EVOLUTION_API_KEY` no `shared/.env`; recarregar o PHP-FPM. |
| "chave do servidor incorreta" | `EVOLUTION_API_KEY` diferente do `AUTHENTICATION_API_KEY` em `/opt/evolution/.env`. |
| Boas-vindas não saem | `EVOLUTION_WEBHOOK_TOKEN` preenchido **antes** de conectar a loja; logs: `docker compose logs evolution`. |
| Evolution fora do ar | Na VPS de mensageria: `cd /opt/evolution && docker compose ps` e `docker compose up -d`. |
| Atualizar a Evolution | Não rodar `docker compose pull` sem planejar: a v2.4 exige ativação de licença no `/manager`. |
