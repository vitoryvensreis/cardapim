# Banco: abas do painel do gerente

Migrations `20261110070000` a `20261110070300` (aditivas, aplicadas antes do codigo novo).
Verificacao somente leitura: `php8.4 database/verify-gerencia-abas.php --verify`.

## `user` (colunas novas)

| Coluna | Tipo | Para que serve |
| --- | --- | --- |
| `excluido_em` | DATETIME NULL | pessoa removida da equipe que ja tinha historico: a conta fica anonimizada (`removido_{id}`), sem dados pessoais e sem acesso, preservando o id para a auditoria |
| `dois_fatores` | ENUM('desativado','app','email') | metodo da verificacao em duas etapas |
| `dois_fatores_segredo` | VARCHAR(255) NULL | segredo do autenticador **cifrado** (`v1:` + AES-256-GCM); chave fora do banco |
| `dois_fatores_ativado_em` | DATETIME NULL | quando foi ligada |

Indice `idx_user_restaurante_excluido (id_restaurante, excluido_em)` para a lista da equipe. O codigo por e-mail reaproveita `ultimo_codigo_hash` / `ultimo_codigo_expira_em`.

## `user_codigos_recuperacao`

`id`, `id_user` (FK `user`, ON DELETE CASCADE), `codigo_hash` CHAR(64) (SHA-256 do codigo normalizado), `usado_em`, `criado_em`. Unico `(id_user, codigo_hash)`. Uso unico garantido por `UPDATE ... WHERE usado_em IS NULL` (conta a linha alterada).

## `restaurantes.sempre_aberto` + `restaurante_horarios`

- `restaurantes.sempre_aberto` TINYINT(1) (CHECK 0/1). Ligado, os turnos ficam guardados para quando voltar ao modo por dia.
- `restaurante_horarios`: `id_restaurante` (FK), `dia_semana` 0-6 (0 = domingo), `turno` 1-2, `abre` TIME, `fecha` TIME. Unico `(id_restaurante, dia_semana, turno)`; CHECKs de dia, turno e `abre <> fecha`.
- `fecha` menor que `abre` = fecha no dia seguinte (ex.: sexta 18:00-02:00 vale ate sabado 02:00).
- Gravacao: apaga e insere os turnos do restaurante numa transacao.

## `assinaturas`

| Coluna | Tipo | Observacao |
| --- | --- | --- |
| `id_restaurante`, `plano_id` | FKs | |
| `valor` | DECIMAL(10,2) | copiado do plano no momento da contratacao (o preco do plano pode mudar depois) |
| `periodo` | ENUM('mensal','semestral','anual') | copiado do plano |
| `status` | ENUM('ativa','cancelada','encerrada') | cancelada continua valendo ate `vence_em`; encerrada = substituida ou vencida |
| `inicio_em`, `vence_em` | DATE | CHECK `vence_em > inicio_em` |
| `cancelada_em`, `encerrada_em` | DATETIME NULL | |

- No maximo uma assinatura vigente (`ativa` ou `cancelada`) por restaurante: garantido pelo servico com trava (`SELECT ... FOR UPDATE` no restaurante) e conferido pelo verify.
- `restaurantes.plano_id` acompanha a assinatura atual.
- Carga inicial: cada restaurante com `plano_id` ganhou uma assinatura `ativa` a partir de `ultimo_pagamento` (ou da data da migration).

## Arquivos fora do banco

- `storage/uploads/logos/{32hex}.webp`: logos enviadas (servidas por `/midia/logos/...`). Ficam em `shared/storage` na VPS (sobrevivem aos deploys).
- `storage/keys/app.key`: chave da criptografia do segredo do autenticador (0600). **Incluir no backup**.

## Producao

Schema aplicado pela execucao GitHub Actions **#63** (commit `98861e9`, gatilho `[cardapim-gerencia-abas]`), com backup
`shared/backups/cardapim-before-gerencia-abas-20261110T115551Z.sql.gz` verificado antes; `verify-gerencia-abas.php` e
`verify-user-schema.php` confirmaram o resultado. O codigo das abas foi publicado depois, no commit seguinte, sem gatilho.
