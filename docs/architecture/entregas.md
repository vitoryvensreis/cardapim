# Painel do entregador e rastreio ao vivo

Tela `/{slug}/entregas` para o motoqueiro (feita para o celular) e mapa ao vivo na pagina do pedido do cliente,
no estilo dos apps de corrida. Sem API paga: Leaflet (BSD-2, em `public/assets/vendor/leaflet/`) com o mapa proprio do
Cardapim (PMTiles na VPS, ver `docs/architecture/mapa.md`) e o OpenStreetMap publico como reserva.

## Rotas

| Metodo | Rota | Quem | O que faz |
|---|---|---|---|
| GET | `/{slug}/entregas` | motoqueiro | Painel. Gerente e redirecionado para `/{slug}/caixa/delivery`; outros cargos recebem 403 |
| POST | `/{slug}/entregas/{id}/entregue` | motoqueiro | Confirma a entrega (CSRF de formulario) |
| POST | `/{slug}/entregas/{id}/forma` | motoqueiro | "Trocar forma de pagamento" (popup): `escolha = forma|taxa vista`, `troco_para?`, `cpf?`. Pix por QR Code (so com Asaas) troca para `pix_online`, gera a cobranca e redireciona para o QR |
| GET | `/{slug}/entregas/{id}/pix` | motoqueiro | QR Code do Pix da entrega; com `Accept: application/json` devolve `{status: pago|pendente|sem_qr}` (consulta o Asaas, reserva do aviso) |
| POST | `/{slug}/entregas/localizacao` | motoqueiro | JSON `{latitude, longitude, precisao?, direcao?, velocidade?}`, cabecalho `X-CSRF-Token`, ate 2 KB, 40 por minuto |
| POST | `/{slug}/entregas/localizacao/parar` | motoqueiro | Apaga a posicao e avisa em tempo real (`entregador.parou`) |
| GET | `/api/v1/pedidos/{id}/rastreio` | cliente dono do pedido | Posicao do entregador, distancia e minutos estimados (`Cache-Control: no-store`) |

Respostas da localizacao: 200 ok, 400 corpo invalido, 403 cargo, 419 CSRF, 422 coordenada invalida, 429 excesso.
Rastreio: 401 sem login, 404 pedido de outro cliente.

## Painel (`EntregadorController`, `EntregadorService`, `entregador.js`)

- **Com voce agora**: pedidos `saiu_entrega` com `id_entregador` = o motoqueiro. Mostra cliente, endereco,
  bairro e referencia, quanto receber e o troco para levar (ou "Ja pago pelo app"), observacao, itens, rota no
  Google Maps e no Waze, ligar e WhatsApp, e o botao **Entreguei** (com confirmacao).
- **Entreguei**: so a propria entrega e so em `saiu_entrega`. Pedido a receber na entrega exige caixa aberto (o
  dinheiro entra no caixa do turno); com o caixa fechado a mensagem pede para o caixa abrir. Chama
  `DeliveryService::concluir` (mesma regra do caixa). Sem mais entregas em rota, a posicao e apagada.
- **Feitas hoje** e resumo de hoje e 7 dias (quantidade e soma das taxas de entrega).
- **Valores das taxas** (resumo "R$ X em taxas" e a taxa de cada entrega feita): o gerente liga ou desliga em
  Gerencia > Entrega e pagamento > **Painel do entregador** (`restaurante_entrega_pagamento.entregador_ve_valores`,
  padrao ligado). Desligado, o entregador ve so a quantidade de entregas. O valor a receber do cliente aparece sempre.
  Migration `20261120180000_entregador_ve_valores.php` (aplicada com `[cardapim-entregador-valores]`, backup antes;
  `database/verify-entregador-valores.php --verify`). Antes da migration o painel segue mostrando os valores.
- Entrega nova despachada para ele: bipe, vibracao e a lista atualiza sozinha (evento `entrega.despachada`, canal
  `entregas`); tambem atualiza a cada 30 s.

## Localizacao obrigatoria

- O painel liga a localizacao sozinho ao abrir. O celular pede a permissao so na primeira vez e guarda a resposta
  (tambem no app instalado). Nao existe botao "Parar de compartilhar": para quando ele sai da conta.
- Sem permissao (bloqueada, janela fechada ou navegador sem GPS), a lista de entregas fica escondida atras da tela
  "Ative sua localizacao para comecar", com o passo a passo de como liberar no Android e no iPhone e "Tentar de novo".
  Se a permissao mudar com o painel aberto, a tela aparece ou some na hora.
- Sem sinal preciso por mais de 20 s, usa a localizacao aproximada (rede) para nao ficar sem ponto. Ao voltar para o
  app (depois do WhatsApp/Waze ou com a tela desligada), manda a posicao na hora.
- Limite do navegador: com o app fora da tela, o celular pausa a localizacao (so app nativo rastreia em segundo plano).

## Localizacao (LGPD)

- Tabela `entregador_posicoes`: **uma linha por entregador** (PK `id_user`), so a ultima posicao. Nada de historico
  do trajeto. Apagada ao parar de compartilhar, ao sair do sistema ou quando nao ha mais entregas com ele.
- O navegador usa `watchPosition` (alta precisao) e envia no maximo a cada 8 s, ou antes se andou mais de 30 m.
  Mantem a tela acesa (Wake Lock) enquanto compartilha e lembra a escolha no aparelho para retomar sozinho.
- O cliente so ve o entregador enquanto o PEDIDO DELE esta "Saiu para entrega" com esse entregador. Ate 2 min:
  "Localizacao ao vivo"; de 2 a 30 min: "Ultima localizacao ha X min" (moto mais clara); mais que isso, some.
  Ve apenas o primeiro nome, a posicao, distancia e tempo estimado (22 km/h em linha reta).
- O caixa ve no detalhe do pedido se a localizacao esta ao vivo (com link para o mapa) ou desligada.

## Mapa do cliente (`acompanhar.js`)

- Aparece na pagina do pedido quando o status e `saiu_entrega`; consulta o rastreio a cada 8 s.
- Marcadores: moto (laranja), casa do cliente (vinho) e restaurante. Antes da primeira posicao do entregador, o mapa
  ja aparece com a casa e o restaurante.
- Pedido sem coordenada (area por bairro): `DestinoEntrega::garantir` busca a casa uma vez no despacho (Nominatim pela
  rua ou centro do CEP pela BrasilAPI, com cache) e grava no pedido; o rastreio tenta de novo se faltou.
- Ruas: mapa proprio (`public/mapas/ceara.pmtiles`, desenhado no aparelho) quando cobre os pontos; senao tiles de
  `tile.openstreetmap.org` com "© OpenStreetMap" (uso moderado; ja bloquearam o site por volume). Escolha em
  `public/assets/js/mapa-base.js`; detalhes em `docs/architecture/mapa.md`.

## Etapas para o cliente

`StatusPedido::etapas('entrega')` = recebido, em preparo, **pronto**, saiu para entrega, entregue.
Retirada: recebido, em preparo, pronto, retirado.

## Tabelas (migration `20261111100000_entregador_posicoes_mesas_padrao.php`)

- `entregador_posicoes`: `id_user` (PK, FK user ON DELETE CASCADE), `id_restaurante`, `latitude`/`longitude`
  `DECIMAL(10,7)` com CHECK, `precisao_m`, `direcao` (< 360), `velocidade_kmh`, `atualizado_em`.
- A mesma migration cria as 5 mesas iniciais para restaurantes sem mesa (ver `caixa.md`).
- Verificacao: `php8.4 database/verify-entregas.php --verify`. Aplicacao na VPS: `deploy/scripts/apply-entregas.sh`
  (backup MySQL antes).

## Trocar forma de pagamento na entrega (`TrocarFormaPagamento`, `trocar-forma.js`)

- Sem pergunta no caminho normal: o motoboy toca em "Entreguei" e vale a forma do pedido. O botao "Trocar forma de
  pagamento" (entrega com ele, pagamento pendente, fora do iFood) abre um popup com Dinheiro, Debito, Credito, Pix e,
  com o Asaas conectado, Pix por QR Code. Cada opcao mostra a taxa e o "Receber" calculados pelo servidor (regras do
  PR 1, base = itens + entrega - desconto). Dinheiro aceita "Troco para". A tela envia a taxa vista; se mudou, nada e
  gravado. Forma, taxa, rotulo, total e troco mudam no mesmo UPDATE, com o pedido travado; evento na linha do tempo,
  auditoria e tempo real (`pedidos`, `entregas`). Sem aviso de WhatsApp ao cliente.
- Pix por QR Code: `PixPedido::gerarNaEntrega` cria a cobranca no Asaas do restaurante com o total (taxa `pix_online`
  se houver), referencia `entrega:{id}`, QR valido por 30 min (gera de novo pelo popup). Sem CPF salvo, o popup pede o
  CPF. Confirmado pelo aviso (`WebhookController`, `PixPedido::confirmarEntrega`) ou pela consulta da tela; o pedido
  segue em rota com o pagamento `pago`. Enquanto nao cai, o "Entreguei" (e o "Concluir" do caixa) avisa para esperar
  ou trocar a forma. Falha do Asaas volta a forma de antes. `bin/expirar-pix.php` nunca cancela esses pedidos.
- O mesmo popup fica no caixa (Delivery > pedido), sem o QR: no caixa o Pix so registra.

## Testes

`tests/http/entregas.sh`: acesso por cargo, CSRF e validacao da localizacao, despacho aparecendo no painel,
rastreio (sem login, outro cliente, sem localizacao, ao vivo, apos entregue), Entreguei com caixa fechado,
de outro motoqueiro e repetido, e "Feitas hoje".
