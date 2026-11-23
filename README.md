# Deliveryzim (Cardapim)

SaaS multi-restaurante para bares, restaurantes, pizzarias e lanchonetes: cardápio online e pedidos, caixa (PDV), mesas e comandas, painel do garçom, cozinha (KDS), estoque com ficha técnica, delivery com entregador e rastreio, financeiro, cupons e fidelidade, impressão térmica e balança pelo Agent Windows, e integrações com iFood, WhatsApp (Evolution API), Asaas (Pix) e SMS.

Produção: https://deliveryzim.com.br · Código interno: `Cardapim` (namespace `Cardapim\`)

---

## Google Routes API — cálculo opcional de entrega

A plataforma usa **uma única chave de API** (Google Cloud → Routes API, faturamento e restrição por IP da VPS), configurada pelo `adm_master` em **Painel → Integrações → Google Maps** (`/painel/integracoes/google-maps`). A chave fica criptografada na tabela `configuracoes`, usando `Cripto`; **nunca** incluir o segredo no Git ou no navegador.

- **Padrão desligado:** Haversine continua calculando em linha reta, exatamente como antes.
- **Ativação manual:** primeiro salvar a chave, depois executar o teste de rota e então ativar a integração. Ao ativar, substitui o motor de distância das faixas por quilômetros de **todos** os restaurantes; áreas por bairro continuam tendo prioridade.
- **Google ativo:** `GoogleRoutesApi` chama `POST https://routes.googleapis.com/directions/v2:computeRoutes` com modo `DRIVE`, `TRAFFIC_UNAWARE` e máscara `routes.distanceMeters`. Não são necessários SDK ou mapa Google no frontend.
- **Falha de API/cota:** não aprova pedido nem inventa taxa via Haversine. A entrega informa indisponibilidade e pode ser tentada de novo.
- **Diagnóstico no ADM Master:** respostas da Routes API são interpretadas por `error.status`, `error.details[].reason` (`google.rpc.ErrorInfo`) e HTTP; o painel mostra causas e ações em português para chave inválida, restrição por IP ou referenciador, API desabilitada, faturamento, cotas, parâmetros, rota não encontrada e falhas do Google. Em 403 sem motivo explícito, mostra permissão negada **sem presumir IP incorreto**. Nunca mostra ou registra o JSON bruto nem a chave do Google. Falhas de rede e erros internos permanecem distintos. O teste não muda a ativação global.
- **Retorno imediato:** no mesmo painel, **Desligar** restaura Haversine sem migration nem deploy. Remover a chave também desativa.
- **Custo e licença:** configure cotas no Google Cloud; distâncias retornadas pelo Google não são persistidas no pedido nem em cache. O valor financeiro cobrado e a faixa própria do restaurante são preservados.
- **Economia sem mudar a UX:** o checkout só confere entrega com rua, número, bairro, cidade e UF completos (e CEP válido quando preenchido); aguarda 1,1 s após a última edição, descarta respostas de endereços anteriores e evita duas requisições simultâneas para o mesmo endereço. O resultado mostrado na tela não é guardado como cache persistente de rotas.
- **Pré-filtro Haversine (Google ativo):** após a prioridade de bairro, apenas quando o cliente compartilha explicitamente o GPS pelo botão “Estou neste endereço”, com precisão de até 50 m e localização capturada nos últimos 2 minutos, a distância em linha reta pode **excluir** endereços que excedem a maior faixa por mais de 500 m + margem do GPS. Sem localização precisa ou recente, a API Google decide normalmente. O filtro **nunca aprova** uma entrega nem escolhe taxa: isso permanece exclusivamente com a Routes API.
- **GPS e endereço:** ao alterar CEP, rua, número, bairro, cidade ou estado, o checkout descarta a localização anterior para não usar o GPS de outro endereço.
- **Confirmação preservada:** a criação do pedido continua verificando a taxa no backend. Não há reaproveitamento de orçamentos entre requisições nem cache de resultados Google, em respeito às condições aplicáveis.
- **Rastreamento:** GPS do entregador, Leaflet/OpenStreetMap e WebSocket são independentes e não exibem conteúdo de rotas Google. O resultado da Routes API serve somente para escolher a taxa.

Testes sem chamadas pagas: `php tests/unit/integracoes.php`, `php tests/unit/entrega.php`. Documentação: [Routes API](https://developers.google.com/maps/documentation/routes/compute_route_directions), [políticas](https://developers.google.com/maps/documentation/routes/policies).

## Comece pelo mapa

**Antes de mexer em qualquer coisa, leia o [Mapa do projeto](docs/MAPA-DO-PROJETO.md).**
Ele mostra onde está cada pasta, classe, script e rota, o que cada um faz e o que é afetado quando você muda. Também tem a seção **“quero mudar X, onde mexo?”** (seção 13).

| Documento | Para quê |
| --- | --- |
| [docs/MAPA-DO-PROJETO.md](docs/MAPA-DO-PROJETO.md) | Estrutura, funcionamento e onde mexer |
| [docs/ANALISE-DESEMPENHO.md](docs/ANALISE-DESEMPENHO.md) | Gargalos e plano para muitas lojas ao mesmo tempo |
| [docs/architecture/](docs/architecture/) | Um documento por funcionalidade (caixa, garçom, pedidos, impressão, iFood, tempo real...) |
| [docs/deploy/](docs/deploy/) | VPS/CloudPanel, tempo real, Evolution, SMS, e-mail, troca de domínio |
| [docs/database/](docs/database/) | Desenho das tabelas |
| [docs/api/v1.md](docs/api/v1.md) | API REST v1 |
| [docs/brand/identidade-visual.md](docs/brand/identidade-visual.md) | Marca, cores e logos |
| [docs/PRODUTO-E-HISTORICO.md](docs/PRODUTO-E-HISTORICO.md) | Visão do produto, roadmap e histórico completo das entregas (README antigo) |
| [desktop-agent/README.md](desktop-agent/README.md) | Deliveryzim Agent (impressão e balança no Windows) |

---

## Stack

- PHP 8.4 sem framework, MVC modular (`Controller → Service → Repository → PDO`), PSR-4 pelo Composer
- MySQL 8 (fonte da verdade), Redis 7 (aviso do tempo real)
- WebSocket próprio em PHP (`bin/tempo-real.php`, systemd) com Transactional Outbox
- Nginx + PHP-FPM em VPS Linux (CloudPanel), deploy por GitHub Actions
- Frontend: HTML pelas views PHP, CSS e JavaScript externos (sem framework), ícones Lucide, PWA da equipe
- Agent Windows em Python (impressão ESC/POS e balança), instalador gerado no GitHub Releases

## Estrutura em resumo

```
app/          Código PHP (Core, Controllers, Services, Repositories, Modules, Realtime, Shared, Views)
routes/       URLs → controllers (web, api, plataforma, gerencia, caixa, garcom)
public/       Única pasta exposta na web (index.php + assets)
database/     Migrações, verificações e seeds
bin/          Tempo real e rotinas de cron
deploy/       Scripts de release e migração com backup, Nginx, systemd, Redis
desktop-agent/  Agent Windows
tests/        Testes unit, integration, http e realtime
docs/         Documentação
```

Detalhes de cada pasta e arquivo: [Mapa do projeto, seção 2](docs/MAPA-DO-PROJETO.md#2-árvore-de-pastas).

## Áreas do sistema

| Quem | Endereço |
| --- | --- |
| Cliente final (cardápio e pedido) | `/{slug}` |
| Gerente | `/{slug}/gerencia` |
| Caixa | `/{slug}/caixa` |
| Garçom | `/{slug}/garcom` |
| Cozinha (KDS) | `/{slug}/gerencia/cozinha` |
| Entregador | `/{slug}/entregas` |
| Administração da plataforma | `/painel` |
| API | `/api/v1/...` |

## Rodar localmente

```bash
cp .env.example .env              # preencha DB_* e APP_KEY (nunca suba o .env)
composer dump-autoload            # opcional: sem Composer o bootstrap usa autoload próprio
php database/migrate.php --status
php database/migrate.php --apply
php -S 127.0.0.1:8000 -t public dev/router.php
```

Tempo real (opcional): Redis (`deploy/redis/docker-compose.yml`) e `php bin/tempo-real.php` com `WS_ATIVO=1`.

## Testes

```bash
php tests/unit/complementos.php      # cada arquivo de tests/unit roda sozinho, sem banco
```

A lista oficial de testes que precisam passar está no passo “Testes unitarios sem banco” do CI.

O CI (`.github/workflows/ci-deploy.yml`) também confere a sintaxe PHP e JS, aplica o schema em um MySQL limpo, roda os testes de integração e o smoke test HTTP, e **bloqueia emojis e estilos/scripts inline nas views**.

## Fluxo de trabalho e deploy

1. Branch a partir da `main` → desenvolvimento → testes.
2. Pull request → CI verde → merge **autorizado** na `main`.
3. O GitHub Actions publica na VPS (release nova + link `current`) e valida.
4. **Mudança de banco** só é aplicada quando o título do merge tem a tag da migração, por exemplo `[cardapim-complemento-quantidade]`. O script `deploy/scripts/apply-*.sh` faz backup, aplica e confere. O passo a passo para criar uma migração está no [Mapa, seção 6](docs/MAPA-DO-PROJETO.md#6-banco-de-dados-migrações-e-deploy-com-backup).
5. Mudanças em `desktop-agent/**` geram um instalador novo do Agent nos Releases do GitHub.

Guia da VPS: [docs/deploy/cloudpanel.md](docs/deploy/cloudpanel.md).

## Regras do projeto

- Isolamento total entre restaurantes (`id_restaurante` em toda consulta). Permissões sempre conferidas no servidor.
- CSS e JS sempre em arquivos externos (`public/assets/css`, `public/assets/js`). Nada de `style=""`, script inline ou `onclick`.
- Sem emojis nas interfaces. Ícones só Lucide.
- Paleta oficial: Vinho `#651424`, Branco `#FFFFFF`, Laranja `#FF5825`. Usar só as logos oficiais.
- Valores em `DECIMAL`, transações nas operações críticas, proteção contra SQL Injection, XSS e CSRF.
- Segredos só no `.env` da VPS ou criptografados no banco. Nunca no Git.
- UX simples: qualquer pessoa precisa entender a tela sem treinamento.

A lista completa e o motivo de cada regra estão no [Mapa, seção 14](docs/MAPA-DO-PROJETO.md#14-regras-que-nunca-podem-ser-quebradas).

## Licença

Licença a definir. Até a inclusão de um arquivo `LICENSE`, não presuma direito de redistribuição ou uso comercial.
