# Mapa do projeto — Deliveryzim (Cardapim)

Atualizado em 23/11/2026 (expiração automática do Pix online em 60 minutos).
Para quem vai mexer no código pela primeira vez. Responde três perguntas: **onde está cada coisa**, **o que ela faz** e **o que ela afeta**.

> Nome: o produto se chama **Deliveryzim** (marca configurável em `configuracoes.nome_sistema`). O código interno continua com o nome **Cardapim** (namespace `Cardapim\`, logs `[Cardapim]`, banco `cardapim`). Os dois nomes são a mesma coisa.

Sumário

1. Visão em 1 minuto
2. Árvore de pastas
3. Como uma requisição funciona
4. `app/` camada por camada
5. Rotas: qual arquivo atende qual endereço
6. Banco de dados, migrações e o padrão de deploy com backup
7. Tempo real (WebSocket)
8. Impressão e Deliveryzim Agent (Windows)
9. Integrações (iFood, WhatsApp, Asaas, SMS, e-mail, mapa)
10. Frontend: CSS, JS, ícones e PWA
11. Rotinas (cron) e processos fixos
12. Testes e CI/CD
13. Receitas: “quero mudar X, onde mexo?”
14. Regras que nunca podem ser quebradas
15. Outras documentações

---

## 1. Visão em 1 minuto

- **PHP 8.4 puro**, sem framework, no padrão MVC: `Controller → Service → Repository → PDO/MySQL`.
- **Multi-restaurante**: tudo é filtrado por `id_restaurante`, e o endereço público de cada loja é `/{slug}`.
- **Áreas por cargo**: plataforma (`/painel`, adm_master), gerente (`/{slug}/gerencia`), caixa (`/{slug}/caixa`), garçom (`/{slug}/garcom`), cozinha (`/{slug}/gerencia/cozinha`), entregador (`/{slug}/entregas`) e cliente final (`/{slug}`).
- **MySQL é a fonte da verdade**. Redis serve só para avisar o servidor de tempo real.
- **Tempo real próprio**: o processo `bin/tempo-real.php` (WebSocket), alimentado pela tabela `outbox_eventos`.
- **Impressão e balança** pelo **Deliveryzim Agent** (Python, Windows) em `desktop-agent/`.
- **Deploy**: push na `main` → GitHub Actions → VPS (CloudPanel, Nginx, PHP-FPM) com releases e link `current`.

---

## 2. Árvore de pastas

```
cardapim/
├── app/                    Código PHP da aplicação (namespace Cardapim\, PSR-4)
│   ├── Core/               Núcleo: roteador, request/response, sessão, banco, views, config
│   ├── Controllers/        Recebem a requisição, conferem acesso/CSRF e devolvem Response
│   │   ├── Api/            JSON: cliente final, Agent Windows, tempo real, disponibilidade
│   │   ├── Caixa/          Modo caixa (/{slug}/caixa)
│   │   ├── Gerencia/       Painel do gerente (/{slug}/gerencia)
│   │   ├── Plataforma/     Painel da administração (/painel)
│   │   └── Concerns/       Trechos reaproveitados (FluxoMinhaConta)
│   ├── Services/           Regras de negócio por assunto (pedidos, caixa, impressão, iFood...)
│   ├── Repositories/       SQL. Todas as consultas filtram por restaurante
│   ├── Modules/            Regras puras e reaproveitáveis (catálogo, estoque, entrega, integrações)
│   ├── Realtime/           Servidor WebSocket, Redis, canais, ticket e publicação (outbox)
│   ├── Shared/             Utilitários: Esquema, Http, segurança, números, slug, validação
│   └── Views/              Templates PHP (HTML). Sem CSS/JS dentro
├── bootstrap/app.php       Autoload (Composer ou fallback), helpers e fuso America/Fortaleza
├── routes/                 Mapa de URLs → controllers (web, api, plataforma, gerencia, caixa, garcom)
├── public/                 ÚNICA pasta exposta na web
│   ├── index.php           Front controller (toda requisição PHP entra aqui)
│   └── assets/             css/, js/, icons/ (sprite Lucide), img/ (logos oficiais), vendor/
├── database/
│   ├── migrations/         Migrações versionadas (AAAAMMDDHHMMSS_nome.php)
│   ├── migrate.php         php database/migrate.php --status | --apply
│   ├── verify-*.php        Conferem se uma migração foi aplicada corretamente
│   ├── seeders/ + seed-*   Dados modelo (planos demo, restaurante modelo, cardápio exemplo)
│   └── initialize-schema.php
├── bin/                    Processos e rotinas de linha de comando (cron e systemd)
├── deploy/
│   ├── scripts/            activate-release.sh, apply-*.sh (migração com backup), mapa-*.sh
│   ├── nginx/              Exemplos de location (site e /ws do tempo real)
│   ├── systemd/            cardapim-tempo-real.service
│   └── redis/              docker-compose do Redis (porta 6381)
├── desktop-agent/          Deliveryzim Agent para Windows (Python): impressão ESC/POS, balança, avisos
├── virtual-scale/          App Android de TESTE que simula balança por Wi-Fi (nunca usar para cobrar)
├── tests/                  unit/, integration/, http/ (smoke por shell), realtime/, fakes/
├── docs/                   Documentação por assunto (ver seção 15)
├── storage/                Fora do Git na VPS (link para shared/): cache, logs, uploads, exports, sessions
├── resources/fonts/        Fonte Poppins usada para desenhar imagens (cartão de compartilhamento)
├── dev/                    Ferramentas locais: router.php (php -S), icons/build-sprite.php
├── graphify-out/           Grafo gerado automaticamente do código (apoio de leitura, não editar)
├── config/, workers/       Vazias por enquanto (workers/ é o lugar previsto para a fila de tarefas)
├── .github/workflows/      ci-deploy.yml, desktop-agent-windows.yml, android-virtual-scale.yml
├── .env.example            Lista das variáveis de ambiente (o .env real fica só na VPS)
└── composer.json           Só autoload PSR-4 (nenhuma dependência externa)
```

Pastas vazias com `.gitkeep` (`app/Modules/Customers`, `Delivery`, `Establishments`, `Finance`, `Identity`, `Kitchen`, `Notifications`, `Orders`, `POS`, `Payments`, `Platform`, `Reports`, `Tables`, `WhatsApp`, `Integrations/*`) são reservas da estrutura planejada. O código real desses assuntos está em `Services/` e `Modules/Integracoes/`. **Não crie código novo nelas sem antes conferir se o assunto já existe em `Services/`.**

---

## 3. Como uma requisição funciona

```
Nginx ─► public/index.php
          1. require bootstrap/app.php             (autoload, helpers marca(), fuso)
          2. DominioCanonico::daRequisicao()       (301 do domínio antigo)
          3. $router = require routes/web.php      (web.php inclui plataforma, gerencia, caixa, garcom, api)
          4. $router->dispatch(Request::capture()) (primeira rota que bater, na ordem de registro)
          5. Controller → Service → Repository     (PDO via Database::connect())
          6. Response::html|json|redirect ->send()
          erro não tratado → log "[Cardapim] ..." + página errors/500
```

- **Ordem das rotas importa**: rotas específicas antes das genéricas (por exemplo, `gerencia.php` é carregado antes de `/{slug}/gerencia/{secao}`).
- **Controllers sempre retornam `Response`**, e o roteador lança erro se não retornarem.
- **Acesso**: `Services/Auth/Autorizacao.php` é a fonte única do RBAC (cargos, status de restaurante com acesso). `Guard`/`AcessoRestaurante` conferem o slug contra o restaurante da pessoa logada. As bases `BaseGerencia`, `BaseCaixa` e `BasePlataforma` fazem acesso, CSRF, mensagens e render em um lugar só.
- **Views**: `View::render('pasta/arquivo', $dados, 'layouts/xxx')`. Os layouts ficam em `app/Views/layouts/` (app, caixa, cardapio, cozinha, entregador, garcom, gerencia, marketing, painel, plataforma, public, status, fragmento).

---

## 4. `app/` camada por camada

### 4.1 `app/Core/` — núcleo (afeta TODAS as telas)

| Arquivo | Faz | Cuidado |
| --- | --- | --- |
| `Router.php` | Registra rotas (`get`, `post`...), casa `{param}` e chama o controller | Mudança aqui afeta todas as URLs |
| `Request.php` / `Response.php` | Leitura da requisição e resposta (html, json, redirect, cabeçalhos) | Cabeçalhos de segurança passam por aqui |
| `Session.php` | Sessão com cookie seguro, expiração por inatividade e token CSRF | Login de todos os perfis |
| `Database.php` | `Database::connect()`: PDO MySQL (1 conexão por requisição) | Lê DB_* do `.env` |
| `Config.php` | `Config::get('CHAVE')`: variável de ambiente ou `.env` | Segredos ficam só no `.env` |
| `View.php` | Renderiza template + layout, com escape | |
| `AssetUrl.php` | URL de CSS/JS com `?v=` (data do arquivo) para limpar o cache | |
| `Icon.php` | Ícones Lucide pelo sprite `public/assets/icons/lucide.svg` | Ícone novo: `dev/icons/icons.txt` + `build-sprite.php` |
| `RateLimiter.php` | Limite de tentativas (arquivo em `storage/cache`) | Login, códigos, APIs públicas |
| `DominioCanonico.php` | Redireciona domínios antigos (`DOMINIOS_REDIRECIONAR`) | |
| `helpers.php` | `marca()` e `marca_html()`: nome da plataforma | Nunca escrever “Deliveryzim” fixo em tela |

### 4.2 `app/Controllers/`

Controllers só orquestram: validam acesso e entrada, chamam o Service e escolhem a view. **Regra de negócio não fica aqui.**

| Controller | Endereço | Assunto |
| --- | --- | --- |
| `HomeController`, `LegalController`, `SeoController`, `StatusController` | `/`, páginas legais, robots/sitemap, `/status` | Site público |
| `AuthController`, `RecuperarSenhaController` | `/login`, `/cadastro`, `/recuperar-senha` | Entrada da equipe |
| `CatalogoController` | `/restaurantes`, `/catalogo` | Lista pública de restaurantes |
| `RestauranteController` | `/{slug}` e `/{slug}/pedido/...` | Cardápio do cliente, sacola, finalizar, acompanhar |
| `GarcomController` | `/{slug}/garcom` | Mesas, comanda, conta e Pix no celular |
| `EntregadorController` | `/{slug}/entregas` | Entregas, confirmar e localização |
| `MidiaController`, `PwaController` | `/midia/...`, `/pwa/...` | Logos fora de `public/`, manifesto e service worker |
| `WebhookController` | `/webhooks/asaas/{id}` | Pagamentos Asaas |
| `EvolutionWebhookController` | `/webhooks/evolution/{token}` | Mensagens recebidas no WhatsApp (saudação) |
| `AreaController` | áreas genéricas “em construção” | |
| `Api/*` | `/api/v1/...` | Ver seção 5 |
| `Caixa/*` | `/{slug}/caixa/...` | Caixa, comandas, mesas, delivery, iFood, impressão, histórico |
| `Gerencia/*` | `/{slug}/gerencia/...` | Uma classe por aba do painel do gerente |
| `Plataforma/*` | `/painel/...` | Restaurantes, usuários, planos, faturas, integrações, logs, suporte |

### 4.3 `app/Services/` — regras de negócio

| Pasta / arquivo | O que decide | Quem usa (o que afeta) |
| --- | --- | --- |
| `Auth/` | Login, RBAC (`Autorizacao`), 2FA, “manter conectado”, recuperar senha | Todas as áreas logadas |
| `Cadastro/CadastroRestaurante` | Cadastro público: restaurante + gerente + assinatura | `/cadastro` |
| `Cardapio/CardapioPublico` | O que o cliente vê em `/{slug}`: categorias, preços, disponibilidade, “onde vende” | Cardápio online, sacola |
| `Pedidos/` | `CriarPedido` (pedido online), `CheckoutPublico`, `LinhasSacola`, `StatusPedido`, `CancelarPedido`, `PixPedido`, `VerificadorEntrega`, `AvisosWhatsApp`, `SaudacaoWhatsApp`, `HistoricoCliente` | Cardápio, caixa, garçom, WhatsApp, Agent |
| `Caixa/` | `CaixaService` (abrir/fechar e gaveta), `ComandaService` (mesa/balcão), `MesaService`, `DeliveryService`, `EntregaBalcaoService` (venda no balcão que vira entrega), `HistoricoComandas` | Modo caixa e garçom |
| `Garcom/` | `PermissoesGarcom` (remover item, cancelar, fechar conta), `ComandaPix` | Painel do garçom |
| `Cozinha/PainelCozinha` | Fila do KDS | Tela da cozinha |
| `Impressao/` | `CupomImpressao` (blocos do cupom), `RotaProducao` (**regra única** de qual impressora recebe cada item), `FilaImpressao` (grava na fila do Agent), `TesteImpressao` (simulador) | Agent, caixa, garçom, gerência > Impressoras |
| `Descontos/` | Cupons, fidelidade e a regra de não somar (`DescontoPedido`) | Checkout |
| `Entregas/` | Entregador, destino e rastreio | Painel do entregador, acompanhar pedido |
| `Gerencia/` | Um service por aba: Produto, Categoria, Complemento, ComboCadastro, MeioAMeioCadastro, Insumo, Equipe, Perfil, Financeiro, EntregaPagamento, Cupom, Fidelidade, Assinatura, VisaoGeral, ImportarCardapio; `Integracoes/` (Asaas, iFood, WhatsApp) | Painel do gerente |
| `Ifood/` | Toda a integração iFood: rotina, eventos, importar pedido, ações, catálogo, publicação, estado da loja | `bin/ifood.php`, caixa, gerência |
| `Plataforma/` | Restaurantes, usuários, planos, marca, cobrança das assinaturas (Asaas da plataforma), SMS, `GoogleMapsService` (chave única da Routes API, teste e alternância global Haversine/Google) | `/painel`, `/painel/integracoes/google-maps`, `bin/cobranca-diaria.php` |
| `Cliente/` | Login do cliente final por código (WhatsApp da plataforma ou SMS) | Cardápio |
| `WhatsApp/` | `EventoEvolution` (webhook → evento), `DiagnosticoSaudacao`, `SessoesAbandonadas` | Saudação, rotina de limpeza |
| `Painel/Menu*` | **Fonte única dos menus** (gerência, caixa, plataforma) | Menu lateral/inferior |
| `Email/`, `Geo/`, `Seo/`, `Suporte/` | E-mail SMTP, geocodificação e mapa base, SEO e cartão de link, chamados | |
| `Auditoria.php` | Grava em `log_user` quem criou/editou/excluiu o quê | Logs da plataforma |
| `Configuracoes.php` | Lê `configuracoes` (marca, textos, logos) | Todas as telas |
| `Catalogo*.php`, `Disponibilidade.php` | Catálogo público com cursor; conferência de usuário/e-mail/CNPJ ao digitar | Cadastro, catálogo |

### 4.4 `app/Modules/` — regras puras e clientes de API

| Módulo | Conteúdo | Afeta |
| --- | --- | --- |
| `Catalog/Complementos.php` | **Regra única** de complementos (obrigatório, mínimo/máximo, repetir, quantidade por opção) | Cardápio, sacola, pedido, caixa, garçom, iFood |
| `Catalog/Combo.php` | **Regra única** dos combos (preço dividido entre os itens) | Cardápio, caixa, garçom, iFood |
| `Catalog/MeioAMeio.php` | **Regra única** do Meio a Meio (sabores como grupo virtual, preço pela regra, fração de estoque) | Cardápio, caixa, garçom, iFood (pedido) |
| `Catalog/PlanilhaCardapio`, `LeitorPlanilha`, `ImagemCardapio` | Importar cardápio (CSV/XLSX) e fotos | Gerência > Importar |
| `Inventory/` | Disponibilidade dos produtos, baixa por ficha técnica, análise, quantidades em milésimos, venda por peso | Estoque, pedidos, caixa |
| `Entrega/` | Área de entrega e taxa, distância, comparação de bairros | Checkout, verificador de entrega |
| `Restaurante/` | Horário de funcionamento (puro), upload de logo | Aberto/fechado, perfil |
| `Marca/MarcaUpload` | Logos da plataforma | `/painel/configuracoes` |
| `Integracoes/` | Clientes HTTP: `IfoodApi`, `AsaasApi`, `EvolutionApi`, `SmsGateApi`, `GoogleRoutesApi` (somente distância pelas ruas), contrato `ProvedorWhatsApp` | Services de integração |

### 4.5 `app/Repositories/` — SQL

Toda consulta recebe `id_restaurante` e filtra por ele. Pastas: `Caixa/`, `Desktop/` (impressões, impressoras, produto↔impressora, pesagens), `Estoque/` (produtos, categorias, complementos, combos, meio a meio, insumos), `Gerencia/`, `Plataforma/`, além de `PedidoRepository`, `OutboxRepository`, `UsuarioRepository`, `RestauranteRepository`, `ClienteRepository`, `PlanoRepository`, `SuporteRepository`, `LogUserRepository`, `ContaRepository`.
Mudar uma consulta afeta todas as telas que usam o repositório. Procure os usos com `grep -rn "NomeRepository" app` antes de mudar. A configuração global e criptografada do Google fica em `configuracoes` via `Repositories/Plataforma/GoogleMapsRepository` (não em `IntegracaoRepository`, que é por restaurante).

### 4.6 `app/Realtime/`

`TempoReal` (publica: grava no outbox e avisa o Redis), `Servidor` (WebSocket), `Canais` (quem pode ouvir o quê), `Ticket` (autorização curta para conectar), `Quadro` (protocolo), `RedisConexao` (cliente Redis sem extensão), `Conexao`. Ver seção 7.

### 4.7 `app/Shared/`

| Arquivo | Uso |
| --- | --- |
| `Esquema.php` | `Esquema::tem($db, 'recurso')`: esconde uma função nova até a migração dela existir no banco |
| `Http/ClienteHttp` | Cliente HTTP com tempo limite curto, usado por todas as integrações |
| `Seguranca/` | `Cripto` (dados sensíveis no banco, usa `APP_KEY`), `Totp`, códigos de recuperação |
| `Numero`, `Slug`, `Validation/Documento`, `Navegador`, `Planilha`, `MidiaUrl`, `RestauranteLogo`, `Consentimento`, `Html/Campo` | Conversões, validações e componentes de formulário |

### 4.8 `app/Views/`

Uma pasta por área (`caixa/`, `garcom/`, `gerencia/<aba>/`, `plataforma/`, `restaurante/` (cardápio do cliente), `auth/`, `legal/`, `marketing/`, `entregador/`, `status/`, `errors/`, `partials/` (head, footer, consentimento, PWA)). Arquivos com `_` na frente são pedaços incluídos por outras views. **Nada de CSS ou JS dentro das views**: só `<link>` e `<script defer src>`.

---

## 5. Rotas: qual arquivo atende qual endereço

| Arquivo | Prefixo | Exemplos |
| --- | --- | --- |
| `routes/web.php` | público + inclui os outros | `/`, `/login`, `/cadastro`, `/restaurantes`, `/midia/...`, `/pwa/...`, `/webhooks/...`, `/{slug}`, `/{slug}/entregas` |
| `routes/plataforma.php` | `/painel` | restaurantes, usuários, planos, faturas, integrações (WhatsApp, SMS, Asaas, aplicativos), logs, configurações |
| `routes/gerencia.php` | `/{slug}/gerencia` | visão geral, estoque, equipe, financeiro, entrega e pagamento, cupons, fidelidade, integrações, impressoras, cozinha, perfil, assinatura, suporte |
| `routes/caixa.php` | `/{slug}/caixa` | abrir/fechar, mesas, comandas, delivery, iFood, imprimir, histórico de comandas |
| `routes/garcom.php` | `/{slug}/garcom` | mesas, comanda, conta, Pix |
| `routes/api.php` | `/api/v1` | `cliente/*`, `pedidos/*`, `restaurantes/{slug}/situacao`, `cep`, `cadastro/disponibilidade`, `tempo-real/ticket`, `desktop/*` (Agent) |
| `routes/webhooks.php` | — | Reservado (os webhooks atuais estão em `web.php`) |

Documentação da API: `docs/api/v1.md`.

---

## 6. Banco de dados, migrações e deploy com backup

- **Migrações**: `database/migrations/AAAAMMDDHHMMSS_descricao.php`. Ver o estado: `php database/migrate.php --status`. Aplicar: `--apply`.
- **Dinheiro**: sempre `DECIMAL`. Quantidade de estoque: `DECIMAL(...,3)`, tratada no PHP em milésimos inteiros (`Inventory/Quantidade`).
- **Tabelas centrais**: `restaurantes`, `user`, `planos`, `assinaturas`, `produtos`, `categorias`, `complemento_*`, `combo_*`, `insumos`, `pedidos`, `pedido_itens`, `mesas`, `caixas`, `outbox_eventos`, `impressoes`, `impressoras`, `desktop_agent_devices`, `restaurante_*` (configurações por loja), `configuracoes` (plataforma), `log_user` (auditoria). Detalhes em `docs/database/`.

### Padrão obrigatório de uma migração nova (o que todo PR com banco precisa ter)

1. `database/migrations/<data>_<nome>.php`: idempotente; a coluna que serve de “marcador” é criada **por último**.
2. `database/verify-<nome>.php`: confere tabelas, colunas e índices depois de aplicar.
3. `deploy/scripts/apply-<nome>.sh`: backup `mysqldump` → `migrate.php --apply` → `verify-<nome>.php`.
4. Passo novo em `.github/workflows/ci-deploy.yml`, disparado quando o título do commit de merge contém a tag `[cardapim-<nome>]`.
5. Recurso novo em `app/Shared/Esquema.php` (`'recurso' => ['tabela', 'coluna_marcador']`). O código usa `Esquema::tem()` para a tela funcionar antes e depois da migração.
6. Título do merge com a tag, por exemplo `Merge pull request #146: ... [cardapim-complemento-quantidade]`.

**Efeito**: sem a tag, o código vai para a VPS, mas a migração não roda, e a função nova fica escondida (sem erro).

---

## 7. Tempo real (WebSocket)

```
PHP (pedido criado/alterado)
   └─ TempoReal::publicar()  → INSERT outbox_eventos (mesma transação do pedido)
                             → PUBLISH no Redis "outbox"
bin/tempo-real.php (systemd: cardapim-tempo-real.service, porta WS_PORTA 8091, Nginx /ws)
   └─ SUBSCRIBE "outbox" → lê as linhas novas → envia só para quem assinou o canal da loja
Navegador: public/assets/js/tempo-real.js
   └─ pede ticket em /api/v1/tempo-real/ticket?restaurante={slug} → conecta /ws → assina canais
   └─ reconecta sozinho; dispara o evento DOM "cardapim:tempo-real" para as telas
```

- Canais e permissões: `app/Realtime/Canais.php` (fonte única).
- Limpeza do outbox: o próprio servidor apaga eventos antigos (`RETENCAO_DIAS`).
- O Agent ainda **não** usa o WebSocket: ele consulta `/api/v1/desktop/eventos` a cada 2,5 s (ver `docs/ANALISE-DESEMPENHO.md`).
- Guia: `docs/architecture/tempo-real.md`, `docs/deploy/tempo-real.md`.

---

## 8. Impressão e Deliveryzim Agent

```
Caixa/garçom clica Imprimir  ─┐
Pedido aceito (produção)     ─┼─► FilaImpressao ─► RotaProducao (qual impressora) ─► CupomImpressao (blocos)
Cancelamento de item         ─┘                    └─► INSERT impressoes (fila)
Agent (Windows) ─ GET /api/v1/desktop/eventos?impressoes=1&impressoras=1&imagem=1&principal=<chave>
                   ─► reserva os cupons ─► CupomImagem desenha cada um no papel da impressora ─► Agent imprime a imagem e corta
```

Desde o Agent 0.9.0 **o cupom é desenhado no servidor** (`CupomImagem`): o Agent informa de cada impressora o papel (58/80 mm), a logo e o espaço antes do corte (`POST /api/v1/desktop/impressoras`, colunas `impressoras.papel`, `logo`, `margem_corte`), recebe a imagem pronta e só converte em ESC/POS. Mudança de layout, letra, acento, logo ou margem é só no PHP, sem instalar nada. Agents antigos continuam recebendo os blocos e montando como antes.

| Onde | O quê |
| --- | --- |
| `app/Services/Impressao/RotaProducao.php` | Regra única: item → impressora (produção, cancelamento e simulador usam a mesma) |
| `app/Services/Impressao/CupomImpressao.php` | Monta os blocos (`titulo`, `centro`, `destaque`, `texto`, `linha`, `valor`, `item`) |
| `app/Services/Impressao/CupomImagem.php` | Desenha os blocos como PNG preto e branco (384 ou 576 pontos), com a fonte/pesos/tamanho da loja, logo (`resources/img/logo-cupom.png`), espaço antes do corte e margem lateral de cada impressora (mm) |
| `app/Services/Impressao/AparenciaCupom.php` | **Fonte única** da aparência do cupom: padrão do sistema (Roboto Mono 400/700, letra Normal), fontes (Roboto Mono, Poppins) e pesos disponíveis, tamanhos, validação e prévia de exemplo. Arquivos das fontes em `resources/fonts/` |
| `app/Repositories/Desktop/CupomAparenciaRepository.php` | Tabela `restaurante_cupom_aparencia` (sem linha = padrão) |
| `app/Services/Impressao/TesteImpressao.php` | Simulador da Gerência > Impressoras (não grava nada) |
| `app/Controllers/Api/DesktopAgentController.php` | API do Agent: vínculo, eventos, impressões, balança, situação |
| `app/Repositories/Desktop/*` | Fila `impressoes`, `impressoras`, produto↔impressora, pesagens |
| `desktop-agent/agent/printing.py` | `image_receipt`: imagem do sistema → ESC/POS (GS v 0) + corte; `job_receipt`: montagem antiga pelos blocos (reserva) |
| `desktop-agent/agent/updater.py` | Atualização automática: confere os Releases (`agent-vX.Y.Z`), baixa, confere o SHA-256 e instala em silêncio com o Agent parado |
| `desktop-agent/agent/app.py` | Janela do Agent, preferências, polling e fila de impressão |
| `desktop-agent/agent/scale.py`, `scale_panel.py` | Balança serial/TCP |
| `desktop-agent/agent/api.py`, `config.py`, `alerts.py`, `sound.py`, `notifier.py` | API, preferências, aviso sonoro de pedido |
| `desktop-agent/installer/deliveryzim-agent.iss` | Instalador (Inno Setup) |
| `.github/workflows/desktop-agent-windows.yml` | Push na `main` em `desktop-agent/**` → testes + Release `Deliveryzim-Agent-Setup.exe` |

Versão do Agent: `desktop-agent/agent/__init__.py`. Mudanças no cupom (PHP) chegam na hora. Mudanças no próprio Agent saem num Release novo e, a partir da 0.9.0, cada Agent se atualiza sozinho. Guia: `docs/architecture/impressao-agent.md` e `desktop-agent/README.md`.

---

## 9. Integrações

| Integração | Cliente HTTP | Regras | Entrada | Docs |
| --- | --- | --- | --- | --- |
| iFood | `Modules/Integracoes/IfoodApi.php` | `Services/Ifood/*`, `Services/Gerencia/Integracoes/Ifood*` | `bin/ifood.php` (cron, rodada a cada 30 s) | `docs/architecture/ifood.md` |
| WhatsApp (Evolution API) | `EvolutionApi.php` (`ProvedorWhatsApp`) | `Services/Pedidos/AvisosWhatsApp`, `SaudacaoWhatsApp`, `Services/WhatsApp/*`, `Gerencia/Integracoes/WhatsAppService` | `/webhooks/evolution/{EVOLUTION_WEBHOOK_TOKEN}` | `docs/deploy/evolution.md`, `docs/architecture/saudacao-whatsapp.md` |
| Asaas (Pix do restaurante) | `AsaasApi.php` | `Pedidos/PixPedido`, `Garcom/ComandaPix`, `Gerencia/Integracoes/AsaasService` | `/webhooks/asaas/{id}` + cron `bin/expirar-pix.php` (só pedidos online) | `docs/architecture/integracoes.md` |
| Asaas (assinaturas da plataforma) | `AsaasApi.php` | `Plataforma/AssinaturaCobranca`, `CobrancaAssinaturas` | `bin/cobranca-diaria.php` | `docs/architecture/assinaturas.md` |
| SMS (SMSGate) | `SmsGateApi.php` | `Cliente/SmsPlataforma`, `Plataforma/SmsGatewayService` | — | `docs/deploy/sms.md` |
| E-mail | `Services/Email/SmtpCliente` | `Mailer` | — | `docs/deploy/email.md` |
| Mapa próprio | — | `Services/Geo/*` | `deploy/scripts/mapa-*.sh` | `docs/architecture/mapa.md` |

Credenciais da plataforma ficam no `.env` ou no painel. As de cada restaurante ficam criptografadas no banco (`Cripto`). **Nunca no Git.**

---

## 10. Frontend

- `public/assets/css/core.css`: variáveis da marca (Vinho `#651424`, Branco `#FFFFFF`, Laranja `#FF5825`), base e componentes. Os outros arquivos são **por tela** (`caixa.css`, `garcom.css`, `cardapio.css`, `impressoras.css`...).
- `public/assets/js/core.js` e `forms.js`: utilidades comuns. Um arquivo por tela (`caixa.js`, `garcom.js`, `cardapio.js`, `checkout.js`, `cozinha.js`...). `tempo-real.js` é o cliente WebSocket. `sw.js` e `pwa.js` cuidam do app instalável.
- Ícones: **somente Lucide** pelo sprite (`Icon` no PHP). **Proibido emoji** (o CI barra emoji e estilos inline).
- Logos oficiais: `public/assets/img/` e as enviadas pelo painel (servidas por `/midia/...`).
- CSS e JS são sempre arquivos externos, carregados com `defer`. O cache é limpo pela data do arquivo (`AssetUrl`).

---

## 11. Rotinas (cron) e processos fixos

| Comando | Quando | O que faz |
| --- | --- | --- |
| `bin/tempo-real.php` | sempre (systemd `cardapim-tempo-real.service`) | Servidor WebSocket |
| `bin/ifood.php` | cron a cada minuto | Duas rodadas de 30 s: pedidos, eventos e ações do iFood |
| `bin/cobranca-diaria.php` | cron diário | Faturas das assinaturas; bloqueia inadimplentes depois da tolerância |
| `bin/expirar-pix.php` | cron a cada minuto | Confere Pix online vencido (60 min) no Asaas, exclui a cobrança pendente e cancela o pedido, com estoque/eventos |
| `bin/whatsapp-sessoes.php --limpar` | cron a cada 15 min | Fecha instâncias do Evolution sem restaurante |
| `bin/limpar-saudacoes.php` | cron diário | Apaga registros antigos de saudação |

Os comandos rodam como o usuário do site, com `php8.4 /home/<usuario>/apps/cardapim/current/bin/...` e logs em `shared/storage/logs/`.

---

## 12. Testes e CI/CD

- `tests/unit/*.php`: regras puras, sem banco. Rodam no CI (“Testes unitários sem banco”).
- `tests/integration/*.php`: com MySQL do CI (schema aplicado do zero).
- `tests/http/*.sh` e `*-ci.php`: smoke test HTTP com o servidor local (`dev/router.php`).
- `tests/realtime/cliente.php`: cliente WebSocket de teste.
- `desktop-agent/tests/`: testes Python do Agent (rodam no Windows do CI).
- `.github/workflows/ci-deploy.yml`: sintaxe PHP, Composer, JS, testes, **interface sem emojis e sem estilos inline**, schema MySQL, smoke HTTP. Depois, na `main`, publica na VPS (`activate-release.sh`) e roda os `apply-*.sh` pedidos pela tag do merge.

Fluxo de trabalho: branch → PR → CI verde → merge autorizado → deploy automático → validação.

---

## 13. Receitas: “quero mudar X, onde mexo?”

| Quero... | Mexa em | Confira também |
| --- | --- | --- |
| Criar uma tela nova no gerente | `routes/gerencia.php`, controller em `Controllers/Gerencia/` (estende `BaseGerencia`), service em `Services/Gerencia/`, view em `Views/gerencia/<aba>/`, CSS/JS próprios | Item no menu: `Services/Painel/MenuGerencia.php` |
| Mudar quem pode acessar algo | `Services/Auth/Autorizacao.php` | Bases dos controllers; regras do garçom em `Garcom/PermissoesGarcom` |
| Mudar o conteúdo do cupom | `Services/Impressao/CupomImpressao.php` | `TesteImpressao` (simulador); bloco novo também em `CupomImagem` e `printing.py` (reserva) |
| Total no delivery aceito / nome de quem atendeu | `restaurante_impressao_config` (`delivery_com_preco`, `mostrar_atendente`) via `ImpressoraRepository::opcoesCupom` | Aplicado em `FilaImpressao` (`producaoPedido`, `quem()`) e `TesteImpressao`; rode `tests/unit/opcoes-cupom.php` |
| Mudar o visual do cupom (letra, tamanho, logo, margens) | `Services/Impressao/CupomImagem.php` | Vale na hora para Agents 0.9.0+; rode `tests/unit/cupom-imagem.php` |
| Nova fonte ou mudar o padrão da aparência do cupom | `Services/Impressao/AparenciaCupom.php` (`FONTES`, `PADRAO`) + arquivos `.ttf` em `resources/fonts/` | Tela: card "Aparência do cupom" em `Views/gerencia/integracoes/impressoras.php` e `public/assets/js/cupom-aparencia.js`; rode `tests/unit/aparencia-cupom.php` |
| Mudar qual impressora recebe o item | `Services/Impressao/RotaProducao.php` | `ProdutoImpressoraRepository`, gerência > Impressoras |
| Corrigir corte do papel ou largura | Espaço e largura: escolhidos no Agent e desenhados em `CupomImagem` | Envio direto à impressora: `printing.py` (Release novo, atualização automática) |
| Mudar regra de complemento ou combo | `Modules/Catalog/Complementos.php` / `Combo.php` | Afeta cardápio, caixa, garçom e iFood; rode `tests/unit/complementos.php` e `combos.php` |
| Mudar o que aparece no cardápio do cliente | `Services/Cardapio/CardapioPublico.php`, `Views/restaurante/`, `cardapio.css/js` | Disponibilidade: `Modules/Inventory/DisponibilidadeProdutos.php` |
| Mudar a criação do pedido online | `Services/Pedidos/CriarPedido.php`, `LinhasSacola.php`, `CheckoutPublico.php` | Estoque (`BaixaEstoque`), descontos, tempo real, Agent |
| Mudar status do pedido | `Services/Pedidos/StatusPedido.php` | Avisos de WhatsApp, iFood (`IfoodAcoes`), tempo real |
| Popup do cardápio (banner 1:1 ou texto) | `Services/Gerencia/PopupService.php`, `Modules/Restaurante/PopupUpload.php` | Tela em `Views/gerencia/popup.php`; no cardápio `Views/restaurante/_popup.php` e `js/cardapio-popup.js`; doc em `docs/architecture/cupons-fidelidade.md` |
| Mexer no caixa/comandas | `Services/Caixa/*`, `Controllers/Caixa/*`, `Views/caixa/` | Garçom usa o mesmo `ComandaService` |
| Mexer na integração iFood | `Services/Ifood/*`, `Modules/Integracoes/IfoodApi.php` | `bin/ifood.php`, `tests/unit/ifood*.php` |
| Mensagens de WhatsApp | `Services/Pedidos/AvisosWhatsApp.php`, `SaudacaoWhatsApp.php` | Webhook `EvolutionWebhookController`, `DiagnosticoSaudacao` |
| Nova coluna ou tabela | Seção 6 (migração + verify + apply + CI + Esquema + tag) | `docs/database/` |
| Novo evento em tempo real | `TempoReal::publicar()` no service, canal em `Realtime/Canais.php`, tratar em `tempo-real.js` / JS da tela | Permissão do canal |
| Ícone novo | `dev/icons/icons.txt` → `php dev/icons/build-sprite.php` | Usar `Icon` no PHP |
| Nome/logos da plataforma | `/painel/configuracoes` (banco); código: `helpers.php`, `Services/Plataforma/MarcaService.php` | Nunca nome fixo |
| Variável de ambiente nova | `.env.example` + `Config::get()` | Documentar em `docs/deploy/` |
| Rotina agendada | `bin/<nome>.php` + linha de cron documentada | Logs em `storage/logs`; Pix usa instalador `deploy/scripts/install-expirar-pix-cron.sh` |
| Desempenho | `docs/ANALISE-DESEMPENHO.md` | |

---

## 14. Regras que nunca podem ser quebradas

1. Toda consulta filtra por `id_restaurante`. Nunca confie em ID ou permissão vindos do navegador.
2. Toda ação que grava confere o CSRF e o cargo no servidor.
3. Dinheiro em `DECIMAL`. Operações críticas dentro de transação.
4. CSS e JS em arquivos externos. Nada de `style=""`, `<script>` inline ou `onclick`.
5. Nenhum emoji nas interfaces. Ícones só Lucide.
6. Segredos só no `.env` ou criptografados no banco. Nunca no Git ou no frontend.
7. Migração sempre com verify, apply com backup e tag no merge (seção 6).
8. Regras únicas não se duplicam: `Complementos`, `Combo`, `RotaProducao`, `Autorizacao`, `Canais`, `Menu*`.
9. Não remova funcionalidades sem autorização.

---

## 15. Outras documentações

- `docs/ANALISE-DESEMPENHO.md`: gargalos e plano para muitas lojas ao mesmo tempo
- `docs/architecture/*.md`: um arquivo por assunto (caixa, garçom, cozinha, pedidos, complementos, combos, impressão, iFood, tempo real, PWA...)
- `docs/database/*.md`: desenho das tabelas
- `docs/deploy/*.md`: CloudPanel, tempo real, Evolution, SMS, e-mail, troca de domínio
- `docs/api/v1.md`: API REST
- `docs/brand/identidade-visual.md`: marca
- `docs/PRODUTO-E-HISTORICO.md`: visão do produto, roadmap e o README antigo completo
- `desktop-agent/README.md`, `virtual-scale/README.md`

### Tags de marketing da plataforma

- `/painel/tags` (somente adm_master): Google Analytics 4, Meta Pixel e Google Tag Manager, com ID, ativação e escopos independentes. Dados JSON na chave `marketing_tags` de `configuracoes`; sem migration.
- `TagsService` valida e audita; os controllers Home e Catalogo informam explicitamente o escopo ao head compartilhado. O JS externo `rastreamento.js` carrega os provedores no modo básico por padrão, somente após consentimento v2. O aceite antigo não autoriza rastreamento. O ADM Master pode habilitar `google_advanced` na mesma chave JSON, após avaliação documentada da base legal e revisão do contêiner. No modo avançado, sem escolha, GA4 direto pode enviar sinais sem cookies com os quatro estados negados; GTM carrega apenas a biblioteca com allowlist de debug e bloqueio de classes externas (restrições do Google, não sandbox). Após aceite, o GTM recarrega sem as restrições para disparar tags autorizadas; GA4 direto atualiza consentimento. Meta direto não carrega antes do aceite. Recusa atualiza consentimento, tenta limpar cookies de marketing conhecidos e recarrega sem bibliotecas externas. A restrição a páginas públicas permanece; `_dbg`, `gtm_debug` e `gtm_preview` são validados. Não liberar `gtm_auth` ou parâmetros arbitrários. Configure verificações de consentimento no GTM, publique o contêiner e valide no Tag Assistant e no relatório em tempo real. O teste automático de instalação sem aceite não é garantido.
- Cadastro, login, cardápios, checkout e áreas privadas são excluídos. GTM não pode coexistir com tags diretas no mesmo escopo; publique somente contêineres confiáveis, sem coleta de dados pessoais. O GTM executa código de terceiros no contexto da origem; restrições de escopo não são sandbox. Revise as regras do contêiner e a CSP real da VPS antes de ativar. Buscas e parâmetros não relacionados a campanha bloqueiam o carregamento. Não há envio de conversões server-side nesta versão.
- Testes: `php tests/unit/tags.php`, `node tests/js/rastreamento.js` e smoke de cookies. As tags vêm desativadas até configuração pelo master.

### Fiscal por restaurante (etapa 02)
`/{slug}/gerencia/fiscal` prepara o emitente com aprovação administrativa, credencial cifrada, certificado e configuração em homologação. A tabela `restaurante_fiscal` guarda a escolha de impressora separada de `impressao_pontos`; o card novo é independente. Agent, renderizadores e PDV permanecem inalterados.

### Cadastro fiscal de produtos (etapa 03 — base)
`FiscalProdutosController` / `FiscalProdutosService` / `FiscalProdutosRepository`, `Modules/Fiscal/CadastroProdutoFiscal.php` e `CatalogoNcm.php`, `Views/gerencia/fiscal/produtos/`. Migration `20261125100000_fiscal_produtos.php`, marcador `[cardapim-fiscal-produtos]` para backup/migration dedicado. Não altera serviços de venda ou repositórios comerciais. Acesso condicionado a schema + fiscal habilitado + CNPJ correspondente.

Liberação fiscal: `FiscalAcesso` verifica controle global e por restaurante. Migration `20261125110000_fiscal_liberacoes.php` (marcador `[cardapim-fiscal-liberacoes]`). Superadmin administra via `/painel/fiscal`; não modifica assinaturas, permissões comerciais ou vendas.

Complementação fiscal: `ImportacaoFiscal`, `CatalogosFiscais`, `ComposicaoFiscal`, `VerificacaoFiscal`, `FiscalCatalogosController` e rotas da gerência em `/fiscal/produtos/importar`, `/catalogos`, `/validacao`, `/{id}/composicao`. Migration `20261125120000_fiscal_catalogos.php`, tabelas fiscal_catalogos e fiscal_composicoes, marcador `[cardapim-fiscal-catalogos]`. Sem alteração no núcleo comercial.
