# Impressão pelo Deliveryzim Agent

Os botões **Imprimir** do caixa e do garçom mandam o cupom para o **Deliveryzim Agent** instalado no computador
da impressora (Windows). O navegador nunca abre a janela de impressão.

## Onde imprime

| Tela | Botão | Cupom |
|------|-------|-------|
| Caixa > comanda (mesa ou balcão) | Imprimir conta | `conta`: itens, serviço, desconto, total, já pago, observação do balcão |
| Caixa > delivery > pedido | Imprimir | `pedido`: cliente, telefone, endereço, itens, observação, pagamento e troco |
| Caixa > fechamentos > caixa | Imprimir | `fechamento`: troco inicial, formas, entradas/saídas, esperado e contado |
| Garçom > comanda | Imprimir > Comanda inteira | `pre_conta`: mesa e nome da comanda no topo, itens e total até agora |
| Garçom > comanda | Imprimir > Só os itens novos | `itens_novos`: só o que foi lançado depois da última impressão da comanda |

"Só os itens novos" usa `impressoes.ate_item` (maior item já impresso, de cupom entregue a um Agent e sem erro).
Imprimir a comanda inteira também conta como impresso. Sem nada novo, o botão fica desligado.

## Várias impressoras (Agent 0.8.0+)

- **No Agent:** lista sem limite, cada impressora com apelido (Balcão, Cozinha, Bar...), impressora do Windows e papel.
  A primeira é a principal. Botões Testar, Remover e "+ Adicionar impressora". O Agent manda ao sistema só a chave e o
  apelido (`POST /api/v1/desktop/impressoras`, lista completa; quem saiu da lista vira `ativo = 0`).
- **Gerência > Integrações > Impressoras:** impressoras cadastradas (computador, ligada/desligada) e, para cada ponto
  (`caixa_conta`, `caixa_delivery`, `caixa_fechamento`, `garcom`, `delivery_novo`), as impressoras marcadas e a padrão.
- **Na hora de imprimir:** ponto sem impressora marcada = impressora principal de qualquer Agent (como antes);
  uma marcada = imprime direto nela; duas ou mais = o caixa escolhe no botão (lista com os apelidos, padrão em destaque)
  e o garçom escolhe em "Imprimir em" (padrão já marcada). Impressora fora do ponto ou com o computador desligado é recusada
  com o motivo.
- **Pedido novo do delivery:** sai em TODAS as impressoras marcadas em `delivery_novo`; cada Agent recebe no evento
  `impressoras` (chaves das impressoras dele; lista vazia = nada a imprimir neste computador; `null` = ponto sem
  configuração, usa a principal).
- **Compatibilidade:** Agent 0.7 (sem lista) só recebe cupons sem impressora escolhida e não aparece na lista da gerência.

Banco: migration `20261120230000_create_impressoras.php` (`impressoras`, `impressao_pontos`, `impressoes.id_impressora`),
`database/verify-impressoras.php`, `deploy/scripts/apply-impressoras.sh` (gatilho `[cardapim-impressoras]`).
Testes: `tests/http/impressoras.sh`, `desktop-agent/tests/test_printer_list.py`.

## Impressão por produto (vias de produção)

Para restaurantes com várias estações (Churrasqueira, Cozinha, Bar, Balcão):

- **Produto:** Estoque > produto > **Sai na impressora**, com um check por apelido de impressora do Agent.
  **Atalho:** Estoque > Categorias > *Impressora de produção por categoria* aplica as impressoras marcadas a todos
  os produtos da categoria (depois dá para ajustar produto por produto).
- **Ponto "Produção"** (Integrações > Impressoras): a impressora padrão recebe os itens sem impressora marcada.
  Com o ponto configurado, as vias de produção ficam ligadas.
- **Garçom:** em Imprimir > **Só os itens novos**, cada item sai na impressora do produto (um cupom por estação, com a
  mesa em letra grande, hora, garçom, complementos e observações, sem o total). "Comanda inteira" continua num cupom só.
- **Garçom ao lançar** (ponto "Garçom: ao lançar itens", migration `20261121300000_impressao_lancamento`): com
  impressora marcada no ponto, tocar em **Lançar** já manda os itens novos, cada item na impressora marcada no produto
  e o produto sem marcação na padrão do ponto (o ponto só liga a função e define essa padrão).
  Estação desligada não desfaz o lançamento: o garçom vê "Não imprimiu" e os itens ficam em "Novos itens" no caixa.
  A opção "Só os itens novos" saiu do Imprimir do garçom (fica só "Comanda inteira").
  Sem impressora no ponto, lançar não imprime (como antes).
- **Caixa:** na comanda, os botões **Novos itens (N)** (em destaque quando há itens) e **Conta** ficam lado a lado.
  "Novos itens" divide por estação (balcão e mesa); "Conta" continua num cupom só.
- **Produto nunca sai onde não está marcado:** vale para todos os cupons com impressora definida: "Imprimir conta",
  "Comanda inteira", o pedido do delivery impresso pelo caixa e o pedido novo do delivery. Os itens cujo produto está
  marcado só em outras impressoras ficam de fora (os totais continuam os da conta). Produto sem nenhuma marcação sai em
  qualquer impressora. Se nenhum item puder sair, o envio é recusado com o nome da impressora.
- **Pedido novo do delivery** (Agent 0.8.0+ com o ponto configurado): o sistema grava um cupom por impressora do ponto,
  no mesmo modelo do caixa e só com os produtos dela (uma vez por pedido, lote `N<id do pedido>`). O evento chega ao
  Agent com `impressoras: []`, então ele só avisa e imprime pela fila. Sem o ponto configurado, o Agent imprime o pedido
  inteiro na impressora principal, como antes.
- **Delivery:** quando o pedido é **aceito** (caixa, Receber do Agent, aceite no iFood ou aceite automático), as vias
  de produção saem por estação, uma vez por pedido. A via do pedido novo sai nas impressoras do ponto "Pedido novo do delivery".
- **Estação desligada:** no garçom/caixa o envio inteiro é recusado com o nome da estação (nada sai pela metade);
  os itens continuam como "novos" para tentar de novo.
- **Item cancelado** não sai na via de produção (nem gera aviso).
- A tela mostra uma situação única do envio dividido ("Impresso em Bar e Cozinha").

Banco: migration `20261121000000_impressao_por_produto.php` (`produto_impressoras`, ponto `producao`, `impressoes.lote`,
tipo `producao`), `database/verify-impressao-produto.php`, `deploy/scripts/apply-impressao-produto.sh`
(gatilho `[cardapim-impressao-produto]`). Testes: `tests/unit/impressao.php`, `tests/http/impressao-produto.sh`.
O Agent 0.8.0 já atende (não precisa atualizar).

## Fluxo

1. O navegador envia `POST` (com CSRF). O servidor monta o cupom com os dados do banco (`CupomImpressao`)
   e grava em `impressoes`. Sem Agent que saiba imprimir ligado (consulta nos últimos 30 s), recusa na hora.
2. O Agent (0.7.0+) consulta `GET /api/v1/desktop/eventos?desde=N&impressoes=1` a cada 2,5 s. O servidor marca
   `desktop_agent_devices.impressao_em` e **reserva** os cupons pendentes para aquele computador (UPDATE condicionado:
   dois computadores nunca imprimem o mesmo cupom). Agent antigo (sem `impressoes=1`) nunca pega cupom.
3. O Agent imprime em ESC/POS (58 ou 80 mm, logo opcional) e informa `POST /api/v1/desktop/impressoes/{id}/resultado`
   com `{"ok": true}` ou `{"ok": false, "erro": "..."}`.
4. O navegador pergunta a situação (`aguardando`, `imprimindo`, `impresso`, `erro`, `expirada`) e mostra no aviso.
   Cupom não reservado em 2 minutos expira (nunca imprime atrasado de surpresa).

O cupom vai em **blocos** (`titulo`, `centro`, `destaque`, `texto`, `linha`, `valor`, `item`); o Agent só ajusta à
largura do papel, então um modelo novo de cupom não exige atualizar o Agent.

## Rotas

| Método | Rota | Quem |
|--------|------|------|
| POST | `/{slug}/caixa/imprimir/comanda/{id}` | caixa, gerente |
| POST | `/{slug}/caixa/imprimir/pedido/{id}` | caixa, gerente |
| POST | `/{slug}/caixa/imprimir/caixa/{id}` | caixa, gerente |
| GET | `/{slug}/caixa/imprimir/{id}` | situação do cupom |
| POST | `/{slug}/garcom/comandas/{id}/imprimir` (`modo=toda` ou `modo=novos`) | garçom, gerente |
| GET | `/{slug}/garcom/impressoes/{id}` | situação do cupom |
| POST | `/api/v1/desktop/impressoes/{id}/resultado` | Agent (token do dispositivo) |
| POST | `/api/v1/desktop/impressoras` | Agent 0.8+: lista de impressoras (chave + apelido) |
| GET/POST | `/{slug}/gerencia/integracoes/impressoras` | gerente: pontos de impressão |

Os botões aceitam o campo `impressora` (id), conferido no servidor contra o ponto.

Com `Accept: application/json` as rotas respondem JSON (202 enviado / 409 recusado com o motivo); sem JavaScript
o formulário volta para a página com o aviso.

## Observação do pedido de balcão

Na comanda do balcão (caixa): **Adicionar observação ao pedido** (até 300 caracteres, `pedidos.observacao`).
Aparece na conta e sai no cupom. Rota `POST /{slug}/caixa/comandas/{id}/observacao`.

## Banco

Migration `20261120200000_create_impressoes.php` (tabela `impressoes` + `desktop_agent_devices.impressao_em`),
`database/verify-impressoes.php` e `deploy/scripts/apply-impressoes.sh` (backup antes; gatilho
`[cardapim-impressoes]` no CI). Antes da migration os botões respondem que a impressão ainda está sendo ativada.

## Testes

`tests/unit/impressao.php` (cupom), `tests/http/impressao.sh` (fluxo completo, isolamento entre restaurantes,
Agent antigo, falha da impressora) e `desktop-agent/tests/test_print_jobs.py`.
