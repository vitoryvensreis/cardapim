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

Por que Evolution: não abre um navegador por loja, então cada WhatsApp conectado custa pouca memória.
O canal Baileys usa o WhatsApp Web, que não é oficial: existe risco de banimento de número. Para volume alto
de automação, a própria Evolution oferece o canal oficial (Cloud API da Meta).

## Variáveis no `shared/.env` do Deliveryzim

```env
EVOLUTION_BASE_URL=https://wpp.escotilhaprodutora.com
EVOLUTION_API_KEY=<AUTHENTICATION_API_KEY do /opt/evolution/.env>
EVOLUTION_WEBHOOK_TOKEN=<openssl rand -hex 32>
```

- `EVOLUTION_API_KEY` é a chave global. Fica só no servidor; nunca no Git, no navegador ou em print.
- `EVOLUTION_WEBHOOK_TOKEN` entra na URL que cada instância chama:
  `https://deliveryzim.com.br/webhooks/evolution/{token}`. Trocar o valor exige reconectar as lojas
  (o endereço fica gravado em cada instância).

## Conectar e reconectar

- Cada loja conecta pelo painel (**Integrações → WhatsApp → Conectar**); o WhatsApp da plataforma, em `/painel/integracoes/whatsapp`.
- `integracoes.dados.provedor` = `evolution`. O código depende só do contrato `ProvedorWhatsApp`.

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
4. Regras de boas-vindas: [saudacao-whatsapp.md](../architecture/saudacao-whatsapp.md).

Outros eventos respondem 200 e são ignorados.

## Limpeza de instâncias abandonadas

`bin/whatsapp-sessoes.php --limpar` (cron a cada 15 min). Só mexe em instâncias com nome do
Deliveryzim (`deliveryzim_...`). Instâncias de outros projetos no mesmo
servidor (por exemplo `teste-escotilha` ou `taskesc-...`) nunca são tocadas.

## Testes

- `php tests/unit/whatsapp-evolution.php`: nomes, cliente da Evolution sem rede e conversão do webhook
  (inclusive LID, grupo, status e mensagens da própria loja).
- `tests/fakes/integracoes-fake.php` imita a Evolution (`/evo/...`) nos testes HTTP do CI.

## Problemas comuns

| Sintoma | Verificar |
|---|---|
| "O WhatsApp ainda não está disponível" | `EVOLUTION_BASE_URL` e `EVOLUTION_API_KEY` no `shared/.env`; recarregar o PHP-FPM. |
| "chave do servidor incorreta" | `EVOLUTION_API_KEY` diferente do `AUTHENTICATION_API_KEY` em `/opt/evolution/.env`. |
| Boas-vindas não saem | `EVOLUTION_WEBHOOK_TOKEN` preenchido **antes** de conectar a loja; logs: `docker compose logs evolution`. |
| Evolution fora do ar | Na VPS de mensageria: `cd /opt/evolution && docker compose ps` e `docker compose up -d`. |
| Atualizar a Evolution | Não rodar `docker compose pull` sem planejar: a v2.4 exige ativação de licença no `/manager`. |
