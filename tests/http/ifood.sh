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

echo "Conectar o iFood e verificar as quatro abas"
curl -s -o /dev/null -H 'Content-Type: application/json' -d '{"modo":"normal"}' "$fake/_teste/catalogo"
esperar "url antiga redireciona para status" "$(curl -s -b "$gj" -o /dev/null -w '%{http_code} %{redirect_url}' "$g/integracoes/ifood")" "302 $g/integracoes/ifood/status"
pagina "$gj" "$g/integracoes/ifood/status" >/dev/null
if grep -q 'Loja conectada' "$tmp/pagina.html"; then
  enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/desconectar" >/dev/null
fi
enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/iniciar" >/dev/null
enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/concluir" codigo='AUTH-CI-OK' >/dev/null
esperar "escolher loja" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/loja" loja="$loja")" "303 $g/integracoes/ifood/status"
esperar "conectado e cardapio buscado" "$(aviso "$gj" "$g/integracoes/ifood/status")" 'iFood conectado à loja "Loja CI Centro". Encontramos 2 itens no cardápio do iFood (0 ligados aos seus produtos).'

esperar "aba Status" "$(pagina "$gj" "$g/integracoes/ifood/status")" "200"
contem "navegacao tem Status" "$tmp/pagina.html" '/integracoes/ifood/status'
contem "navegacao tem Loja" "$tmp/pagina.html" '/integracoes/ifood/loja'
contem "navegacao tem Cardapio" "$tmp/pagina.html" '/integracoes/ifood/cardapio'
contem "navegacao tem Dados" "$tmp/pagina.html" '/integracoes/ifood/dados'
contem "Status ativo e acessivel" "$tmp/pagina.html" 'href="'
contem "disponibilidade somente em Status" "$tmp/pagina.html" 'Disponibilidade agora'
contem "pausas somente em Status" "$tmp/pagina.html" 'Nova pausa'
contem "inicio com segundos no navegador" "$tmp/pagina.html" 'id="pausa-inicio" type="datetime-local" step="1"'
contem "inicio atualizado automaticamente" "$tmp/pagina.html" 'data-ifood-inicio-auto'
contem "retorno ao inicio automatico" "$tmp/pagina.html" 'data-ifood-retomar-inicio'
contem "Status nao mistura cardapio" "$tmp/pagina.html" 'id="secao-status"'
nao_contem "Status sem secao Loja" "$tmp/pagina.html" 'id="secao-loja"'
nao_contem "Status sem secao Cardapio" "$tmp/pagina.html" 'id="secao-cardapio"'
contem "overlay do Status" "$tmp/pagina.html" 'data-ifood-overlay'
contem "confirmacao AJAX do Status" "$tmp/pagina.html" 'data-pausas-status-url='
contem "motivo no limite Merchant" "$tmp/pagina.html" 'maxlength="255"'
contem "horario fixo de Brasilia" "$tmp/pagina.html" 'Brasília'
nao_contem "sem seletor de fuso" "$tmp/pagina.html" 'id="fuso-ifood"'
nao_contem "sem relogios" "$tmp/pagina.html" 'data-ifood-relogios'
nao_contem "sem inicio imediato" "$tmp/pagina.html" 'name="inicio_agora"'
esperar "POST antigo de fuso removido" "$(curl -s -b "$gj" -o /dev/null -w '%{http_code}' -X POST "$g/integracoes/ifood/fuso")" "404"
esperar "caixa sem acesso ao Status" "$(pagina "$cx" "$g/integracoes/ifood/status" | sed 's/302/403/')" "403"

esperar "aba Loja" "$(pagina "$gj" "$g/integracoes/ifood/loja")" "200"
contem "horarios sempre expandidos" "$tmp/pagina.html" 'class="bloco ifood-horarios-painel" id="horarios"'
nao_contem "sem detalhes recolhiveis de horario" "$tmp/pagina.html" 'class="ifood-secao-recolhivel"'
contem "Loja tem conta vinculada" "$tmp/pagina.html" 'Conta vinculada'
contem "Loja tem horarios" "$tmp/pagina.html" 'Horários de funcionamento'
nao_contem "Loja sem pausas" "$tmp/pagina.html" 'id="pausas"'
nao_contem "Loja sem cardapio" "$tmp/pagina.html" 'id="secao-cardapio"'

esperar "aba Cardapio" "$(pagina "$gj" "$g/integracoes/ifood/cardapio")" "200"
contem "resumo do cardapio" "$tmp/pagina.html" '0 de 2'
contem "contagem de produtos locais" "$tmp/pagina.html" 'Produtos ativos no Deliveryzim'
contem "contagem independente do catalogo remoto" "$tmp/pagina.html" '<span>Itens no cardápio do iFood</span><strong>2</strong>'
contem "opcoes do iFood" "$tmp/pagina.html" 'Aceitar pedidos do iFood sozinho'
contem "vinculos preservados" "$tmp/pagina.html" '/integracoes/ifood/cardapio/vinculos'
nao_contem "Cardapio sem dados do Merchant" "$tmp/pagina.html" 'Lojas vinculadas'

esperar "aba Dados" "$(pagina "$gj" "$g/integracoes/ifood/dados")" "200"
contem "grid de cards com espacamento" "$tmp/pagina.html" 'class="ifood-dados-cards"'
contem "lista lojas vinculadas" "$tmp/pagina.html" 'Loja CI Praia'
contem "marca loja ligada" "$tmp/pagina.html" 'Ligada a este restaurante'
contem "razao social" "$tmp/pagina.html" 'Loja CI Ltda'
contem "endereco" "$tmp/pagina.html" 'Rua CI, 10, Centro, Fortaleza - CE'
contem "historico de comunicacao" "$tmp/pagina.html" 'Diagnóstico da comunicação com o iFood'
nao_contem "Dados sem seletor de fuso" "$tmp/pagina.html" 'name="fuso"'
nao_contem "Dados sem pausas" "$tmp/pagina.html" 'id="pausas"'

esperar "alias minha-loja" "$(curl -s -b "$gj" -o /dev/null -w '%{http_code} %{redirect_url}' "$g/integracoes/ifood/minha-loja")" "302 $g/integracoes/ifood/status"
ini="$(date -d '+10 minutes' '+%Y-%m-%dT%H:%M')"; fim="$(date -d '+70 minutes' '+%Y-%m-%dT%H:%M')"
esperar "pausa sem motivo recusada" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao= inicio="$ini" fim="$fim" | cut -d' ' -f1)" "422"
esperar "pausa com fim antes do inicio recusada" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao='Cozinha cheia' inicio="$fim" fim="$ini" | cut -d' ' -f1)" "422"
esperar "pausa maior que 7 dias recusada" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao='Prazo invalido' inicio="$ini" fim="$(date -d '+9 days' '+%Y-%m-%dT%H:%M')" | cut -d' ' -f1)" "422"
esperar "duracao minima 1 min" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao='Muito curta' inicio="$ini" fim="$ini" | cut -d' ' -f1)" "422"
esperar "criar pausa" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao='Cozinha cheia CI' inicio="$ini" fim="$fim" | cut -d' ' -f1)" "303"
curl -s "$fake/_teste/ultimo-payload-pausa" > "$tmp/payload-pausa.json"
nao_contem "POST mantem hora local sem offset" "$tmp/payload-pausa.json" '-03:00'
nao_contem "POST nao envia UTC Z" "$tmp/payload-pausa.json" 'Z"'
contem "POST devolve valor de relogio original" "$tmp/payload-pausa.json" "$ini"
esperar "sobreposicao tratada no backend" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao='Sobreposta CI' inicio="$ini" fim="$fim" | cut -d' ' -f1)" "422"
pagina "$gj" "$g/integracoes/ifood/status" >/dev/null
contem "pausa ativa listada" "$tmp/pagina.html" 'Cozinha cheia CI'
pausa="$(php -r 'if (preg_match("~name=\"pausa\" value=\"([a-f0-9-]+)\"~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
if [[ -z "$pausa" ]]; then falha "id da pausa na pagina"; fi
curl -s -b "$gj" -G --data-urlencode "pausa=$pausa" --data-urlencode 'acao=criar' --data-urlencode "inicio=$ini" --data-urlencode "fim=$fim" "$g/integracoes/ifood/pausas/estado" > "$tmp/estado-pausa.json"
contem "endpoint de confirmacao devolve o registro" "$tmp/estado-pausa.json" '"registro_confirmado":true'
contem "periodo confirmado no fuso de Brasilia" "$tmp/estado-pausa.json" '"periodo_confirmado":true'
contem "consulta status realizada antes da confirmacao visual" "$tmp/estado-pausa.json" '"situacao_consultada":true'
contem "botao atualizado apos criar" "$tmp/estado-pausa.json" '"situacao_botao":{"estado":'
contem "status atualizado apos criar" "$tmp/estado-pausa.json" 'data-ifood-status'
curl -s -b "$gj" -G --data-urlencode "pausa=$pausa" --data-urlencode 'acao=criar' --data-urlencode "inicio=$fim" --data-urlencode "fim=$ini" "$g/integracoes/ifood/pausas/estado" > "$tmp/estado-periodo-errado.json"
contem "nao aceita pausa com periodo diferente" "$tmp/estado-periodo-errado.json" '"periodo_confirmado":false'
contem "endpoint traz status operacional" "$tmp/estado-pausa.json" '"html_status":'
contem "endpoint traz card atualizado" "$tmp/estado-pausa.json" '"html":'
esperar "endpoint de confirmacao exige login" "$(curl -s -o /dev/null -w '%{http_code}' "$g/integracoes/ifood/pausas/estado?pausa=$pausa&acao=criar")" "401"
esperar "endpoint rejeita ID invalido" "$(curl -s -b "$gj" -o /dev/null -w '%{http_code}' "$g/integracoes/ifood/pausas/estado?pausa=invalido!&acao=criar")" "422"
# A resposta GET valida, nao o DELETE isolado, controla o fim do overlay.
curl -s -b "$gj" -G --data-urlencode "pausa=$pausa" --data-urlencode 'acao=remover' "$g/integracoes/ifood/pausas/estado" > "$tmp/estado-remocao-pendente.json"
contem "ID ainda listado nao confirma remocao" "$tmp/estado-remocao-pendente.json" '"registro_confirmado":false'
contem "GET de interrupcoes consultado" "$tmp/estado-remocao-pendente.json" '"lista_consultada":true'
nao_contem "remocao nao depende de GET status" "$tmp/estado-remocao-pendente.json" '"situacao_consultada":true'
contem "ainda nao renderiza status durante GET de remocao pendente" "$tmp/estado-remocao-pendente.json" '"html_status":null'
# Um outro ID pode permanecer na lista sem impedir a confirmacao da remocao alvo.
extra_ini="$(TZ=America/Fortaleza date -d '+3 days' '+%Y-%m-%dT%H:%M:00')"
extra_fim="$(TZ=America/Fortaleza date -d '+3 days 20 minutes' '+%Y-%m-%dT%H:%M:00')"
curl -s -H 'Authorization: Bearer acc-ci' -H 'Content-Type: application/json' \
  --data "{\"description\":\"Outra pausa CI\",\"start\":\"$extra_ini\",\"end\":\"$extra_fim\"}" \
  "$fake/merchant/v1.0/merchants/$loja/interruptions" > "$tmp/extra-pausa.json"
extra_id="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["id"]??"";' "$tmp/extra-pausa.json")"
[[ -n "$extra_id" ]] && ok "outra pausa inserida no fake" || falha "falhou ao criar outra pausa no fake"
esperar "remover pausa" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas/remover" pausa="$pausa" | cut -d' ' -f1)" "303"
curl -s -b "$gj" -G --data-urlencode "pausa=$pausa" --data-urlencode 'acao=remover' "$g/integracoes/ifood/pausas/estado" > "$tmp/estado-pausa.json"
contem "endpoint confirma remocao mesmo com outra pausa" "$tmp/estado-pausa.json" '"registro_confirmado":true'
contem "GET da lista confirma remocao" "$tmp/estado-pausa.json" '"lista_consultada":true'
contem "outra pausa ainda existe" "$tmp/estado-pausa.json" 'Outra pausa CI'
contem "remocao consulta disponibilidade apos confirmar ID ausente" "$tmp/estado-pausa.json" '"situacao_consultada":true'
contem "remocao atualiza disponibilidade agora" "$tmp/estado-pausa.json" 'data-ifood-status'
contem "remocao atualiza botao sem aguardar o cron" "$tmp/estado-pausa.json" '"situacao_botao":{"estado":'
curl -s -X DELETE -H 'Authorization: Bearer acc-ci' -o /dev/null "$fake/merchant/v1.0/merchants/$loja/interruptions/$extra_id"
curl -s -b "$gj" -G --data-urlencode "pausa=$pausa" --data-urlencode 'acao=remover' "$g/integracoes/ifood/pausas/estado" > "$tmp/estado-pausa-vazia.json"
contem "lista vazia valida confirma remocao" "$tmp/estado-pausa-vazia.json" '"registro_confirmado":true'
contem "GET vazio foi consultado com sucesso" "$tmp/estado-pausa-vazia.json" '"lista_consultada":true'
contem "GET vazio tambem traz disponibilidade atual" "$tmp/estado-pausa-vazia.json" 'data-ifood-status'
contem "GET vazio atualiza botao" "$tmp/estado-pausa-vazia.json" '"situacao_botao":{"estado":'
pagina "$gj" "$g/integracoes/ifood/status" >/dev/null
contem "sem pausas depois de remover" "$tmp/pagina.html" 'Nenhuma pausa ativa.'

# Compatibilidade: o novo input envia segundos, mas o backend tambem aceita minutos legados.
ini_seg="$(TZ=America/Fortaleza date -d '+2 hours' '+%Y-%m-%dT%H:%M:%S')"
fim_seg="$(TZ=America/Fortaleza date -d '+3 hours' '+%Y-%m-%dT%H:%M:%S')"
esperar "criar pausa com segundos" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao='Segundos CI' inicio="$ini_seg" fim="$fim_seg" | cut -d' ' -f1)" "303"
curl -s "$fake/_teste/ultimo-payload-pausa" > "$tmp/payload-pausa-segundos.json"
contem "POST preserva segundos exatos" "$tmp/payload-pausa-segundos.json" "$ini_seg"
pagina "$gj" "$g/integracoes/ifood/status" >/dev/null
pausa_seg="$(php -r 'if (preg_match("~name=\"pausa\" value=\"([a-f0-9-]+)\"~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
[[ -n "$pausa_seg" ]] && ok "identificador da pausa com segundos" || falha "nao encontrou pausa com segundos"
curl -s -b "$gj" -G --data-urlencode "pausa=$pausa_seg" --data-urlencode 'acao=criar' --data-urlencode "inicio=$ini_seg" --data-urlencode "fim=$fim_seg" "$g/integracoes/ifood/pausas/estado" > "$tmp/estado-pausa-segundos.json"
contem "GET confirma periodo com precisao de segundos" "$tmp/estado-pausa-segundos.json" '"periodo_confirmado":true'
esperar "remover pausa com segundos" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas/remover" pausa="$pausa_seg" | cut -d' ' -f1)" "303"
# Pela tela (JS): a resposta traz o card de pausas pronto, com o aviso dentro dele.
por_js() { # jar pagina-do-token url [campo=valor ...] -> codigo (corpo em $tmp/js.json)
  local jar="$1" origem="$2" url="$3" t args=(); shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/js.json" -c "$jar" -b "$jar" -w '%{http_code}' -H 'X-Requested-With: fetch' -H 'Accept: application/json' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
agora_local="$(TZ=America/Fortaleza date '+%Y-%m-%dT%H:%M')"; depois_local="$(TZ=America/Fortaleza date -d '+30 minutes' '+%Y-%m-%dT%H:%M')"
esperar "pausa pela tela" "$(por_js "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao='Pausa tela CI' inicio="$agora_local" fim="$depois_local")" "200"
contem "aviso dentro do card" "$tmp/js.json" 'data-pausas-aviso'
contem "aviso de pausa criada" "$tmp/js.json" 'recebeu a solicitação de pausa'
contem "pausa nova ja na lista" "$tmp/js.json" 'Pausa tela CI'
contem "resposta do POST informa ID para acompanhar" "$tmp/js.json" '"id_pausa":"'
contem "resposta identifica criacao da pausa" "$tmp/js.json" '"acao":"criar"'
contem "horario da pausa identificado" "$tmp/js.json" 'Horário vigente'
esperar "pausa invalida pela tela" "$(por_js "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao= inicio="$agora_local" fim="$depois_local")" "422"
contem "erro no campo dentro do card" "$tmp/js.json" 'erro-pausa-descricao'
esperar "pausa pela tela sem token" "$(curl -s -o /dev/null -w '%{http_code}' -b "$gj" -H 'X-Requested-With: fetch' --data 'descricao=Teste' "$g/integracoes/ifood/pausas")" "419"
pausa_js="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); if (preg_match("~name=\"pausa\" value=\"([a-f0-9-]+)\"~", $d["html"] ?? "", $m)) echo $m[1];' "$tmp/js.json")"
esperar "remover pela tela" "$(por_js "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas/remover" pausa="$pausa_js")" "200"
contem "aviso de removida no card" "$tmp/js.json" 'recebeu a solicitação de remoção'
contem "lista vazia no card" "$tmp/js.json" 'Nenhuma pausa ativa.'
esperar "horarios sobrepostos recusados" "$(enviar "$gj" "$g/integracoes/ifood/loja" "$g/integracoes/ifood/horarios" horario_proprio=1 'h[SUNDAY][0][abre]=09:00' 'h[SUNDAY][0][fecha]=12:00' 'h[SUNDAY][1][abre]=11:00' 'h[SUNDAY][1][fecha]=16:00' | cut -d' ' -f1)" "422"
esperar "enviar horarios do checklist" "$(enviar "$gj" "$g/integracoes/ifood/loja" "$g/integracoes/ifood/horarios" horario_proprio=1 \
  'h[SATURDAY][0][abre]=10:00' 'h[SATURDAY][0][fecha]=19:00' \
  'h[SUNDAY][0][abre]=09:00' 'h[SUNDAY][0][fecha]=12:00' 'h[SUNDAY][1][abre]=13:00' 'h[SUNDAY][1][fecha]=16:00' 'h[SUNDAY][2][abre]=17:00' 'h[SUNDAY][2][fecha]=23:00' | cut -d' ' -f1)" "303"
pagina "$gj" "$g/integracoes/ifood/loja" >/dev/null
contem "sabado no iFood" "$tmp/pagina.html" '10:00 às 19:00'
contem "domingo com tres horarios no iFood" "$tmp/pagina.html" '09:00 às 12:00 · 13:00 às 16:00 · 17:00 às 23:00'
contem "horario diferente fica ligado" "$tmp/pagina.html" 'name="horario_proprio" value="1" checked'
turnos_ifood() { curl -s -H 'Authorization: Bearer acc-ci' "$fake/merchant/v1.0/merchants/$loja/opening-hours" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode(" ", array_map(static fn ($t) => $t["dayOfWeek"] . "@" . substr($t["start"], 0, 5) . "+" . $t["duration"], $d["shifts"] ?? []));'; }

echo "Horario do iFood: o do Perfil (padrao) ou um diferente"
esperar "perfil muda com horario diferente ligado" "$(enviar "$gj" "$g/perfil" "$g/perfil/horarios" modo=semana 'dia[2][aberto]=1' 'dia[2][abre1]=11:00' 'dia[2][fecha1]=15:00' | cut -d' ' -f1)" "303"
esperar "iFood nao segue o perfil com horario diferente" "$(turnos_ifood)" "SATURDAY@10:00+540 SUNDAY@09:00+180 SUNDAY@13:00+180 SUNDAY@17:00+360"
esperar "voltar ao horario do perfil" "$(enviar "$gj" "$g/integracoes/ifood/loja" "$g/integracoes/ifood/horarios" | cut -d' ' -f1)" "303"
esperar "aviso do horario do perfil" "$(aviso "$gj" "$g/integracoes/ifood/loja")" 'Pronto: o iFood segue o horário do Perfil. Quando você mudar o horário no Perfil, o iFood muda junto.'
esperar "iFood recebeu o horario do perfil" "$(turnos_ifood)" "TUESDAY@11:00+240"
pagina "$gj" "$g/integracoes/ifood/loja" >/dev/null
nao_contem "horario diferente desligado" "$tmp/pagina.html" 'name="horario_proprio" value="1" checked'
contem "resumo do perfil na tela" "$tmp/pagina.html" 'Horário do Perfil'
esperar "perfil muda de novo" "$(enviar "$gj" "$g/perfil" "$g/perfil/horarios" modo=semana 'dia[3][aberto]=1' 'dia[3][abre1]=18:00' 'dia[3][fecha1]=02:00' | cut -d' ' -f1)" "303"
esperar "aviso do perfil fala do iFood" "$(aviso "$gj" "$g/perfil")" 'Horário de funcionamento salvo. O iFood também recebeu o horário novo.'
esperar "iFood segue o perfil (madrugada no dia seguinte)" "$(turnos_ifood)" "WEDNESDAY@18:00+360 THURSDAY@00:00+120"

echo "Botao Abrir loja no iFood (gerencia e caixa)"
pagina "$gj" "$g" >/dev/null
contem "botao no topo da gerencia" "$tmp/pagina.html" 'data-ifood-loja'
pagina "$cx" "$c" >/dev/null
contem "botao no topo do caixa" "$tmp/pagina.html" 'data-ifood-loja'
contem "caixa usa as rotas do caixa" "$tmp/pagina.html" '/caixa/ifood/situacao'
curl -s -b "$gj" -o "$tmp/sit.json" "$g/integracoes/ifood/botao-loja/situacao"
contem "loja aberta" "$tmp/sit.json" '"estado":"aberta"'
esperar "pausa valendo agora" "$(enviar "$gj" "$g/integracoes/ifood/status" "$g/integracoes/ifood/pausas" descricao='Pausa botao CI' inicio="$(TZ=America/Fortaleza date '+%Y-%m-%dT%H:%M')" fim="$(TZ=America/Fortaleza date -d '+60 minutes' '+%Y-%m-%dT%H:%M')" | cut -d' ' -f1)" "303"
curl -s -b "$gj" -o "$tmp/sit.json" "$g/integracoes/ifood/botao-loja/situacao"
# O cron pode ter atualizado o snapshot no mesmo segundo do POST ou nao:
# ambos "divergente" e "erro/Verificando iFood" sao seguros ate GET /status.
if grep -Eq '"estado":"(divergente|erro)"' "$tmp/sit.json"; then
  ok "gerencia sinaliza estado transitorio seguro apos POST"
else
  falha "gerencia nao sinalizou estado transitorio seguro apos POST"
fi
nao_contem "nunca inventa fechamento apos POST" "$tmp/sit.json" '"estado":"pausada"'
nao_contem "nunca informa fechada antes do GET" "$tmp/sit.json" '"estado":"fechada"'
curl -s -b "$cx" -o "$tmp/sit.json" "$c/ifood/situacao"
contem "caixa aguarda confirmacao antes de permitir reabrir" "$tmp/sit.json" '"pode_abrir":false'
curl -s -o "$tmp/estado-publico.json" "$base/api/v1/restaurantes/$slug/situacao"
contem "cardapio publico bloqueia novos pedidos imediatamente" "$tmp/estado-publico.json" '"fechado_integracao":true'
esperar "cardapio mostra fechamento temporario" "$(pagina "$gj" "$base/$slug")" "200"
contem "cardapio informa pausa do restaurante" "$tmp/pagina.html" 'Fechado temporariamente'
# Comportamento observado na loja TEST: interrupcao existe mas status Merchant ainda informa OK.
curl -s -o /dev/null -H 'Content-Type: application/json' -X POST \
  --data '{"ativa":true}' "$fake/_teste/ifood-status-forcar-aberta"
rodar
curl -s -b "$gj" -o "$tmp/sit.json" "$g/integracoes/ifood/botao-loja/situacao?novo=1"
contem "gerencia informa que o Merchant ainda esta aberto" "$tmp/sit.json" '"estado":"divergente"'
contem "nao chama de loja fechada" "$tmp/sit.json" 'iFood: loja aberta'
curl -s -b "$cx" -o "$tmp/sit.json" "$c/ifood/situacao?novo=1"
contem "caixa tambem detecta a divergencia" "$tmp/sit.json" '"estado":"divergente"'
pagina "$gj" "$g/integracoes/ifood/status" >/dev/null
contem "aviso visivel na tela da loja" "$tmp/pagina.html" 'Pausa vigente, mas loja aberta no iFood.'
curl -s -o "$tmp/estado-publico.json" "$base/api/v1/restaurantes/$slug/situacao"
contem "nao abre checkout local com pausa propria vigente" "$tmp/estado-publico.json" '"fechado_integracao":true'
curl -s -o /dev/null -H 'Content-Type: application/json' -X POST \
  --data '{"ativa":false}' "$fake/_teste/ifood-status-forcar-aberta"
rodar
curl -s -b "$gj" -o "$tmp/sit.json" "$g/integracoes/ifood/botao-loja/situacao?novo=1"
contem "status Merchant fechado restaura botao de remover pausa" "$tmp/sit.json" '"estado":"pausada"'
esperar "abrir sem token recusado" "$(curl -s -o /dev/null -w '%{http_code}' -b "$cx" -X POST "$c/ifood/abrir")" "419"
t="$(token "$cx" "$c")"
esperar "caixa abre a loja" "$(curl -s -o "$tmp/abrir.json" -w '%{http_code}' -b "$cx" -c "$cx" -X POST -H "X-CSRF-Token: $t" "$c/ifood/abrir")" "200"
# Remover pausa é assíncrono no iFood: não declarar sucesso pelo comando DELETE.
contem "resposta do botao informa estado real" "$tmp/abrir.json" '"situacao":'
rodar
curl -s -o "$tmp/estado-publico.json" "$base/api/v1/restaurantes/$slug/situacao"
contem "cardapio reabre somente depois de confirmar no Merchant" "$tmp/estado-publico.json" '"fechado_integracao":false'
pagina "$gj" "$g/integracoes/ifood/status" >/dev/null
contem "pausa removida pelo botao" "$tmp/pagina.html" 'Nenhuma pausa ativa.'
t="$(token "$cx" "$c")"
esperar "abrir loja ja aberta" "$(curl -s -o "$tmp/abrir.json" -w '%{http_code}' -b "$cx" -c "$cx" -X POST -H "X-CSRF-Token: $t" "$c/ifood/abrir")" "200"
contem "avisa que ja estava aberta" "$tmp/abrir.json" 'já está aberta'
esperar "sem login nao consulta" "$(curl -s -o /dev/null -w '%{http_code}' "$c/ifood/situacao")" "401"

echo "iFood -> Deliveryzim: fechamento remoto e reabertura confirmada"
inicio_remoto="$(date -u -d '-1 minute' '+%Y-%m-%dT%H:%M:%SZ')"
fim_remoto="$(date -u -d '+60 minutes' '+%Y-%m-%dT%H:%M:%SZ')"
curl -s -o "$tmp/remota.json" -w '%{http_code}' -X POST -H 'Authorization: Bearer acc-ci' -H 'Content-Type: application/json' \
  --data "{\"description\":\"Pausa Portal CI\",\"start\":\"$inicio_remoto\",\"end\":\"$fim_remoto\"}" \
  "$fake/merchant/v1.0/merchants/$loja/interruptions" > "$tmp/codigo-remoto"
esperar "portal criou pausa" "$(cat "$tmp/codigo-remoto")" "201"
pausa_remota="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d["id"] ?? "";' "$tmp/remota.json")"
if [[ -z "$pausa_remota" ]]; then falha "portal recebeu ID da pausa"; fi
rodar
curl -s -o "$tmp/estado-publico.json" "$base/api/v1/restaurantes/$slug/situacao"
contem "pausa feita no Portal fecha o Deliveryzim" "$tmp/estado-publico.json" '"fechado_integracao":true'
curl -s -o /dev/null -X DELETE -H 'Authorization: Bearer acc-ci' "$fake/merchant/v1.0/merchants/$loja/interruptions/$pausa_remota"
rodar
curl -s -o "$tmp/estado-publico.json" "$base/api/v1/restaurantes/$slug/situacao"
contem "reabertura externa restaura disponibilidade" "$tmp/estado-publico.json" '"fechado_integracao":false'


echo "Cardapio do iFood ligado aos produtos"
esperar "pagina do cardapio" "$(pagina "$gj" "$g/integracoes/ifood/cardapio/vinculos")" "200"
contem "item do iFood listado" "$tmp/pagina.html" 'Lanche iFood CI'
contem "codigo PDV do item" "$tmp/pagina.html" 'Código PDV PDV-CI-1'
contem "css do cardapio" "$tmp/pagina.html" '/assets/css/ifood.css'
item="$(php -r 'if (preg_match("~Lanche iFood CI</strong>.*?name=\"produto\[(\d+)\]\"~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
produto="$(php -r 'if (preg_match("~<option value=\"(\d+)\">~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
if [[ -z "$item" || -z "$produto" ]]; then falha "item e produto encontrados na pagina"; fi
esperar "ligar item ao produto" "$(enviar "$gj" "$g/integracoes/ifood/cardapio/vinculos" "$g/integracoes/ifood/cardapio/ligar" "produto[$item]=$produto" | cut -d' ' -f1)" "303"
esperar "ligacao salva" "$(aviso "$gj" "$g/integracoes/ifood/cardapio/vinculos")" '1 ligação(ões) salva(s). A disponibilidade vai para o iFood em até 1 minuto.'
esperar "produto de fora recusado" "$(enviar "$gj" "$g/integracoes/ifood/cardapio/vinculos" "$g/integracoes/ifood/cardapio/ligar" "produto[$item]=999999" | cut -d' ' -f1)" "303"
esperar "aviso do produto de fora" "$(flash "$gj" "$g/integracoes/ifood/cardapio/vinculos")" "painel-flash-erro"
pagina "$gj" "$g/integracoes/ifood/cardapio/vinculos?filtro=soltos" >/dev/null
contem "filtro sem ligacao mostra o solto" "$tmp/pagina.html" 'Item sem par CI'
nao_contem "filtro sem ligacao esconde o ligado" "$tmp/pagina.html" 'Lanche iFood CI</strong>'
esperar "caixa nao abre o cardapio do iFood" "$(pagina "$cx" "$g/integracoes/ifood/cardapio/vinculos" | sed 's/302/403/')" "403"

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
esperar "salvar opcoes" "$(enviar "$gj" "$g/integracoes/ifood/cardapio" "$g/integracoes/ifood/opcoes" aceitar_automatico=1 sincronizar_estoque=1)" "303 $g/integracoes/ifood/cardapio"
pagina "$gj" "$g/integracoes/ifood/cardapio" >/dev/null
contem "opcao aceitar marcada" "$tmp/pagina.html" 'name="aceitar_automatico" value="1" checked'
curl -s -o /dev/null -H 'Content-Type: application/json' --data "$(pedido_json ped-ci-3 7800 IFOOD online 1111)" "$fake/_teste/pedido"
rodar
id3="$(id_local 7800)"
pagina "$cx" "$c/delivery/$id3" >/dev/null
contem "aceito sozinho" "$tmp/pagina.html" 'Aceito automaticamente. Em preparo.'
no_registro "iFood recebeu o aceite automatico" "acao ped-ci-3 confirm"
enviar "$gj" "$g/integracoes/ifood/cardapio" "$g/integracoes/ifood/opcoes" sincronizar_estoque=1 >/dev/null

echo "Item recriado pelo iFood (outro id, mesmo nome) usa a ligacao existente"
curl -s -o /dev/null -H 'Content-Type: application/json' --data '{"id":"ped-ci-4","displayId":"7811","orderType":"TAKEOUT","merchant":{"id":"'"$loja"'"},"customer":{"name":"Ana iFood CI"},"items":[{"id":"item-ci-1-novo","name":"Lanche iFood CI","externalCode":"PDV-NOVO","unit":"UN","quantity":1,"unitPrice":25.9,"totalPrice":25.9}],"total":{"subTotal":25.9,"orderAmount":25.9},"payments":{"prepaid":25.9,"methods":[{"method":"PIX","type":"ONLINE","prepaid":true,"value":25.9}]}}' "$fake/_teste/pedido"
rodar
id4="$(id_local 7811)"
esperar "pedido do item recriado chegou" "$(pagina "$cx" "$c/delivery/$id4")" "200"
contem "pedido do item recriado aberto" "$tmp/pagina.html" 'iFood #7811'
nao_contem "sem aviso de item sem ligacao" "$tmp/pagina.html" 'Sem produto ligado'
pagina "$gj" "$g/integracoes/ifood/cardapio/vinculos?filtro=soltos" >/dev/null
nao_contem "item novo ligado sozinho" "$tmp/pagina.html" 'Código PDV PDV-NOVO'

echo "Disponibilidade e situacao da integracao"
esperar "enviar disponibilidade agora" "$(enviar "$gj" "$g/integracoes/ifood/cardapio/vinculos" "$g/integracoes/ifood/cardapio/sincronizar" | cut -d' ' -f1)" "303"
# Item de pedido recriado, ausente no catalogo atual remoto, nao entra mais na sincronizacao.
esperar "disponibilidade enviada" "$(aviso "$gj" "$g/integracoes/ifood/cardapio/vinculos")" "1 item(ns) atualizado(s) no iFood."
pagina "$gj" "$g/integracoes/ifood/status" >/dev/null
contem "pedidos do iFood hoje no novo status" "$tmp/pagina.html" 'Pedidos do iFood hoje'
nao_contem "rotina rodando (sem alerta de parada)" "$tmp/pagina.html" 'não estão chegando agora'
pagina "$gj" "$g/integracoes/ifood/cardapio" >/dev/null
contem "itens ligados no resumo (somente itens do catalogo atual)" "$tmp/pagina.html" '1 de 2'

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
# Ancora no titulo do grupo (o aviso de "salvo" no topo tambem traz o nome).
grupo_ifood="$(php -r 'if (preg_match("~<h2 id=\"grupo-(\d+)\">" . preg_quote(htmlspecialchars($argv[2]), "~") . "</h2>~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$grupo_nome")"
bacon="$(php -r 'if (preg_match("~<h2 id=\"grupo-" . $argv[2] . "\">.*?<strong>Bacon iFood</strong>.*?opcoes/(\d+)/pausar~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$grupo_ifood")"
[[ -n "$grupo_ifood" && -n "$bacon" ]] && ok "grupo ($grupo_ifood) e complemento ($bacon) encontrados" || falha "grupo ou complemento nao encontrado"
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

# A exclusao arquiva o produto com vinculo no iFood; a rotina remove a publicacao remota.
esperar "excluir produto publicado" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/$pub/excluir" | cut -d' ' -f1)" "303"
aviso "$gj" "$g/estoque/produtos" > "$tmp/exclusao-aviso.txt"
contem "aviso de exclusao pendente no iFood" "$tmp/exclusao-aviso.txt" 'A exclusão do produto publicado no iFood será enviada pela sincronização.'
pagina "$gj" "$g/estoque/produtos" >/dev/null
nao_contem "produto excluido sumiu da lista" "$tmp/pagina.html" "$prod_nome Novo"
rodar
no_registro "produto publicado excluido no iFood" "excluir-produto "

# Item externo ligado: desligar a sincronizacao geral nao pode impedir a pausa ao excluir.
enviar "$gj" "$g/integracoes/ifood/cardapio" "$g/integracoes/ifood/opcoes" sincronizar_estoque=0 >/dev/null
esperar "excluir produto ligado ao item externo" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/$produto/excluir" | cut -d' ' -f1)" "303"
aviso "$gj" "$g/estoque/produtos" > "$tmp/exclusao-externa-aviso.txt"
contem "item externo deve ser pausado" "$tmp/exclusao-externa-aviso.txt" 'O item vinculado ao iFood será pausado pela sincronização.'
rodar
no_registro "pausa de item externo com estoque desligado" "status item-ci-1 UNAVAILABLE"

echo "Reconciliar catalogo remoto, produtos arquivados e falhas de API"
pagina "$gj" "$g/integracoes/ifood/cardapio" >/dev/null
contem "catalogo remoto inicialmente com 2 itens" "$tmp/pagina.html" '<span>Itens no cardápio do iFood</span><strong>2</strong>'
contem "vinculos arquivados separados dos ativos" "$tmp/pagina.html" 'Itens ligados a produtos excluídos aqui'
pagina "$gj" "$g/integracoes/ifood/cardapio/vinculos" >/dev/null
contem "produto arquivado indicado na ligacao" "$tmp/pagina.html" 'Produto excluído daqui (aguardando sincronização)'

curl -s -o /dev/null -H 'Content-Type: application/json' -d '{"modo":"erro"}' "$fake/_teste/catalogo"
pagina "$gj" "$g/integracoes/ifood/cardapio" >/dev/null
contem "falha nao exibe numero antigo como atual" "$tmp/pagina.html" 'Não confirmado'
contem "falha informa que nao confirmou catalogo" "$tmp/pagina.html" 'Não foi possível conferir o cardápio do iFood agora.'
pagina "$gj" "$g/integracoes/ifood/cardapio/vinculos" >/dev/null
contem "falha preserva ultimo vinculo conhecido" "$tmp/pagina.html" 'Lanche iFood CI'

curl -s -o /dev/null -H 'Content-Type: application/json' -d '{"modo":"excluido"}' "$fake/_teste/catalogo"
pagina "$gj" "$g/integracoes/ifood/cardapio" >/dev/null
contem "item remoto excluido nao conta" "$tmp/pagina.html" '<span>Itens no cardápio do iFood</span><strong>1</strong>'
pagina "$gj" "$g/integracoes/ifood/cardapio/vinculos" >/dev/null
nao_contem "item remoto excluido some dos vinculos" "$tmp/pagina.html" 'Item sem par CI'

curl -s -o /dev/null -H 'Content-Type: application/json' -d '{"modo":"vazio"}' "$fake/_teste/catalogo"
pagina "$gj" "$g/integracoes/ifood/cardapio" >/dev/null
contem "catalogo vazio mostra zero confirmado" "$tmp/pagina.html" '<span>Itens no cardápio do iFood</span><strong>0</strong>'
pagina "$gj" "$g/integracoes/ifood/cardapio/vinculos" >/dev/null
contem "catalogo vazio nao guarda linhas antigas" "$tmp/pagina.html" 'Nenhum item do iFood ainda'

curl -s -o /dev/null -H 'Content-Type: application/json' -d '{"modo":"normal"}' "$fake/_teste/catalogo"
pagina "$gj" "$g/integracoes/ifood/cardapio" >/dev/null
contem "itens remotos restaurados apos nova consulta" "$tmp/pagina.html" '<span>Itens no cardápio do iFood</span><strong>2</strong>'

enviar "$gj" "$g/integracoes/ifood" "$g/integracoes/ifood/desconectar" >/dev/null
if (( falhas > 0 )); then
  echo "FALHOU: $falhas verificacao(oes) do iFood."
  exit 1
fi
echo "OK: iFood (pedidos, entregador, aceitar/pronto/cancelar, concluido, cardapio e disponibilidade)."
