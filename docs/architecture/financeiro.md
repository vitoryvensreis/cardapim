# Financeiro (painel do gerente)

Aba `/{slug}/gerencia/financeiro`, so para o cargo `gerente` do restaurante (ou `adm_master`), conferido no backend em
toda requisicao (`BaseGerencia::entrar` -> `AcessoRestaurante`). Toda consulta filtra por `id_restaurante`.

## O que a tela mostra

| Aba | Rota | Conteudo |
| --- | --- | --- |
| Resumo | `GET /financeiro` | Entrou, Saiu, Resultado e Vendas (pedidos, ticket medio); como o dinheiro entrou (por forma de pagamento, contas recebidas, outras entradas do caixa); para onde foi (saidas da gaveta por motivo e contas pagas por categoria); vendas por canal; dia a dia; aviso de contas vencidas ou que vencem em 7 dias |
| Entradas e saidas | `GET /financeiro/extrato` (`?sentido=entrada\|saida`) | Extrato do periodo, agrupado por dia: vendas recebidas (uma linha por dia e forma), movimentos da gaveta (com quem fez e a hora) e contas baixadas |
| Contas a pagar e receber | `GET /financeiro/contas` (`?situacao=abertas\|vencidas\|pagas\|todas&tipo=pagar\|receber`) | Vencidas, vencem em 7 dias, para receber; lista com "Paguei"/"Recebi" em um toque, desfazer, editar e excluir |
| Fechamentos de caixa | `GET /financeiro/fechamentos` | Caixas abertos no periodo, vendas, dinheiro contado e sobra/falta; "Ver" abre o detalhe no modo caixa (`/{slug}/caixa/historico/{id}`) |
| Planilha | `GET /financeiro/relatorio` | CSV (`;`, UTF-8 com BOM, abre no Excel em portugues) com o resumo e o extrato do periodo |

Periodo (Resumo, Entradas e saidas, Fechamentos e Planilha): `?periodo=hoje|ontem|7dias|mes|mes_passado|personalizado&de=AAAA-MM-DD&ate=AAAA-MM-DD`
(`Services/Gerencia/PeriodoFinanceiro`). Padrao: este mes ate hoje. Personalizado: ate 366 dias; data invalida mostra este mes com aviso.
Funciona sem JavaScript (formulario GET).

## Regras do calculo (regime de caixa)

`Services/Gerencia/FinanceiroService`, sempre em centavos (inteiros):

- **Entrou** = pagamentos de pedidos (`pedido_pagamentos`, pela data do pagamento; pedido cancelado nao conta) + "Outra entrada" na gaveta + contas a receber recebidas (pela data da baixa).
- **Saiu** = saidas da gaveta (`caixa_movimentos`), menos sangria + contas a pagar pagas (pela data da baixa).
- **Sangria** (gaveta -> cofre) e **reforco de troco** (cofre -> gaveta) so mudam o dinheiro de lugar: aparecem como informacao e nao entram no resultado.
- **Vendas** (card e "vendas por canal") = pedidos com `status = 'concluido'` pela data `concluido_em`; o ticket medio e o total dividido pelo numero de pedidos.
- O fim do periodo e exclusivo (`[de 00:00, ate+1 00:00)`), com datas no fuso do sistema (`America/Fortaleza`).

## Contas a pagar e a receber

Rotas (todas com CSRF): `GET /financeiro/contas/nova` (`?tipo=receber`), `POST /financeiro/contas`, `GET|POST /financeiro/contas/{id}`,
`POST /financeiro/contas/{id}/baixar` (`pago_em` opcional, padrao hoje, nunca no futuro; `forma` opcional),
`POST /financeiro/contas/{id}/reabrir`, `POST /financeiro/contas/{id}/excluir` (com confirmacao na tela).

- Categorias fixas por tipo (`ContaFinanceiraService::CATEGORIAS`); categoria de outro tipo e recusada.
- **Repetir por 2 a 12 meses** cria uma conta por mes no mesmo dia, com "(1/3)" na descricao; dia 31 vira o ultimo dia nos meses curtos.
- Baixa so acontece se a conta ainda estiver aberta (dois cliques nao baixam duas vezes).
- Logs em `log_user`: `Conta cadastrada`, `Conta editada`, `Conta paga`, `Conta recebida`, `Baixa de conta desfeita`, `Conta excluida`.
- Na planilha, texto que comeca com `=`, `+`, `-` ou `@` recebe `'` na frente (nao vira formula no Excel).

Tabela: [`contas_financeiras`](../database/gerencia-abas.md#contas_financeiras).

## Arquivos

`Controllers/Gerencia/FinanceiroController`, `Services/Gerencia/{FinanceiroService,ContaFinanceiraService,PeriodoFinanceiro}`,
`Repositories/Gerencia/FinanceiroRepository`, `Views/gerencia/financeiro/*`, `public/assets/css/financeiro.css`, `public/assets/js/financeiro.js`.

## Testes

- `tests/unit/financeiro.php`: periodos (atalhos, personalizado, limite de 1 ano, datas invalidas) e rotulos.
- `tests/http/financeiro.sh`: acesso por cargo e entre restaurantes, telas, planilha, cadastro/validacao/repeticao, baixa, desfazer, editar, excluir, efeito da baixa no "Saiu" e protecao contra formulas na planilha.
