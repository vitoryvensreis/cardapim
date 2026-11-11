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
  restaurante (`externalReference = pedido:{id}`), codigo copia e cola na tela do cliente, prazo de 30 min.
  CPF obrigatorio (exigencia do Asaas), guardado no cadastro do cliente. Pedido fica `aguardando_pagamento`
  e vira `recebido` pelo webhook do Asaas (`PAYMENT_RECEIVED`/`PAYMENT_CONFIRMED`) ou pela consulta que a tela
  do cliente faz enquanto espera. Pix vencido: pedido cancelado e estoque devolvido.
- **Na entrega/retirada**: Pix, cartao de debito, cartao de credito, dinheiro (com "troco para").
  Pedido ja nasce `recebido`.

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
- Expirar Pix nao pago tambem sem a tela do cliente aberta (hoje expira quando o cliente ou o webhook consulta).
- Avisar o cliente pelo WhatsApp a cada mudanca de status.
