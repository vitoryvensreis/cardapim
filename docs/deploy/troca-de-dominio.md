# Troca de domínio (cardapim.escotilhaprodutora.com -> deliveryzim.com.br)

Objetivo: o sistema passa a responder em `https://deliveryzim.com.br`. Os endereços antigos
(`cardapim.escotilhaprodutora.com`, `deliveryzim.com`, `www.deliveryzim.com`, `www.deliveryzim.com.br`) levam
com 301 para o mesmo caminho no domínio novo. Nada de banco, pastas ou usuário do site muda.

## Como o código lida com a troca

- Todo endereço completo (webhooks, links do WhatsApp, e-mail, tempo real) vem do `APP_URL` do `shared/.env`.
- `DOMINIOS_REDIRECIONAR` (lista separada por vírgula) liga o redirecionamento em `Cardapim\Core\DominioCanonico`:
  - só GET e HEAD (formulário enviado de uma página antiga continua funcionando);
  - `/webhooks/` nunca é redirecionado: o Asaas segue avisando no endereço antigo até cada conta clicar
    "Configurar de novo";
  - lista vazia = nada muda. Teste: `tests/unit/dominio.php`.

## Passo a passo

1. **DNS** (Registro.br para o `.com.br`; registrador do `.com`): registros `A` de `@` e `www` apontando para o IP
   da VPS (o mesmo do CloudPanel). Sem `AAAA` se a VPS não tiver IPv6 configurado no site. Conferir na VPS:
   `dig +short deliveryzim.com.br` e `dig +short www.deliveryzim.com.br`.
2. **CloudPanel > Sites > (site atual) > Vhost**: em TODAS as linhas `server_name cardapim.escotilhaprodutora.com;`
   (bloco 80/443 e bloco interno 8080), trocar por
   `server_name cardapim.escotilhaprodutora.com deliveryzim.com.br www.deliveryzim.com.br deliveryzim.com www.deliveryzim.com;`
   e salvar. O site continua com o nome antigo no CloudPanel (só aparência; o campo não muda).
3. **CloudPanel > SSL/TLS > Actions > New Let's Encrypt Certificate**: certificado com os 5 nomes (manter o antigo).
   O DNS do passo 1 precisa já estar valendo.
4. **Conferir antes de virar**: `https://deliveryzim.com.br/status` abre com cadeado.
5. **Virada** (root, um passo por vez): backup do `shared/.env`, depois
   `APP_URL=https://deliveryzim.com.br` e
   `DOMINIOS_REDIRECIONAR=cardapim.escotilhaprodutora.com,deliveryzim.com,www.deliveryzim.com,www.deliveryzim.com.br`;
   reiniciar o tempo real (`systemctl restart cardapim-tempo-real`, ele confere a origem pelo `APP_URL`).
   O PHP lê o `.env` a cada requisição (não precisa reiniciar o PHP-FPM).
6. **GitHub > Settings > Variables**: `CARDAPIM_DEPLOY_URL=https://deliveryzim.com.br` (o deploy confere a saúde
   nesse endereço; com o antigo ele receberia 301 e voltaria a versão).
7. **Asaas**: em `/painel/integracoes/asaas` (plataforma) e em Gerência > Integrações > Asaas de cada restaurante
   conectado, clicar "Configurar de novo" (o webhook é recriado com o endereço novo).
8. **Avisar a equipe**: entrar de novo no endereço novo (o login é por domínio) e reinstalar o app (PWA).
   Links antigos de acompanhamento enviados por WhatsApp continuam funcionando pelo 301.
9. **E-mail**: sem `MAIL_FROM`, o remetente vira `no-reply@deliveryzim.com.br`; configurar SPF/DKIM do domínio novo
   antes de depender do código de verificação por e-mail.

## Voltar atrás

Restaurar o backup do `shared/.env` (APP_URL antigo e sem `DOMINIOS_REDIRECIONAR`), reiniciar o tempo real e
voltar `CARDAPIM_DEPLOY_URL`. O Vhost e o certificado com os nomes novos podem ficar.
