#!/usr/bin/env bash
# Testes da API de balanças: vinculação, leitura efêmera, segurança multi-restaurante e revogação.
# Uso: bash tests/http/desktop-balanca.sh <base>
set -Eeuo pipefail
base="$1"
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
post() {
  local target="$1" token="$2" body="$3"
  curl -s -o "$tmp/last.json" -w '%{http_code}' \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $token" -d "$body" "$base$target"
}

manager="$tmp/gerente"; other="$tmp/outro"; courier="$tmp/entregador"
expect "gerente autenticado" "$(login "$manager" ci_gerente)" "303"
expect "outro gerente autenticado" "$(login "$other" ci_outro_gerente)" "303"
expect "entregador autenticado" "$(login "$courier" ci_motoqueiro)" "303"

code="$(curl -s -b "$manager" -o "$tmp/csrf.json" -w '%{http_code}' "$base/api/v1/desktop/csrf")"
expect "CSRF somente equipe autorizada" "$code" "200"
csrf="$(json "$tmp/csrf.json" csrf)"
[[ "${#csrf}" -eq 64 ]] || fail "CSRF invalido"

code="$(curl -s -b "$manager" -o "$tmp/vinculo.json" -w '%{http_code}' \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    -d '{"nome":"CAIXA-BALANCA-CI"}' "$base/api/v1/desktop/vincular")"
expect "vinculo por gerente" "$code" "201"
device="$(json "$tmp/vinculo.json" device_id)"
token="$(json "$tmp/vinculo.json" token)"
[[ "$token" == dzag_* ]] || fail "Token do agente nao criado"

expect "pesagem exige token" "$(post /api/v1/desktop/pesagem falso '{"peso_kg":"0.350","estavel":true}')" "401"
expect "recusa peso acima da faixa" "$(post /api/v1/desktop/pesagem "$token" '{"peso_kg":"100.000","estavel":true}')" "422"
expect "recusa dados nao numericos" "$(post /api/v1/desktop/pesagem "$token" '{"peso_kg":"0.350 OR 1=1","estavel":true}')" "422"
expect "recusa peso estavel ausente" "$(post /api/v1/desktop/pesagem "$token" '{"peso_kg":null,"estavel":true}')" "422"
expect "recebe 350 g" "$(post /api/v1/desktop/pesagem "$token" '{"peso_kg":"0.350","estavel":true}')" "200"

code="$(curl -s -b "$manager" -o "$tmp/balancas.json" -w '%{http_code}' "$base/api/v1/desktop/balancas")"
expect "gerente consulta balancas" "$code" "200"
php -r '$d=json_decode(file_get_contents($argv[1]),true);foreach($d["balancas"]??[] as $b){if($b["id"]===(int)$argv[2] && $b["peso_kg"]==="0.350" && $b["estavel"]===true)exit(0);}exit(1);' "$tmp/balancas.json" "$device" || fail "peso estavel nao aparece no restaurante"
ok "peso estavel mostrado no restaurante"

code="$(curl -s -b "$other" -o "$tmp/outra-loja.json" -w '%{http_code}' "$base/api/v1/desktop/balancas")"
expect "outra loja consulta apenas o proprio escopo" "$code" "200"
php -r '$d=json_decode(file_get_contents($argv[1]),true);foreach($d["balancas"]??[] as $b){if($b["id"]===(int)$argv[2])exit(1);}exit(0);' "$tmp/outra-loja.json" "$device" || fail "vazamento entre restaurantes"
ok "sem vazamento de balanca para outra loja"
expect "entregador nao consulta pesagens" "$(curl -s -b "$courier" -o /dev/null -w '%{http_code}' "$base/api/v1/desktop/balancas")" "403"
expect "sem sessao nao consulta pesagens" "$(curl -s -o /dev/null -w '%{http_code}' "$base/api/v1/desktop/balancas")" "403"

expect "limpa leitura instavel" "$(post /api/v1/desktop/pesagem "$token" '{"peso_kg":null,"estavel":false}')" "200"
curl -s -b "$manager" "$base/api/v1/desktop/balancas" > "$tmp/balancas.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true);foreach($d["balancas"]??[] as $b){if($b["id"]===(int)$argv[2] && $b["peso_kg"]===null && $b["estavel"]===false)exit(0);}exit(1);' "$tmp/balancas.json" "$device" || fail "balanca instavel ainda exposta"
ok "leitura instavel nao fica disponivel"
expect "revoga dispositivo" "$(post /api/v1/desktop/desconectar "$token" '{}')" "200"
expect "dispositivo revogado nao envia peso" "$(post /api/v1/desktop/pesagem "$token" '{"peso_kg":"0.350","estavel":true}')" "401"

curl --fail --silent --show-error "$base/assets/js/balanca-caixa.js" -o "$tmp/bridge.js"
grep -q 'Usar ' "$tmp/bridge.js" || fail "acao manual da balanca ausente"
ok "script de uso manual disponivel"
echo "API de balancas validada."
