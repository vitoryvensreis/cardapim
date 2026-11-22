# Análise de desempenho — Deliveryzim (Cardapim)

Data: 22/11/2026 · Base analisada: `main` em `b93b205`
Objetivo: saber o que acontece quando muitas lojas trabalham ao mesmo tempo, e o que mudar, em ordem de prioridade.

> Esta análise foi feita lendo o código e a configuração do repositório. Não houve teste de carga na VPS.
> Os números de carga abaixo são estimativas calculadas a partir dos intervalos de consulta que estão no código.
> Antes de decidir investimentos, faça a medição da seção 7.

---

## 1. Resumo

O sistema aguenta bem o volume de **pedidos** previsto: cerca de 200 lojas com 10 a 20 pedidos por dia cada dá 2 a 4 mil pedidos/dia. O gargalo não são os pedidos. É o **tráfego de fundo**: telas e programas que perguntam ao servidor “tem novidade?” a cada poucos segundos, mesmo quando não há nada novo.

| Prioridade | Problema | Impacto com 200 lojas | Esforço |
| --- | --- | --- | --- |
| P1 | Agent Windows consulta `/api/v1/desktop/eventos` a cada 2,5 s e cada consulta faz um `UPDATE` | ~80 req/s e ~80 gravações/s no MySQL só de Agents parados | Baixo |
| P1 | Pool do PHP-FPM e OPcache não estão documentados nem versionados | Fila de requisições e respostas lentas no horário de pico | Baixo |
| P1 | Envio de WhatsApp/e-mail/iFood em `register_shutdown_function` segura o worker do PHP até 20 s | Poucas mensagens lentas esgotam o pool do PHP-FPM | Médio |
| P2 | `Esquema::tem()` consulta o `information_schema` em toda requisição | Consulta cara repetida milhares de vezes por minuto | Baixo |
| P2 | Sessões e limitador de tentativas em arquivo (`storage/sessions`, `storage/cache`) | Trava de arquivo por sessão, I/O de disco e limpeza aleatória | Baixo |
| P2 | Telas com consulta periódica (cardápio público 10 s, garçom Pix 3 s, balança 1,5 s, cozinha 20 s, iFood 10 s) | Somam centenas de req/s quando muitos clientes estão com o cardápio aberto | Médio |
| P3 | `filemtime` por arquivo de CSS/JS em toda página, roteador compila ~360 regex por requisição | Alguns milissegundos por página | Baixo |
| P3 | Tabelas que só crescem (`outbox_eventos` limpa; `impressoes`, `log_user`, saudações precisam de rotina) | Consultas ficam lentas com meses de uso | Baixo |
| P3 | Sem monitoramento de lentidão (slow log do PHP-FPM e do MySQL) | Problemas só aparecem quando o cliente reclama | Baixo |

Com P1 e P2 resolvidos, a VPS atual (KVM 4: 4 vCPU, 16 GB RAM, NVMe) tem folga para a meta de 200 lojas. Sem eles, a VPS passa a maior parte do tempo respondendo “não há nada novo”.

---

## 2. Como uma requisição percorre o sistema

```
Navegador / Agent / iFood / Evolution
        │  HTTPS
        ▼
Nginx ──► arquivos estáticos (public/assets, ícones, PWA)  ← cache longo (?v=filemtime)
   │
   ├──► /ws (proxy) ──► bin/tempo-real.php (systemd, 1 processo, WebSocket próprio)
   │                         ▲ lê outbox_eventos quando o Redis avisa (canal "outbox")
   │
   └──► PHP-FPM ──► public/index.php
                     ├─ bootstrap/app.php (autoload, fuso)
                     ├─ DominioCanonico (301 do domínio antigo)
                     ├─ routes/web.php (+ plataforma, gerência, caixa, garçom, api)
                     ├─ Router::dispatch → percorre as rotas em ordem, compila cada padrão
                     └─ Controller → Service → Repository → PDO (1 conexão por requisição)
                                                  │
                                                  └─ MySQL (fonte da verdade)
                                                  └─ Redis (só aviso do tempo real)
```

Pontos importantes:

- **Uma conexão PDO por requisição** (`app/Core/Database.php`), sem conexão persistente, `ATTR_TIMEOUT` 5 s. Para este porte isso é correto. Não ative conexões persistentes sem limitar o pool do PHP-FPM, porque cada worker passaria a segurar uma conexão aberta.
- **`.env` é lido uma vez por requisição** (`Config::arquivo()` guarda em memória estática). Custo baixo.
- **O tempo real é um processo só** (`bin/tempo-real.php`, `MemoryMax=256M`, `LimitNOFILE=4096`). Ele aguenta milhares de conexões ociosas. O limite de 4096 arquivos abertos vira teto de conexões simultâneas: com 200 lojas e cerca de 5 telas por loja são 1000 conexões, então há folga. Acima de ~3000 telas, aumente `LimitNOFILE`.

---

## 3. Problemas e correções, em ordem

### P1.1 — Agent Windows: consulta a cada 2,5 s com gravação

Onde: `desktop-agent/agent/app.py` (`_poll`, `delay = 2.5`) e `app/Controllers/Api/DesktopAgentController.php` (`eventos()` e `dispositivo()`).

O que acontece em cada consulta, mesmo sem pedido novo:

1. Uma consulta com `JOIN` de 3 tabelas para autenticar o token.
2. `UPDATE desktop_agent_devices SET ultimo_acesso_em = NOW()`, uma gravação com log binário e trava de linha.
3. `SELECT` em `outbox_eventos` (o índice `idx_outbox_restaurante (id_restaurante, id)` atende bem).
4. `Esquema::tem('impressoes')` e o `SELECT` da fila de impressão.

Conta: 200 lojas × 1 computador ÷ 2,5 s = **80 requisições/s e 80 UPDATEs/s**, 24 horas por dia se o computador ficar ligado. Quando há pedido tocando, soma-se `/api/v1/desktop/pedidos/situacao` a cada 5 s.

Correções (fazer na ordem):

1. **Gravar `ultimo_acesso_em` no máximo 1 vez por minuto**: `UPDATE ... WHERE id = ? AND (ultimo_acesso_em IS NULL OR ultimo_acesso_em < NOW() - INTERVAL 60 SECOND)`. Isso tira ~98% das gravações e não muda nada para o usuário.
2. **Intervalo adaptativo no Agent**: 2,5 s quando a loja teve movimento nos últimos minutos e 10 s quando está parada. Volta a 2,5 s assim que chega um pedido ou cupom. Divide a carga por 3 a 4.
3. **Melhor solução (médio prazo)**: o Agent assina o WebSocket que já existe (canal `pedidos` e `impressoes` da loja) e só consulta `/eventos` quando recebe o aviso, ou a cada 60 s como garantia. A carga cai para perto de zero e a impressão fica mais rápida.

### P1.2 — PHP-FPM e OPcache sem configuração versionada

Não há no repositório (`deploy/`) a configuração do pool PHP-FPM nem do OPcache. Elas ficam só no CloudPanel e podem estar no padrão (`pm = ondemand`, `pm.max_children = 5` ou 10 em muitos painéis). Com 5 workers, 5 requisições lentas ao mesmo tempo param o site.

Recomendação para a KVM 4 (4 vCPU, 16 GB). Cada worker usa cerca de 40 a 60 MB:

```ini
; pool do site (CloudPanel > Site > PHP Settings ou arquivo do pool)
pm = dynamic
pm.max_children = 40
pm.start_servers = 8
pm.min_spare_servers = 6
pm.max_spare_servers = 16
pm.max_requests = 1000
request_slowlog_timeout = 3s
slowlog = /home/<usuario>/logs/php/slow.log
request_terminate_timeout = 60s
```

```ini
; php.ini (OPcache)
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 60
opcache.jit = tracing
opcache.jit_buffer_size = 64M
```

O deploy troca o link `current` para outra pasta, e o OPcache guarda os arquivos pelo caminho real. Por isso `validate_timestamps = 1` funciona sem `reload`. Para invalidar o cache na hora, coloque `sudo systemctl reload php8.4-fpm` no fim do `activate-release.sh`, se o usuário do deploy tiver permissão.

Ação: copiar estes valores para `deploy/php-fpm/cardapim.conf.example` e documentar em `docs/deploy/cloudpanel.md`.

### P1.3 — Envios externos seguram workers do PHP

Onde: `AvisosWhatsApp`, `SaudacaoWhatsApp`, `Mailer`, `IfoodAcoes` usam `register_shutdown_function` com `fastcgi_finish_request()`. O cliente recebe a resposta rápido, mas **o worker do PHP-FPM continua ocupado** até a chamada externa acabar. O timeout do Evolution/WhatsApp é de 20 s.

Se o Evolution ficar lento e 40 avisos estiverem saindo, os 40 workers ficam presos e o site inteiro para de responder, inclusive para lojas que não usam WhatsApp.

Correção:

1. **Curto prazo**: baixar o timeout de conexão para 3 s e o total para 8 s nos envios em segundo plano.
2. **Definitivo**: fila de trabalhos. O PHP grava a tarefa em uma tabela `fila_tarefas` (ou na `outbox_eventos`, que já existe), e um processo em `workers/` (pasta já criada e vazia) executa os envios sob systemd, com novas tentativas e idempotência. Isso segue a regra de Transactional Outbox do projeto e libera o PHP-FPM só para atender telas.

### P2.1 — `Esquema::tem()` em toda requisição

Onde: `app/Shared/Esquema.php`. Cada recurso consultado faz um `SELECT COUNT(*) FROM information_schema.COLUMNS ...`. No MySQL 8 essa consulta é rápida, mas não é de graça, e roda em quase toda tela de pedido, cardápio e impressão (até 10 recursos).

Correção: guardar o resultado no **APCu** (`apcu_fetch`/`apcu_store`, validade de 5 minutos), ou em um arquivo `storage/cache/esquema.php` gerado pelo `migrate.php --apply`. Se o APCu não estiver instalado, mantém o comportamento atual. Depois que todas as migrações estiverem aplicadas em produção, um recurso pode sair da lista e virar código fixo.

### P2.2 — Sessões e limitador em arquivo

Onde: `app/Core/Session.php` (`storage/sessions`, limpeza com probabilidade 1/100) e `app/Core/RateLimiter.php` (`storage/cache`).

- O PHP **trava o arquivo da sessão** durante a requisição. Duas abas do caixa na mesma sessão esperam uma pela outra. O código já chama `session_write_close()` antes dos envios lentos, o que é bom.
- A limpeza 1/100 faz uma requisição aleatória varrer a pasta inteira.

Correção: usar o Redis que já existe (porta 6381) para sessões, com `session.save_handler = redis` (extensão phpredis) e um prefixo próprio. Ou manter em arquivo, desligar a limpeza aleatória (`session.gc_probability = 0`) e limpar por cron a cada hora. Nas rotas só de leitura (`/situacao`, `/eventos`, cardápio público) abra a sessão com `read_and_close` ou não abra.

Atenção: o Redis está com `maxmemory 128mb` e `allkeys-lru`. Para guardar sessões, troque para `volatile-lru` e dê validade a cada chave, para o Redis nunca apagar uma sessão ativa. Ou use um banco Redis separado.

### P2.3 — Consultas periódicas das telas

| Tela | Arquivo | Intervalo | Observação |
| --- | --- | --- | --- |
| Balança do caixa | `public/assets/js/balanca-caixa.js` | 1,5 s | Só com balança vinculada; aceitável |
| Garçom aguardando Pix | `public/assets/js/garcom.js` | 3 s | Já ouve o tempo real; pode ir para 10 s |
| Situação da loja no cardápio público | `public/assets/js/cardapio-status.js` | 10 s | **Maior volume**: cada cliente com o cardápio aberto |
| iFood (botão da loja) | `public/assets/js/ifood-botao.js` | 10 s | Por tela de gerente aberta |
| Acompanhar pedido | `public/assets/js/acompanhar.js` | 5 a 15 s | Por cliente com pedido em andamento |
| Cozinha (KDS) | `public/assets/js/cozinha.js` | 20 s | Já tem tempo real; o intervalo é garantia |
| Fila do caixa | `public/assets/js/caixa.js` | 60 s | Ok |

Correções:

1. **Cardápio público**: trocar 10 s por 60 s e consultar também quando a aba volta a ficar visível (isso já existe). Horário de abertura muda raramente. Melhor ainda: calcular no navegador o próximo horário de abrir ou fechar, que já vem na página, e consultar só nesse momento.
2. Adicionar `Cache-Control: public, max-age=30` na resposta de `/api/v1/restaurantes/{slug}/situacao` e um cache de 30 s no Nginx (`fastcgi_cache`) só para essa rota. Mil clientes passam a gerar 1 consulta a cada 30 s por loja.
3. Nas telas que já recebem tempo real (cozinha, garçom, caixa), deixar a consulta periódica só como garantia (60 s) e parar quando a aba estiver oculta. Algumas já fazem isso.

### P3.1 — Pequenos custos por página

- `AssetUrl::versao()` chama `filemtime` para cada CSS/JS em toda página. Com OPcache e o cache de status de arquivos do PHP o custo é baixo. Se quiser zerar, gere `storage/cache/assets.php` com as versões no deploy.
- `Router::dispatch()` percorre ~360 rotas e compila o regex de cada uma até achar a certa. As rotas de API e do Agent ficam no fim da lista (`api.php` é o último `require`), então justamente as mais chamadas são as mais caras. Correções simples: guardar o regex compilado no array da rota ao registrar, e carregar `routes/api.php` primeiro quando o caminho começar com `/api/`.

### P3.2 — Crescimento das tabelas

- `outbox_eventos`: o servidor de tempo real já apaga eventos antigos (`RETENCAO_DIAS`). Ok.
- `impressoes`: guarda o conteúdo de cada cupom em JSON. Crie uma rotina que apague cupons entregues há mais de 30 dias.
- `log_user` e logs de auditoria: manter (histórico), mas conferir os índices por `id_restaurante` e data antes de abrir consultas por período.
- Saudações do WhatsApp: já existe `bin/limpar-saudacoes.php`; confirme que está no cron.

Sugestão: um único `bin/manutencao.php` diário que chama todas as limpezas e registra quantas linhas apagou.

### P3.3 — MySQL

Valores iniciais para 16 GB de RAM, com MySQL, PHP-FPM, Redis e Evolution na mesma máquina:

```ini
innodb_buffer_pool_size = 4G
innodb_log_file_size = 512M        ; ou innodb_redo_log_capacity = 1G no MySQL 8.0.30+
innodb_flush_log_at_trx_commit = 1 ; manter 1 (dinheiro e pedidos)
max_connections = 200
slow_query_log = 1
long_query_time = 0.5
log_queries_not_using_indexes = 0
```

Revise os índices a cada nova tela de relatório. As consultas mais quentes já estão cobertas: `outbox_eventos (id_restaurante, id)` e `impressoes (id_restaurante, entregue_em, criado_em)`.

---

## 4. Isolamento entre lojas e efeito vizinho

Todas as consultas analisadas filtram por `id_restaurante`, e o isolamento de dados está correto. O risco de desempenho é o **efeito vizinho**: como tudo roda no mesmo pool PHP-FPM e no mesmo MySQL, uma loja com problema afeta as outras. Por exemplo, um Evolution lento, um relatório pesado de um ano inteiro ou uma importação de planilha grande.

Proteções recomendadas:

- Fila de trabalhos (P1.3) para tudo que fala com serviço externo.
- Limite de período nos relatórios (já existe no financeiro por período) e `LIMIT` em toda listagem.
- `RateLimiter` também nas rotas públicas de API mais chamadas (`/situacao`, `/cep`).

---

## 5. Capacidade estimada depois das correções

| Origem | Hoje (estimado) | Depois de P1 e P2 |
| --- | --- | --- |
| Agents (200 lojas) | 80 req/s + 80 UPDATE/s | 20 req/s + ~3 UPDATE/s (ou ~0 com WebSocket) |
| Cardápio público (1000 clientes ao mesmo tempo) | 100 req/s | ~2 req/s (cache de 30 s) |
| Telas da equipe (1000 telas) | ~60 req/s | ~20 req/s |
| Pedidos e ações reais | < 5 req/s no pico | igual |

Uma requisição simples de API com OPcache leva de 5 a 15 ms de CPU. Com 4 vCPU a VPS processa algo como 250 a 500 req/s desse tipo. Hoje o tráfego de fundo pode passar de 200 req/s no pico. Depois das correções, fica abaixo de 50 req/s.

---

## 6. Plano de execução sugerido

1. **Semana 1** (sem risco para o usuário): P1.1 itens 1 e 2, P1.2, P2.1, P2.3 itens 1 e 2, P3.3 (slow log).
2. **Semana 2**: P2.2 (sessões no Redis) com teste em horário calmo; rotina `bin/manutencao.php`.
3. **Mês 1**: fila de trabalhos em `workers/` (P1.3) e Agent pelo WebSocket (P1.1 item 3).
4. **Sempre**: rodar a medição da seção 7 antes e depois de cada etapa.

Cada etapa segue o fluxo do projeto: branch, PR, CI, merge autorizado e deploy pelo GitHub.

---

## 7. Como medir na VPS

```bash
# Requisições por segundo e as rotas mais chamadas (log de acesso do Nginx do site)
sudo awk '{print $7}' /home/<usuario>/logs/nginx/access.log | cut -d'?' -f1 | sort | uniq -c | sort -rn | head -20

# Workers do PHP-FPM ocupados agora
ps -o pid,etime,rss,cmd -C php-fpm8.4 | wc -l

# Consultas lentas do MySQL (depois de ligar o slow log)
sudo mysqldumpslow -s t /var/lib/mysql/*-slow.log | head -40

# Conexões no servidor de tempo real
sudo ss -tn state established '( sport = :8091 )' | wc -l   # WS_PORTA do .env (padrao 8091)

# Memória e CPU gerais
htop
```

Para um teste de carga controlado, use `k6` ou `ab` a partir de outra máquina contra um restaurante de teste, nunca contra uma loja real. Exemplo: `ab -n 2000 -c 50 https://deliveryzim.com.br/api/v1/restaurantes/<slug-teste>/situacao`.

---

## 8. Arquivos citados

- `app/Core/Database.php`, `Config.php`, `Session.php`, `RateLimiter.php`, `AssetUrl.php`, `Router.php`
- `app/Shared/Esquema.php`
- `app/Controllers/Api/DesktopAgentController.php`
- `app/Repositories/Desktop/ImpressaoRepository.php`, `app/Repositories/OutboxRepository.php`
- `app/Realtime/Servidor.php`, `bin/tempo-real.php`, `deploy/systemd/cardapim-tempo-real.service`
- `app/Services/Pedidos/AvisosWhatsApp.php`, `SaudacaoWhatsApp.php`, `app/Services/Email/Mailer.php`, `app/Services/Ifood/IfoodAcoes.php`
- `desktop-agent/agent/app.py`
- `public/assets/js/cardapio-status.js`, `garcom.js`, `balanca-caixa.js`, `cozinha.js`, `ifood-botao.js`, `acompanhar.js`
- `deploy/redis/docker-compose.yml`, `deploy/nginx/*.example`
