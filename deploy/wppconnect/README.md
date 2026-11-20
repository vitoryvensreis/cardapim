# Servidor do WhatsApp do Deliveryzim

- `Dockerfile`: imagem `deliveryzim/wppconnect-server:2.10.73-wa-js-4.8.2` (WPPConnect Server 2.10.73 com WA-JS 4.8.2,
  que corrige o envio para contas com identificador LID). Validada pelo workflow `.github/workflows/wppconnect-image.yml`.
- `docker-compose.yml`: cópia idêntica do que roda na VPS em `/opt/wppconnect-deliveryzim`. Mudou aqui, muda lá (e vice-versa).

Instalação, capacidade, conferências e diagnóstico: [docs/deploy/wppconnect-docker.md](../../docs/deploy/wppconnect-docker.md).
O `.env` com `SECRET_KEY` e `WEBHOOK_URL` fica só na VPS (permissão 600) e nunca entra no Git.
