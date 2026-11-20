# Importar cardápio por planilha

Tela: **Gerência > Integrações > Importar cardápio** (`/{slug}/gerencia/integracoes/importar-cardapio`). Só o gerente do restaurante (ou o adm master) acessa; tudo é conferido no servidor.

## Fluxo

1. O gerente baixa o modelo (CSV com `;` e BOM, abre direto no Excel em português e no Google Planilhas).
2. Envia a planilha (`.xlsx` ou `.csv`, até 2 MB, 3.000 linhas, 1.000 produtos). **Nada é gravado neste passo.**
3. A tela mostra a conferência: produtos novos, categorias novas, complementos, produtos que já existem (ignorados) e cada linha com problema, com o número da linha na planilha.
4. Com zero erros, o botão **Importar N produtos** grava tudo numa transação só. Com qualquer erro, nada é importado: o gerente corrige e envia de novo.

O plano conferido fica na sessão do gerente, preso ao restaurante e ao usuário, por 30 minutos, com um código aleatório que o formulário de confirmação precisa devolver (impede confirmar uma conferência antiga ou de outra aba).

## Formato

Colunas reconhecidas pelo título, em qualquer ordem, sem diferenciar acento ou maiúscula. Colunas desconhecidas são ignoradas. Obrigatórias: **Produto** e **Preço**.

| Coluna | Uso |
|---|---|
| Categoria | Vazia = mesma da linha de cima. Reaproveita a categoria que já existe com o mesmo nome. |
| Produto | Uma linha por produto. Nome único no restaurante. |
| Descrição | Opcional, até 500 letras. |
| Preço | `24,90`, `24.90`, `R$ 1.234,56` ou número do Excel. |
| Código | Opcional, único no restaurante (letras, números, `.`, `-`, `_`). |
| Complemento | Nome do grupo (Tamanho, Sabor, Adicionais). Vazio numa linha de opção = mesmo grupo da linha de cima. |
| Obrigatório | Sim/Não (mínimo 1 ou 0). |
| Máximo | Quantas opções o cliente pode marcar (1 a 30, padrão 1). |
| Opção | Uma opção por linha, logo abaixo do produto (a primeira pode ficar na linha do produto). |
| Preço da opção | Valor **somado** ao preço do produto. Vazio = grátis. |

Aliases aceitos estão em `PlanilhaCardapio::COLUNAS` (ex.: "Nome do produto", "Valor", "Grupo").

## Regras da importação

- Produto com nome já cadastrado (ativo ou arquivado, sem diferenciar acento/maiúscula) é **ignorado**: nunca altera nem apaga o que existe.
- Código já usado por outro produto do restaurante é erro.
- Produtos novos entram **à venda**, **sem controle de estoque**, vendidos **por unidade**, sem foto, na ordem da planilha (`produtos.ordem`).
- Grupos de complementos iguais (mesmo nome, mínimo, máximo, opções e preços) viram **um grupo só**, ligado a todos os produtos; um grupo igual que já existe no restaurante é reaproveitado.
- Um registro em `log_user` ("Cardápio importado por planilha") com o arquivo, as quantidades e os nomes dos produtos.
- Evento de tempo real `estoque.alterado` (área `produto`, ação `importado`) para as telas abertas recarregarem.

## Código

| Parte | Arquivo |
|---|---|
| Leitura CSV/XLSX (sem biblioteca) | `app/Modules/Catalog/LeitorPlanilha.php` |
| Regras da planilha (sem banco) | `app/Modules/Catalog/PlanilhaCardapio.php` |
| Conferir com o banco e importar | `app/Services/Gerencia/ImportarCardapioService.php` |
| Rotas e sessão | `app/Controllers/Gerencia/ImportarCardapioController.php`, `routes/gerencia.php` |
| Tela | `app/Views/gerencia/integracoes/importar-cardapio.php`, estilos em `public/assets/css/painel.css` |

Rotas (todas com o acesso de gerência; POST com CSRF):

- `GET  /{slug}/gerencia/integracoes/importar-cardapio`
- `GET  /{slug}/gerencia/integracoes/importar-cardapio/modelo`
- `POST /{slug}/gerencia/integracoes/importar-cardapio/conferir` (multipart, campo `planilha`)
- `POST /{slug}/gerencia/integracoes/importar-cardapio/importar` (campo `codigo`)
- `POST /{slug}/gerencia/integracoes/importar-cardapio/descartar`

Sem tabela nova nem migration.

## Segurança

- XLSX é lido com `ZipArchive` + SimpleXML: só a primeira aba, alvo da aba sem `..`, XML com `DOCTYPE` recusado (sem entidades externas), `LIBXML_NONET`, limite de 25 MB por XML descompactado (protege contra ZIP "bomba").
- O tipo é conferido pelo conteúdo, não só pela extensão (`.xlsx` precisa ser ZIP; `.csv` não pode ser ZIP nem binário).
- CSV em Windows-1252 (Excel em português) é convertido para UTF-8.
- O arquivo temporário é apagado logo depois da leitura; a planilha não é guardada.
- Tudo isolado por `id_restaurante` (FKs compostas do banco garantem).
- Se o servidor não tiver a extensão `zip`, a tela aceita só CSV e explica como salvar em CSV.

## Testes

- `tests/unit/importar-cardapio.php`: CSV (`;`, `,`, tabulação, ANSI), XLSX gerado no teste, regras e mensagens de cada linha, assinatura dos grupos e o serviço em SQLite (existentes, isolamento entre restaurantes, grupo reaproveitado, tudo-ou-nada).
- `tests/http/importar-cardapio.sh`: modelo, permissão por cargo e restaurante, CSRF, planilha com erros, conferência, importação, grupo reaproveitado, reenvio sem nada novo, descartar e `.xlsx`.
