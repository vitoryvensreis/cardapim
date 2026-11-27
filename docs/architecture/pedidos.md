# Pedidos, entrega e pagamento (cardapio online)

Cliente final finaliza o pedido no cardapio: escolhe entrega ou retirada, informa o endereco,
escolhe a forma de pagamento e acompanha o pedido. O restaurante configura tudo na aba
**Entrega e pagamento** do painel do gerente.

> A equipe recebe, prepara e despacha os pedidos no modo caixa (`docs/architecture/caixa.md`).
> Os pedidos baixam o estoque quando o cliente pede e geram o evento `pedido.novo` no outbox do tempo real.

## Tabelas

| Tabela | Para que serve |
| --- | --- |
| `pedidos` | Um por pedido. **`id_restaurante` obrigatorio**; `id_cliente` (cliente final; NULL no balcao sem cadastro) e `id_user` (pessoa da equipe que registrou; NULL quando o proprio cliente pediu). `numero` sequencial por restaurante. Valores em `DECIMAL` com CHECK `total = subtotal + taxa_entrega - desconto`. Nome, WhatsApp e endereco copiados. `consumo_estoque` (o que foi baixado, para devolver no cancelamento). `chave_idempotencia` (clique duplo nao cria dois pedidos). Dados do Pix. |
| `pedido_itens` | Itens com nome e preco copiados do produto. FKs compostas `(id_pedido, id_restaurante)` e `(id_produto, id_restaurante)`. |
| `pedido_eventos` | Linha do tempo: cada status, quem fez (`id_user` ou `id_cliente`) e quando. |
| `restaurante_entrega_pagamento` | Uma linha por restaurante: entrega/retirada, tempos, formas aceitas. **Sem linha = pedidos online desligados.** |
| `restaurante_areas_entrega` | Areas por **bairro** (nome + cidade) ou por **raio** (ate X km), com taxa e pedido minimo. |
| `asaas_clientes` | Id do pagador na conta Asaas de cada restaurante (reaproveitado nas proximas cobrancas). |

Migrations: `20261110120000_create_entrega_pagamento.php`, `20261110120100_create_pedidos.php`.
Verificacao: `php database/verify-pedidos.php --verify`.

## Formas de pagamento

- **Pix pelo app** (`pix_online`): so com o Asaas do restaurante conectado. Cobranca Pix na conta do
  restaurante (`externalReference = pedido:{id}`), codigo copia e cola na tela do cliente, prazo de 60 min.
  CPF obrigatorio (exigencia do Asaas), guardado no cadastro do cliente. Pedido fica `aguardando_pagamento`
  e vira `recebido` pelo webhook do Asaas (`PAYMENT_RECEIVED`/`PAYMENT_CONFIRMED`) ou pela consulta que a tela
  do cliente faz enquanto espera. Pix vencido: o processo agendado consulta a situação no Asaas;
  quando ainda pendente, remove a cobrança no Asaas e só então cancela o pedido e devolve o estoque.
  Se já estiver pago, confirma o pedido. Se o Asaas falhar, não cancela às cegas e tenta na próxima execução.
- **Na entrega/retirada**: Pix, cartao de debito, cartao de credito, dinheiro (com "troco para").
  Pedido ja nasce `recebido`.

### Taxa por forma de pagamento (opcional)

- Gerencia > Entrega e pagamento > "Cobrar taxa por forma de pagamento": para Pix (pelo app, com comprovante e na
  entrega), debito e credito, **porcentagem OU valor fixo** (0,01% a 20% ou R$ 0,01 a R$ 50,00). Dinheiro nunca tem taxa.
  Tabela `restaurante_taxas_pagamento` (sem linha = sem taxa); o formulario so mexe nas taxas quando manda `taxas_enviado=1`.
- Calculo em `Services/Pedidos/TaxaPagamento`: base = itens + entrega - desconto; porcentagem arredondada meio para cima,
  em centavos. Cupom e fidelidade nunca descontam sobre a taxa; o pedido minimo continua olhando so os itens.
- Checkout: "+ 3,5%" ao lado da forma e a linha "Taxa do credito (3,5%)" no resumo (`checkout.js` faz a mesma conta so
  para mostrar). O corpo leva `taxa_pagamento_centavos`; se a regra mudou, `CriarPedido` devolve 409 (`campo =
  forma_pagamento`) e a tela recarrega. A taxa e conferida de novo sob a trava do restaurante.
- Pedido: `pedidos.taxa_pagamento` e `taxa_pagamento_rotulo` (foto da regra). `total = subtotal + taxa_entrega +
  taxa_servico + taxa_pagamento - desconto` (CHECK `chk_pedidos_valores`). Aparece no acompanhamento, no caixa, no cupom
  impresso (`resumoPedido`), na cobranca do Pix pelo app (o total ja inclui) e na NFC-e em `vOutro` (validar com o contador).
- Migration `20261203100000_taxas_pagamento`, `database/verify-taxas-pagamento.php`, `deploy/scripts/apply-taxas-pagamento.sh`
  (gatilho `[cardapim-taxas-pagamento]`; `Esquema::tem('taxas_pagamento')`). Testes: `tests/unit/taxa-pagamento.php` e
  o bloco "Taxa por forma de pagamento" de `tests/http/pedidos.sh`.

### A mesma taxa em todo fechamento de conta (caixa, mesa, balcao, garcom, entrega do caixa)

- **Sem interruptor**: taxa cadastrada vale em toda conta. Mapa da forma do caixa para a regra
  (`TaxaPagamento::FORMAS_CONTA`): debito -> debito, credito -> credito, pix (so registra) -> pix_entrega,
  pix_online (QR do garcom) -> pix_online. Dinheiro nunca tem taxa.
- **Taxa por pagamento**: cada pagamento guarda `pedido_pagamentos.taxa` e `taxa_rotulo`; o `valor` ja inclui a taxa
  (bate com a maquininha). O pedido soma as taxas em `taxa_pagamento` (rotulo "Taxas de pagamento" quando ha formas
  diferentes) e o total e refeito no mesmo UPDATE (`ComandaService::atualizarTaxaDaConta`), respeitando o CHECK.
  Como total e pago sobem juntos, o "falta pagar" continua igual a conta sem taxa. Remover o pagamento tira a taxa.
- **Taxa vista**: a tela manda `taxa_vista` (o que mostrou); o servidor confere (`ComandaService::conferirTaxa`). Sem
  JavaScript ou com regra mudada, nada e gravado e a mensagem diz quanto cobrar; o segundo toque ja leva a taxa certa.
- Pix do garcom (`comanda_pix.taxa`): o QR cobra o que falta + taxa do Pix pelo app; ao confirmar, o pagamento entra
  com a taxa. Entrega feita no caixa: taxa da forma no total do pedido (paga agora ou na entrega); o calculo da aba
  Entrega devolve `taxas_pagamento` por forma. Concluir a entrega no caixa grava a taxa do pedido no pagamento.
- Cupom da comanda: linha da taxa antes do TOTAL e "Cartão e Pix podem ter taxa na hora de pagar." enquanto falta pagar.
- Migration `20261204100000_taxas_pagamento_contas`, `database/verify-taxas-contas.php`,
  `deploy/scripts/apply-taxas-contas.sh` (gatilho `[cardapim-taxas-contas]`; `Esquema::tem('taxas_contas')` — antes
  da migration o codigo funciona sem taxa nas contas). Testes: `tests/unit/taxa-pagamento.php` e o bloco "Taxa por
  forma de pagamento nas contas" de `tests/http/caixa.sh`.

### Pix pela chave do restaurante (com comprovante), um Pix por vez

- Gerencia > Entrega e pagamento > "Pix pela chave (com comprovante)": tipo da chave (CPF, CNPJ, celular, e-mail,
  aleatoria), chave, nome de quem recebe (ate 25 letras, sem acento), cidade (ate 15) e WhatsApp do comprovante
  (vazio = telefone do Perfil). "Ver como fica" (`POST .../entrega-pagamento/pix-teste`) devolve um codigo de R$ 1,00
  para o dono ler no app do banco; nada e gravado. Aviso fixo: o Cardapim nao confirma pagamentos feitos pela chave.
- **Um Pix por vez**: Asaas (`pag_pix_online`) e chave (`pag_pix_comprovante`) nunca juntos (servico + CHECK
  `chk_pix_um_por_vez`). Qualquer Pix com QR Code ligado desliga o Pix na entrega.
- `Shared/PixCopiaECola`: BR Code estatico do Banco Central (campos EMV 00, 26, 52, 53, 54, 58, 59, 60, 62/05 e CRC16
  no 63), sem biblioteca nem chamada externa. Valida e padroniza cada tipo de chave.
- Cliente (`pix_comprovante`): o pedido nasce `aguardando_pagamento` com o copia e cola em `pix_copia_cola` e prazo
  de 60 min (`PixChavePedido`). A tela mostra o QR, "Copiar codigo Pix" e "Ja paguei: enviar comprovante", que chama
  `POST /api/v1/pedidos/{id}/comprovante` (so o dono, CSRF, limite de uso, repetir nao duplica) e abre o WhatsApp do
  restaurante com a mensagem pronta. O pedido vai para `recebido` com o pagamento `pendente` e `comprovante_enviado_em`.
  Sem comprovante no prazo: cancelado e estoque devolvido (`bin/expirar-pix.php` e a propria tela).
- Caixa (Delivery): etiqueta "Conferir Pix" na fila; no pedido, "Pix: conferir comprovante no WhatsApp", a acao
  principal **"Confirmei o Pix, aceitar"** (`POST /{slug}/caixa/delivery/{id}/confirmar-pix`: pago + em preparo +
  vias de producao + auditoria), "Abrir a conversa com o cliente" e "Recusar" (cancela com motivo e devolve o estoque).
  Aceitar, pronto e concluir ficam bloqueados ate conferir. Ao concluir entra no caixa como Pix.
- Taxa: regra `pix_comprovante` (opcional). NFC-e: `tPag` 20 como Pix (validar com o contador/NeXT).
- Migration `20261205100000_pix_chave`, `database/verify-pix-chave.php`, `deploy/scripts/apply-pix-chave.sh`
  (gatilho `[cardapim-pix-chave]`; `Esquema::tem('pix_chave')`). Testes: `tests/unit/pix-copia-e-cola.php` (inclui o
  exemplo oficial do BR Code) e os blocos "Pix pela chave" de `tests/http/pedidos.sh` e `tests/http/garcom.sh`.

## Areas de entrega e geolocalizacao (sem custo)

Regra (a mesma no cardapio, no servidor e no "Testar um endereco" do gerente):
1. Bairro cadastrado (mesmo nome e cidade, sem acento/maiuscula) tem prioridade.
2. Senao, vale a **menor** faixa de km que alcanca o endereco.
3. Nenhuma = nao entrega (mensagem diz o motivo e oferece retirada, se houver).

Distancia em linha reta (Haversine) entre o restaurante e o cliente.

| Dado | Fonte gratuita |
| --- | --- |
| CEP -> rua, bairro, cidade | BrasilAPI v2 (as vezes com coordenadas), ViaCEP como reserva |
| Endereco -> coordenadas | Nominatim (OpenStreetMap), so quando ha faixa de km e o bairro nao esta na lista |
| Celular do cliente | "Estou neste endereco: usar minha localizacao" (GPS do navegador). Se discordar do endereco digitado por mais de 1,5 km, vale o endereco |
| Local do restaurante | GPS do aparelho do gerente (dentro do restaurante) ou busca pelo endereco do Perfil |

Politica do Nominatim publico (https://operations.osmfoundation.org/policies/nominatim/): no maximo
1 consulta por segundo para o site inteiro, User-Agent identificando o Cardapim, sem uso pesado e com cache.
O Cardapim respeita: trava global de 1 s, cache em arquivo de 30 dias (`storage/cache/geo`), `GEO_CONTATO`
(e-mail) e `GEO_NOMINATIM=0` para desligar. Com volume grande: Nominatim proprio (`GEO_NOMINATIM_URL`) ou
provedor pago. Bairros nao dependem de nenhuma API.

## Rotas

Painel do gerente (`/{slug}/gerencia/entrega-pagamento`, gerente do restaurante ou adm_master; POST com CSRF; tudo com log):

| Metodo | Rota | Uso |
| --- | --- | --- |
| GET/POST | `/entrega-pagamento` | Ver / salvar modos, tempos e formas de pagamento |
| GET | `/entrega-pagamento/testar?cep=&numero=&bairro=&cidade=` | Testar se entrega num endereco |
| POST | `/entrega-pagamento/areas`, `/areas/{id}`, `/areas/{id}/pausar`, `/areas/{id}/excluir` | Areas de entrega |
| POST | `/entrega-pagamento/localizacao`, `/localizacao/remover` | Local do restaurante |
| POST | `/entrega-pagamento/entregador` | Painel do entregador: mostrar ou esconder os valores das taxas (`entregador_ve_valores=1` mostra) |

Cliente final:

| Metodo | Rota | Uso |
| --- | --- | --- |
| GET | `/{slug}/finalizar` | Tela de finalizacao |
| GET | `/{slug}/pedido/{id}` | Acompanhar (so o dono do pedido) |
| GET | `/api/v1/cep/{cep}` | Endereco pelo CEP |
| GET | `/api/v1/cliente/endereco` | Ultimo endereco do cliente |
| POST | `/api/v1/restaurantes/{slug}/entrega` | Entrega neste endereco? Taxa e minimo |
| POST | `/api/v1/restaurantes/{slug}/pedidos` | Criar pedido (CSRF, login, idempotente) |
| GET | `/api/v1/pedidos/{id}` | Situacao do pedido (so o dono; 404 para os outros) |

## Regras de seguranca e consistencia

- Tudo recalculado no servidor: precos, disponibilidade, taxa, pedido minimo, troco e area de entrega.
- Restaurante fechado (com horario informado) nao recebe pedido.
- Limite: 15 pedidos por hora por cliente e por IP.
- Estoque baixado na criacao do pedido (mesma transacao, insumos travados); faltou insumo -> 409 com os itens,
  e o cliente remove na propria tela. Cancelamento devolve o consumo guardado.
- Produto que ja foi vendido nao e apagado: "Excluir" arquiva (some do cardapio, historico intacto).
- Webhook do Asaas confere o token; o pedido so e confirmado se o valor bater.

## Pendente

- Tela da cozinha (KDS) e area do entregador.
- O agendamento do cron de Pix deve estar ativo na VPS (ver abaixo).
- Avisar o cliente pelo WhatsApp a cada mudanca de status.

## Expiração automática de Pix online (60 minutos)

- Código: `Services/Pedidos/PixPedido::expirar()` (fonte única), `CancelarPedido::cancelar(..., somentePixExpirado: true)`, `bin/expirar-pix.php`.
- Cron do usuário do site: `* * * * * php8.4 /home/<usuario>/apps/cardapim/current/bin/expirar-pix.php >> /home/<usuario>/apps/cardapim/shared/storage/logs/expirar-pix.log 2>&1`.
- Instalador idempotente: `deploy/scripts/install-expirar-pix-cron.sh`, chamado após healthcheck do deploy. Confirmar com `crontab -l` na VPS; se o CloudPanel não permitir, o deploy informa para configurar manualmente.
- A rotina roda sem navegador, usa `flock` para evitar sobreposição e processa até 100 pedidos vencidos por minuto, com cursor salvo em `storage/cache/expirar-pix-cursor.txt` para não deixar um erro antigo bloquear pedidos de outras lojas. Restringe a `forma_pagamento='pix_online'`, `status='aguardando_pagamento'`, `pagamento_status='pendente'`, com `pix_cobranca_id` e `pix_expira_em`.
- Segurança financeira: consulta o Asaas antes de remover o QR Code. `CONFIRMED`/`RECEIVED` são confirmados, `PENDING`/`OVERDUE` são excluídos pela API antes do cancelamento local; erro de rede/HTTP bloqueia o cancelamento, que será tentado novamente. O UPDATE local exige o status ainda pendente e prazo vencido, para não cancelar após um webhook concorrente. Eventos, estoque e avisos usam o cancelamento existente.
- **Escopo:** somente Pix de pedidos no cardápio; não cancela comandas da mesa, pedidos do iFood nem faturas de assinatura da plataforma.
- **Limite:** cron a cada minuto significa atraso normal de até cerca de 1 minuto após o vencimento. Monitorar erros no log e reprocessar falhas; nunca transformar falha do Asaas em cancelamento silencioso.
