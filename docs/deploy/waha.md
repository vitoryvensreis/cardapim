# WhatsApp pelo WAHA (motor GOWS) na VPS de mensageria

O WAHA (WhatsApp HTTP API, https://waha.devlike.pro) roda na mesma VPS de mensageria da Evolution, em Docker,
com o motor **GOWS** (biblioteca whatsmeow, em Go). Substitui a Evolution (Baileys) para as lojas: cada loja que
reconectar o WhatsApp passa a usar o WAHA.

| Item | Valor |
|---|---|
| Endereço | `https://waha.escotilhaprodutora.com` (painel em `/dashboard`, Swagger em `/`) |
| Servidor | VPS de mensageria (189.24.3.89), Docker `waha`, imagem fixa `devlikeapro/waha:gows-2026.10.1` |
| Pasta | `/opt/waha` (`.env` e `CREDENCIAIS.txt` com permissão 600, `docker-compose.yml`) |
| Sessões | volume Docker `waha_waha_sessions` (persistente; sobrevive a reinício e atualização) |
| HTTPS | Caddy de `/opt/evolution/Caddyfile` (bloco `waha.escotilhaprodutora.com` → `waha:3000`, rede `evolution_default`) |
| Sessões do Deliveryzim | `deliveryzim_r{id}_{8 hex}` (lojas) e `deliveryzim_plataforma_{8 hex}` (códigos de verificação) |

Por que WAHA/GOWS: o whatsmeow mantém sessões estáveis por mais tempo que o Baileys, suporta a passkey que o
WhatsApp passou a pedir em algumas contas e recebe correções com frequência. Continua sendo não oficial: existe
risco de banimento de número, então os envios devem ser moderados (boas-vindas e avisos de pedido).

## Instalação (feita em 28/11/2026)

`/opt/waha/.env` (gerado com `openssl rand`; a chave da API fica com hash `sha512:` no `.env` e em texto só no
`CREDENCIAIS.txt`):

```env
WHATSAPP_DEFAULT_ENGINE=GOWS
WAHA_API_KEY=sha512:<sha512 da chave>
WAHA_DASHBOARD_ENABLED=True
WAHA_DASHBOARD_USERNAME=admin
WAHA_DASHBOARD_PASSWORD=<senha>
WHATSAPP_SWAGGER_USERNAME=admin
WHATSAPP_SWAGGER_PASSWORD=<senha>
WHATSAPP_RESTART_ALL_SESSIONS=True
WAHA_BASE_URL=https://waha.escotilhaprodutora.com
WAHA_LOG_LEVEL=info
TZ=America/Fortaleza
```

`/opt/waha/docker-compose.yml`: serviço `waha`, `restart: unless-stopped`, volume `waha_sessions:/app/.sessions`,
rede externa `evolution_default` (a do Caddy), sem porta publicada (só pelo Caddy).

Atualizar: trocar a tag fixa no `docker-compose.yml` depois de ler as notas da versão
(https://github.com/devlikeapro/waha/releases) e rodar `docker compose pull && docker compose up -d`.

**Antes de apagar a Evolution:** o Caddy (que atende o WAHA e o SMSGate) está no compose da Evolution. Mova o
Caddy para uma pasta própria antes, senão o WAHA e o SMS saem do ar junto.

## Variáveis no `shared/.env` do Deliveryzim

```env
WAHA_URL=https://waha.escotilhaprodutora.com
WAHA_API_KEY=<"Chave da API" do /opt/waha/CREDENCIAIS.txt>
WAHA_WEBHOOK_TOKEN=<openssl rand -hex 32>
WHATSAPP_PROVEDOR_PADRAO=waha
```

- `WAHA_API_KEY`: todas as chamadas, no cabeçalho `X-Api-Key`. Só no servidor; nunca no Git, no navegador ou em print.
- `WAHA_WEBHOOK_TOKEN`: entra na URL que cada sessão de loja chama: `https://deliveryzim.com.br/webhooks/waha/{token}`.
  Trocar o valor exige "Corrigir recebimento" (ou reconectar) em cada loja.
- `WHATSAPP_PROVEDOR_PADRAO=waha`: conexões **novas** nascem no WAHA. Lojas já conectadas seguem no servidor
  gravado em `integracoes.dados.provedor` (`evolution` ou `waha`; sem o campo, Evolution).

## Migrar uma loja

Integrações → WhatsApp → **Desconectar** e depois **Conectar** (lê o QR com o celular da loja). A sessão nova nasce
no WAHA. Conferir em seguida "Recebendo mensagens" e mandar um "Oi" de outro número (diagnóstico das boas-vindas).

## O que o Deliveryzim chama no WAHA (`app/Modules/Integracoes/WahaApi.php`)

| Ação | Chamada |
|---|---|
| Criar sessão (parada, com webhook da loja) | `POST /api/sessions` |
| Iniciar (gera o QR) | `POST /api/sessions/{s}/start` |
| Situação e número | `GET /api/sessions/{s}` (`WORKING`, `SCAN_QR_CODE`, `STARTING`, `PASSKEY_*`, `STOPPED`, `FAILED`) |
| QR Code | `GET /api/{s}/auth/qr` (JSON base64) |
| Conversa certa do número (9 extra) | `GET /api/contacts/check-exists?phone=&session=` |
| Enviar texto | `POST /api/sendText` |
| Webhook (conferir / corrigir) | `GET /api/sessions/{s}` / `PUT /api/sessions/{s}` (reinicia a sessão para aplicar) |
| Sair | `POST /api/sessions/{s}/logout` + `DELETE /api/sessions/{s}` |
| Limpeza de sessões abandonadas | `GET /api/sessions?all=true` + `DELETE` (`bin/whatsapp-sessoes.php`) |

## Webhook (`POST /webhooks/waha/{WAHA_WEBHOOK_TOKEN}`)

- Só sessões de loja têm webhook, com o evento `message` (mensagens recebidas) e reenvio exponencial (4 tentativas).
- Proteção: segredo na URL + `X-Webhook-Hmac` (HMAC-SHA512 do corpo) com o token da loja, gerado ao criar a sessão
  e guardado cifrado em `integracoes.credenciais`.
- `EventoWaha` converte para o formato da `SaudacaoWhatsApp` (mesmo caminho da Evolution: diagnóstico, horário,
  uma boas-vindas por contato por dia). Contato com LID: usa o número alternativo quando vier; senão responde no @lid.

## Pendências conhecidas

- Passkey: quando o WhatsApp pedir passkey, a tela mostra "Preparando o WhatsApp..." (status `PASSKEY_*`).
  Concluir pelo painel do WAHA (`/dashboard`) até a tela do Deliveryzim ganhar essa etapa.
- Monitoramento de ponta a ponta, reinício automático e "Reconectar com código": ver `PLANO-WHATSAPP-WUZAPI.md` (projeto).

Testes: `tests/unit/whatsapp-waha.php` e o bloco "Webhook do WAHA" de `tests/http/avisos.sh`.
