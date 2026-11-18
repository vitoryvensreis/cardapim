# Hotfix para envio de mensagens no WPPConnect do Cardapim

## Motivo

O WPPConnect Server 2.10.73 do Deliveryzim foi instalado com Core 2.3.6 e WA-JS 4.7.2.
Em 18/11/2026, o WA-JS 4.7.2 falhou ao enviar mensagens com
`UserPrefs.getMeLidUserOrThrow is not a function`.
A equipe do WA-JS publicou a correção em 4.8.2 (PR [#3796](https://github.com/wppconnect-team/wa-js/pull/3796)).
A dependência `^4.7.2` do Core 2.3.6 permite instalar WA-JS 4.8.2 sem trocar o Core.

A imagem derivada mantém o servidor e o Core existentes e substitui somente a pasta
`node_modules/@wppconnect/wa-js` com o pacote publicado 4.8.2.
**Não edite `node_modules` de containers rodando.** Não faça `npm install` na VPS de produção.

## Build (sem afetar o container atual)

Após merge e deploy dos arquivos pelo GitHub:

```bash
cd /opt/wppconnect-cardapim
docker build --pull --progress=plain \
  -t cardapim/wppconnect-server:2.10.73-wa-js-4.8.2 \
  -f /home/escotilhaprodutora-cardapim/apps/cardapim/current/deploy/wppconnect-hotfix/Dockerfile \
  /home/escotilhaprodutora-cardapim/apps/cardapim/current/deploy/wppconnect-hotfix
```

Esse comando cria uma nova imagem local sem alterar o container atual e sem necessitar do `.env` ou tokens.

## Pré-requisitos antes da troca

1. CI de build do Docker verde. O build acima precisa terminar com sucesso.
2. Backup validado do `docker-compose.yml`, `.env` e volumes
   `wppconnect-cardapim_tokens` e `wppconnect-cardapim_userdata`.
3. Parar o container apenas para gerar backup consistente dos volumes. A interrupção deve ser planejada.
4. Guardar a imagem anterior `wppconnect/wppconnect-server:2.10.73` e o compose original para rollback.
5. Nunca alterar containers `taskesc-wppconnect` ou `leaguecut-wpp`.

## Troca e rollback

Depois de backups e testes, no `/opt/wppconnect-cardapim/docker-compose.yml`,
a única mudança deve ser:

```diff
-    image: wppconnect/wppconnect-server:2.10.73
+    image: cardapim/wppconnect-server:2.10.73-wa-js-4.8.2
```

Validar `docker compose config --quiet` e recriar **somente** o serviço
com `docker compose up -d --no-deps --force-recreate wppconnect`.
Não usar `down -v`. Verificar porta `127.0.0.1:21467`, sessão de plataforma,
sessão de restaurante e envios de teste únicos. Confirmar também o evento real de webhook.

Rollback: restaurar a linha `image: wppconnect/wppconnect-server:2.10.73`
no compose (ou restaurar o arquivo com backup) e recriar somente o serviço.
Volumes não devem ser apagados ou substituídos em uma reversão comum.
Se houver alteração incompatível nos perfis pela nova biblioteca, interromper o
serviço e restaurar um backup consistente, com avaliação explícita de impacto.

## Limites da validação

O CI comprova que a imagem constrói e contém a versão corrigida, **não** que a entrega
real do WhatsApp funciona. Testar no WhatsApp da plataforma e em um restaurante
após a troca. Nunca enviar testes repetidos após timeout: uma mensagem pode ter sido aceita.
