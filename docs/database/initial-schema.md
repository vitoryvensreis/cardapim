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

Uma publicação única marcada com `[cardapim-demo-plans]` executará backup MySQL via mysqldump antes da migration e do seeder na VPS. O backup comprimido ficará em `shared/backups` com permissão 600. O gatilho deverá ser removido depois da primeira execução verificada. Manter `CARDAPIM_RUN_MIGRATIONS=false` para atualizações futuras não autorizadas.
