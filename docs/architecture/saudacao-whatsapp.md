# Saudacao automatica de WhatsApp (primeira mensagem do dia)

Este recurso e **independente dos seis avisos de pedido** e fica em
`/{slug}/gerencia/integracoes/whatsapp#saudacao`.
Cada gerente pode ativar e editar a mensagem; novas lojas comecam com a saudacao **desligada**.

## Regras

- Somente mensagens recebidas (`onmessage` com `fromMe=false`) de numeros brasileiros diretos `55DDDnumero@c.us`.
- Nao responde grupos, broadcasts, status, contatos LID sem numero confirmado, nem mensagens do proprio restaurante.
- Somente se a loja estiver ativa e o horario do Perfil permitir. Usa `HorarioFuncionamento::abertoEm` (inclusive apos meia-noite), horario `America/Fortaleza` da aplicacao.
- Mensagens recebidas fora do horario nao contam; o primeiro contato **elegivel** do dia inicia a saudacao.
- Mensagens antigas (mais de 5 min) ou com relogio adiantado mais de 2 min sao ignoradas, inclusive as reemitidas apos o WPPConnect iniciar.
- UNIQUE `(id_restaurante, telefone, dia_local)`: impede respostas duplicadas por dia e por loja, inclusive chamadas repetidas.
- Texto com variaveis `{{restaurante}}` e `{{cardapio}}`. Link da loja e obrigatorio e inserido quando faltar.
- Guarda apenas a mensagem de saida e o telefone no historico. Limpar registros com mais de 35 dias pelo script de manutencao.

## Webhook da versao WPPConnect 2.10.73

O servidor atual ja envia mensagens dos restaurantes por sessao via `WppConnectApi`; para **receber** mensagens e preciso um webhook do WPPConnect.
No 2.10.73, `callWebHook` usa o `WEBHOOK_URL` global do servidor (ou um URL de sessao autorizado).
Recomenda-se um **unico callback global** para todas as sessoes (incluindo a plataforma, ignorada no backend).
A entrada `POST /webhooks/wppconnect/{token}` e publica, protegida por um token aleatorio longo (>=32 caracteres); nao usa sessao nem CSRF do navegador.

Configurar manualmente na VPS, sem gravar segredos no Git:

1. Gerar um segredo aleatorio forte, por exemplo `openssl rand -hex 32`, e armazenar em
   `/home/escotilhaprodutora-cardapim/apps/cardapim/shared/.env` como `WPPCONNECT_WEBHOOK_TOKEN=... `.
   Mesmo valor deve permanecer estavel entre releases; fazer backup.
2. Na configuracao do container `/opt/wppconnect-cardapim`, apontar
   `WEBHOOK_URL=https://deliveryzim.com.br/webhooks/wppconnect/<mesmo-segredo>`.
   Verificar a versao e o metodo de carregar as variaveis no docker-compose antes de editar; nao mudar
   `SECRET_KEY`, `PORT`, sessoes nem o volume.
3. Recriar **somente** o container Cardapim se necessario para carregar essa variavel.
   Fazer a operacao em janela de manutencao: pode interromper as conexoes do WhatsApp temporariamente.
   Confirmar pelos logs do container que o webhook esta habilitado e que as sessoes voltaram.
4. Fazer teste enviando mensagem de numero proprio para a loja conectada, depois verificar
   `whatsapp_saudacao_diaria` (pendente/enviando/enviado/falhou), sem expor telefone inteiro em logs.
5. Confirmar que avisos de pedidos e o WhatsApp da plataforma continuam funcionando.

**Atenção:** sem `WPPCONNECT_WEBHOOK_TOKEN` no PHP e `WEBHOOK_URL` no container, a saudacao
nao funcionara, mesmo que a opcao esteja ativada no painel. A integracao atual de
pedidos nao depende desse webhook e continua intacta.

## Diagnostico seguro de eventos recebidos

A resposta HTTP 200 **nao confirma** que um evento foi elegivel: o webhook responde 200
tambem para eventos ignorados, sem registrar saudacao. O WPPConnect pode emitir varios
tipos de eventos por sessao.

Para investigar uma loja que recebe mensagens, mas nao agenda saudacoes, ativar
**temporariamente** no `shared/.env`:

```env
WPPCONNECT_SAUDACAO_DIAGNOSTICO=1
```

Enviar **uma** mensagem de outro numero durante o horario e examinar o log de
erros PHP do site. Uma linha com
`[Cardapim] Saudacao WhatsApp ignorada: <motivo>` revela a etapa da recusa.
Esses logs nao incluem numero, token, sessao ou conteudo da mensagem.
Possiveis motivos: `remetente_lid_sem_numero`, `data_invalida_ausente_ou_antiga`,
`sessao_sem_restaurante_ativo`, `fora_do_horario`, `automacao_desligada`,
`ja_registrada_hoje`. Se nao aparecer motivo mesmo com webhook POST 200,
verificar evento, rota, logs do PHP e limites de corpo.

A rotina aceita tanto `t` quanto `timestamp` em segundos Unix, e rejeita
mensagens com horario antigo/futuro. LID (`@lid`) continua sendo rejeitado
**quando nao houver numero de telefone confirmado**: nunca tratar o numero LID
como se fosse um telefone para disparos. Mapear LID para numero real exige
resolucao confiavel via API, antes de responder.

Desativar e retirar `WPPCONNECT_SAUDACAO_DIAGNOSTICO` apos concluir os testes.
Nao aumentar o nivel de log global nem imprimir payloads de clientes.

## Tabelas e deploy

- Migration aditiva `20261118190000_create_whatsapp_saudacoes.php`.
- Configuracao `restaurante_whatsapp_saudacao`.
- Historico diario `whatsapp_saudacao_diaria`.
- `database/verify-saudacoes.php --verify` verifica tabela e chave unica.
- Aplicar antes do codigo PHP, com backup, por `deploy/scripts/apply-saudacoes.sh`.
  O workflow so executa com `[cardapim-saudacoes]` no commit de merge.
- O codigo e publicado depois, em outro merge (o deploy normal nao aplica migrations automaticamente).

## Envio e privacidade

O webhook confirma imediatamente e agenda envio apos a resposta HTTP.
O envio utiliza o token cifrado e a sessao da propria loja. Cada registro passa por
`pendente -> enviando -> enviado/falhou`.
Nao ha repeticao automatica depois de falha **ambigua**: evita duas boas-vindas se o WPPConnect
aceitou o envio, mas a resposta falhou. O restaurante pode consultar status no banco; futuras
versoes poderao oferecer um painel de diagnostico sem exposicao de dados pessoais.

Para limpar dados de mais de 35 dias, agendar o comando `php8.4 bin/limpar-saudacoes.php`
no cron do usuario do site, uma vez ao dia. O comando nao envia mensagens.
