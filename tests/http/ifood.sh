#!/usr/bin/env bash
# Teste HTTP da integracao com o iFood contra o servidor FALSO (tests/fakes/integracoes-fake.php):
# pedido novo pelo polling (com confirmacao de leitura), itens com complementos, pagamento, codigo de coleta,
# entregador do iFood, aceitar/pronto/cancelar indo para o iFood, cancelado e concluido vindos do iFood,
# cardapio ligado aos produtos e disponibilidade enviada ao iFood.
# Uso: bash tests/http/ifood.sh <base> <slug> <base-do-fake>
# A rotina roda com IFOOD_ROTINA (padrao: php bin/ifood.php --uma-vez, com as variaveis IFOOD_* do fake).
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3/ifood"
rotina="${IFOOD_ROTINA:-php bin/ifood.php --uma-vez}"
g="$base/$slug/gerencia"
c="$base/$slug/caixa"
loja='11111111-2222-3333-4444-555555555555'
senha='Senha#CI2026'
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
token() { curl -s -c "$1" -b "$1" -o "$tmp/token.html" "$2"; grep -o -m1 'name="_csrf" value="[a-f0-9]*"' "$tmp/token.html" | sed 's/.*value="//; s/"$//' || true; }
login() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
enviar() { # jar url-da-pagina-do-token url [campo=valor ...] -> "codigo location"
  local jar="$1" origem="$2" url="$3" t args=(); shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
pagina() { curl -s -c "$1" -b "$1" -o "$tmp/pagina.html" -w '%{http_code}' "$2"; }
flash() { curl -s -c "$1" -b "$1" "$2" | grep -o 'painel-flash-[a-z]*' | head -1; }
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
rodar() { $rotina > "$tmp/rotina.log" 2>&1 || { cat "$tmp/rotina.log"; falha "rotina do iFood rodou sem erro"; }; }
registro() { curl -s "$fake/_teste/registro" > "$tmp/registro.json"; }
no_registro() { registro; if grep -q -- "$2" "$tmp/registro.json"; then ok "$1"; else falha "$1 (iFood nao recebeu: $2)"; php -r '$d = json_decode(file_get_contents($argv[1]), true); echo "    ultimas chamadas: ", implode(" | ", array_slice($d["linhas"] ?? [], -8)), "\n";' "$tmp/registro.json"; fi; }
evento() { curl -s -o /dev/null -H 'Content-Type: application/json' --data "$1" "$fake/_teste/evento"; }
id_local() { # display do iFood -> id do pedido no sistema (lista do delivery)
  curl -s -b "$cx" "$c/delivery" | php -r 'if (preg_match("~/caixa/delivery/(\d+)\">\s*<span class=\"delivery-linha\"><strong>#\d+ <span class=\"selo-ifood\">iFood #" . preg_quote($argv[1], "~") . "<~", stream_get_contents(STDIN), $m)) echo $m[1];' "$1"
}
pedido_json() { # id display entregue_por pagamento(online|dinheiro) codigo_coleta
  local pagamento
  if [[ "$4" == "online" ]]; then
    pagamento='{"prepaid": 64.29, "pending": 0, "methods": [{"value": 64.29, "currency": "BRL", "method": "CREDIT", "type": "ONLINE", "prepaid": true, "card": {"brand": "VISA"}}]}'
    printf '{"id":"%s","displayId":"%s","orderType":"DELIVERY","orderTiming":"IMMEDIATE","createdAt":"2026-11-12T15:00:00Z","merchant":{"id":"%s","name":"Loja CI Centro"},
      "customer":{"id":"c1","name":"Maria iFood CI","documentNumber":"12345678909","ordersCountOnMerchant":3,"phone":{"number":"0800 700 1234","localizer":"55667788"}},
      "items":[{"index":1,"id":"item-ci-1","name":"Lanche iFood CI","externalCode":"PDV-CI-1","unit":"UN","quantity":2,"unitPrice":25.90,"optionsPrice":0,"totalPrice":51.80},
               {"index":2,"id":"item-x-ci","name":"Refri iFood CI","unit":"UN","quantity":1,"unitPrice":9.50,"optionsPrice":0,"totalPrice":9.50,"observations":"Bem gelado","options":[{"name":"Gelo e limao","quantity":1,"unitPrice":0,"price":0}]}],
      "total":{"subTotal":61.30,"deliveryFee":5.99,"benefits":3.00,"additionalFees":0,"orderAmount":64.29},
      "benefits":[{"value":3.00,"target":"CART","sponsorshipValues":[{"name":"IFOOD","value":3.00}],"campaign":{"name":"Cupom CI"}}],
      "payments":%s,
      "delivery":{"mode":"DEFAULT","deliveredBy":"%s","pickupCode":"%s","observations":"Portao azul","deliveryAddress":{"streetName":"Rua das Flores","streetNumber":"100","neighborhood":"Centro","city":"Sao Paulo","state":"SP","postalCode":"01001000","complement":"Ap 12","reference":"Perto da praca","coordinates":{"latitude":-23.55,"longitude":-46.63}}}}' \
      "$1" "$2" "$loja" "$pagamento" "$3" "$5"
  else
    pagamento='{"prepaid": 0, "pending": 34.00, "methods": [{"value": 34.00, "currency": "BRL", "method": "CASH", "type": "OFFLINE", "prepaid": false, "cash": {"changeFor": 100}}]}'
    printf '{"id":"%s","displayId":"%s","orderType":"DELIVERY","orderTiming":"IMMEDIATE","createdAt":"2026-11-12T15:05:00Z","merchant":{"id":"%s"},
      "customer":{"name":"Joao iFood CI","phone":{"number":"0800 700 9999","localizer":"11223344"}},
      "items":[{"index":1,"id":"item-y-ci","name":"Prato iFood CI","unit":"UN","quantity":1,"unitPrice":30.00,"totalPrice":30.00}],
      "total":{"subTotal":30.00,"deliveryFee":4.00,"benefits":0,"additionalFees":0,"orderAmount":34.00},
      "payments":%s,
      "delivery":{"mode":"DEFAULT","deliveredBy":"%s","deliveryAddress":{"streetName":"Av Brasil","streetNumber":"","neighborhood":"Vila Nova","city":"Sao Paulo","state":"SP","postalCode":"02002000"}}}' \
      "$1" "$2" "$loja" "$pagamento" "$3"
  fi
}

gj="$tmp/g"; cx="$tmp/c"
esperar "gerente entra" "$(login "$gj" ci_gerente)" "303"
esperar "caixa entra" "$(login "$cx" ci_caixa)" "303"

echo "Conectar o iFood (busca o cardapio sozinho)"
pagina "$gj" "$g/integracoes/ifood" >/dev/null
if grep -q 'Loja conectada' "$tmp/pagina.html"; then
  enviar "$gj" "$g/integracoes/ifood" "$g/integracoes/ifood/desconectar" >/dev/null
fi
enviar "$gj" "$g/integracoes/ifood" "$g/integracoes/ifood/iniciar" >/dev/null
enviar "$gj" "$g/integracoes/ifood" "$g/integracoes/ifood/concluir" codigo='AUTH-CI-OK' >/dev/null
esperar "escolher a loja" "$(enviar "$gj" "$g/integracoes/ifood" "$g/integracoes/ifood/loja" loja="$loja")" "303 $g/integracoes/ifood"
esperar "conectado e cardapio buscado" "$(aviso "$gj" "$g/integracoes/ifood")" 'iFood conectado à loja "Loja CI Centro". Encontramos 2 itens no cardápio do iFood (0 ligados aos seus produtos).'
pagina "$gj" "$g/integracoes/ifood" >/dev/null
contem "resumo do cardapio" "$tmp/pagina.html" '0 de 2'
contem "opcoes do iFood" "$tmp/pagina.html" 'Aceitar pedidos do iFood sozinho'
contem "link para ligar os itens" "$tmp/pagina.html" '/integracoes/ifood/cardapio'

echo "Loja no iFood (Merchant): lojas, dados, disponibilidade, pausas e horarios"
contem "link da loja no iFood" "$tmp/pagina.html" '/integracoes/ifood/minha-loja'
esperar "pagina da loja no iFood" "$(pagina "$gj" "$g/integracoes/ifood/minha-loja")" "200"
contem "lista as lojas vinculadas" "$tmp/pagina.html" 'Loja CI Praia'
contem "marca a loja ligada" "$tmp/pagina.html" 'Ligada a este restaurante'
contem "razao social nos dados" "$tmp/pagina.html" 'Loja CI Ltda'
contem "endereco nos dados" "$tmp/pagina.html" 'Rua CI, 10, Centro, Fortaleza - CE'
contem "disponibilidade" "$tmp/pagina.html" 'Loja aberta no iFood'
esperar "caixa nao abre a loja no iFood" "$(pagina "$cx" "$g/integracoes/ifood/minha-loja" | sed 's/302/403/')" "403"
ini="$(date -d '+10 minutes' '+%Y-%m-%dT%H:%M')"; fim="$(date -d '+70 minutes' '+%Y-%m-%dT%H:%M')"
esperar "pausa sem motivo recusada" "$(enviar "$gj" "$g/integracoes/ifood/minha-loja" "$g/integracoes/ifood/pausas" descricao= inicio="$ini" fim="$fim" | cut -d' ' -f1)" "422"
esperar "pausa com fim antes do inicio recusada" "$(enviar "$gj" "$g/integracoes/ifood/minha-loja" "$g/integracoes/ifood/pausas" descricao='Cozinha cheia' inicio="$fim" fim="$ini" | cut -d' ' -f1)" "422"
esperar "criar pausa" "$(enviar "$gj" "$g/integracoes/ifood/minha-loja" "$g/integracoes/ifood/pausas" descricao='Cozinha cheia CI' inicio="$ini" fim="$fim" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/integracoes/ifood/minha-loja" >/dev/null
contem "pausa ativa listada" "$tmp/pagina.html" 'Cozinha cheia CI'
pausa="$(php -r 'if (preg_match("~name=\"pausa\" value=\"([a-f0-9-]+)\"~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
if [[ -z "$pausa" ]]; then falha "id da pausa na pagina"; fi
esperar "remover pausa" "$(enviar "$gj" "$g/integracoes/ifood/minha-loja" "$g/integracoes/ifood/pausas/remover" pausa="$pausa" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/integracoes/ifood/minha-loja" >/dev/null
contem "sem pausas depois de remover" "$tmp/pagina.html" 'Nenhuma pausa ativa.'
esperar "horarios sobrepostos recusados" "$(enviar "$gj" "$g/integracoes/ifood/minha-loja" "$g/integracoes/ifood/horarios" 'h[SUNDAY][0][abre]=09:00' 'h[SUNDAY][0][fecha]=12:00' 'h[SUNDAY][1][abre]=11:00' 'h[SUNDAY][1][fecha]=16:00' | cut -d' ' -f1)" "422"
esperar "enviar horarios do checklist" "$(enviar "$gj" "$g/integracoes/ifood/minha-loja" "$g/integracoes/ifood/horarios" \
  'h[SATURDAY][0][abre]=10:00' 'h[SATURDAY][0][fecha]=19:00' \
  'h[SUNDAY][0][abre]=09:00' 'h[SUNDAY][0][fecha]=12:00' 'h[SUNDAY][1][abre]=13:00' 'h[SUNDAY][1][fecha]=16:00' 'h[SUNDAY][2][abre]=17:00' 'h[SUNDAY][2][fecha]=23:00' | cut -d' ' -f1)" "303"
pagina "$gj" "$g/integracoes/ifood/minha-loja" >/dev/null
contem "sabado no iFood" "$tmp/pagina.html" '10:00 às 19:00'
contem "domingo com tres horarios no iFood" "$tmp/pagina.html" '09:00 às 12:00 · 13:00 às 16:00 · 17:00 às 23:00'

echo "Cardapio do iFood ligado aos produtos"
esperar "pagina do cardapio" "$(pagina "$gj" "$g/integracoes/ifood/cardapio")" "200"
contem "item do iFood listado" "$tmp/pagina.html" 'Lanche iFood CI'
contem "codigo PDV do item" "$tmp/pagina.html" 'Código PDV PDV-CI-1'
contem "css do cardapio" "$tmp/pagina.html" '/assets/css/ifood.css'
item="$(php -r 'if (preg_match("~Lanche iFood CI</strong>.*?name=\"produto\[(\d+)\]\"~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
produto="$(php -r 'if (preg_match("~<option value=\"(\d+)\">~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
if [[ -z "$item" || -z "$produto" ]]; then falha "item e produto encontrados na pagina"; fi
esperar "ligar item ao produto" "$(enviar "$gj" "$g/integracoes/ifood/cardapio" "$g/integracoes/ifood/cardapio/ligar" "produto[$item]=$produto" | cut -d' ' -f1)" "303"
esperar "ligacao salva" "$(aviso "$gj" "$g/integracoes/ifood/cardapio")" '1 ligação(ões) salva(s). A disponibilidade vai para o iFood em até 1 minuto.'
esperar "produto de fora recusado" "$(enviar "$gj" "$g/integracoes/ifood/cardapio" "$g/integracoes/ifood/cardapio/ligar" "produto[$item]=999999" | cut -d' ' -f1)" "303"
esperar "aviso do produto de fora" "$(flash "$gj" "$g/integracoes/ifood/cardapio")" "painel-flash-erro"
pagina "$gj" "$g/integracoes/ifood/cardapio?filtro=soltos" >/dev/null
contem "filtro sem ligacao mostra o solto" "$tmp/pagina.html" 'Item sem par CI'
nao_contem "filtro sem ligacao esconde o ligado" "$tmp/pagina.html" 'Lanche iFood CI</strong>'
esperar "caixa nao abre o cardapio do iFood" "$(pagina "$cx" "$g/integracoes/ifood/cardapio" | sed 's/302/403/')" "403"

echo "Pedido novo do iFood (polling e confirmacao de leitura)"
curl -s -o /dev/null -H 'Content-Type: application/json' --data "$(pedido_json ped-ci-1 7788 IFOOD online 4321)" "$fake/_teste/pedido"
rodar
no_registro "rotina consultou o iFood com a loja" "polling $loja"
no_registro "rotina buscou o pedido" "pedido ped-ci-1"
no_registro "rotina confirmou a leitura dos avisos" '"ack ev-'
no_registro "disponibilidade do item ligado enviada" "status item-ci-1"
id1="$(id_local 7788)"
if [[ -n "$id1" ]]; then ok "pedido do iFood no delivery do caixa"; else falha "pedido do iFood no delivery do caixa"; fi
pagina "$cx" "$c/delivery/fila" >/dev/null
contem "selo iFood na fila lateral" "$tmp/pagina.html" 'selo-ifood'
esperar "pedido abre no caixa" "$(pagina "$cx" "$c/delivery/$id1")" "200"
contem "selo com o codigo do iFood" "$tmp/pagina.html" 'iFood #7788'
contem "entrega pelo iFood" "$tmp/pagina.html" 'Entrega pelo iFood'
contem "pago pelo app" "$tmp/pagina.html" 'Pago pelo app do iFood (Cartão de crédito VISA).'
contem "codigo de coleta" "$tmp/pagina.html" '4321'
contem "complemento do item" "$tmp/pagina.html" 'Gelo e limao · Obs.: Bem gelado'
contem "cupom do iFood" "$tmp/pagina.html" 'Cupom CI'
contem "telefone do iFood com codigo" "$tmp/pagina.html" '0800 700 1234, código 55667788'
contem "CPF na nota" "$tmp/pagina.html" '12345678909'
contem "aviso do item sem ligacao" "$tmp/pagina.html" 'Sem produto ligado (o estoque não baixou): Refri iFood CI'
contem "total igual ao do iFood" "$tmp/pagina.html" 'R$ 64,29'
contem "espera o entregador do iFood" "$tmp/pagina.html" 'O iFood chama o entregador.'
nao_contem "sem escolher motoqueiro da loja" "$tmp/pagina.html" 'Quem vai levar?'
contem "cancelar com motivo do iFood" "$tmp/pagina.html" 'name="motivo_ifood"'

echo "Aceitar e pronto vao para o iFood"
enviar "$cx" "$c/delivery/$id1" "$c/delivery/$id1/aceitar" >/dev/null
esperar "aceitar avisa o iFood" "$(aviso "$cx" "$c/delivery/$id1")" "Pedido #$(php -r 'if (preg_match("~<h1>Pedido #(\d+)~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html"): pedido aceito. em preparo. O iFood foi avisado."
sleep 1; rodar
no_registro "iFood recebeu o aceite" "acao ped-ci-1 confirm"
enviar "$cx" "$c/delivery/$id1" "$c/delivery/$id1/pronto" >/dev/null
sleep 1; rodar
no_registro "iFood recebeu o pronto" "acao ped-ci-1 readyToPickup"
esperar "despachar recusado (entregador do iFood)" "$(enviar "$cx" "$c/delivery/$id1" "$c/delivery/$id1/despachar" entregador=1 | cut -d' ' -f1)" "303"
esperar "aviso do entregador do iFood" "$(aviso "$cx" "$c/delivery/$id1")" "O entregador do iFood vai buscar este pedido. Ele sai sozinho daqui quando o entregador pegar."

echo "Entregador do iFood"
evento '{"code":"ADR","fullCode":"ASSIGN_DRIVER","orderId":"ped-ci-1","merchantId":"'"$loja"'","metadata":{"workerName":"Carlos CI","workerPhone":"11999990000","workerVehicleType":"MOTORCYCLE"}}'
evento '{"code":"AAO","fullCode":"ARRIVED_AT_ORIGIN","orderId":"ped-ci-1","merchantId":"'"$loja"'"}'
rodar
pagina "$cx" "$c/delivery/$id1" >/dev/null
contem "nome do entregador" "$tmp/pagina.html" 'Carlos CI'
contem "veiculo do entregador" "$tmp/pagina.html" 'Moto'
contem "telefone do entregador" "$tmp/pagina.html" 'tel:11999990000'
contem "entregador chegou na loja" "$tmp/pagina.html" 'Entregador chegou na loja'
contem "destaque de chegada" "$tmp/pagina.html" 'ifood-entrega is-chegou'
evento '{"code":"DSP","fullCode":"DISPATCHED","orderId":"ped-ci-1","merchantId":"'"$loja"'"}'
rodar
pagina "$cx" "$c/delivery/$id1" >/dev/null
contem "saiu com o entregador do iFood" "$tmp/pagina.html" 'Saiu com o entregador do iFood.'
contem "iFood conclui sozinho" "$tmp/pagina.html" 'O iFood conclui este pedido sozinho quando o cliente receber.'
evento '{"code":"CON","fullCode":"CONCLUDED","orderId":"ped-ci-1","merchantId":"'"$loja"'"}'
rodar
pagina "$cx" "$c/delivery/$id1" >/dev/null
contem "concluido pelo iFood" "$tmp/pagina.html" 'Pedido concluído no iFood.'
contem "pedido entregue" "$tmp/pagina.html" 'Entregue às'
echo '{"id":"repetido-ci-1"}' >/dev/null
curl -s -o /dev/null -H 'Content-Type: application/json' --data "$(pedido_json ped-ci-1 7788 IFOOD online 4321)" "$fake/_teste/pedido"
rodar
esperar "aviso repetido nao cria outro pedido" "$(curl -s -b "$cx" "$c/delivery" | grep -c 'iFood #7788' || true)" "0"

echo "Pedido entregue pela loja, em dinheiro, e cancelamento pelo iFood"
curl -s -o /dev/null -H 'Content-Type: application/json' --data "$(pedido_json ped-ci-2 7799 MERCHANT dinheiro '')" "$fake/_teste/pedido"
rodar
id2="$(id_local 7799)"
if [[ -n "$id2" ]]; then ok "pedido da loja no delivery"; else falha "pedido da loja no delivery"; fi
pagina "$cx" "$c/delivery/$id2" >/dev/null
contem "cobrar em dinheiro com troco" "$tmp/pagina.html" 'Cobrar na entrega: Dinheiro, troco para R$ 100,00.'
contem "numero vazio vira S/N" "$tmp/pagina.html" 'Av Brasil, S/N'
contem "loja escolhe quem leva" "$tmp/pagina.html" 'Quem vai levar?'
esperar "cancelar sem motivo recusado" "$(enviar "$cx" "$c/delivery/$id2" "$c/delivery/$id2/cancelar" motivo_ifood= | cut -d' ' -f1)" "303"
esperar "pede o motivo do iFood" "$(aviso "$cx" "$c/delivery/$id2")" "Escolha o motivo do cancelamento (o iFood pede um da lista)."
enviar "$cx" "$c/delivery/$id2" "$c/delivery/$id2/cancelar" motivo_ifood=503 motivo='Acabou o prato' >/dev/null
esperar "cancelamento pedido ao iFood" "$(aviso "$cx" "$c/delivery/$id2")" "Pedimos ao iFood para cancelar o pedido #$(php -r 'if (preg_match("~<h1>Pedido #(\d+)~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/aviso.html"). Ele é cancelado aqui (e o estoque volta) quando o iFood confirmar, em poucos segundos."
pagina "$cx" "$c/delivery/$id2" >/dev/null
contem "aguardando o iFood" "$tmp/pagina.html" 'Cancelamento pedido ao iFood'
sleep 1; rodar
no_registro "iFood recebeu o pedido de cancelamento com o codigo" "acao ped-ci-2 requestCancellation 503"
evento '{"code":"CAN","fullCode":"CANCELLED","orderId":"ped-ci-2","merchantId":"'"$loja"'","metadata":{"CANCEL_REASON":"Item indisponivel"}}'
rodar
pagina "$cx" "$c/delivery/$id2" >/dev/null
contem "cancelado no iFood" "$tmp/pagina.html" 'Cancelado no iFood: Item indisponivel'
contem "pedido cancelado" "$tmp/pagina.html" 'Pedido cancelado'

echo "Opcoes: aceitar sozinho"
esperar "salvar opcoes" "$(enviar "$gj" "$g/integracoes/ifood" "$g/integracoes/ifood/opcoes" aceitar_automatico=1 sincronizar_estoque=1)" "303 $g/integracoes/ifood"
pagina "$gj" "$g/integracoes/ifood" >/dev/null
contem "opcao aceitar marcada" "$tmp/pagina.html" 'name="aceitar_automatico" value="1" checked'
curl -s -o /dev/null -H 'Content-Type: application/json' --data "$(pedido_json ped-ci-3 7800 IFOOD online 1111)" "$fake/_teste/pedido"
rodar
id3="$(id_local 7800)"
pagina "$cx" "$c/delivery/$id3" >/dev/null
contem "aceito sozinho" "$tmp/pagina.html" 'Aceito automaticamente. Em preparo.'
no_registro "iFood recebeu o aceite automatico" "acao ped-ci-3 confirm"
enviar "$gj" "$g/integracoes/ifood" "$g/integracoes/ifood/opcoes" sincronizar_estoque=1 >/dev/null

echo "Item recriado pelo iFood (outro id, mesmo nome) usa a ligacao existente"
curl -s -o /dev/null -H 'Content-Type: application/json' --data '{"id":"ped-ci-4","displayId":"7811","orderType":"TAKEOUT","merchant":{"id":"'"$loja"'"},"customer":{"name":"Ana iFood CI"},"items":[{"id":"item-ci-1-novo","name":"Lanche iFood CI","externalCode":"PDV-NOVO","unit":"UN","quantity":1,"unitPrice":25.9,"totalPrice":25.9}],"total":{"subTotal":25.9,"orderAmount":25.9},"payments":{"prepaid":25.9,"methods":[{"method":"PIX","type":"ONLINE","prepaid":true,"value":25.9}]}}' "$fake/_teste/pedido"
rodar
id4="$(id_local 7811)"
esperar "pedido do item recriado chegou" "$(pagina "$cx" "$c/delivery/$id4")" "200"
contem "pedido do item recriado aberto" "$tmp/pagina.html" 'iFood #7811'
nao_contem "sem aviso de item sem ligacao" "$tmp/pagina.html" 'Sem produto ligado'
pagina "$gj" "$g/integracoes/ifood/cardapio?filtro=soltos" >/dev/null
nao_contem "item novo ligado sozinho" "$tmp/pagina.html" 'Código PDV PDV-NOVO'

echo "Disponibilidade e situacao da integracao"
esperar "enviar disponibilidade agora" "$(enviar "$gj" "$g/integracoes/ifood/cardapio" "$g/integracoes/ifood/cardapio/sincronizar" | cut -d' ' -f1)" "303"
esperar "disponibilidade enviada" "$(aviso "$gj" "$g/integracoes/ifood/cardapio")" "2 item(ns) atualizado(s) no iFood."
pagina "$gj" "$g/integracoes/ifood" >/dev/null
contem "pedidos do iFood hoje" "$tmp/pagina.html" 'Pedidos do iFood hoje'
nao_contem "rotina rodando (sem alerta de parada)" "$tmp/pagina.html" 'não estão chegando agora'
contem "itens ligados no resumo" "$tmp/pagina.html" '2 de'

echo "Publicar o cardapio no iFood (Catalog)"
r5="$(printf '%05d' $((RANDOM % 100000)))"
php -r '$i = imagecreatetruecolor(400, 320); imagefill($i, 0, 0, imagecolorallocate($i, 200, 60, 40)); imagepng($i, $argv[1]);' "$tmp/foto.png"
multipart() { # jar url pagina-do-token [-F campo=valor ...] -> "codigo location"
  local jar="$1" url="$2" pag="$3" t
  shift 3
  t="$(token "$jar" "$pag")"
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' -F "_csrf=$t" "$@" "$url"
}
grupo_nome="CI Adicionais iFood $r5"
esperar "grupo de complementos com fotos" "$(multipart "$gj" "$g/estoque/complementos" "$g/estoque/complementos/novo" -F "nome=$grupo_nome" -F minimo=0 -F maximo=2 \
  -F 'opcoes[0][nome]=Bacon iFood' -F 'opcoes[0][preco]=4,50' -F 'opcoes[0][disponivel]=1' -F "opcao_foto[0]=@$tmp/foto.png;type=image/png" \
  -F 'opcoes[1][nome]=Cheddar iFood' -F 'opcoes[1][preco]=3,00' -F 'opcoes[1][disponivel]=1' -F "opcao_foto[1]=@$tmp/foto.png;type=image/png" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/complementos" >/dev/null
grupo_ifood="$(php -r 'if (preg_match("~" . preg_quote($argv[2], "~") . ".*?estoque/complementos/(\d+)\"~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$grupo_nome")"
bacon="$(php -r 'if (preg_match("~<strong>Bacon iFood</strong>.*?opcoes/(\d+)/pausar~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
contem "foto do complemento salva" "$tmp/pagina.html" '/midia/cardapio/'
prod_nome="Produto Teste $r5"
esperar "produto com foto, categoria nova e complementos" "$(multipart "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" -F "nome=$prod_nome" -F unidade_venda=un -F 'preco=29,90' \
  -F controle=livre -F disponivel=1 -F categoria=nova -F "nova_categoria=Teste Homologacao $r5" -F "grupos[]=$grupo_ifood" -F "foto=@$tmp/foto.png;type=image/png" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/integracoes/ifood/publicar" >/dev/null
contem "tela de publicar" "$tmp/pagina.html" 'Publicar cardápio no iFood'
contem "produto listado para publicar" "$tmp/pagina.html" "$prod_nome"
pub="$(php -r 'if (preg_match("~id=\"pub-(\d+)\"[^>]*>(?:(?!</li>).)*?" . preg_quote($argv[2], "~") . "~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$prod_nome")"
[[ -n "$pub" ]] && ok "produto encontrado ($pub)" || falha "produto nao encontrado na tela de publicar"
esperar "publicar sem marcar recusado" "$(enviar "$gj" "$g/integracoes/ifood/publicar" "$g/integracoes/ifood/publicar" | cut -d' ' -f1)" "303"
esperar "aviso de marcar" "$(aviso "$gj" "$g/integracoes/ifood/publicar")" 'Marque os produtos que vão para o iFood.'
enviar "$gj" "$g/integracoes/ifood/publicar" "$g/integracoes/ifood/publicar" "produtos[]=$pub" >/dev/null
esperar "publicado" "$(aviso "$gj" "$g/integracoes/ifood/publicar")" 'Produto publicado no iFood. Confira no Portal do Parceiro.'
contem "situacao no iFood" "$tmp/aviso.html" 'No iFood'
no_registro "categoria criada no iFood" "categoria Teste Homologacao $r5"
no_registro "foto enviada ao iFood" "foto"
no_registro "item com grupo, 2 complementos e foto" "item $prod_nome preco 29.9 grupos 1 opcoes 2 foto"
esperar "enviar mudancas (sem mudanca)" "$(enviar "$gj" "$g/integracoes/ifood/publicar" "$g/integracoes/ifood/publicar/sincronizar" | cut -d' ' -f1)" "303"
esperar "nada mudou" "$(aviso "$gj" "$g/integracoes/ifood/publicar")" 'Tudo igual: o cardápio do iFood já está atualizado.'
pagina "$gj" "$g/estoque/produtos/$pub" >/dev/null
esperar "muda nome e preco do item" "$(multipart "$gj" "$g/estoque/produtos/$pub" "$g/estoque/produtos/$pub" -F "nome=$prod_nome Novo" -F unidade_venda=un -F 'preco=34,90' \
  -F controle=livre -F disponivel=1 -F "grupos[]=$grupo_ifood" -F "foto=@$tmp/foto.png;type=image/png" \
  -F "categoria=$(php -r 'if (preg_match("~<option value=\"(\d+)\" selected~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")" | cut -d' ' -f1)" "303"
esperar "pausa o complemento" "$(enviar "$gj" "$g/estoque/complementos" "$g/estoque/complementos/opcoes/$bacon/pausar" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/complementos" >/dev/null
contem "complemento aparece pausado" "$tmp/pagina.html" 'R$ 4,50 · Pausado'
enviar "$gj" "$g/integracoes/ifood/publicar" "$g/integracoes/ifood/publicar/sincronizar" >/dev/null
aviso "$gj" "$g/integracoes/ifood/publicar" >/dev/null
contem "mudancas enviadas" "$tmp/aviso.html" 'Mudanças enviadas ao iFood'
no_registro "nome novo pelo cadastro, com o preco antigo" "item $prod_nome Novo preco 29.9"
no_registro "preco do item por PATCH" "item-price .* 34.9"
no_registro "complemento pausado por PATCH" "opcao-status .* UNAVAILABLE"

enviar "$gj" "$g/integracoes/ifood" "$g/integracoes/ifood/desconectar" >/dev/null
if (( falhas > 0 )); then
  echo "FALHOU: $falhas verificacao(oes) do iFood."
  exit 1
fi
echo "OK: iFood (pedidos, entregador, aceitar/pronto/cancelar, concluido, cardapio e disponibilidade)."
