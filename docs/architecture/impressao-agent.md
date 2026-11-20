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
