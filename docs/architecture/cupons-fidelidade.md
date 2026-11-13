# Cupons e fidelidade

Descontos dos pedidos do **cardápio online** (cliente identificado pelo WhatsApp). O gerente configura em
`Gerência > Clientes`. Cupom e fidelidade **não se somam**: com cupom válido, vale o cupom e o prêmio da fidelidade
fica guardado para o próximo pedido; sem cupom, o prêmio entra sozinho.

## Cupons (`Gerência > Clientes > Cupons`)

- Código (3 a 20 letras, números ou hífen; o cliente pode digitar em minúsculas ou com espaços), desconto em
  **%** (1 a 100) ou **valor fixo**, pedido mínimo opcional (valor dos itens), validade opcional (vale até o fim do dia).
- Quantas vezes: **várias, sem limite** / **até N vezes** / **só 1 vez no total**, e a opção **cada cliente usa 1 vez**.
- Usos = pedidos com o cupom que não foram cancelados (cancelou, o uso volta). Situação na lista: ativo, pausado,
  vencido ou esgotado. Cupom já usado em pedido não é excluído (fica no histórico): use **Pausar**.
- O desconto vale sobre os itens (nunca sobre a taxa de entrega) e nunca passa do valor deles.

## Fidelidade (`Gerência > Clientes > Fidelidade`)

- Liga/desliga, quantos pedidos para ganhar (1 a 100), quais pedidos contam (**qualquer valor** ou **a partir de R$ X**
  em itens) e o prêmio (**%** ou **valor fixo**).
- Contam os pedidos **concluídos** (entregues ou retirados) do cliente no restaurante, feitos depois do último prêmio
  usado. Prêmio usado = pedido com `desconto_origem = 'fidelidade'` não cancelado; se esse pedido for cancelado, o
  prêmio volta. Pedidos feitos antes de ligar o programa também contam. Desligar não apaga a contagem.
- Na tela de finalizar, o cliente vê "3 de 5 pedidos. Faltam 2 para ganhar 10% de desconto" com bolinhas, ou
  "Você ganhou 10% de desconto!".

## Pedido (`CriarPedido`)

- O navegador manda `cupom` (opcional). O servidor calcula o desconto (`Services/Descontos/DescontoPedido`), grava
  `desconto`, `desconto_origem`, `id_cupom`, `cupom_codigo` e `motivo_desconto`, e confere de novo **dentro da
  transação**, com o restaurante travado (o limite do cupom e o prêmio não estouram com pedidos ao mesmo tempo).
- Erro de cupom: HTTP 422 com `campo: "cupom"`; desconto mudou entre a prévia e o pedido: 409.
- Prévia: `POST /api/v1/restaurantes/{slug}/desconto` `{itens:[{id,quantidade}], cupom?}` com X-CSRF-Token.
  Resposta: `desconto_centavos`, `origem` (cupom | fidelidade | null), `rotulo`, `cupom`, `fidelidade`
  (`compras`, `necessarias`, `faltam`, `ganhou`, `recompensa`, `minimo`, `regra`). 30 tentativas de cupom por IP a cada 10 min.
- Acompanhamento do cliente e caixa (Delivery) mostram a linha do desconto ("Cupom X" / "Desconto fidelidade").

## Banco (migration `20261113120000_create_cupons_fidelidade.php`)

- `cupons`: id_restaurante, codigo (único no restaurante), tipo, valor, pedido_minimo, limite_usos (NULL = sem limite),
  um_por_cliente, valido_ate, ativo. CHECKs de valor, mínimo e limite.
- `restaurante_fidelidade`: uma linha por restaurante (ativo, compras_necessarias, tipo, valor, valor_minimo).
- `pedidos` (+): `desconto_origem`, `id_cupom` (FK composta com o restaurante), `cupom_codigo`.
- Verificação: `php database/verify-cupons.php --verify`.

## Rotas (gerente, CSRF)

| Método | Rota | O que faz |
| --- | --- | --- |
| GET | `/{slug}/gerencia/cupons` | Lista (busca, usos, situação) |
| GET/POST | `/{slug}/gerencia/cupons/novo`, `/{slug}/gerencia/cupons` | Novo cupom |
| GET/POST | `/{slug}/gerencia/cupons/{id}` | Editar |
| POST | `/{slug}/gerencia/cupons/{id}/pausar` | Pausa ou ativa |
| POST | `/{slug}/gerencia/cupons/{id}/excluir` | Exclui (só sem uso) |
| GET/POST | `/{slug}/gerencia/fidelidade` | Configuração do programa |

Testes: `tests/http/cupons.sh`.
