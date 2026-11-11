# Tempo real na VPS (Redis + servidor WebSocket + Nginx)

Passo a passo para ligar o tempo real em produção. Comandos como **root** no terminal da VPS.
Arquitetura e regras: `docs/architecture/tempo-real.md`.

Resumo do que fica rodando:

| Peça | Onde | Porta (só 127.0.0.1) |
| --- | --- | --- |
| Redis do Cardapim | container Docker `redis-cardapim` em `/opt/redis-cardapim` | 6381 |
| Servidor de tempo real | serviço systemd `cardapim-tempo-real` (PHP 8.4, usuário do site) | 8091 |
| Nginx (CloudPanel) | `location = /ws` no vhost do site | 443 (`wss://deliveryzim.com.br/ws`) |

Nada disso mexe nos Redis do mailcow nem nos WPPConnect existentes.

## 1. Redis isolado do Cardapim

```bash
mkdir -p /opt/redis-cardapim && cd /opt/redis-cardapim
printf 'REDIS_PASSWORD=%s\n' "$(openssl rand -hex 32)" > .env && chmod 600 .env
cp /home/escotilhaprodutora-cardapim/apps/cardapim/current/deploy/redis/docker-compose.yml .
docker compose up -d && sleep 3 && docker compose ps
docker exec redis-cardapim sh -c 'redis-cli -a "$REDIS_PASSWORD" --no-auth-warning ping'
ss -tlnp | grep 6381
```

Esperado: `PONG` e `127.0.0.1:6381`.

## 2. Configuração do Cardapim (`shared/.env`)

Copia a senha do Redis sem mostrar na tela e acrescenta as variáveis (com backup antes):

```bash
ENV=/home/escotilhaprodutora-cardapim/apps/cardapim/shared/.env
cp -p "$ENV" "$ENV.bak-$(date +%Y%m%d%H%M%S)"
grep -q '^REDIS_PORT=' "$ENV" || { P=$(grep '^REDIS_PASSWORD=' /opt/redis-cardapim/.env | cut -d= -f2); printf '\nREDIS_HOST=127.0.0.1\nREDIS_PORT=6381\nREDIS_PASSWORD=%s\nWS_PORTA=8091\n' "$P" >> "$ENV"; unset P; }
grep -E '^(REDIS_|WS_)' "$ENV" | sed 's/\(PASSWORD=\).*/\1********/'
```

`WS_ATIVO` fica para o passo 6 (só depois que tudo estiver respondendo).

## 3. Tabela `outbox_eventos`

É criada pelo deploy (gatilho `[cardapim-tempo-real]`, com backup antes). Conferir:

```bash
sudo -u escotilhaprodutora-cardapim php8.4 /home/escotilhaprodutora-cardapim/apps/cardapim/current/database/verify-tempo-real.php --verify
```

## 4. Serviço do servidor de tempo real

```bash
cp /home/escotilhaprodutora-cardapim/apps/cardapim/current/deploy/systemd/cardapim-tempo-real.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now cardapim-tempo-real
sleep 3; systemctl --no-pager status cardapim-tempo-real | head -12
journalctl -u cardapim-tempo-real --no-pager -n 20
ss -tlnp | grep 8091
```

Esperado no log: `Tempo real ouvindo em tcp://127.0.0.1:8091` e `Redis conectado`.

## 5. Nginx (CloudPanel)

CloudPanel > Sites > `cardapim.escotilhaprodutora.com` > **Vhost**. Dentro do bloco `server { ... }` que tem
`listen 443`, antes do `location /`, cole o conteúdo de `deploy/nginx/tempo-real.conf.example` e salve
(o CloudPanel testa e recarrega o Nginx). Teste pela internet:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' \
  -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' -H 'Origin: https://deliveryzim.com.br' \
  https://deliveryzim.com.br/ws
```

Esperado: **401** (chegou no servidor de tempo real e ele pediu o ticket). `404`/`502` = Nginx ainda não encaminha.

## 6. Ligar nas telas

```bash
ENV=/home/escotilhaprodutora-cardapim/apps/cardapim/shared/.env
grep -q '^WS_ATIVO=' "$ENV" && sed -i 's/^WS_ATIVO=.*/WS_ATIVO=1/' "$ENV" || printf 'WS_ATIVO=1\n' >> "$ENV"
curl -s -H 'Accept: application/json' https://deliveryzim.com.br/status
```

O status deve mostrar `"tempo_real":{"situacao":"operacional","redis":"conectado"}`. No painel do gerente
aparece **Ao vivo** no topo. Teste: abra o Estoque em duas abas, reponha um insumo numa delas; a outra
mostra "O estoque mudou em outra tela".

Para desligar sem desinstalar: `WS_ATIVO=` (vazio) no `.env`. As telas voltam a funcionar como antes.

## Manutenção

| O que | Comando |
| --- | --- |
| Logs ao vivo | `journalctl -u cardapim-tempo-real -f` |
| Reiniciar | `systemctl restart cardapim-tempo-real` |
| Situação | `systemctl status cardapim-tempo-real` ou `/status` (JSON) |
| Redis | `cd /opt/redis-cardapim && docker compose ps` / `docker compose logs --tail=50` |

Deploy novo não precisa de nada: o servidor percebe a release nova, avisa os navegadores (código 1012),
sai e o systemd sobe a versão nova em ~2 s; as telas reconectam sozinhas e recebem o que perderam.

## Problemas comuns

- **"Sem atualização ao vivo" no painel**: `systemctl status cardapim-tempo-real`; teste do passo 5 (401 esperado).
- **Log com "Redis indisponivel"**: o tempo real continua (busca a cada 1 s). Conferir `docker compose ps` e a senha no `.env`.
- **Log com "Banco indisponivel"**: o servidor tenta de novo a cada 5 s sozinho; conferir o MySQL.
- **Conexão recusada: origem não permitida**: o `APP_URL` do `.env` precisa ser exatamente o endereço do site.
