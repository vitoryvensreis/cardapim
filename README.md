# Deliveryzim (Cardapim)

SaaS multi-restaurante para bares, restaurantes, pizzarias e lanchonetes: cardápio online e pedidos, caixa (PDV), mesas e comandas, painel do garçom, cozinha (KDS), estoque com ficha técnica, delivery com entregador e rastreio, financeiro, cupons e fidelidade, impressão térmica e balança pelo Agent Windows, e integrações com iFood, WhatsApp (WAHA, motor GOWS; Evolution API nas conexões antigas), Asaas (Pix) e SMS.

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

## Central fiscal — API NeXT (etapas 01 e 02)

O adm_master pode configurar e testar a credencial administrativa em **Painel → Fiscal** (/painel/fiscal). Integração desativada por padrão, somente consultas e ambientes de teste; sem emissão real nem alterações no PDV. A configuração é cifrada na tabela existente configuracoes. Veja [documentação fiscal](docs/architecture/fiscal-next.md).

A configuração opcional por restaurante fica em **Gerência → Fiscal**. A confirmação do vínculo pelo administrador antecede o cadastro automático na NeXT; emissão real bloqueada. O card fiscal da integração de impressoras salva separadamente, usando o padrão do Cupom completo.

## Comece pelo mapa

**Antes de mexer em qualquer coisa, leia o [Mapa do projeto](docs/MAPA-DO-PROJETO.md).**
Ele mostra onde está cada pasta, classe, script e rota, o que cada um faz e o que é afetado quando você muda. Também tem a seção **“quero mudar X, onde mexo?”** (seção 13).

| Documento | Para quê |
| --- | --- |
| [docs/MAPA-DO-PROJETO.md](docs/MAPA-DO-PROJETO.md) | Estrutura, funcionamento e onde mexer |
| [docs/ANALISE-DESEMPENHO.md](docs/ANALISE-DESEMPENHO.md) | Gargalos e plano para muitas lojas ao mesmo tempo |
| [docs/architecture/](docs/architecture/) | Um documento por funcionalidade (caixa, garçom, pedidos, impressão, iFood, tempo real...) |
| [docs/deploy/](docs/deploy/) | VPS/CloudPanel, tempo real, WAHA (WhatsApp), Evolution, SMS, e-mail, troca de domínio |
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

### Cadastro fiscal automático (etapa 02.1)
Consulta pontual à BrasilAPI pelo CNPJ vinculado, preenchimento inicial editável e botão Repreencher. CEP usa a API interna; código IBGE identificado no servidor. IE pendente ou isenção declarada não libera homologação; nenhum dado consultado é salvo sem confirmação. Sem migration ou alteração no Agent.

### Simulação fiscal automática (etapa 02.2)
Ambiente 3 sem preenchimento manual de série, numeração, CSC ou A1. Série interna 999 e sequência preservada. Emitente conectado não precisa repetir cadastro. Consultas fiscais exclusivamente via NeXT.

### Cadastro fiscal dos produtos (etapa 03 — base)
Com módulo fiscal habilitado: Central Fiscal → Produtos fiscais / Perfis tributários, modelos de nomes sem impostos predefinidos, perfis versionados, revisão declarada, vínculos em massa com prévia, sugestões por categoria, classificação individual e consulta NCM oficial. Dados fiscais separados do CRUD comercial. Sem emissão. CEST e demais códigos avançados têm cadastro manual e validação estrutural; importação XML/CSV, catálogos CEST/CFOP/cClassTrib, regras de complementos e validação completa do motor ainda pendentes.

### Correções da auditoria fiscal (26/11/2026)
Contrato da NeXT conferido com a OpenAPI oficial: `ClienteIncluir` envia o CNPJ do restaurante no corpo (`CNPJCliente`); as mensagens da NeXT aparecem para o usuário; o superadmin pode **conferir a credencial** do restaurante (compara no servidor com o `ClientesListar` e corrige) e **refazer o vínculo** quando o CNPJ do restaurante mudar. Certificado A1 em formato antigo é aceito; CSC e ID do CSC obrigatórios em homologação; séries de 0 a 889. NCM vale enquanto estiver vigente (reconferência anual) e pode ser revalidado em lote; CFOP e CST/CSOSN do escopo NFC-e não dependem de catálogo importado. Telas com etapas, linguagem simples, erro destacado no campo, filtros de pendências e importação com seletor por nome. Detalhes em [docs/architecture/fiscal-next.md](docs/architecture/fiscal-next.md). Sem migration.

### Emissão de NFC-e e caixa (Etapas 04 e 05)
Com o módulo fiscal pronto para o restaurante, a venda concluída (mesa, balcão, delivery próprio; iFood opcional e desligado por padrão) entra numa fila e o cron `bin/fiscal-fila.php` emite a NFC-e pela NeXT, com número reservado em transação, confirmação antes de reenviar e retentativas automáticas. A venda nunca espera nem falha por causa da nota. O caixa pode informar CPF/CNPJ na nota, vê a situação da nota e pode tentar de novo. Com o módulo desligado nada muda. Migration `20261127100000_fiscal_documentos` (tag `[cardapim-fiscal-documentos]`). Detalhes em [docs/architecture/fiscal-next.md](docs/architecture/fiscal-next.md).

### DANFE, notas emitidas e produção fiscal (Etapas 06 a 08)
DANFE NFC-e com QR Code impresso pelo Deliveryzim Agent (QR gerado no servidor, sem biblioteca externa), reimpressão no caixa, tela **Notas emitidas** (cancelar, inutilizar números, PDF, XML, e-mail e ZIP mensal para o contador) e liberação de **produção** por restaurante, exclusiva do superadmin. Migration `20261128100000_fiscal_danfe_producao` (tag `[cardapim-fiscal-danfe-producao]`). Detalhes em [docs/architecture/fiscal-next.md](docs/architecture/fiscal-next.md).

### Liberação administrativa e automação fiscal (Etapa 03)
Superadmin → Fiscal → Liberação do módulo fiscal: autorização individual por restaurante e controle geral de visibilidade. Por padrão nenhum restaurante autorizado (inclusive existentes, liberar explicitamente); dados e ativação local preservados ao ocultar. Menu, URLs, cards fiscais e ações de impressão protegidos. Classificação individual em lote com seleção de campos, preservação padrão, prévia, transação e nova revisão; GTIN nunca replicado. Consulta GTIN NeXT via NFC-e em homologação com A1 válido, somente sugestão, sem mudar cadastro comercial. Não libera emissão real.

### Complementação da Etapa 03 — importação, catálogos e conferência
Em Fiscal → Produtos fiscais: importar XML de NF-e/NFC-e processado de produção vinculado ao CNPJ do restaurante ou CSV UTF-8; prévia, vínculo explícito, preservação padrão e confirmação transacional. Não autentica XML na SEFAZ e não importa tributos de entrada, preço ou estoque. Superadmin → Fiscal → Catálogos fiscais: registrar CSV normalizado das fontes oficiais, com tipo, versão, vigência, SHA-256 e auditoria; pesquisa por código/descrição, CEST por prefixo NCM e cClassTrib por CST/NFC-e. Carga inicial e atualização são manuais; nenhuma tabela fictícia é semeada. Conferência cadastral de perfis, códigos, unidade e composições; combo pai usa componentes, complemento regular pode compor principal ou usar referência fiscal existente, complemento separado precisa referência válida. Sem emissão, sem cálculo/alteração comercial; homologação completa e cálculo/rateio permanecem nas etapas 04–08.

Correção NCM: pesquisa direta por código/descrição na BrasilAPI, somente por ação fiscal explícita, cache de sessão limitado e consulta exata no salvamento fiscal individual fora da transação; lotes/importações usam evidências locais e podem salvar pendente. Não há novas tabelas, migrations ou chamadas no núcleo comercial. Fonte e vigência registradas; evidência cadastral revalidada em até 30 dias, sem autorização de emissão.

- Fiscal etapa 03: simplificação do cadastro, seleção por regime, CEST integrado e recuperação de formulários; ver `docs/architecture/fiscal-next.md`. Emissão real permanece bloqueada.

- Fiscal: pesquisa NCM assistida por nomes comerciais e sinônimos, com candidatos verificados pela BrasilAPI e seleção explícita (sem classificação automática).

- Financeiro → Relatórios: vendas por produto, dia, hora, pedido/comanda, com filtros combinados, resumo por situação e CSV. Consulta somente leitura dos itens históricos, preços e quantidades por unidade; componentes de combos já rateados, adicionais comuns incluídos no total do item (sem ranking individual de adicionais). Por padrão considera o horário de lançamento e todas as situações, com cancelamentos separados; por fechamento considera apenas itens válidos de pedidos concluídos. Categoria do cadastro atual, período máximo de 93 dias, paginação de 50 e exportação limitada a 10 mil linhas. A rota anterior de planilha financeira permanece intacta. Sem novas tabelas ou migrations.

- Relatórios: sugestões automáticas de produtos a partir de dois caracteres, busca com debounce de 250 ms, até 20 sugestões por consulta, seleção pelo ID e pesquisa livre preservada. Apenas o catálogo do restaurante autenticado é consultado, inclusive itens inativos com histórico. Sem mudanças no banco.

## Taxa por forma de pagamento em todas as contas

A taxa cadastrada em Gerência → Entrega e pagamento (débito, crédito e Pix; dinheiro nunca tem) vale em **todo** fechamento de conta: cardápio online, caixa, mesa, balcão, garçom e entrega feita no caixa. Não há interruptor: cadastrou, vale. Cada pagamento guarda a taxa que cobrou (`pedido_pagamentos.taxa`), o pedido soma em `taxa_pagamento` e o total já sai com a taxa; o "falta pagar" continua o valor da conta. A tela mostra "Cobrar do cliente" antes de receber e o servidor confere a taxa vista. A regra de o garçom fechar a conta só com permissão continua igual. Migration `20261204100000_taxas_pagamento_contas` (tag `[cardapim-taxas-contas]`). Detalhes em [docs/architecture/pedidos.md](docs/architecture/pedidos.md).

## Pix pela chave do restaurante (um Pix por vez)

**Avisos para as lojas** (Plataforma → Avisos para as lojas): pop-up para o gerente de todas as lojas, em qualquer tela da gerência, com título, texto, imagem opcional e botão de ação. O botão leva a uma tela da própria loja (ex.: WhatsApp da loja; o link muda sozinho para cada loja) ou a um link externo https. Cada aviso tem período (início e fim) e quantas vezes por dia cada gerente vê; o painel mostra quantas lojas viram e clicaram. Rotas `/painel/avisos*` e `POST /{slug}/gerencia/avisos/{id}/clique`; tabelas `avisos_loja` e `avisos_loja_exibicoes` (migration `20261207100000_avisos_loja`, tag `[cardapim-avisos-loja]`).

O WhatsApp de cada loja conecta pelo QR Code ou, quando a câmera não lê, por um **código de 8 caracteres** digitado no celular. Um vigia (`bin/whatsapp-vigia.php`, a cada minuto, instalado pelo deploy) reinicia sozinho a sessão que cair no WAHA, avisa a loja (tela do WhatsApp e Visão geral) quando só alguém com o celular resolve, faz o teste de ponta a ponta (a cada 30 minutos, no horário de funcionamento, o WhatsApp da plataforma manda uma mensagem de teste para cada loja e confere se chegou) e tenta de novo as boas-vindas que falharam (até 3 vezes, dentro de 10 minutos). Detalhes em [docs/deploy/waha.md](docs/deploy/waha.md).

Em Gerência → Entrega e pagamento, o restaurante escolhe **um** jeito de receber Pix com QR Code: pelo Asaas (confirma sozinho) ou pela **chave Pix** dele (o sistema monta o QR Code e o copia e cola pelo padrão do Banco Central, sem custo e sem confirmação automática). No cardápio, o pedido chega na hora ao restaurante e o cliente fica na tela do QR Code, com "Já paguei: enviar comprovante" (abre o WhatsApp do restaurante). O caixa aceita (a produção imprime com "SOLICITAR COMPROVANTE"), e o popup "Confirmar pagamento Pix" pede "Recebi o comprovante, confirmar Pix" ou "Pix não recebido" (cancela). Confirmado, a tela do cliente vira o acompanhamento. Na mesa, o garçom mostra o QR e toca em "Recebi o Pix". O caixa continua só registrando o Pix. Migration `20261205100000_pix_chave` (tag `[cardapim-pix-chave]`). Detalhes em [docs/architecture/pedidos.md](docs/architecture/pedidos.md).

## Trocar forma de pagamento na entrega

Se o cliente vai pagar de outro jeito na entrega ou na retirada, o motoboy (no painel de entregas) ou o caixa (no pedido do Delivery) toca em **Trocar forma de pagamento**: o popup mostra a taxa de cada forma e quanto receber, e o pedido fica com a forma, a taxa e o total novos. O "Entreguei" continua sem nenhuma pergunta. Com o Asaas conectado, o motoboy pode gerar o QR Code do Pix na hora (confirmado pelo aviso do Asaas); no caixa o Pix só registra. Sem migration nova. Detalhes em [docs/architecture/entregas.md](docs/architecture/entregas.md).

## Fechamento temporário do link

Em Gerência → Perfil → Horário de funcionamento, feche ou reabra a próxima ocorrência de cada dia. A exceção por data é salva imediatamente, sem alterar os turnos semanais ou sincronizar o iFood. Bloqueia novos pedidos pelo link, inclusive no backend; pedidos anteriores seguem normalmente. A data seguinte respeita os horários, exceto a continuação de um turno cancelado na véspera. Também funciona no modo 24 horas. A rota POST `/{slug}/gerencia/perfil/horarios/fechamento` usa sessão, CSRF e os campos `data` (AAAA-MM-DD) e `fechado` (0/1). A coluna `restaurantes.fechamentos_temporarios` guarda as datas por loja; deploy com backup pela tag `[cardapim-fechamento-temporario]`.
