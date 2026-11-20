# SMS pelo celular Android (SMSGate)

Servidor próprio de SMS da Escotilha Produtora, pronto para o Deliveryzim e para outros projetos.
O SMS sai pelo chip de um celular Android com o app **SMSGate** (SMS Gateway for Android, open source,
Apache 2.0) conectado ao servidor privado na VPS de mensageria.

```
Deliveryzim ─▶ WhatsApp da plataforma (Evolution) ─ falhou? ─▶ SMSGate (VPS) ─▶ celular Android ─▶ SMS
```

| Item | Valor |
|---|---|
| Endereço | `https://sms.escotilhaprodutora.com` (HTTPS pelo Caddy da Evolution) |
| Servidor | VPS de mensageria (189.24.3.89), pasta `/opt/smsgate`: `backend`, `worker` (Docker, imagem `ghcr.io/android-sms-gateway/server`) e `db` (MariaDB) |
| Modo | privado: só registra celular com o token privado (`PRIVATE_TOKEN` em `/opt/smsgate/.env`, permissão 600) |
| Retenção | mensagens apagadas do banco depois de 72 h (`TASKS__MESSAGES_CLEANUP__MAX_AGE`) |
| Backup | `/opt/smsgate/backup.sh` todo dia às 4h10, 7 dias em `/opt/backups/smsgate` |
| Celular | Samsung S22 (Android 16), app SMSGate instalado pelo APK oficial (`app-release.apk`) |

## Uso no Deliveryzim

- Só o **código de login do cliente final** usa SMS, e só como reserva: primeiro tenta o WhatsApp da
  plataforma; se ele estiver desconectado ou o envio falhar, o mesmo código vai por SMS na hora
  (`Services/Cliente/CodigoVerificacao`, canais `WhatsAppPlataforma` e `SmsPlataforma`).
- Cliente HTTP: `Modules/Integracoes/SmsGateApi` → `POST {SMSGATE_URL}/messages` com autenticação básica,
  `priority: 100` (ignora limites e horário do app) e `ttl` de 10 minutos (o celular descarta o SMS atrasado).
- **Configuração no painel da plataforma**: `/painel/integracoes/sms` (adm_master) — endereço, usuário e senha do app,
  mensagem de teste (10 por hora) e apagar. Fica cifrada na tabela `configuracoes` (chave `sms_gateway`,
  `SmsGatewayRepository`); a senha nunca volta para a tela. Tudo com log de auditoria.
- Sem configuração no painel, vale o `shared/.env` do Deliveryzim:

```env
SMSGATE_URL=https://sms.escotilhaprodutora.com/api/3rdparty/v1
SMSGATE_USUARIO=<usuário do app: Settings > Cloud Server>
SMSGATE_SENHA=<senha do app>
```

Sem nenhuma das duas, o login continua só pelo WhatsApp (comportamento anterior).

## Outros projetos

- Usar a mesma API: `POST https://sms.escotilhaprodutora.com/api/3rdparty/v1/messages`
  com `{"textMessage":{"text":"..."},"phoneNumbers":["+55..."]}`.
- Credenciais só no servidor de cada projeto (nunca no navegador, app público ou Git).
- O servidor tem tokens de acesso (JWT) ligados, para dar a cada projeto uma credencial própria.

## Celular

- Chip com plano de SMS, ligado no carregador e no Wi-Fi. De preferência um chip só para isso.
- App: Settings → Cloud Server → API URL `https://sms.escotilhaprodutora.com/api/mobile/v1` + Private Token;
  Home → Cloud server ligado → Start Service.
- Samsung: Bateria → Limites de uso em segundo plano → **Aplicativos que nunca serão suspensos** → SMSGate.
- Android 16: a permissão de SMS é "configuração restrita" para app fora da Play Store. Liberar em
  Aplicativos → SMSGate → ⋮ → Permitir configurações restritas (com o Bloqueador automático desligado
  durante a liberação).

## Problemas comuns

| Sintoma | Verificar |
|---|---|
| Código não chega por SMS | App com "Stop Service" (serviço ligado), celular com sinal e crédito; `docker compose logs --tail 50 backend` em `/opt/smsgate`. |
| "usuário ou senha do SMSGate incorretos" | `SMSGATE_USUARIO`/`SMSGATE_SENHA` diferentes do app. Trocar de servidor no app gera credenciais novas. |
| Servidor fora do ar | `curl https://sms.escotilhaprodutora.com/health` e `cd /opt/smsgate && docker compose up -d`. |
| Atualizar o servidor | `docker compose pull && docker compose up -d` em `/opt/smsgate`, depois do backup. |
