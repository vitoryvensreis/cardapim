# Painel da plataforma (administração do Cardapim)

Área do cargo `adm_master` em `/painel`, com menu lateral igual ao do gerente (mesma estrutura visual:
`app/Views/layouts/painel.php`, usada por `layouts/gerencia.php` e `layouts/plataforma.php`).
Acesso conferido no servidor a cada requisição (`Controllers/Plataforma/BasePlataforma`): sem login vai para o
login; qualquer outro cargo recebe 403. Todo POST exige CSRF e toda ação gera log com `[Plataforma]`.

| Seção | Rota | Situação |
| --- | --- | --- |
| Dashboard | `/painel` | **Pronto**: assinaturas, restaurantes, pedidos da plataforma e saúde do sistema ([detalhes](#dashboard)) |
| Restaurantes | `/painel/restaurantes` | **Pronto**: lista, cadastrar, editar, situação, plano, contas e excluir ([detalhes](#restaurantes)) |
| Planos | `/painel/planos` | **Pronta**: planos publicos e privados (link de convite), vitalicio gratis ([assinaturas.md](assinaturas.md)) |
| Configurações | `/painel/configuracoes` | **Pronta**: nome, textos, logos, icone do app e teste do e-mail do sistema ([email.md](../deploy/email.md)) |
| Faturas | `/painel/faturas` | **Pronta**: números do mês, lista com filtros e "Atualizar agora" ([detalhes](#faturas)) |
| Usuários | `/painel/usuarios` | **Pronto**: todas as contas, criar, editar, senha, bloquear, 2FA e excluir ([detalhes](#usuarios)) |
| Integrações | `/painel/integracoes` | **Pronta**: Asaas da plataforma e WhatsApp da plataforma |
| Logs de auditoria | `/painel/logs` | **Pronto**: filtros por tipo, restaurante, pessoa, texto e datas; planilha ([detalhes](#logs-de-auditoria)) |
| Suporte | `/painel/suporte` | **Pronto**: chamados dos restaurantes ([suporte.md](suporte.md)) |
| Minha conta | `/painel/minha-conta` | **Pronta**: dados, senha, verificação em duas etapas, aparelhos e acessos ([detalhes](#minha-conta)) |

Fonte única do menu e do que cada seção vai ter: `app/Services/Painel/MenuPlataforma.php`.
Rotas: `routes/plataforma.php` (registradas antes das rotas `/{slug}`).

## Restaurantes

Rotas: `GET /painel/restaurantes` (`?busca=&status=&plano=&pagina=`), `GET /painel/restaurantes/novo`, `POST /painel/restaurantes`,
`GET|POST /painel/restaurantes/{id}`, `POST /painel/restaurantes/{id}/status` (`status`, `motivo`), `POST .../plano` (`plano_id`),
`POST .../excluir` (`confirmacao` = endereço do restaurante). Código: `Controllers/Plataforma/RestaurantesController`,
`Services/Plataforma/RestauranteAdminService`, `Repositories/Plataforma/RestauranteAdminRepository`, views `plataforma/restaurantes/*`.

- **Lista**: busca por nome, endereço, cidade, CNPJ ou telefone; filtro por situação (com contagem) e plano; 25 por página.
- **Cadastrar**: restaurante + gerente + 5 mesas + assinatura numa transação (`CadastroRestaurante::cadastrarPeloPainel`, a mesma
  gravação do cadastro do site). Aceita qualquer plano ativo, inclusive privado; CNPJ e CPF opcionais (CNPJ obrigatório em plano
  pago, porque o Asaas exige); plano pago só com o Asaas da plataforma conectado.
- **Editar**: nome, telefone, e-mail, endereço (mesmas regras do Perfil, `PerfilService::salvarDados`) e CNPJ. O endereço do cardápio não muda.
- **Situação**: Liberar (ativo), Suspender (pausa: equipe desconectada, cardápio fora do ar) e Desativar (fora do catálogo). Suspender e
  desativar pedem motivo, que vai para o log. Pendente e inadimplente continuam vindo da cobrança.
- **Plano**: `AssinaturaService::assinar($plano, true)` aceita plano privado; plano pago gera/ajusta a cobrança no Asaas.
- **Excluir**: só restaurante **sem movimento** (sem pedidos, caixas, contas no financeiro nem faturas pagas) e com as integrações
  desconectadas; pede para digitar o endereço. Apaga contas, cardápio e configurações; os logs ficam (sem o vínculo com as contas
  apagadas). Com movimento, a tela orienta a usar "Desativar".
- Atalhos: "Ver cardápio" e "Abrir painel do restaurante" (o administrador entra em qualquer restaurante).

## Usuários

Rotas: `GET /painel/usuarios` (`?busca=&cargo=&restaurante=&situacao=todos|ativos|bloqueados&pagina=`), `GET /painel/usuarios/novo`
(`?restaurante=&cargo=`), `POST /painel/usuarios`, `GET|POST /painel/usuarios/{id}`, `POST .../senha`, `POST .../acesso`,
`POST .../dois-fatores`, `POST .../excluir`. Código: `UsuariosController`, `UsuarioAdminService`, `UsuarioAdminRepository`.

- Lista administradores e a equipe de todos os restaurantes (gerente, caixa, garçom, entregador), 30 por página. Contas excluídas não aparecem.
- Criar/editar: função, restaurante (menos administrador), nome, usuário, e-mail (obrigatório para administrador e gerente), WhatsApp, CPF.
  Mudar de função ou de restaurante desconecta os aparelhos lembrados.
- Trocar senha (desconecta aparelhos), bloquear/liberar, desligar a verificação em duas etapas (suporte) e excluir (apaga quem nunca entrou;
  com histórico, anonimiza).
- Proteções: ninguém bloqueia, exclui ou muda a própria função; a plataforma nunca fica sem administrador ativo.
- Testes: `tests/http/adm-gestao.sh`. Logs: `Usuário criado/editado/excluído`, `Usuário: senha redefinida`, `Usuário: acesso bloqueado/liberado`,
  `Usuário: verificação em duas etapas desligada`, `Restaurante: liberar acesso/suspender/desativar`, `Restaurante: CNPJ alterado`,
  `Restaurante excluído`, `Cadastro do restaurante` (pelo painel).

## Integrações da plataforma

Rotas (todas só adm_master):

```text
GET  /painel/integracoes
GET  /painel/integracoes/asaas          POST /painel/integracoes/asaas (conectar)
POST /painel/integracoes/asaas/webhook  POST /painel/integracoes/asaas/testar  POST /painel/integracoes/asaas/desconectar
POST /painel/integracoes/asaas/reativar POST /painel/integracoes/asaas/cobranca (regras das assinaturas)
GET  /painel/integracoes/whatsapp       POST /painel/integracoes/whatsapp/conectar
GET  /painel/integracoes/whatsapp/estado (JSON)  POST /painel/integracoes/whatsapp/teste  POST /painel/integracoes/whatsapp/desconectar
```

- **Asaas**: conta da plataforma para receber as assinaturas (base das futuras Faturas). Chave cifrada, webhook automático
  com os eventos de assinatura, lista "Pronto para cobrar?" (conta aprovada, chave Pix, avisos ativos, com "Reativar avisos")
  e "Cobrança das assinaturas": formas (Pix, Boleto, Cartão), vencimento da 1ª cobrança, dias grátis, multa, juros,
  desconto para pagar antes, dias de atraso para suspender e fatura enviada pelo Asaas. O ciclo (mensal, semestral, anual)
  vem do plano.
- **WhatsApp**: número da plataforma, separado dos restaurantes, no mesmo WPPConnect (`127.0.0.1:21467`).
  Próximo uso: código de verificação do cliente final, que entra só com o número de telefone.
- Detalhes técnicos: [integracoes.md](integracoes.md). Teste: `tests/http/plataforma.sh` (CI).

## Faturas

Rotas: `GET /painel/faturas` (`?situacao=pendente|paga|vencida|cancelada|estornada&mes=AAAA-MM&busca=&ambiente=producao|sandbox&pagina=`)
e `POST /painel/faturas/atualizar`. Código: `Controllers/Plataforma/FaturasController`, `Repositories/Plataforma/FaturaAdminRepository`,
view `plataforma/faturas/lista.php`, CSS `faturas.css`. Tabela: `assinatura_faturas` (já existente, gravada pela `AssinaturaCobranca`).

- **Números do topo**: recebido no mês (pela data do pagamento), a receber (em aberto), vencido e restaurantes com fatura vencida.
- **Lista**: vencidas primeiro, depois em aberto, pagas, estornadas e canceladas; restaurante, plano, valor, vencimento, situação
  (texto + ícone), data e forma do pagamento e link "Ver no Asaas" (só `https://`).
- **Ambiente**: mostra as faturas do ambiente da conta Asaas conectada (produção ou teste). Se houver faturas do outro, aparece o atalho.
- **Atualizar agora**: consulta no Asaas até 40 assinaturas ativas (as com fatura em aberto primeiro) pela `AssinaturaCobranca::sincronizar`.
  Normalmente não precisa: o aviso (webhook) do Asaas já muda a situação sozinho.
- Somente leitura: nenhuma fatura é criada, editada ou apagada nesta tela.

## Dashboard

Rota: `GET /painel` (`?periodo=hoje|7dias|30dias`, padrão 7 dias). Código: `PainelController::inicio`,
`Repositories/Plataforma/DashboardRepository`, view `plataforma/dashboard.php`, CSS `dashboard-plataforma.css` (+ `visao-geral.css`
pelo gráfico e ranking; o gráfico reaproveita `gerencia/visao-geral/_barras.php`).

- **Avisos no topo**: chamados esperando resposta, faturas vencidas e restaurantes esperando o 1º pagamento (cada um com link).
- **Números**: receita por mês (assinaturas ativas, valor dividido pelos meses do período), recebido no mês e a receber (faturas
  do ambiente do Asaas conectado), restaurantes ativos e novos em 30 dias, pedidos do período comparados ao período anterior.
- **Pedidos na plataforma**: barras por dia (14 dias, ou 30 no período de 30 dias), valor, quantos restaurantes venderam e
  pedidos por canal. Cancelados e Pix não pagos ficam de fora.
- **Quem mais vendeu** (5 restaurantes) e **restaurantes por situação** (links para a lista filtrada).
- **Saúde do sistema**: banco, tempo real (`StatusController::tempoReal`), Asaas e WhatsApp da plataforma, iFood (chaves,
  lojas conectadas e última passagem da rotina, alerta acima de 3 minutos) e e-mail (SMTP configurado ou não), com link para resolver.

## Logs de auditoria

Rotas: `GET /painel/logs` (`?tipo=acessos|seguranca|alteracoes|clientes&restaurante=<id>|plataforma&pessoa=&busca=&de=AAAA-MM-DD&ate=&pagina=`)
e `GET /painel/logs/planilha` (mesmos filtros, CSV com `;` e BOM, até 20 mil linhas; o download também vira log "Logs exportados").
Código: `Controllers/Plataforma/LogsController`, `Repositories/Plataforma/LogAdminRepository`, view `plataforma/logs.php`, CSS `logs.css`.
Planilhas usam `Shared/Planilha` (a mesma proteção contra fórmulas do Financeiro).

- Lista agrupada por dia (Hoje, Ontem, data), mais recentes primeiro, 50 por página: o que aconteceu, detalhes, quem (nome, usuário,
  função, restaurante) e IP. Ícone e cor por tipo, sempre com texto.
- Só leitura: nada é editado ou apagado. Restaurante = restaurante da conta que fez; "Administração da plataforma" = contas `adm_master`.

## Minha conta

Rotas em `/painel/minha-conta` iguais às do gerente (`/dados`, `/senha`, `/dois-fatores/app`, `/dois-fatores/email`,
`/dois-fatores/email/confirmar`, `/dois-fatores/desativar`, `/codigos`, `/aparelhos/sair-todos`, `/aparelhos/{id}/sair`).
O fluxo é o mesmo do gerente: `Controllers/Concerns/FluxoMinhaConta` (trait) usado por `Gerencia\MinhaContaController` e
`Plataforma\MinhaContaController`; views em `app/Views/minha-conta/`. Usuário e e-mail do administrador mudam em Usuários, por
outro administrador.

Teste: `tests/http/painel-adm.sh`.
