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
| Iniciar (gera o QR) | `POST /api/sessions/{s}/start` (se já iniciada, `422` → `POST .../restart`) |
| Reiniciar (vigia; o celular continua vinculado) | `POST /api/sessions/{s}/restart` |
| Código de conexão (sem QR) | `POST /api/{s}/auth/request-code {phoneNumber}` → `{"code":"ABCD-EFGH"}` (só em `SCAN_QR_CODE`) |
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

## Conectar com código (sem ler o QR)

Na tela de conexão, com o QR Code aberto: **"Não consegue ler o QR Code? Conectar com código"**. A pessoa digita o
número do WhatsApp da loja e recebe um código de 8 caracteres; no celular: Aparelhos conectados → Conectar um
aparelho → **Conectar com número de telefone** → digita o código. Só para servidor WAHA (a Evolution segue só com QR).
Rotas JSON com CSRF: `POST /{slug}/gerencia/integracoes/whatsapp/codigo` e `POST /painel/integracoes/whatsapp/codigo`.
Limite: 5 códigos por 10 minutos por loja.

## Confirmação no celular (passkey)

Status `PASSKEY_REQUIRED` / `PASSKEY_CONFIRMATION_REQUIRED` viram o estado `passkey`: a tela explica que é preciso
confirmar no celular (senha, digital ou rosto do aparelho) e atualiza sozinha depois.

## Vigia (`bin/whatsapp-vigia.php`, a cada minuto)

Instalado no crontab da conta do site pelo deploy (`deploy/scripts/install-whatsapp-vigia-cron.sh`, marcador
`# cardapim-whatsapp-vigia`, log em `shared/storage/logs/whatsapp-vigia.log`, só quando algo acontece).
Para cada WhatsApp em uso (loja ou plataforma) no WAHA:

| Situação no WAHA | O que o vigia faz |
|---|---|
| `WORKING` | Nada. Se estava com alerta, tira o alerta ("WhatsApp reconectado automaticamente") |
| `STOPPED` / `FAILED` | Reinicia na hora; 3 rodadas seguidas sem voltar: alerta na loja. Depois reinicia de 10 em 10 min |
| `STARTING` preso | Espera 1 rodada, depois reinicia; alerta na 3ª |
| `SCAN_QR_CODE` | Celular desvinculado: alerta na hora (só alguém com o celular resolve) |
| `PASSKEY_*` | Alerta: confirmar no celular |
| Servidor do WAHA fora do ar | Nada (não é falha da loja) |

Alerta = `integracoes.status = 'erro'` com o motivo em `ultimo_erro`: aparece na tela do WhatsApp e na Visão geral
("WhatsApp precisa de atenção"), com registro no log de atividades. As boas-vindas continuam saindo enquanto o WhatsApp
estiver de pé.

## Fila das boas-vindas

- Até 3 tentativas por contato/dia (espera de 1 e 3 minutos); as novas tentativas saem pelo vigia.
- Número sem WhatsApp não repete. Boas-vindas que não saiu em 10 minutos é descartada (chegar atrasada atrapalha).
- Envio interrompido no meio (processo caiu) não repete, para o cliente não receber duas vezes.

## Teste de ponta a ponta (a cada 30 minutos)

Feito pelo vigia (`TesteWhatsApp`), só no horário de funcionamento de cada loja e só com o WhatsApp da **plataforma**
conectado:

1. O WhatsApp da plataforma manda para o número da loja: "Teste automático do Deliveryzim: confere se o WhatsApp da
   loja está recebendo mensagens. Pode ignorar. DZT-XXXXXX".
2. O webhook da loja reconhece o código `DZT-...`: marca o teste como recebido e **não** manda boas-vindas.
3. Não chegou em 2 minutos: reinicia a sessão da loja e testa de novo em 3 minutos. Segunda falha seguida: alerta
   "aparece conectado, mas parou de receber mensagens" (tela do WhatsApp e Visão geral). Só o teste chegando (ou a
   loja conectar de novo) tira esse alerta, porque a sessão continua dizendo que está conectada.
4. Problema no WhatsApp da plataforma não conta como falha da loja.

Estado em `integracoes.dados.teste` da loja (`codigo`, `enviado_em`, `recebido_em`, `proximo_em`, `falhas`).

Testes: `tests/unit/whatsapp-waha.php` e o bloco "Webhook do WAHA" de `tests/http/avisos.sh`.
