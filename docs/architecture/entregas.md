# Painel do entregador e rastreio ao vivo

Tela `/{slug}/entregas` para o motoqueiro (feita para o celular) e mapa ao vivo na pagina do pedido do cliente,
no estilo dos apps de corrida. Sem API paga: mapa OpenStreetMap com Leaflet (BSD-2, em `public/assets/vendor/leaflet/`).

## Rotas

| Metodo | Rota | Quem | O que faz |
|---|---|---|---|
| GET | `/{slug}/entregas` | motoqueiro | Painel. Gerente e redirecionado para `/{slug}/caixa/delivery`; outros cargos recebem 403 |
| POST | `/{slug}/entregas/{id}/entregue` | motoqueiro | Confirma a entrega (CSRF de formulario) |
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
- Entrega nova despachada para ele: bipe, vibracao e a lista atualiza sozinha (evento `entrega.despachada`, canal
  `entregas`); tambem atualiza a cada 30 s.

## Localizacao (LGPD)

- Tabela `entregador_posicoes`: **uma linha por entregador** (PK `id_user`), so a ultima posicao. Nada de historico
  do trajeto. Apagada ao parar de compartilhar, ao sair do sistema ou quando nao ha mais entregas com ele.
- O navegador usa `watchPosition` (alta precisao) e envia no maximo a cada 8 s, ou antes se andou mais de 30 m.
  Mantem a tela acesa (Wake Lock) enquanto compartilha e lembra a escolha no aparelho para retomar sozinho.
- O cliente so ve o entregador enquanto o PEDIDO DELE esta "Saiu para entrega" com esse entregador e a posicao tem
  menos de 5 minutos. Ve apenas o primeiro nome, a posicao, distancia e tempo estimado (22 km/h em linha reta).
- O caixa ve no detalhe do pedido se a localizacao esta ao vivo (com link para o mapa) ou desligada.

## Mapa do cliente (`acompanhar.js`)

- Aparece na pagina do pedido quando o status e `saiu_entrega`; consulta o rastreio a cada 8 s.
- Marcadores: moto (laranja), casa do cliente (vinho) e restaurante, quando ha coordenadas. Sem coordenada do
  endereco, mostra so o entregador.
- Tiles de `tile.openstreetmap.org` com atribuicao "© OpenStreetMap" (politica de uso do OSM: uso moderado, sem
  pre-carregar areas). Com muito volume, trocar por um provedor de tiles proprio ou pago sem mudar o codigo.

## Etapas para o cliente

`StatusPedido::etapas('entrega')` = recebido, em preparo, **pronto**, saiu para entrega, entregue.
Retirada: recebido, em preparo, pronto, retirado.

## Tabelas (migration `20261111100000_entregador_posicoes_mesas_padrao.php`)

- `entregador_posicoes`: `id_user` (PK, FK user ON DELETE CASCADE), `id_restaurante`, `latitude`/`longitude`
  `DECIMAL(10,7)` com CHECK, `precisao_m`, `direcao` (< 360), `velocidade_kmh`, `atualizado_em`.
- A mesma migration cria as 5 mesas iniciais para restaurantes sem mesa (ver `caixa.md`).
- Verificacao: `php8.4 database/verify-entregas.php --verify`. Aplicacao na VPS: `deploy/scripts/apply-entregas.sh`
  (backup MySQL antes).

## Testes

`tests/http/entregas.sh`: acesso por cargo, CSRF e validacao da localizacao, despacho aparecendo no painel,
rastreio (sem login, outro cliente, sem localizacao, ao vivo, apos entregue), Entreguei com caixa fechado,
de outro motoqueiro e repetido, e "Feitas hoje".
