#!/usr/bin/env bash
# Deliveryzim Agent: "Receber" no aviso aceita o pedido (como o Aceitar do caixa), situacao dos pedidos
# avisados, isolamento entre restaurantes e "Agent conectado" (navegador sem som repetido).
# Uso: bash tests/http/desktop-pedidos.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
senha='Senha#CI2026'

ok() { printf '  ok  %s\n' "$1"; }
fail() { printf '  FALHOU %s\n' "$1"; exit 1; }
expect() {
  local desc="$1" got="$2" expected="$3"
  [[ "$got" == "$expected" ]] && ok "$desc" || fail "$desc (obtido: $got, esperado: $expected)"
}
json() {
  php -r '$d=json_decode(file_get_contents($argv[1]),true); $x=$d;foreach(explode(".",$argv[2]) as $key) {$x=is_array($x)&&array_key_exists($key,$x)?$x[$key]:null;}echo is_bool($x)?($x?"true":"false"):($x===null?"null":(is_scalar($x)?(string)$x:json_encode($x)));' "$1" "$2"
}
login() {
  local jar="$1" user="$2" t
  t="$(curl -s -c "$jar" -b "$jar" "$base/login" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"$//')"
  curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' \
    --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$user" \
    --data-urlencode "senha=$senha" "$base/login"
}
vincular() {
  local jar="$1" nome="$2" csrf
  curl -s -b "$jar" -o "$tmp/csrf.json" "$base/api/v1/desktop/csrf"
  csrf="$(json "$tmp/csrf.json" csrf)"
  curl -s -b "$jar" -o "$tmp/vinculo.json" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    -d "{\"nome\":\"$nome\"}" "$base/api/v1/desktop/vincular"
  json "$tmp/vinculo.json" token
}
api() {
  local method="$1" token="$2" path="$3"
  curl -s -o "$tmp/last.json" -w '%{http_code}' -X "$method" -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' "$base$path"
}

caixa="$tmp/caixa"; outro="$tmp/outro"; gerente="$tmp/gerente"; garcom="$tmp/garcom"
expect "caixa autenticado" "$(login "$caixa" ci_caixa)" "303"
expect "gerente autenticado" "$(login "$gerente" ci_gerente)" "303"
expect "outro gerente autenticado" "$(login "$outro" ci_outro_gerente)" "303"
expect "garcom autenticado" "$(login "$garcom" ci_garcom)" "303"

# Sem Agent conectado o navegador toca normalmente.
curl -s -b "$gerente" -o "$tmp/ativo.json" "$base/api/v1/desktop/agente-ativo"
expect "sem Agent conectado o navegador toca" "$(json "$tmp/ativo.json" ativo)" "false"

token="$(vincular "$caixa" "CAIXA-AGENT-CI")"
[[ "$token" == dzag_* ]] || fail "Agent nao vinculado"
cursor="$(json "$tmp/vinculo.json" cursor)"
token_outro="$(vincular "$outro" "OUTRO-AGENT-CI")"
[[ "$token_outro" == dzag_* ]] || fail "Agent do outro restaurante nao vinculado"

pedido="$(php tests/http/desktop-pedido-ci.php "$slug")"
[[ "$pedido" =~ ^[0-9]+$ ]] || fail "pedido de teste nao criado"

# O Agent consulta os eventos (isso tambem marca o Agent como conectado).
expect "Agent consulta eventos" "$(api GET "$token" "/api/v1/desktop/eventos?desde=$cursor")" "200"
php -r '$d=json_decode(file_get_contents($argv[1]),true);foreach($d["pedidos"]??[] as $p){if($p["id"]===(int)$argv[2] && $p["status"]==="recebido" && str_ends_with($p["url"],"/caixa/delivery/".$argv[2]) && str_starts_with($p["url"],"/".$argv[3]."/"))exit(0);}exit(1);' \
  "$tmp/last.json" "$pedido" "$slug" || fail "evento sem pedido Novo ou sem link Ver no sistema"
ok "evento traz o pedido Novo e o link Ver no sistema"

# Agent conectado: navegador de TODOS os aparelhos do restaurante fica sem som repetido.
curl -s -b "$gerente" -o "$tmp/ativo.json" "$base/api/v1/desktop/agente-ativo"
expect "gerente ve o Agent conectado" "$(json "$tmp/ativo.json" ativo)" "true"
curl -s -b "$caixa" -o "$tmp/ativo.json" "$base/api/v1/desktop/agente-ativo"
expect "caixa ve o Agent conectado" "$(json "$tmp/ativo.json" ativo)" "true"
curl -s -b "$garcom" -o "$tmp/ativo.json" "$base/api/v1/desktop/agente-ativo"
expect "garcom nao recebe informacao do Agent" "$(json "$tmp/ativo.json" ativo)" "false"
curl -s -o "$tmp/ativo.json" "$base/api/v1/desktop/agente-ativo"
expect "sem login nao recebe informacao do Agent" "$(json "$tmp/ativo.json" ativo)" "false"

# Situacao dos pedidos avisados.
expect "situacao exige token" "$(api GET falso "/api/v1/desktop/pedidos/situacao?ids=$pedido")" "401"
expect "situacao exige ids" "$(api GET "$token" "/api/v1/desktop/pedidos/situacao?ids=")" "422"
expect "situacao do pedido Novo" "$(api GET "$token" "/api/v1/desktop/pedidos/situacao?ids=$pedido")" "200"
expect "pedido ainda esta Novo" "$(json "$tmp/last.json" pedidos.0.status)" "recebido"
expect "outro restaurante consulta" "$(api GET "$token_outro" "/api/v1/desktop/pedidos/situacao?ids=$pedido")" "200"
expect "outro restaurante nao ve o pedido" "$(json "$tmp/last.json" pedidos)" "[]"

# Receber = aceitar.
expect "receber exige token" "$(api POST falso "/api/v1/desktop/pedidos/$pedido/receber")" "401"
expect "outro restaurante nao aceita o pedido" "$(api POST "$token_outro" "/api/v1/desktop/pedidos/$pedido/receber")" "404"
expect "pedido inexistente" "$(api POST "$token" "/api/v1/desktop/pedidos/999999999/receber")" "404"
expect "Agent recebe (aceita) o pedido" "$(api POST "$token" "/api/v1/desktop/pedidos/$pedido/receber")" "200"
expect "pedido passa para Em preparo" "$(json "$tmp/last.json" status)" "em_preparo"
expect "receber de novo avisa que ja mudou" "$(api POST "$token" "/api/v1/desktop/pedidos/$pedido/receber")" "409"
expect "409 informa a situacao atual" "$(json "$tmp/last.json" status)" "em_preparo"
api GET "$token" "/api/v1/desktop/pedidos/situacao?ids=$pedido" >/dev/null
expect "situacao mostra Em preparo (Agent para de tocar)" "$(json "$tmp/last.json" pedidos.0.status)" "em_preparo"

# Aceitar no caixa tambem faz o Agent parar: segundo pedido aceito pelo caixa.
pedido2="$(php tests/http/desktop-pedido-ci.php "$slug")"
t="$(curl -s -b "$gerente" "$base/$slug/caixa/delivery/$pedido2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"$//')"
curl -s -b "$gerente" -o /dev/null --data-urlencode "_csrf=$t" "$base/$slug/caixa/delivery/$pedido2/aceitar"
api GET "$token" "/api/v1/desktop/pedidos/situacao?ids=$pedido2" >/dev/null
expect "aceite pelo caixa aparece para o Agent" "$(json "$tmp/last.json" pedidos.0.status)" "em_preparo"

# Agent desvinculado: o navegador volta a tocar e o token nao aceita mais nada.
expect "desvincula o Agent" "$(api POST "$token" "/api/v1/desktop/desconectar")" "200"
expect "desvincula o Agent do outro restaurante" "$(api POST "$token_outro" "/api/v1/desktop/desconectar")" "200"
curl -s -b "$gerente" -o "$tmp/ativo.json" "$base/api/v1/desktop/agente-ativo"
expect "sem Agent o navegador volta a tocar" "$(json "$tmp/ativo.json" ativo)" "false"
pedido3="$(php tests/http/desktop-pedido-ci.php "$slug")"
expect "Agent desvinculado nao aceita pedido" "$(api POST "$token" "/api/v1/desktop/pedidos/$pedido3/receber")" "401"

curl --fail --silent --show-error "$base/assets/js/alerta-pedido.js" -o "$tmp/alerta.js"
grep -q '/api/v1/desktop/agente-ativo' "$tmp/alerta.js" || fail "navegador nao confere o Agent"
grep -q 'Som no Agent' "$tmp/alerta.js" || fail "botao sem o estado Som no Agent"
ok "navegador confere o Agent e mostra Som no Agent"
echo "Agent: receber pedido e som validados."
