# Primeira estrutura de banco — Cardapim

## Rota pública de saúde

- **GET /status**: página responsiva com estado do PHP e da conexão MySQL.
- **GET /status** com cabeçalho Accept: application/json: JSON para monitoramento.
- Com conexão MySQL operacional, retorna HTTP **200**. Caso falhe, HTTP **503**.
- Não expõe credenciais, host do banco, exceções, mensagens SQL nem versão detalhada do MySQL. Em produção, o PHP exibe somente versão principal/secundária.
- A rota apenas executa SELECT 1; **não confirma a existência das tabelas**. Use migrations para verificar o schema.
- Cache desabilitado e indexação de busca bloqueada.

## Tabela planos

| Coluna | Tipo | Observação |
| --- | --- | --- |
| id | BIGINT UNSIGNED | Chave primária auto incremental |
| nome | VARCHAR(120) | Nome comercial |
| descricao | TEXT NULL | Descrição |
| valor | DECIMAL(10,2) | Valor em reais, não negativo |
| periodo | ENUM | mensal, semestral ou anual |
| ativo | TINYINT(1) | Padrão 1 |
| created_at, updated_at | TIMESTAMP | Auditoria de criação/atualização |

Há chave única em (nome, periodo); o mesmo nome pode oferecer períodos diferentes.

## Tabela restaurantes

| Coluna | Tipo | Observação |
| --- | --- | --- |
| id | BIGINT UNSIGNED | Chave primária e identificador do restaurante/tenant nesta fase |
| nome | VARCHAR(180) | Nome do estabelecimento |
| slug | VARCHAR(160) | Identificador público exclusivo; não alterar sem migração das URLs |
| telefone | VARCHAR(25) NULL | Número com DDD; validar na aplicação |
| email | VARCHAR(190) NULL | Contato |
| cnpj | CHAR(14) NULL | Somente dígitos; único quando preenchido |
| plano_id | BIGINT UNSIGNED NULL | FK para planos.id; pode ficar vazio durante onboarding |
| status_rest | ENUM | pendente (padrão), ativo, suspenso, inativo |
| logo_path | VARCHAR(500) NULL | Caminho/URL da logo, sem armazenar binário na tabela |
| created_at, updated_at | TIMESTAMP | Auditoria de criação/atualização |

Não é possível apagar um plano referenciado por restaurantes (ON DELETE RESTRICT). Planos e restaurantes não recebem tenant_id: planos são globais, e cada linha de restaurante representa o tenant desta fase. Os dados operacionais futuros deverão ser isolados por tenant_id e establishment_id quando aplicável. A administração de multiunidades não foi implementada nesta entrega.

## Aplicar as migrations

As duas migrations estão em database/migrations/ e são processadas pelo runner já existente.

Na VPS, **primeiro conferir e garantir backup**; as DDL do MySQL não são desfeitas por rollback da release:

    BASE=/home/escotilhaprodutora-cardapim/apps/cardapim
    runuser -u escotilhaprodutora-cardapim -- php8.4 "$BASE/current/database/migrate.php" --status
    runuser -u escotilhaprodutora-cardapim -- php8.4 "$BASE/current/database/migrate.php" --apply
    runuser -u escotilhaprodutora-cardapim -- php8.4 "$BASE/current/database/migrate.php" --status

As operações executam no banco informado no shared/.env. Não inserir credenciais no terminal em linha de comando nem no repositório.

O workflow de deploy continua respeitando CARDAPIM_RUN_MIGRATIONS=false; não ativar sem configurar backup privado mysql-backup.cnf e política de retenção. As tabelas são criadas vazias, sem planos ou restaurantes fictícios.

## Bootstrap inicial concluído em 09/11/2026

O schema inicial foi aplicado na VPS pelo GitHub Actions #12, release 9e165516, com as duas migrations confirmadas no log e relacionamento validado. O gatilho especial de inicialização foi desativado após a execução, evitando repetição acidental em deploys futuros.

O script `database/initialize-schema.php --apply` continua disponível em CLI para verificação controlada do esquema inicial; ele recusa bancos com tabelas inesperadas ou estrutura parcial. As próximas migrations exigem configuração prévia de backup. CARDAPIM_RUN_MIGRATIONS permanece false.

## Planos fictícios e último pagamento (11/2026)

A migration `20261109235000_add_ultimo_pagamento_restaurantes.php` adiciona `restaurantes.ultimo_pagamento DATE NULL DEFAULT NULL`. O campo registra a data do último pagamento individual de cada restaurante e permanece nulo até um pagamento real; ele **não** representa uma data comum a todos os restaurantes do mesmo plano.

Três planos de demonstração, **sem cobrança real**, são semeados pelo script `php8.4 database/seed-demo-planos.php --apply`. O seeder é idempotente: não modifica planos existentes com mesmo nome e período.

| Nome | Período | Valor fictício |
| --- | --- | --- |
| Demonstração Mensal | mensal | R$ 99,90 |
| Demonstração Semestral | semestral | R$ 499,90 |
| Demonstração Anual | anual | R$ 899,90 |

Aplicado em produção em 09/11/2026 pela execução GitHub Actions #16 (commit `f17c8094`), com backup MySQL antes da alteração, migration aplicada e três planos verificados. O backup comprimido foi salvo em `shared/backups` com permissão 600. O gatilho temporário foi retirado após a execução confirmada. `CARDAPIM_RUN_MIGRATIONS=false` permanece para futuras alterações planejadas.


## Visibilidade dos planos e logs de usuarios (11/2026)

- Nova coluna `planos.visibilidade TINYINT(1) NOT NULL DEFAULT 1` com restrição CHECK para 0 ou 1. O valor 1 significa **visível para oferta pública** (padrão); 0 significa **oculto da compra pública**, para futura atribuição individual a parceiros. O campo `ativo` é independente: um plano oculto não está necessariamente inativo. A aplicação de listagem e compra ainda não foi implementada; ela precisará filtrar `visibilidade=1 AND ativo=1` quando existir.
- Nova tabela `log_user`:
  - `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
  - `titulo VARCHAR(200) NOT NULL`
  - `descricao TEXT NOT NULL`
  - `id_user BIGINT UNSIGNED NULL`
  - `data_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `ip VARCHAR(45) NULL` (IPv4 e IPv6)
- Índices de consulta por `(id_user, data_hora)` e por `data_hora`.
- Nenhuma chave estrangeira em `id_user` por enquanto: a tabela de usuários ainda não existe. Decidir o vínculo (inclusive isolamentos por tenant) na implementação da identidade e permissões.
- **Apenas estrutura**, sem automação de gravação de logs nem criação de planos vitalícios.

As migrations versionadas são `20261109235500_add_visibilidade_planos.php` e `20261109235600_create_log_user.php`. A operação de publicação pontual executa backup MySQL privado antes de aplicá-las; `CARDAPIM_RUN_MIGRATIONS=false` permanece. Os planos já cadastrados recebem visibilidade 1.


### Implantação em produção

As duas alterações foram aplicadas no MySQL da VPS pelo GitHub Actions **#20** (commit `f808751f`). O log confirmou o backup `shared/backups/cardapim-before-visibilidade-log-20261110T000424Z.sql.gz`, a aplicação das migrations `20261109235500_add_visibilidade_planos.php` e `20261109235600_create_log_user.php`, e a verificação das seis colunas e do valor padrão 1. O acionamento temporário foi retirado do workflow após a operação. `CARDAPIM_RUN_MIGRATIONS` permanece desativado.


## Configuracoes globais e restaurante modelo (11/2026)

A tabela global `configuracoes` armazena apenas valores publicos (nunca senhas, tokens, segredos), com:

| Coluna | Tipo | Finalidade |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | PK |
| `chave` | VARCHAR(100) UNIQUE | Identificador estavel |
| `valor` | TEXT | Texto ou URL/caminho |
| `tipo` | ENUM('texto','imagem') | Categoria do valor |
| `descricao` | VARCHAR(255) NULL | Explicacao |
| `created_at, updated_at` | TIMESTAMP | Historico temporal |

**Valores inicialmente cadastrados** (idempotentes, sem sobrescrever edicoes):

- `logo_colorida` = `/assets/img/logo-colorida.svg`
- `logo_branca` = `/assets/img/logo-branca.svg`
- `logo_preta` = `/assets/img/logo-preta.svg`
- `icone` = `/assets/img/icone.svg`
- `nome_sistema` = `Cardapim`
- `descricao_padrao` = `Gestão de restaurantes do jeito simples.`
- `texto_rodape` = `Menos complicação. Mais restaurante.`

As logos **sao lidas da tabela** por `CardapimServicesConfiguracoes::logo('logo_colorida')` (ou logo_branca, logo_preta, icone), usando uma unica consulta por requisicao; o service rejeita URLs inseguras e expoe somente chaves permitidas. Na tela `/status`, se o banco falhar, a marca volta a aparecer como texto e o codigo HTTP informa 503.

**CSS, JS e outras imagens internas:** `CardapimCoreAssetUrl::asset('css/site.css')` retorna `/assets/css/site.css`; `::asset('js/site.js')` retorna `/assets/js/site.js`. Para links absolutos, `AssetUrl::absolute('img/icone.svg')` utiliza `APP_URL` do .env. Assim nao se duplicam URLs por arquivo no banco e futuras mudancas de dominio nao exigem updates em cada asset.

**Restaurante modelo:** nome `Restaurante Modelo Cardapim`, slug `restaurante-modelo-cardapim`, plano `Demonstração Mensal`, status `ativo`. Telefone, CNPJ, e-mail, logo e ultimo pagamento permanecem vazios. O seeder e idempotente e nao altera um restaurante se o slug ja existe.

Migration: `database/migrations/20261110001000_create_configuracoes.php`. Seeder: `database/seed-configuracoes-restaurante.php`. A preparacao de producao usa script pontual com backup antes da migracao e seed, antes de ativar a release. `CARDAPIM_RUN_MIGRATIONS=false` permanece.


### Implantacao do schema e modelo em producao

A execucao GitHub Actions **#24**, commit `63779b63`, confirmou backup MySQL privado antes da migration, aplicacao de `20261110001000_create_configuracoes.php`, seed das URLs de marca/textos e criacao/validacao do Restaurante Modelo vinculado ao plano Demonstracao Mensal. A troca de release ocorreu **depois** das alteracoes no banco, e o healthcheck HTTPS passou. Backup: `shared/backups/cardapim-before-configuracoes-20261110T003220Z.sql.gz`. O gatilho especial do workflow foi removido na etapa seguinte. `CARDAPIM_RUN_MIGRATIONS=false` permanece.


## Usuarios e vinculacao dos logs (11/2026)

Tabelas em migrations separadas:

- `20261110004500_create_user.php`: cria a tabela **`user`** (nome singular, conforme solicitado).
- `20261110004600_vincular_log_user.php`: adiciona FK `log_user.id_user → user.id` com `ON DELETE RESTRICT` para preservar as evidencias de auditoria.

| Coluna | Tipo | Finalidade |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED, PK | Identificador |
| `id_restaurante` | BIGINT UNSIGNED NULL, FK | Restaurante do usuario |
| `cargo` | ENUM | cliente, gerente, motoqueiro, garcom, caixa, adm_master |
| `usuario` | VARCHAR(60) UNIQUE | Login unico |
| `email` | VARCHAR(190) UNIQUE | E-mail de acesso |
| `senha_hash` | VARCHAR(255) | Hash de senha (PHP `password_hash()`) |
| `nome` | VARCHAR(120) | Primeiro nome |
| `sobrenome` | VARCHAR(150) | Sobrenome |
| `whatsapp` | VARCHAR(25) NULL | Telefone de contato |
| `ultimo_acesso` | DATETIME NULL | Data do ultimo login |
| `ultimo_codigo_hash` | VARCHAR(255) NULL | Hash do ultimo codigo de verificacao, nunca o codigo em claro |
| `ultimo_codigo_expira_em` | DATETIME NULL | Expiracao do codigo |
| `email_verificado_em` | DATETIME NULL | Confirmacao de e-mail |
| `ativo` | TINYINT(1) DEFAULT 1 | Ativo ou desativado |
| `criado_em` | DATETIME | Data e hora de criacao |
| `atualizado_em` | DATETIME | Atualizacao |

**Permissoes e multi-tenant:** apenas o papel `adm_master` possui `id_restaurante=NULL`. Os demais cargos exigem restaurante existente. CHECK no MySQL protege essas regras independentemente do frontend. Cada consulta futura precisa filtrar restaurante e validar permissoes no backend; a presente entrega cria somente a estrutura, sem implementar autenticacao nem autorizar operacoes. O escopo de cada conta e um restaurante; caso futuramente haja uma conta vinculada a varios estabelecimentos, esse relacionamento exigira tabela associativa.

**Logs:** `log_user.id_user` pode ser NULL para eventos de sistema ou anteriores sem identificacao, mas, se preenchido, aponta obrigatoriamente para `user.id`. `ON DELETE RESTRICT` impede exclusao fisica de usuario com logs, por isso o caminho normal sera `ativo=0`, conservando a auditoria. Logs antigos nao sao apagados nem modificados.

**Seguranca:** `senha_hash` e `ultimo_codigo_hash` nao devem guardar valores em texto puro. O ultimo codigo deve ser gerado aleatoriamente com `random_int()` ou `random_bytes()`, armazenado como hash e validado com vencimento, limite de tentativas e taxa de envio quando a autenticacao for implementada. Nao inserir master com senha de exemplo.

**Aplicacao:** fazer backup privado do banco antes das duas migrations. O script `deploy/scripts/apply-user-schema.sh` valida que somente as duas migrations autorizadas estao pendentes, cria backup com `mysqldump`, aplica a versao e valida as FKs. O controle geral `CARDAPIM_RUN_MIGRATIONS=false` continua.


### Aplicacao em producao

A execucao GitHub Actions **#31** (commit `b0d5550b`) criou backup privado `shared/backups/cardapim-before-user-schema-20261110T011743Z.sql.gz` antes das migrations, aplicou `20261110004500_create_user.php` e `20261110004600_vincular_log_user.php` e validou chaves estrangeiras, cargos e restricoes. O acionamento temporario foi removido no workflow seguinte. `CARDAPIM_RUN_MIGRATIONS` permanece `false`.


## Contas de teste por cargo

O seeder `database/seed-test-users.php` cria seis contas com nomes previsiveis, e-mails ficticios em `@cardapim.test` e senhas aleatorias distintas **geradas somente no servidor**. O numero de WhatsApp e lido de `CARDAPIM_TEST_WHATSAPP` no arquivo privado `shared/.env` (11 digitos com DDD); nao o inserir no GitHub.

| Usuario | Cargo | Restaurante | Estado |
| --- | --- | --- | --- |
| `teste_cliente` | cliente | Restaurante Modelo Cardapim | ativo |
| `teste_gerente` | gerente | Restaurante Modelo Cardapim | ativo |
| `teste_motoqueiro` | motoqueiro | Restaurante Modelo Cardapim | ativo |
| `teste_garcom` | garcom | Restaurante Modelo Cardapim | ativo |
| `teste_caixa` | caixa | Restaurante Modelo Cardapim | ativo |
| `teste_adm_master` | adm_master | nenhum, global | **inativo** |

O `adm_master` ficticio fica desativado para proteger a producao; habilitacao real devera ocorrer no processo de onboarding de superadmin, com MFA. Nao usar e-mails de usuarios reais sem verificacao.

**Entrega de senhas:** `shared/backups/acessos-cardapim-testes.txt` fora do diretorio publico, modo `0600`. Nenhuma senha sai em logs de CI, console ou GitHub. Pelo terminal autorizado da VPS, o administrador pode consultar com `cat /home/escotilhaprodutora-cardapim/apps/cardapim/shared/backups/acessos-cardapim-testes.txt` e guardar em cofre de senhas. Nao enviar prints com senhas nem colocar esse arquivo em pasta publica.

**Operacao de producao:** configurar `CARDAPIM_TEST_WHATSAPP` no `shared/.env`; criar backup privado `mysqldump` pelo `deploy/scripts/apply-test-users.sh`; o seeder requer o Restaurante Modelo no plano mensal e recusa contas preexistentes com conflitos. Para conferir sem alterar, usar `php8.4 database/seed-test-users.php --verify`. `CARDAPIM_RUN_MIGRATIONS=false` permanece. A criacao dessas contas nao implementa login, permissoes backend ou envio por WhatsApp.


### Execucao no terminal autorizado da VPS

A publicacao de codigo nao executa o seeder automaticamente para evitar expor o WhatsApp pessoal no Git e nao criar credenciais antes da confirmacao do operador. Depois do deploy, executar no terminal da Hostinger como root (o numero sera digitado de forma oculta, sem gravar no historico do shell):

```bash
read -r -s -p 'WhatsApp dos testes (11 digitos com DDD): ' TESTE_WHATSAPP
printf '\n'
runuser -u escotilhaprodutora-cardapim -- env CARDAPIM_TEST_WHATSAPP="$TESTE_WHATSAPP" bash /home/escotilhaprodutora-cardapim/apps/cardapim/current/deploy/scripts/apply-test-users.sh
unset TESTE_WHATSAPP
```

O script salva um backup privado do banco, insere as contas e armazena as senhas aleatorias no arquivo mencionado acima. Nao enviar a senha em prints ou logs de suporte. O seeder nao muda contas preexistentes: em caso de conflitos, interrompe a execucao para revisao.


## CPF na tabela user

- Migration `20261110020000_add_cpf_user.php`: `cpf CHAR(11) NULL DEFAULT NULL` apos `sobrenome`, indice unico `uq_user_cpf` e `CHECK chk_user_cpf (cpf IS NULL OR cpf REGEXP '^[0-9]{11}$')`.
- Armazenar somente os 11 digitos (sem pontuacao). `NULL` e permitido para contas existentes e para o `adm_master` global.
- Validacao oficial no backend: `Cardapim\Shared\Validation\Documento::normalizarCpf()` (digitos verificadores). A mascara do navegador e apenas conforto.
- LGPD: CPF e dado pessoal; nao registrar em logs e exibir mascarado (`***.982.247-**`).
- Aplicacao em producao: `deploy/scripts/apply-user-cpf.sh`, acionado uma unica vez pelo CI com a marca `[cardapim-user-cpf]` na mensagem do commit. O script confere que so essa migration esta pendente, cria backup `mysqldump` validado, aplica e roda `verify-user-schema.php`. `CARDAPIM_RUN_MIGRATIONS` continua `false`.

A execucao GitHub Actions **#40** (commit `2d22656`) criou o backup privado `shared/backups/cardapim-before-user-cpf-20261110T023013Z.sql.gz`, aplicou `20261110020000_add_cpf_user.php` e validou o schema (`verify-user-schema.php`). O acionamento temporario foi removido do workflow no commit seguinte. `CARDAPIM_RUN_MIGRATIONS` permanece `false`.

## CNPJ do restaurante e indice do catalogo

- `restaurantes.cnpj CHAR(14) NULL` com `uq_restaurantes_cnpj` ja existia desde a migration inicial.
- Migration `20261110030000_check_cnpj_restaurantes.php`: `CHECK chk_restaurantes_cnpj (cnpj IS NULL OR cnpj REGEXP '^[0-9]{14}$')` e indice `idx_restaurantes_catalogo (status_rest, nome, id)` para o catalogo publico.
- O cadastro publico exige CNPJ valido (digitos verificadores em `Documento::cnpjValido`). `NULL` segue permitido no banco para o Restaurante Modelo e registros de demonstracao.
- Aplicacao em producao: `deploy/scripts/apply-cnpj-restaurantes.sh`, acionado uma unica vez pelo CI com `[cardapim-cnpj]` na mensagem do commit (backup validado antes, depois `verify-cnpj-restaurantes.php`).
