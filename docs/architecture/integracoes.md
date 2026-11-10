# Integrações (iFood, WhatsApp, Asaas)

Aba `/{slug}/gerencia/integracoes` (só gerente do restaurante ou adm_master). Cada restaurante conecta as **próprias** contas.

## Estrutura

- Tabelas: `integracoes` (uma linha por restaurante e provedor) e `integracao_eventos` (avisos recebidos por webhook).
  Migration `20261110080000_create_integracoes.php`, verificação `database/verify-integracoes.php --verify`.
- `credenciais`: JSON **cifrado** (AES-256-GCM, `Shared/Seguranca/Cripto`, chave fora do banco). `dados`: só o que pode aparecer na tela.
- Clientes das APIs: `Modules/Integracoes/{AsaasApi, WppConnectApi, IfoodApi}` sobre `Shared/Http/ClienteHttp` (cURL, tempo limite curto, sem seguir redirecionamentos).
- Regras: `Services/Gerencia/Integracoes/{AsaasService, WhatsAppService, IfoodService}`. Controller: `Gerencia/IntegracoesController`.
- Configuração do servidor: `Core/Config` (variável de ambiente ou `shared/.env`).
- Toda conexão, desconexão, teste e mensagem de teste gera log em `log_user` (sem chaves, tokens ou conteúdo sensível).

## Integrações da plataforma (administração)

As mesmas tabelas e serviços também guardam as contas da **própria plataforma** (`id_restaurante` NULL),
usadas pelo adm_master em `/painel/integracoes` ([painel da plataforma](painel-plataforma.md)):

- **Asaas da plataforma**: recebe o pagamento das assinaturas dos restaurantes. O webhook usa a mesma rota
  `/webhooks/asaas/{id}`; avisos da plataforma ficam em `integracao_eventos` com `id_restaurante` NULL.
- **WhatsApp da plataforma**: sessão própria no mesmo servidor WPPConnect (`cardapim_plataforma_*`), separada das sessões
  dos restaurantes (`cardapim_r{id}_*`). Será usado para enviar os códigos de verificação do cliente final.
- Migration `20261110100000_integracoes_plataforma.php`: `id_restaurante` aceita NULL e a coluna gerada `escopo`
  (id do restaurante ou 0) garante uma integração por provedor em cada escopo (`UNIQUE(escopo, provedor)`).
- No código: `IntegracaoRepository` e os serviços recebem `?int $idRestaurante` (`null` = plataforma).

## Asaas (Pix dos pedidos online)

1. O gerente escolhe Produção ou Sandbox e cola a **chave de API** da conta dele.
2. O Cardapim confere a chave em `GET /v3/finance/balance` (cabeçalhos `access_token` e `User-Agent`), lê o nome da conta e guarda a chave cifrada (na tela só os 4 últimos caracteres).
3. Cria sozinho o webhook (`POST /v3/webhooks`, `sendType=SEQUENTIALLY`, eventos de cobrança) apontando para
   `APP_URL/webhooks/asaas/{webhook_id}` com um `authToken` aleatório de 48 caracteres. Se falhar, a tela mostra URL e token para configurar à mão.
4. `POST /webhooks/asaas/{id}`: confere o cabeçalho `asaas-access-token` em tempo constante, guarda o aviso em `integracao_eventos`
   (sem repetir o mesmo `id` do evento) e responde 200. Token errado: 401 (com limite de tentativas por IP).
5. Desconectar apaga a chave, remove o webhook no Asaas (quando possível) e invalida o token antigo na hora.

Endereços configuráveis (testes): `ASAAS_URL_PRODUCAO` (padrão `https://api.asaas.com/v3`) e `ASAAS_URL_SANDBOX` (padrão `https://api-sandbox.asaas.com/v3`).
O processamento dos pagamentos (marcar pedido como pago) entra com o módulo de pedidos.

## WhatsApp (WPPConnect próprio)

- Servidor: container Docker isolado, só em `127.0.0.1` ([passo a passo](../deploy/wppconnect-docker.md)).
  `shared/.env`: `WPPCONNECT_BASE_URL=http://127.0.0.1:21467` e `WPPCONNECT_SECRET_KEY=...`. Sem isso, a tela diz que ainda não foi liberado.
- Conectar: cria a sessão `cardapim_r{id}_{aleatório}`, gera o token com a SECRET_KEY (`/api/{sessao}/{secret}/generate-token`, guardado cifrado)
  e chama `start-session`. A tela consulta `GET .../integracoes/whatsapp/estado` (JSON) a cada 3 s e mostra o QR Code (`status-session`).
  Quando o estado vira `CONNECTED`, grava o número (`host-device`) e registra o log.
- Mensagem de teste: número com DDD (vira `55` + DDD + número), até 1000 caracteres, no máximo 10 por hora por restaurante (`send-message`).
- Desconectar: `logout-session` + `close-session` e apaga o token.

## iFood (aplicativo distribuído)

- O Cardapim precisa de um app do tipo **distribuído** no Portal do Desenvolvedor iFood. `shared/.env`: `IFOOD_CLIENT_ID` e `IFOOD_CLIENT_SECRET`
  (opcional `IFOOD_BASE_URL`, padrão `https://merchant-api.ifood.com.br`). Sem isso, a tela diz que ainda não foi liberado.
- Fluxo: `POST /authentication/v1.0/oauth/userCode` gera o **código de vínculo** (10 min) → o gerente autoriza no Portal do Parceiro →
  cola o **código de autorização** (5 min) → `POST /authentication/v1.0/oauth/token` (`grantType=authorization_code` + verificador) →
  `GET /merchant/v1.0/merchants` lista as lojas → o gerente escolhe a loja deste restaurante (se houver só uma, é escolhida sozinha).
- Tokens cifrados; o de acesso é renovado com o refresh token quando vence. "Testar conexão" consulta `GET /merchant/v1.0/merchants/{id}/status`.
- O isolamento entre restaurantes é feito pelo Cardapim: cada restaurante só usa a loja que escolheu.
- Recebimento de pedidos (polling/webhook do iFood) entra com o módulo de pedidos.

## Testes

- `tests/unit/integracoes.php`: clientes das três APIs com respostas simuladas (sem rede).
- `tests/fakes/integracoes-fake.php`: servidor **falso** de iFood, Asaas e WPPConnect usado no CI.
- `tests/http/integracoes.sh`: fluxo completo pelo navegador simulado (chave errada/certa, webhook com token certo/errado/repetido,
  QR Code → conectado → mensagem de teste, código de vínculo → escolher loja → testar, isolamento e CSRF).

## Produção

Tabelas criadas pela execução GitHub Actions **#65** (commit `d88f54b`, gatilho `[cardapim-integracoes]`), com backup
`shared/backups/cardapim-before-integracoes-20261110T132914Z.sql.gz` verificado antes e `verify-integracoes.php` confirmando.
Gatilho removido no commit seguinte. WhatsApp e iFood ficam "aguardando liberação" até as variáveis do `shared/.env` serem preenchidas.
