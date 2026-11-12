# Modo caixa

Tela `/{slug}/caixa` para o caixa (e o gerente/adm_master): mesas e comandas, venda no balcao, delivery,
abrir e fechar o caixa, entradas e saidas de dinheiro e historico de fechamentos.

## Menu lateral

| Item | Rota | O que faz |
| --- | --- | --- |
| Caixa | `/caixa` | Abrir (troco inicial) ou resumo do turno: vendas, dinheiro na gaveta, ticket medio, por forma, atalhos |
| Mesas e comandas | `/caixa/mesas` | Mapa desenhado (tampo + cadeiras pelo numero de lugares): livre (toque abre a comanda) ou ocupada (total, itens, tempo); ultimo quadro "+ Nova mesa" cria a proxima; vendas de balcao em aberto |
| Delivery | `/caixa/delivery` | Pedidos do cardapio online por etapa; detalhe com aceitar, pronto, despachar, entregue, cancelar |
| Entradas e saidas | `/caixa/movimentos` | Reforco de troco, sangria, despesa, fornecedor, entregador, vale |
| Fechamentos | `/caixa/historico` | Cada caixa: vendas por forma, esperado x contado, entradas/saidas e recebimentos |
| Cadastro de mesas | `/caixa/mesas/gerenciar` | Criar uma ou varias (1 a 15), editar numero/nome/lugares, pausar, excluir |

**Fila de delivery colada no menu**: logo abaixo dos links, do pedido mais antigo para o mais novo, com etapa
(Novo, Em preparo, Pronto, Saiu, Esperando Pix), cliente, bairro, tempo e valor. Atualiza sozinha pelo tempo real
(canal `pedidos`) e a cada 60 s e mostra "N novos" no item Delivery. Pedido novo acende o **aviso de pedido novo**
(luz em volta da tela, tremida leve e campainha), o mesmo da gerencia: ver [tempo-real.md](tempo-real.md#aviso-de-pedido-novo). No celular o menu e uma gaveta que rola inteira.

Gerente: `/gerencia/mesas` leva ao cadastro de mesas e `/entregas` ao Delivery do caixa. "Voltar a gerencia" no menu.

## Regras

- **Caixa**: um aberto por restaurante (coluna gerada + UNIQUE no banco). Vendas so sao recebidas com caixa aberto
  (comandas podem receber itens com o caixa fechado). Dinheiro esperado = troco inicial + recebido em dinheiro
  (ja sem troco) + entradas - saidas. Saida nao pode passar do dinheiro da gaveta.
- **Fechamento cego**: a pessoa conta as notas e moedas e digita o total ANTES de ver o esperado. O sistema grava
  esperado, contado, diferenca (sobrou/faltou), observacao e o resumo por forma (JSON, nao muda depois).
- **Comanda** = pedido `status 'aberto'`, `tipo 'mesa'` (a mesa e o "cliente", `id_mesa`) ou `tipo 'balcao'`.
  Uma comanda aberta por mesa (UNIQUE na coluna gerada `mesa_aberta`). Taxa de servico de 10% ligada por padrao
  na mesa (desligavel), desconto exige motivo, "dividir por N pessoas", trocar de mesa, imprimir a conta (80 mm).
- **Estoque**: cada item lancado na comanda baixa os insumos NA HORA pela ficha tecnica e guarda o consumo no item
  (`pedido_itens.consumo_estoque`). Item cancelado (com motivo) devolve exatamente esse consumo; comanda cancelada
  devolve tudo (o motivo do cancelamento da comanda e opcional; sem motivo grava "Cancelada no caixa"). Faltou insumo: o item nao e lancado e a mensagem diz o que falta.
- **Delivery**: o estoque e baixado quando o CLIENTE faz o pedido (`CriarPedido`). Aceitar, pronto, despachar e
  entregar NAO baixam de novo; cancelar devolve. Testado em `tests/http/caixa.sh` (linhas "ESTOQUE").
- **Despacho**: so motoqueiros ativos do proprio restaurante; grava `id_entregador` e `despachado_em`; o cliente ve
  "Saiu para entrega". Retirada: pronto -> "Cliente retirou".
- **Mesas iniciais**: todo restaurante comeca com 5 mesas (1 a 5, 4 lugares) - migration
  `20261111100000_entregador_posicoes_mesas_padrao.php` para os existentes e `MesaRepository::criarPadrao()` para
  restaurantes novos. O "+" (`POST /caixa/mesas/nova`) cria o maior numero + 1 (pula numeros em uso).
- **Pagamento**: `pedido_pagamentos` (uma conta pode ter varias formas). Dinheiro aceita "cliente entregou" e calcula
  o troco AO VIVO enquanto a pessoa digita (verde "Troco" ou vermelho "Ainda falta", `caixa.js`); depois de receber,
  a comanda mostra um cartao grande "Devolva de troco" (flash `caixa_troco`, some ao recarregar). Conta paga = comanda concluida (`forma_pagamento` unica ou `misto`), mesa livre. Delivery entregue registra
  a forma do pedido no caixa (Pix pelo app entra como `pix_online`, sem passar pela gaveta).
- Tudo com transacao, CSRF, conferencia de cargo + restaurante a cada acao e log em `log_user`
  (caixa aberto/fechado, entrada/saida, mesa criada/editada/excluida, item cancelado, desconto, conta fechada,
  pedido despachado/cancelado). Eventos no outbox: `caixa.*`, `comanda.*`, `mesa.*`, `pedido.atualizado`, `entrega.despachada`.

## Tabelas (migration `20261110140000_create_caixa_mesas.php`)

- `mesas`: numero (unico por restaurante; NULL quando excluida com historico), apelido, lugares, ativo, excluida_em.
- `caixas`: status, aberto_em/por, valor_abertura, fechado_em/por, dinheiro_esperado, dinheiro_contado, diferenca, resumo, observacao.
- `caixa_movimentos`: tipo (entrada/saida), categoria, descricao, valor, quem, quando.
- `pedido_pagamentos`: forma (dinheiro, debito, credito, pix, pix_online), valor, troco, caixa, quem.
- `pedidos` (+): status `aberto`, forma `misto` e opcional na comanda, `id_mesa`, `id_entregador`, `id_caixa`,
  `servico_percentual`, `taxa_servico` (entra no CHECK do total), `motivo_desconto`, `pessoas`, `despachado_em`.
- `pedido_itens` (+): `id_user` (quem lancou), `consumo_estoque`, `cancelado_em`, `motivo_cancelamento`, `id_user_cancelou`.

Verificacao: `php database/verify-caixa.php --verify`.

## Funcoes de caixa pesquisadas

Feitas agora: abertura com fundo de troco, sangria e reforco (suprimento), despesas, fechamento por forma de
pagamento com conferencia cega, historico por operador, mapa de mesas, comanda, transferencia entre mesas, divisao da
conta por pessoas e por formas, taxa de servico, desconto com motivo, cancelamento de item com motivo e devolucao ao
estoque, venda no balcao, fila de delivery com despacho e impressao da conta/pedido.

Para depois: NFC-e (nota fiscal), TEF/maquininha integrada, juntar mesas, couvert, tela da cozinha (KDS),
app do garcom, comprovante de sangria para assinar, permissao do gerente para cancelar/descontar acima de um limite,
relatorios por periodo e por operador, area do entregador com as entregas dele.


## Painel do garçom

O garçom usa as mesmas comandas e regras de pagamento pelo celular em `/{slug}/garcom` (ver [garcom.md](garcom.md)). O que ele lança e recebe aparece aqui na hora (tempo real, canal `mesas`).
