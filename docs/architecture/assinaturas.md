# Planos, cadastro e assinaturas

## Planos (`/painel/planos`, so adm_master)

- **Publico** (`visibilidade = 1`): aparece na pagina inicial, no cadastro e na troca de plano do gerente.
- **Privado** (`visibilidade = 0`): so pelo **link de convite** `/cadastro?convite=<codigo_convite>` (24 hex). "Trocar link"
  gera outro codigo e o antigo deixa de valer.
- **Vitalicio** (`periodo = 'vitalicio'`): sempre gratis (`CHECK valor = 0`), nunca gera cobranca. Ex.: loja parceira.
- **Pausado** (`ativo = 0`): ninguem novo assina (nem pelo convite); quem ja assinou continua.
- Mudar o preco nao muda quem ja assinou (a assinatura copia valor e periodo). Plano ja assinado nao pode ser excluido.
- Codigo: `PlanoService`, `PlanoRepository`, `Plataforma/PlanosController`, views `plataforma/planos/*`, `planos.js`.

## Cadastro (`GET/POST /cadastro`)

`CadastroRestaurante::cadastrar` valida tudo no servidor (CPF, WhatsApp, CNPJ, usuario, e-mail, senha, termos, plano) e,
em uma transacao, cria o restaurante (slug unico a partir do nome), o gerente, as 5 mesas e a assinatura. Depois entra
na conta (`Autenticacao::entrarAposCadastro`). Limite: 10 tentativas por IP por hora. Plano pago so com o Asaas da
plataforma conectado.

| Plano | Restaurante | Assinatura | Cobranca |
| --- | --- | --- | --- |
| Gratis (convite/vitalicio) | `ativo` | `ativa`, `vence_em` NULL | nenhuma |
| Pago com dias gratis (`teste_dias`) | `ativo` | `ativa` ate o 1o vencimento | 1a fatura no fim do teste |
| Pago sem teste | `pendente` (monta tudo, cardapio fora do ar) | `aguardando` | 1a fatura em `vencimento_dias` |

## Cobranca no Asaas da plataforma (`AssinaturaCobranca`)

- `gerar`: cliente (`POST /customers`, CNPJ do restaurante, e-mail, celular; `notificationDisabled` conforme "Asaas envia a
  fatura") + assinatura (`POST /subscriptions`: `billingType`, `value`, `nextDueDate`, `cycle`, multa/juros/desconto das
  regras, `externalReference = assinatura:{id}`) e traz as faturas (`GET /subscriptions/{id}/payments`). Falha fica em
  `assinaturas.cobranca_erro` e o gerente ve "Gerar cobranca".
- **Aviso (webhook) da plataforma**: `PAYMENT_*` com `payment.subscription` (ou `externalReference`) grava a fatura em
  `assinatura_faturas` sem duplicar (`UNIQUE ambiente+asaas_id`); paga = estende `vence_em` (vencimento + periodo),
  `ultimo_pagamento` e libera o restaurante (`pendente`/`inadimplente` -> `ativo`). Fatura paga nunca volta a pendente.
- **Atraso**: fatura em aberto com vencimento + `tolerancia_dias` no passado -> restaurante `inadimplente`. Conferido ao
  abrir qualquer tela do restaurante, no cardapio publico e pela rotina `bin/cobranca-diaria.php` (cron diario opcional:
  `php8.4 /home/<usuario>/apps/cardapim/current/bin/cobranca-diaria.php`).
- **inadimplente**: o gerente entra, mas so na Assinatura (as outras abas redirecionam); caixa, garcom e entregador
  veem "acesso pausado"; o cardapio sai do ar. `suspenso`/`inativo` continuam sendo decisao da administracao.
- Troca de plano pago -> pago: `PUT /subscriptions/{id}` (novo valor/ciclo nas proximas faturas). Para gratis: remove a
  assinatura no Asaas. Cancelar: `DELETE /subscriptions/{id}`, usa ate `vence_em`; depois o restaurante fica
  `inadimplente` ate escolher um plano. Desfazer: nova assinatura a partir de `vence_em`.

## Tabelas (migration `20261112100000_planos_assinaturas_cobranca.php`)

- `planos`: `periodo` + `vitalicio`, `codigo_convite CHAR(24) UNIQUE`, `CHECK (periodo <> 'vitalicio' OR valor = 0)`.
- `restaurantes.status_rest` + `inadimplente`.
- `assinaturas`: `status` + `aguardando`, `vence_em` NULL, `ambiente`, `asaas_cliente_id`, `asaas_assinatura_id`, `cobranca_erro`.
- `assinatura_faturas`: `id_restaurante`, `id_assinatura`, `ambiente`, `asaas_id`, `valor DECIMAL(10,2)`, `vencimento`,
  `status` (pendente, paga, vencida, cancelada, estornada), `forma`, `link_fatura`, `link_boleto`, `pago_em`.

## Marca da plataforma (`/painel/configuracoes`)

`MarcaService` grava `nome_sistema`, `descricao_padrao`, `texto_rodape` e as imagens (`logo_preta`, `logo_branca`,
`logo_colorida`, `icone`, `icone_app`) na tabela `configuracoes`. Uploads (`MarcaUpload`): SVG conferido (sem script,
eventos, links externos, entidades) ou PNG/JPG/WebP regravado com GD; icone do app quadrado >= 512 gera 512, 192,
maskable e apple (180). Arquivos em `storage/uploads/marca`, servidos por `/midia/marca/...` com CSP `sandbox`.

## Testes

`tests/http/planos-cadastro.sh` (planos, convite, marca, cadastro gratis e pago, aviso de pagamento, atraso, cancelar) e
`tests/unit/assinaturas.php`.
