# Integrações (iFood, WhatsApp, Asaas)

Aba `/{slug}/gerencia/integracoes` (só gerente do restaurante ou adm_master). Cada restaurante conecta as **próprias** contas.

## Estrutura

- Tabelas: `integracoes` (uma linha por restaurante e provedor) e `integracao_eventos` (avisos recebidos por webhook).
  Migration `20261110080000_create_integracoes.php`, verificação `database/verify-integracoes.php --verify`.
- `credenciais`: JSON **cifrado** (AES-256-GCM, `Shared/Seguranca/Cripto`, chave fora do banco). `dados`: só o que pode aparecer na tela.
- Clientes das APIs: `Modules/Integracoes/{AsaasApi, EvolutionApi, IfoodApi}` sobre `Shared/Http/ClienteHttp` (cURL, tempo limite curto, sem seguir redirecionamentos).
- Regras: `Services/Gerencia/Integracoes/{AsaasService, WhatsAppService, IfoodService}`. Controller: `Gerencia/IntegracoesController`.
- Configuração do servidor: `Core/Config` (variável de ambiente ou `shared/.env`).
- Toda conexão, desconexão, teste e mensagem de teste gera log em `log_user` (sem chaves, tokens ou conteúdo sensível).

## Integrações da plataforma (administração)

As mesmas tabelas e serviços também guardam as contas da **própria plataforma** (`id_restaurante` NULL),
usadas pelo adm_master em `/painel/integracoes` ([painel da plataforma](painel-plataforma.md)):

- **Asaas da plataforma**: recebe o pagamento das assinaturas dos restaurantes. O webhook usa a mesma rota
  `/webhooks/asaas/{id}`; avisos da plataforma ficam em `integracao_eventos` com `id_restaurante` NULL.
- **WhatsApp da plataforma**: instância própria (`deliveryzim_plataforma_*`), separada das instâncias
  dos restaurantes (`deliveryzim_r{id}_*`). Envia os códigos de verificação do cliente final.
- Migration `20261110100000_integracoes_plataforma.php`: `id_restaurante` aceita NULL e a coluna gerada `escopo`
  (id do restaurante ou 0) garante uma integração por provedor em cada escopo (`UNIQUE(escopo, provedor)`).
- No código: `IntegracaoRepository` e os serviços recebem `?int $idRestaurante` (`null` = plataforma).

### Asaas da plataforma para as assinaturas

- **Avisos (webhook)**: além dos eventos de pagamento, a plataforma assina `SUBSCRIPTION_CREATED`, `SUBSCRIPTION_UPDATED`,
  `SUBSCRIPTION_INACTIVATED` e `SUBSCRIPTION_DELETED` (`AsaasApi::EVENTOS_WEBHOOK_PLATAFORMA`). Contas conectadas antes
  disso precisam de "Configurar de novo" para receber os eventos de assinatura.
- **"Pronto para cobrar?"** (`AsaasService::verificarSaude`, rodado ao conectar, configurar avisos e testar): consulta
  `GET /v3/myAccount/status` (conta aprovada: dados comerciais, conta bancária, documentos), `GET /v3/pix/addressKeys?status=ACTIVE`
  (chave Pix) e `GET /v3/webhooks/{id}` (avisos ativos, pausados após falhas, eventos de assinatura). O resultado fica em
  `integracoes.dados.saude` e aparece como lista ok / aviso / erro. Se o Asaas pausou a fila de avisos, o botão
  "Reativar avisos" envia `PUT /v3/webhooks/{id}` com `enabled: true, interrupted: false`.
- **Regras da cobrança** (`Cardapim\Services\Plataforma\CobrancaAssinaturas`, tabela `plataforma_cobranca`, linha única `id = 1`):
  formas (Pix, Boleto, Cartão; mais de uma vira `billingType: UNDEFINED` e o restaurante escolhe na fatura), dias para a
  1ª cobrança vencer, dias grátis, multa (até 2%), juros ao mês (até 10%), desconto para pagar antes e até quantos dias,
  dias de atraso para suspender o restaurante e se o Asaas envia fatura e lembretes. Valores em `DECIMAL`; cada alteração
  gera auditoria "Cobrança das assinaturas". Helpers para criar a assinatura (`POST /v3/subscriptions`): `CICLOS`
  (mensal `MONTHLY`, semestral `SEMIANNUALLY`, anual `YEARLY`), `tipoAsaas()`, `primeiroVencimento()` e `encargosAsaas()`
  (objetos `fine`, `interest`, `discount`).
- Migration `20261111180000_create_plataforma_cobranca.php`, conferência `database/verify-cobranca.php`.

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

## WhatsApp (Evolution API)

- Servidor: Evolution API (canal Baileys) na VPS de mensageria, por HTTPS ([passo a passo](../deploy/evolution.md)).
  `shared/.env`: `EVOLUTION_BASE_URL`, `EVOLUTION_API_KEY` e `EVOLUTION_WEBHOOK_TOKEN`. Sem isso, a tela diz que ainda não foi liberado.
- Código: contrato `Modules/Integracoes/ProvedorWhatsApp`, implementado por `EvolutionApi`; `WhatsAppProvedores` cria o cliente,
  gera os nomes e reconhece as instâncias do Deliveryzim.
- Conectar: cria a instância `deliveryzim_r{id}_{aleatório}` (`POST /instance/create`, já com webhook, sem grupos e sem histórico),
  guarda o token da instância (cifrado) e pede o QR Code (`/instance/connect`). A tela consulta `GET .../integracoes/whatsapp/estado`
  a cada 3 s; quando o estado vira `open`, grava o número e registra o log.
- Mensagem de teste: número com DDD (vira `55` + DDD + número), até 1000 caracteres, no máximo 10 por hora por restaurante (`/message/sendText`).
- Desconectar: `logout` e `delete` da instância; apaga o token.
- Webhook: `POST /webhooks/evolution/{EVOLUTION_WEBHOOK_TOKEN}` (`EvolutionWebhookController` + `Services/WhatsApp/EventoEvolution`).
- **Avisos automáticos do pedido** para o cliente (recebido, preparo, pronto, saiu com link do mapa, entregue, cancelado): [avisos-whatsapp.md](avisos-whatsapp.md).

## iFood (aplicativo distribuído)

- O Cardapim precisa de um app do tipo **distribuído** no Portal do Desenvolvedor iFood. O Client ID e o Client Secret desse app são
  cadastrados em `Painel > Integrações > Aplicativos nos marketplaces` (ver abaixo); no modo antigo continuam valendo
  `IFOOD_CLIENT_ID` e `IFOOD_CLIENT_SECRET` no `shared/.env` (opcional `IFOOD_BASE_URL`, padrão `https://merchant-api.ifood.com.br`).
  Sem credenciais, a tela diz que ainda não foi liberado.
- Fluxo: `POST /authentication/v1.0/oauth/userCode` gera o **código de vínculo** (10 min) → o gerente autoriza no Portal do Parceiro →
  cola o **código de autorização** (5 min) → `POST /authentication/v1.0/oauth/token` (`grantType=authorization_code` + verificador) →
  `GET /merchant/v1.0/merchants` lista as lojas → o gerente escolhe a loja deste restaurante (se houver só uma, é escolhida sozinha).
- Tokens cifrados; o de acesso é renovado com o refresh token quando vence. "Testar conexão" consulta `GET /merchant/v1.0/merchants/{id}/status`.
- O isolamento entre restaurantes é feito pelo Cardapim: cada restaurante só usa a loja que escolheu.
- Pedidos, entregador e estoque sincronizado: [ifood.md](ifood.md) (rotina `bin/ifood.php` com polling a cada 30 s).

## Testes

- `tests/unit/integracoes.php` (Asaas e iFood) e `tests/unit/whatsapp-evolution.php` (Evolution): clientes com respostas simuladas (sem rede).
- `tests/fakes/integracoes-fake.php`: servidor **falso** de iFood, Asaas e Evolution API usado no CI.
- `tests/http/integracoes.sh`: fluxo completo pelo navegador simulado (chave errada/certa, webhook com token certo/errado/repetido,
  QR Code → conectado → mensagem de teste, código de vínculo → escolher loja → testar, isolamento e CSRF).

## Produção

Tabelas criadas pela execução GitHub Actions **#65** (commit `d88f54b`, gatilho `[cardapim-integracoes]`), com backup
`shared/backups/cardapim-before-integracoes-20261110T132914Z.sql.gz` verificado antes e `verify-integracoes.php` confirmando.
Gatilho removido no commit seguinte. WhatsApp e iFood ficam "aguardando liberação" até as variáveis do `shared/.env` serem preenchidas.

## Aplicativos da plataforma nos marketplaces (iFood, 99Food)

Client ID e Client Secret do **app do Cardapim** em cada marketplace. Não são contas dos restaurantes: cada restaurante só
autoriza esse app no portal dele. Valem para toda a plataforma.

- Tela: `/painel/integracoes/aplicativos/{provedor}` (só `adm_master`, CSRF nos POST). Provedores na lista fechada
  `AplicativosParceiros::PROVEDORES` (`ifood`, `99food`); para um marketplace novo basta incluir na lista.
- Rotas: `GET|POST /painel/integracoes/aplicativos/{provedor}` (ver e salvar) e `POST .../{provedor}/remover` (apagar, com confirmação).
- Armazenamento: tabela `configuracoes`, chave `app_parceiro_<provedor>`, valor JSON **cifrado inteiro** (`Cripto`, AES-256-GCM,
  chave fora do banco). Sem migração. `Configuracoes::get` não lê essas chaves (lista fechada de chaves públicas).
- Leitura (`Modules/Integracoes/AplicativosParceiros`): 1) painel; 2) `shared/.env` (`IFOOD_CLIENT_ID/SECRET`,
  `NOVENOVEFOOD_APP_ID/CLIENT_SECRET`); 3) não configurado. Cache de 60 s, então a rotina `bin/ifood.php` pega uma troca sem reiniciar.
- Segurança: o segredo nunca volta para a tela (só os 4 últimos caracteres), nunca vai para os logs; segredo em branco ao salvar
  mantém o atual. Auditoria registra "Aplicativo X configurado/removido" e se o Client ID ou o segredo mudaram, sem os valores.
- Trocar o Client ID do iFood invalida os vínculos das lojas (os tokens são do app antigo): a tela avisa quantas lojas
  precisarão vincular de novo.
- 99Food: só guarda as credenciais; a integração ainda não existe ([../integrations/99food.md](../integrations/99food.md)).
- Testes: `tests/unit/integracoes.php` (sem banco), `tests/integration/aplicativos-parceiros.php` (MySQL, cifragem e prioridade)
  e `tests/http/painel-adm.sh` (tela, validação, segredo mascarado, logs, permissão).
