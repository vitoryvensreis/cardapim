# Servidor do WhatsApp do Deliveryzim (WPPConnect em Docker)

Um container **só do Deliveryzim**, para **várias lojas conectadas ao mesmo tempo** (uma sessão por loja e uma da plataforma).
Fica acessível **apenas por 127.0.0.1**: o site roda na mesma VPS e fala com ele por `http://127.0.0.1:21467`.
Não precisa de domínio, proxy reverso nem porta aberta na internet.

| Item | Valor |
| --- | --- |
| Pasta na VPS | `/opt/wppconnect-deliveryzim` |
| Container | `wppconnect-deliveryzim` |
| Imagem | `deliveryzim/wppconnect-server:2.10.73-wa-js-4.8.2` (construída na VPS a partir de [`deploy/wppconnect/Dockerfile`](../../deploy/wppconnect/Dockerfile)) |
| Configuração | [`deploy/wppconnect/docker-compose.yml`](../../deploy/wppconnect/docker-compose.yml) (cópia idêntica da VPS) |
| Porta | `127.0.0.1:21467` |
| Segredos | `/opt/wppconnect-deliveryzim/.env` (`SECRET_KEY` e `WEBHOOK_URL`), permissão 600, nunca no Git |

## Capacidade

Cada loja conectada é um navegador Chromium escondido: ~400 a 700 MB, mais nos primeiros minutos após ler o QR Code
(baixa as conversas). Com `mem_limit: 6g` cabem **8 a 10 lojas** com folga para os picos.

| Lojas com WhatsApp | `mem_limit` | VPS |
| --- | --- | --- |
| até 10 | 6g | a atual (16 GB, dividida com e-mail e site) |
| 10 a 25 | 12g a 14g | VPS só para o WhatsApp (KVM 4: 4 vCPU, 16 GB) |
| mais que isso | | trocar para um motor sem navegador (WAHA GOWS, Evolution/Baileys) ou API oficial da Meta |

Para subir o limite: editar `mem_limit` e `memswap_limit` na VPS **e** neste repositório, depois
`docker compose up -d --no-deps wppconnect` (as lojas podem precisar ler o QR Code de novo).

## Instalação do zero

1. **Docker**: `docker --version && docker compose version` (se faltar: `curl -fsSL https://get.docker.com | sh`).
2. **Imagem** (com o código do site já publicado em `current/`):
   ```bash
   docker build --pull -t deliveryzim/wppconnect-server:2.10.73-wa-js-4.8.2 \
     -f /home/escotilhaprodutora-cardapim/apps/cardapim/current/deploy/wppconnect/Dockerfile \
     /home/escotilhaprodutora-cardapim/apps/cardapim/current/deploy/wppconnect
   ```
3. **Pasta, chave e webhook**:
   ```bash
   mkdir -p /opt/wppconnect-deliveryzim && cd /opt/wppconnect-deliveryzim && umask 077
   SECRET=$(openssl rand -hex 32)
   HOOK=$(openssl rand -hex 32)
   printf 'SECRET_KEY=%s\nWEBHOOK_URL=https://deliveryzim.com.br/webhooks/wppconnect/%s\n' "$SECRET" "$HOOK" > .env
   echo "WPPCONNECT_SECRET_KEY=$SECRET"; echo "WPPCONNECT_WEBHOOK_TOKEN=$HOOK"
   ```
   Os dois valores vão para o `shared/.env` do site (passo 5). Não mande essas chaves por chat, e-mail nem GitHub.
4. **Configuração**: copiar `deploy/wppconnect/docker-compose.yml` para `/opt/wppconnect-deliveryzim/` e subir:
   ```bash
   cp /home/escotilhaprodutora-cardapim/apps/cardapim/current/deploy/wppconnect/docker-compose.yml .
   docker compose config --quiet && docker compose up -d
   sleep 45 && docker compose ps     # wppconnect-deliveryzim ... (healthy)
   curl -s http://127.0.0.1:21467/healthz; echo
   ```
5. **Ligar o site** (`/home/escotilhaprodutora-cardapim/apps/cardapim/shared/.env`, com backup antes):
   `WPPCONNECT_BASE_URL=http://127.0.0.1:21467`, `WPPCONNECT_SECRET_KEY=...` e `WPPCONNECT_WEBHOOK_TOKEN=...`.
   O site lê o `.env` a cada acesso, não precisa reiniciar.
6. **Cron da limpeza de sessões** (CloudPanel > Cron Jobs, usuário do site), ver [cloudpanel.md](cloudpanel.md):
   `*/15 * * * * php8.4 /home/<usuario>/apps/cardapim/current/bin/whatsapp-sessoes.php --limpar >> /home/<usuario>/apps/cardapim/shared/storage/logs/whatsapp-sessoes.log 2>&1`
7. **Conectar**: plataforma primeiro (painel do adm), depois cada loja, **uma de cada vez** com ~3 minutos entre elas.

## Conferências rápidas

```bash
cd /opt/wppconnect-deliveryzim
SK=$(grep '^SECRET_KEY=' .env | cut -d= -f2-)
docker compose ps                                                         # (healthy)
docker stats --no-stream wppconnect-deliveryzim                           # memória usada / 6GiB
curl -s -X POST -H "x-secret-key: $SK" http://127.0.0.1:21467/api/show-all-sessions; echo
cat /sys/fs/cgroup/system.slice/docker-$(docker inspect -f '{{.Id}}' wppconnect-deliveryzim).scope/memory.events | grep oom_kill
docker compose logs --since 10m wppconnect 2>&1 | grep -v 'onMessage\|onAnyMessage\|onAck\|onPresence' | tail -60
```

No site (usuário do site, pasta `current/`): `php8.4 bin/whatsapp-sessoes.php` lista as sessões do servidor, as que estão em uso
e as abandonadas (não fecha nada sem `--limpar`).

- `oom_kill` maior que 0: faltou memória e o sistema derrubou um navegador. Suba `mem_limit`.
- Sessões na lista que não são de nenhuma loja: o cron fecha depois de 10 minutos; na hora, `php8.4 bin/whatsapp-sessoes.php --limpar --sem-espera`.

## Diagnóstico de 20/11/2026 (por que a segunda loja travava)

- **Sintoma**: com a plataforma e uma loja conectadas tudo funcionava; ao conectar a segunda loja, uma delas passava a dar
  "O serviço demorou demais para responder" no teste e nos avisos, e às vezes o QR Code não aparecia. O painel mostrava "Conectado".
- **Causa**: o container tinha `mem_limit: 2g`. Com plataforma + 2 lojas + **sessões abandonadas** (desconectar com o servidor
  travado deixava a sessão antiga aberta e o painel criava outra), o limite foi atingido 4.475 vezes e o kernel matou navegadores
  (`memory.events`: `oom_kill 2`). O log mostrava `Runtime.callFunctionOn timed out`.
- **Correção**: só o Deliveryzim usa a VPS para WhatsApp (servidores do TaskEsc e do LeagueCut removidos, backup em
  `/root/backup-wpp-antigos`), servidor recriado do zero como `wppconnect-deliveryzim` com 6 GB, `init`, `shm` de 2 GB e sem swap;
  limpeza automática de sessões abandonadas (`bin/whatsapp-sessoes.php`) e a tela de WhatsApp passou a avisar quando a sessão
  aparece conectada mas não responde (`check-connection-session`).

## Problemas comuns

| Sintoma | O que fazer |
| --- | --- |
| Tela de WhatsApp diz "conectado, mas não está respondendo" | Esperar 2 minutos (pode estar baixando conversas). Se continuar: Desconectar e conectar de novo. Conferir `oom_kill` e memória. |
| QR Code não aparece | `docker compose ps` (healthy?) e o log das últimas linhas. Conferir memória. |
| "O servidor do WhatsApp recusou a conexão" | `WPPCONNECT_SECRET_KEY` do site diferente da `SECRET_KEY` do container. |
| Boas-vindas não saem | `WEBHOOK_URL` no `.env` do container e `WPPCONNECT_WEBHOOK_TOKEN` no site precisam ter o mesmo segredo ([saudacao-whatsapp.md](../architecture/saudacao-whatsapp.md)). |
| WhatsApp cai sozinho | Aparelho removido em "Aparelhos conectados" ou número bloqueado. Conectar de novo pelo painel. |

## Manutenção

| Tarefa | Comando (em `/opt/wppconnect-deliveryzim`) |
| --- | --- |
| Reiniciar | `docker compose restart` (as sessões devem voltar sozinhas; loja que aparecer desconectada, conectar pelo painel) |
| Aplicar mudança no compose | `docker compose config --quiet && docker compose up -d --no-deps wppconnect` |
| Parar (mantém as sessões) | `docker compose down` |
| Apagar tudo, inclusive sessões | `docker compose down -v` (todas as lojas leem o QR Code de novo) |
| Voltar para a imagem original | trocar `image:` por `wppconnect/wppconnect-server:2.10.73` e `up -d --no-deps wppconnect` |

Nunca usar `npm install` nem editar `node_modules` dentro do container: mudanças de versão passam pelo `Dockerfile` e pelo
workflow `wppconnect-image.yml` (que constrói e valida a imagem no CI).
