# Modo cozinha (KDS) — somente leitura

O gerente abre **Gerência > Operação > Cozinha**, em `/{slug}/gerencia/cozinha`.

## Experiência

- Layout próprio, ocupando toda a janela: sem menu lateral, sem painel de caixa, sem formulários, sem botões para alterar pedidos.
- Link discreto **Voltar à gerência**. O KDS não solicita automaticamente Fullscreen API do navegador (que exige interação do usuário); ele ocupa 100% da viewport com `100dvh`.
- Cards organizados **do pedido mais antigo ao mais novo** (por `pedidos.criado_em` e ID).
- Em cada card: número do pedido, canal/tipo (entrega, retirada, balcão ou mesa), mesa e nome da comanda quando houver, status, horário, tempo decorrido, itens com nome e quantidade, observações de cada item e observação geral.
- Observações destacadas em painel contrastante; layout com fontes maiores e colunas fluidas para TV, notebook, tablet e celular.
- Sem tela de confirmação, som, vibração, luz piscando ou faixa de pedido novo, inclusive em atualizações.
- Estado vazio explica que novos pedidos aparecerão automaticamente.
- Regras de identidade: cores e tokens de `core.css`; ícones Lucide existentes; nenhum emoji, CSS/JS externos.

## Quais pedidos aparecem

São mostrados **somente** pedidos com status `em_preparo` e pelo menos um item não cancelado. No fluxo de delivery, o pedido entra quando o operador clica em **Aceitar e preparar** no caixa (transição `recebido` → `em_preparo`).

Pedidos ainda em `recebido`, `aguardando_pagamento` ou comandas `aberto` **não aparecem**. Um pedido só aparece se o sistema registrar seu estado como `em_preparo`; não basta chegar, ser pago ou ter itens adicionados. Ao passar a `pronto`, `saiu_entrega`, `concluido` ou `cancelado`, deixa a fila. A cozinha permanece estritamente de leitura.

A tela **não altera status**. O recebimento, preparo, conclusão, cancelamento e pagamentos continuam nas telas existentes de caixa/garçom.

## Dados e arquitetura

- `GET /{slug}/gerencia/cozinha` → `Gerencia/CozinhaController::mostrar`
- `GET /{slug}/gerencia/cozinha/conteudo` → `Gerencia/CozinhaController::conteudo`
- `Services/Cozinha/PainelCozinha` → `Repositories/Gerencia/CozinhaRepository`
- `Views/layouts/cozinha.php` → `Views/gerencia/cozinha/{index,_pedidos}.php`
- `public/assets/{css,js}/cozinha.*`

As duas rotas passam por `BaseGerencia::entrar` e `AcessoRestaurante`; só gerente do restaurante (e `adm_master` em suporte) acessa. Ambas usam respostas sem cache. As consultas usam `id_restaurante` em pedidos, mesas e itens; itens cancelados não aparecem; nenhuma migração é necessária.

## Atualização silenciosa

O layout **não carrega** `alerta-pedido.js` nem cria `data-alerta-pedido`. Ele carrega opcionalmente `tempo-real.js` no canal `pedidos,cozinha,mesas` e sempre carrega `cozinha.js`, que busca o fragmento HTML autenticado ao detectar evento relevante, ao recuperar foco/rede e a cada 20 segundos como reserva.

Nenhum evento cria notificação, toca áudio, vibra ou muda o título da aba. A atualização usa HTML escapado pelo servidor e não executa ações de escrita.

## Testes

- `php tests/unit/cozinha.php` (SQLite em memória): isolamento entre restaurantes, ordenação, entrada após aceite, saída quando pronto, notas e exclusão de itens cancelados.
- `bash tests/http/cozinha.sh <base> <slug>`: 302 sem login, 403 por cargo e tenant, 200 gerente/master, fragmento sem layout, HTML de tela cheia, ausência de alarmes e POST 405.
- CI valida sintaxe PHP, JS, views sem estilos inline/emojis, testes e deploy automático da `main` após merge.

## Limite intencional

O KDS não substitui a operação do caixa nem registra início/fim de preparo. Não foram criadas tabelas, permissões novas ou comandos para mudar status; esta entrega é exclusivamente de visualização.
