#!/usr/bin/env bash
# Varias impressoras do Agent: lista com apelidos enviada pelo Agent, pontos de impressao na Gerencia,
# ponto com uma impressora imprime direto, com varias o garcom escolhe (padrao marcada), impressora fora do ponto
# recusada, cupom so para o Agent dono da impressora, Agent 0.7 nao pega cupom de impressora escolhida e
# pedido novo do delivery sai nas impressoras marcadas de cada computador.
# Uso: bash tests/http/impressoras.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
c="$base/$slug/caixa"
w="$base/$slug/garcom"
g="$base/$slug/gerencia"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
senha='Senha#CI2026'
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
json() { php -r '$d=json_decode(file_get_contents($argv[1]),true); $x=$d; foreach(explode(".",$argv[2]) as $k){$x=is_array($x)&&array_key_exists($k,$x)?$x[$k]:null;} echo is_bool($x)?($x?"true":"false"):($x===null?"null":(is_scalar($x)?(string)$x:json_encode($x,JSON_UNESCAPED_UNICODE)));' "$1" "$2"; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
enviar() { # jar url pagina-do-token [campo=valor ...]
  local jar="$1" url="$2" origem="$3" t args=()
  shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
imprimir() { # jar url pagina-do-token [campo=valor ...] -> codigo; resposta em $tmp/imp.json
  local jar="$1" url="$2" origem="$3" t args=()
  shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/imp.json" -c "$jar" -b "$jar" -w '%{http_code}' -H 'Accept: application/json' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
vincular() {
  local jar="$1" nome="$2" csrf
  curl -s -b "$jar" -o "$tmp/csrf.json" "$base/api/v1/desktop/csrf"
  csrf="$(json "$tmp/csrf.json" csrf)"
  curl -s -b "$jar" -o "$tmp/vinculo.json" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    -d "{\"nome\":\"$nome\"}" "$base/api/v1/desktop/vincular"
  json "$tmp/vinculo.json" token
}
registrar() { # token json -> codigo
  curl -s -o "$tmp/reg.json" -w '%{http_code}' -X POST -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
    -d "$2" "$base/api/v1/desktop/impressoras"
}
agente() { # token desde [novo=1 (com impressoras)] -> codigo; resposta em $tmp/ag.json
  local q="desde=$2&impressoes=1"
  [[ "${3:-1}" == "1" ]] && q="$q&impressoras=1"
  curl -s -o "$tmp/ag.json" -w '%{http_code}' -H "Authorization: Bearer $1" "$base/api/v1/desktop/eventos?$q"
}
resultado() { curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d '{"ok":true}' "$base/api/v1/desktop/impressoes/$2/resultado"; }
cupom() { php -r '$d=json_decode(file_get_contents($argv[1]),true); $j=$d["impressoes"][0]??null; echo $j===null?"":(string)($j[$argv[2]]??"");' "$tmp/ag.json" "$1"; }
qtd_cupons() { php -r '$d=json_decode(file_get_contents($argv[1]),true); echo count($d["impressoes"]??[]);' "$tmp/ag.json"; }
chaves() { php -r '$d=json_decode(file_get_contents($argv[1]),true); $k=array_map(fn($j)=>(string)($j["impressora"]??""), $d["impressoes"]??[]); sort($k); echo implode(",",$k);' "$tmp/ag.json"; }
concluir() { for id in $(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["impressoes"]??[] as $j) echo $j["id"], " ";' "$tmp/ag.json"); do resultado "$1" "$id" >/dev/null; done; }
alvos() { # pedido -> json das impressoras do pedido novo
  php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["pedidos"]??[] as $p){ if($p["id"]===(int)$argv[2]){ echo array_key_exists("impressoras",$p)?json_encode($p["impressoras"]):"ausente"; exit; } } echo "sem-pedido";' "$tmp/ag.json" "$1"
}

cx="$tmp/caixa"; gj="$tmp/gerente"; wj="$tmp/garcom"
esperar "caixa autenticado" "$(login "$cx" ci_caixa)" "303"
esperar "gerente autenticado" "$(login "$gj" ci_gerente)" "303"
esperar "garcom autenticado" "$(login "$wj" ci_garcom)" "303"

echo "Agents mandam a lista de impressoras"
ta="$(vincular "$cx" "PC-CAIXA-IMPRESSORAS")"; cursor="$(json "$tmp/vinculo.json" cursor)"
tb="$(vincular "$gj" "PC-BAR-IMPRESSORAS")"
[[ "$ta" == dzag_* && "$tb" == dzag_* ]] && ok "dois computadores vinculados" || falha "Agents nao vinculados"
esperar "lista exige token" "$(registrar falso '{"impressoras":[]}')" "401"
esperar "apelido vazio recusado" "$(registrar "$ta" '{"impressoras":[{"chave":"aaaa1111bbbb","apelido":" "}]}')" "422"
esperar "chave invalida recusada" "$(registrar "$ta" '{"impressoras":[{"chave":"X!","apelido":"Balcao"}]}')" "422"
esperar "PC do caixa: Balcao e Cozinha" "$(registrar "$ta" '{"impressoras":[{"chave":"aaaa1111bbbb","apelido":"Balcão"},{"chave":"cccc2222dddd","apelido":"Cozinha"}]}')" "200"
esperar "PC do bar: Bar" "$(registrar "$tb" '{"impressoras":[{"chave":"eeee3333ffff","apelido":"Bar"}]}')" "200"
agente "$ta" "$cursor" >/dev/null; agente "$tb" "$cursor" >/dev/null

echo "Gerencia: pontos de impressao"
esperar "garcom nao abre a configuracao" "$(curl -s -o /dev/null -w '%{http_code}' -b "$wj" "$g/integracoes/impressoras")" "403"
esperar "gerente abre as impressoras" "$(curl -s -o "$tmp/ger.html" -w '%{http_code}' -b "$gj" "$g/integracoes/impressoras")" "200"
contem "lista o Balcao" "$tmp/ger.html" 'Balcão'
contem "lista a Cozinha" "$tmp/ger.html" 'Cozinha'
contem "mostra o computador" "$tmp/ger.html" 'PC-BAR-IMPRESSORAS'
contem "impressora ligada" "$tmp/ger.html" 'Ligada'
curl -s -b "$gj" "$g/integracoes" -o "$tmp/int.html"
contem "integracoes leva para as impressoras" "$tmp/int.html" "/$slug/gerencia/integracoes/impressoras"
id_de() { php -r 'if (preg_match("~id=\"p-completo-(\d+)\"[^>]*>\s*<span>" . preg_quote($argv[2], "~") . "</span>~u", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/ger.html" "$1"; }
balcao="$(id_de 'Balcão')"; cozinha="$(id_de 'Cozinha')"; bar="$(id_de 'Bar')"
[[ -n "$balcao" && -n "$cozinha" && -n "$bar" ]] && ok "ids das impressoras ($balcao, $cozinha, $bar)" || falha "ids nao encontrados"
contem "bloco cupom completo" "$tmp/ger.html" 'Cupom completo'
nao_contem "sem o ponto antigo do pedido novo" "$tmp/ger.html" 'Pedido novo do delivery'
enviar "$gj" "$g/integracoes/impressoras" "$g/integracoes/impressoras" "completo[]=$balcao" "fechamento[]=999999999" >/dev/null
curl -s -b "$gj" "$g/integracoes/impressoras" -o "$tmp/ger.html"
contem "impressoras salvas" "$tmp/ger.html" 'Impressoras salvas.'
grep -q "id=\"p-completo-$balcao\" name=\"completo\[\]\" value=\"$balcao\" checked" "$tmp/ger.html" && ok "cupom completo com o Balcao" || falha "cupom completo sem o Balcao"
grep -q "name=\"fechamento\[\]\" value=\"[0-9]*\" checked" "$tmp/ger.html" && falha "id de fora foi aceito" || ok "impressora de fora ignorada"

echo "Caixa: uma impressora no ponto imprime direto"
r="$(enviar "$cx" "$c/balcao" "$c/mesas" nome=Impressoras)"
venda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
curl -s -b "$cx" -o "$tmp/pag.html" "$c/comandas/$venda"
read -r produto < <(php -r 'preg_match_all("~data-produto-id=\"(\d+)\" data-produto-nome=\"([^\"]*)\"~", file_get_contents($argv[1]), $m, PREG_SET_ORDER); foreach ($m as $p) { if (str_contains($p[2], "Cerveja")) { echo $p[1], "\n"; exit; } }' "$tmp/pag.html") || true
enviar "$cx" "$c/comandas/$venda/itens" "$c/comandas/$venda" produto="$produto" quantidade=1 >/dev/null
curl -s -b "$cx" -o "$tmp/pag.html" "$c/comandas/$venda"
nao_contem "sem escolha com uma impressora" "$tmp/pag.html" 'data-imprimir-escolha'
contem "botao ja leva o Balcao" "$tmp/pag.html" "name=\"impressora\" value=\"$balcao\""
esperar "caixa imprime direto" "$(imprimir "$cx" "$c/imprimir/comanda/$venda" "$c/comandas/$venda" "impressora=$balcao")" "202"
esperar "avisa a impressora" "$(json "$tmp/imp.json" mensagem)" "Enviado para a impressora Balcão."
agente "$tb" "$cursor" 0 >/dev/null
esperar "Agent antigo (sem lista) nao pega cupom do Balcao" "$(qtd_cupons)" "0"
agente "$tb" "$cursor" >/dev/null
esperar "Agent do bar nao pega cupom do Balcao" "$(qtd_cupons)" "0"
agente "$ta" "$cursor" >/dev/null
esperar "Agent do caixa pega o cupom" "$(qtd_cupons)" "1"
esperar "cupom vai para a impressora Balcao" "$(cupom impressora)" "aaaa1111bbbb"
resultado "$ta" "$(cupom id)" >/dev/null
esperar "impressora fora do ponto recusada" "$(imprimir "$cx" "$c/imprimir/comanda/$venda" "$c/comandas/$venda" "impressora=$bar")" "409"

echo "Delivery: pedido completo pelo botao do caixa; pedido novo nao imprime sozinho"
pedido="$(php tests/http/desktop-pedido-ci.php "$slug")"
curl -s -b "$cx" -o "$tmp/pag.html" "$c/delivery/$pedido"
nao_contem "delivery sem escolha (uma impressora)" "$tmp/pag.html" 'data-imprimir-escolha'
esperar "caixa imprime o pedido" "$(imprimir "$cx" "$c/imprimir/pedido/$pedido" "$c/delivery/$pedido")" "202"
agente "$ta" "$cursor" >/dev/null
esperar "PC do caixa so avisa o pedido novo" "$(alvos "$pedido")" '[]'
esperar "so o cupom pedido pelo caixa, no Balcao" "$(chaves)" 'aaaa1111bbbb'
concluir "$ta"
agente "$tb" "$cursor" >/dev/null
esperar "PC do bar so avisa" "$(alvos "$pedido")" '[]'
esperar "PC do bar sem cupom" "$(qtd_cupons)" "0"
agente "$tb" "$cursor" 0 >/dev/null
esperar "Agent antigo nao recebe a lista" "$(alvos "$pedido")" "ausente"

echo "Cupom completo com duas impressoras, a padrao marcada"
enviar "$gj" "$g/integracoes/impressoras" "$g/integracoes/impressoras" "completo[]=$balcao" "completo[]=$cozinha" "padrao_completo=$cozinha" >/dev/null
echo "Garcom: duas impressoras no ponto, a padrao marcada"
curl -s -b "$wj" -o "$tmp/pag.html" "$w"
mesa="$(php -r 'if (preg_match("~/garcom/mesas/(\d+)/abrir~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pag.html")"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$produto]=1" >/dev/null
curl -s -b "$wj" -o "$tmp/pag.html" "$w/comandas/$comanda"
contem "garcom escolhe a impressora" "$tmp/pag.html" 'Comanda inteira sai em'
grep -q "name=\"impressora\" value=\"$cozinha\" checked" "$tmp/pag.html" && ok "Cozinha ja marcada (padrao)" || falha "padrao nao marcada"
contem "Balcao como opcao" "$tmp/pag.html" "name=\"impressora\" value=\"$balcao\""
esperar "garcom nao usa impressora fora do ponto" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=toda "impressora=$bar")" "409"
esperar "garcom manda para o Balcao" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=toda "impressora=$balcao")" "202"
agente "$ta" "$cursor" >/dev/null
esperar "cupom do garcom no Balcao" "$(cupom impressora)" "aaaa1111bbbb"
resultado "$ta" "$(cupom id)" >/dev/null
esperar "sem escolher usa a padrao" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=toda)" "202"
agente "$ta" "$cursor" >/dev/null
esperar "cupom na Cozinha" "$(cupom impressora)" "cccc2222dddd"
resultado "$ta" "$(cupom id)" >/dev/null

echo "Agent tira a Cozinha da lista"
esperar "PC do caixa fica so com o Balcao" "$(registrar "$ta" '{"impressoras":[{"chave":"aaaa1111bbbb","apelido":"Balcão"}]}')" "200"
curl -s -b "$wj" -o "$tmp/pag.html" "$w/comandas/$comanda"
nao_contem "garcom volta a imprimir direto" "$tmp/pag.html" 'Comanda inteira sai em'
contem "garcom imprime no Balcao" "$tmp/pag.html" 'Sai na impressora Balcão'
curl -s -b "$gj" "$g/integracoes/impressoras" -o "$tmp/ger.html"
nao_contem "Cozinha some da gerencia" "$tmp/ger.html" '<strong>Cozinha</strong>'

enviar "$wj" "$w/comandas/$comanda/cancelar" "$w/comandas/$comanda" "motivo=Cliente desistiu" >/dev/null
enviar "$cx" "$c/comandas/$venda/cancelar" "$c/comandas/$venda" "motivo=Teste" >/dev/null
enviar "$gj" "$g/integracoes/impressoras" "$g/integracoes/impressoras" >/dev/null # limpa os pontos
curl -s -o /dev/null -X POST -H "Authorization: Bearer $ta" "$base/api/v1/desktop/desconectar"
curl -s -o /dev/null -X POST -H "Authorization: Bearer $tb" "$base/api/v1/desktop/desconectar"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) nas varias impressoras."
  exit 1
fi
echo "Varias impressoras validadas (pontos, escolha, pedido novo por computador, Agent antigo)."
