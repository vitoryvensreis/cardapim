# WPPConnect do Cardapim em Docker (instância isolada)

Um container **só do Cardapim**, separado do WPPConnect que já existe na VPS (`wppconnect.escotilhaprodutora.com`).
Ele fica acessível **apenas por 127.0.0.1** (dentro da própria VPS): o Cardapim roda na mesma máquina e conversa com ele
por `http://127.0.0.1:21466`. Não precisa de domínio, proxy reverso nem porta aberta na internet.

> Não mexa no container/serviço do WPPConnect que já existe. Os nomes, a porta e os volumes abaixo são diferentes de propósito.

## 1. Conferir o Docker

```bash
docker --version
docker compose version
```

Se os dois responderem, vá para o passo 2. Se o Docker não estiver instalado, use o script oficial e confira de novo:

```bash
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker
docker --version && docker compose version
```

## 2. Ver o que já está rodando (para não usar a mesma porta)

```bash
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Ports}}'
ss -tlnp | grep -E ':2146[0-9]' || echo "portas 21460-21469 livres"
```

O WPPConnect existente provavelmente usa a 21465. Vamos usar a **21466**. Se ela aparecer ocupada, troque `21466` por outra livre
(ex.: 21467) nos passos 4, 6 e 8.

## 3. Criar a pasta e a chave secreta

```bash
mkdir -p /opt/wppconnect-cardapim
cd /opt/wppconnect-cardapim
umask 077
SECRET=$(openssl rand -hex 32)
printf 'SECRET_KEY=%s\n' "$SECRET" > .env
chmod 600 .env
echo "$SECRET"
```

Copie o valor que apareceu: ele vai no passo 8. Não mande essa chave por chat, e-mail nem para o GitHub.

## 4. Criar o docker-compose.yml

```bash
cat > /opt/wppconnect-cardapim/docker-compose.yml <<'YAML'
name: wppconnect-cardapim
services:
  wppconnect:
    image: wppconnect/wppconnect-server:latest
    container_name: wppconnect-cardapim
    restart: unless-stopped
    env_file: .env
    ports:
      - "127.0.0.1:21466:21465"
    shm_size: "1gb"
    mem_limit: 2g
    volumes:
      - tokens:/usr/src/wpp-server/tokens
      - userdata:/usr/src/wpp-server/userDataDir
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"
volumes:
  tokens:
  userdata:
YAML
```

- `127.0.0.1:21466` = só a própria VPS enxerga. **Nunca** troque por `21466:21465` (isso abriria para a internet).
- `tokens` e `userdata` guardam as sessões: reiniciar o container não desconecta os WhatsApps.
- `shm_size` e `mem_limit`: o WPPConnect usa o Chromium; isso evita travamentos e protege o resto da VPS.

## 5. Subir o container

```bash
cd /opt/wppconnect-cardapim
docker compose pull
docker compose up -d
docker compose ps
docker compose logs --tail=50
```

O `ps` deve mostrar `wppconnect-cardapim` como `running` (ou `Up`). Os logs não devem ter erros em loop.

## 6. Testar por dentro da VPS

```bash
curl -s http://127.0.0.1:21466/healthz; echo
curl -s -X POST "http://127.0.0.1:21466/api/teste-cardapim/$(grep SECRET_KEY /opt/wppconnect-cardapim/.env | cut -d= -f2)/generate-token"; echo
ss -tlnp | grep 21466
```

- O primeiro comando responde algo como `ok`/`{"status":"ok"}`.
- O segundo devolve `"status":"success"` e um `token` (prova que a chave está certa).
- O terceiro precisa mostrar **`127.0.0.1:21466`** (e não `0.0.0.0:21466`).

## 7. (Opcional) Conferir que não está exposto

No seu computador (fora da VPS):

```bash
curl -m 5 http://IP_DA_VPS:21466/healthz
```

Tem que **falhar** (tempo esgotado ou conexão recusada).

## 8. Ligar o Cardapim ao WPPConnect

Acrescente duas linhas no `.env` compartilhado do Cardapim (troque `COLE_A_CHAVE_AQUI` pelo valor do passo 3):

```bash
ENV=/home/escotilhaprodutora-cardapim/apps/cardapim/shared/.env
cp -p "$ENV" "$ENV.bak-$(date +%Y%m%d%H%M)"
grep -q '^WPPCONNECT_BASE_URL=' "$ENV" || printf '\nWPPCONNECT_BASE_URL=http://127.0.0.1:21466\n' >> "$ENV"
grep -q '^WPPCONNECT_SECRET_KEY=' "$ENV" || printf 'WPPCONNECT_SECRET_KEY=%s\n' 'COLE_A_CHAVE_AQUI' >> "$ENV"
grep -E '^WPPCONNECT_' "$ENV" | sed 's/\(SECRET_KEY=\).*/\1********/'
ls -l "$ENV"
```

- O `.env` continua com o mesmo dono e permissão (o `>>` só acrescenta linhas).
- Não precisa reiniciar nada: o Cardapim lê o `.env` a cada acesso.
- Confira também se existe `APP_URL=https://cardapim.escotilhaprodutora.com` nesse arquivo (é usado nos avisos do Asaas).

## 9. Conectar pelo painel

Entre como gerente > **Integrações** > **WhatsApp** > **Conectar WhatsApp** e leia o QR Code com o celular do restaurante
(WhatsApp > Aparelhos conectados > Conectar um aparelho). Depois envie uma mensagem de teste pela mesma tela.

## Manutenção

| Tarefa | Comando (dentro de `/opt/wppconnect-cardapim`) |
| --- | --- |
| Ver se está rodando | `docker compose ps` |
| Ver os logs | `docker compose logs --tail=100 -f` (Ctrl+C para sair) |
| Reiniciar | `docker compose restart` |
| Atualizar a imagem | `docker compose pull && docker compose up -d` |
| Parar (mantém as sessões) | `docker compose down` |
| Apagar tudo, inclusive sessões | `docker compose down -v` (todos os restaurantes precisam ler o QR de novo) |

Depois que estiver estável, fixe a versão da imagem no `docker-compose.yml` (troque `latest` pela tag em uso) para uma atualização
não chegar sem você querer.

## Problemas comuns

- **Container reiniciando sem parar**: `docker compose logs --tail=200`. Falta de memória aparece como `Killed`; aumente `mem_limit`.
- **QR Code não aparece no painel**: confira o passo 6 e as duas linhas do passo 8 (URL com a porta certa e chave igual à do `.env` do container).
- **"O servidor do WhatsApp recusou a conexão"** no painel: a `WPPCONNECT_SECRET_KEY` do Cardapim é diferente da `SECRET_KEY` do container.
- **WhatsApp cai sozinho**: o celular ficou sem internet ou o aparelho foi removido em "Aparelhos conectados". Conecte de novo pelo painel.
