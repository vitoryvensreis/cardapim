# Deliveryzim (Cardapim)

SaaS multi-restaurante para bares, restaurantes, pizzarias e lanchonetes: cardápio online e pedidos, caixa (PDV), mesas e comandas, painel do garçom, cozinha (KDS), estoque com ficha técnica, delivery com entregador e rastreio, financeiro, cupons e fidelidade, impressão térmica e balança pelo Agent Windows, e integrações com iFood, WhatsApp (Evolution API), Asaas (Pix) e SMS.

Produção: https://deliveryzim.com.br · Código interno: `Cardapim` (namespace `Cardapim\`)

---

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
