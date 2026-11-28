# Saudacao automatica de WhatsApp (primeira mensagem do dia)

Este recurso e **independente dos seis avisos de pedido** e fica em
`/{slug}/gerencia/integracoes/whatsapp#saudacao`.
Cada gerente pode ativar e editar a mensagem; novas lojas comecam com a saudacao **desligada**.

## Regras

- Somente mensagens recebidas (evento `message` do WAHA convertido para `onmessage`, com `fromMe=false`) de contatos individuais brasileiros `55DDDnumero@c.us` **ou** identificadores de chat diretos `NNNN@lid`. O LID nunca e interpretado como numero de telefone.
- Nao responde grupos, broadcasts, status ou mensagens do proprio restaurante. Um LID sem numero real informado pelo WAHA e respondido diretamente no mesmo chat, sem descobrir o numero.
- Somente se a loja estiver ativa e o horario do Perfil permitir. Usa `HorarioFuncionamento::abertoEm` (inclusive apos meia-noite), horario `America/Fortaleza` da aplicacao.
- Mensagens recebidas fora do horario nao contam; o primeiro contato **elegivel** do dia inicia a saudacao.
- Mensagens antigas (mais de 5 min) ou com relogio adiantado mais de 2 min sao ignoradas, inclusive as reemitidas apos o servidor reiniciar.
- UNIQUE `(id_restaurante, telefone, dia_local)`: guarda a identidade de chat (numero ou LID) e impede respostas duplicadas por dia e por loja, inclusive chamadas repetidas.
- Texto com variaveis `{{restaurante}}` e `{{cardapio}}`. Link da loja e obrigatorio e inserido quando faltar.
- Guarda apenas a mensagem de saida e o identificador de destino (telefone ou LID) no historico. Limpar registros com mais de 35 dias pelo script de manutencao.

## Webhook (WAHA)

Cada sessao do WAHA e criada pelo proprio Deliveryzim ja com o webhook
`POST /webhooks/waha/{WAHA_WEBHOOK_TOKEN}` e o evento `message` ([waha.md](../deploy/waha.md)).
Nao ha configuracao manual por loja.

- A entrada e publica, protegida por um segredo aleatorio longo (>=32 caracteres) na URL e pela
  assinatura `X-Webhook-Hmac` (HMAC-SHA512 do corpo) com o token da sessao da loja; nao usa sessao nem CSRF.
- `Services/WhatsApp/EventoWaha` converte o evento para o formato interno
  (`onmessage`, `from` em `55...@c.us` ou `...@lid`, `fromMe`, `t`). Contato com LID usa o numero
  real quando o WAHA informa.
- Mensagem do teste automatico (codigo `DZT-...`, enviada pelo WhatsApp da plataforma) e reconhecida
  antes da saudacao e nunca recebe boas-vindas.
- `WAHA_WEBHOOK_TOKEN` fica so no `shared/.env` da VPS e precisa existir **antes** de a loja
  conectar (o endereco fica gravado na sessao). Trocar o valor exige "Corrigir recebimento" ou reconectar.

**Atencao:** sem `WAHA_WEBHOOK_TOKEN`, o painel nao deixa ativar a saudacao. Os avisos de pedido
nao dependem do webhook.

## Diagnostico seguro de eventos recebidos

A resposta HTTP 200 **nao confirma** que um evento foi elegivel: o webhook responde 200
tambem para eventos ignorados, sem registrar saudacao.

Para investigar uma loja que recebe mensagens, mas nao agenda saudacoes, ativar
**temporariamente** no `shared/.env`:

```env
WHATSAPP_SAUDACAO_DIAGNOSTICO=1
```

Enviar **uma** mensagem de outro numero durante o horario e examinar o log de
erros PHP do site. Uma linha com
`[Cardapim] Saudacao WhatsApp ignorada: <motivo>` revela a etapa da recusa.
Esses logs nao incluem numero, token, sessao ou conteudo da mensagem.
Possiveis motivos: `lid_formato_invalido`, `data_invalida_ausente_ou_antiga`,
`sessao_sem_restaurante_ativo`, `fora_do_horario`, `automacao_desligada`,
`ja_registrada_hoje`. Se nao aparecer motivo mesmo com webhook POST 200,
verificar evento, rota, logs do PHP e limites de corpo.

A rotina aceita tanto `t` quanto `timestamp` em segundos Unix, e rejeita
mensagens com horario antigo/futuro. LID (`@lid`) continua sendo rejeitado
**quando nao houver numero de telefone confirmado**: nunca tratar o numero LID
como se fosse um telefone para disparos. LID pode ser respondido diretamente ao chat original apos a migration do schema.
Nao descobrir ou inventar um numero de telefone.

Desativar e retirar `WHATSAPP_SAUDACAO_DIAGNOSTICO` apos concluir os testes.
Nao aumentar o nivel de log global nem imprimir payloads de clientes.

## Tabelas e deploy

- Migration aditiva `20261118190000_create_whatsapp_saudacoes.php`.
- Configuracao `restaurante_whatsapp_saudacao`.
- Historico diario `whatsapp_saudacao_diaria`.
- `database/verify-saudacoes.php --verify` verifica tabela e chave unica.
- Aplicar antes do codigo PHP, com backup, por `deploy/scripts/apply-saudacoes.sh`.
  O workflow so executa com `[cardapim-saudacoes]` no commit de merge.
- O codigo e publicado depois, em outro merge (o deploy normal nao aplica migrations automaticamente).

## Compatibilidade de schema com LID

O WhatsApp pode identificar remetentes de conversas privadas com `@lid`. O
WAHA aceita o identificador `NNNN@lid` como destinatario em `sendText`.
O campo legado `telefone` de `whatsapp_saudacao_diaria` precisa comportar o
identificador completo do destinatario, sem alterar a chave UNIQUE diaria.
A migration versionada
`20261118220000_expand_whatsapp_saudacao_lid.php` amplia o campo de
`VARCHAR(15)` para `VARCHAR(48)`, preservando o historico.

O deploy da migration ocorre separadamente, **antes** da mudanca no PHP que
passara a aceitar LID. O commit de merge da migration deve conter
`[cardapim-lid-schema]`, que aciona
`deploy/scripts/apply-saudacoes-lid.sh`: cria backup do banco, aplica apenas
a migration esperada e verifica o schema. Nunca alterar a migration anterior
nem apagar a tabela de saudacoes.

Antes da migration, todos os eventos LID continuam sendo rejeitados pelo PHP,
evitando gravacoes truncadas. O sistema de avisos de pedidos e inalterado.

## Envio e privacidade

O webhook confirma imediatamente e agenda envio apos a resposta HTTP.
O envio utiliza o token cifrado e a sessao da propria loja. Cada registro passa por
`pendente -> enviando -> enviado/falhou`.
Ate 3 tentativas (espera de 1 e 3 minutos, novas tentativas pelo vigia), dentro de 10 minutos da
mensagem do cliente. Numero sem WhatsApp nao repete; envio interrompido no meio nao repete (para o
cliente nao receber duas vezes). As ultimas boas-vindas aparecem na tela do WhatsApp, sem dados pessoais.

Para limpar dados de mais de 35 dias, agendar o comando `php8.4 bin/limpar-saudacoes.php`
no cron do usuario do site, uma vez ao dia. O comando nao envia mensagens.
