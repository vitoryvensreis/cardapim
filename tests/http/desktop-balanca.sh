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
# Uma unica balanca ligada no restaurante: o caixa usa sem precisar abrir pelo Agent.
expect "unica balanca ligada e usada sem pareamento" "$(json "$tmp/balancas.json" selecionada.id)" "$device"
expect "resposta avisa que foi automatico" "$(json "$tmp/balancas.json" automatica)" "true"
expect "token obrigatorio para criar vinculo" "$(post /api/v1/desktop/pareamento falso '{}')" "401"
expect "agente cria link de pareamento" "$(post /api/v1/desktop/pareamento "$token" '{}')" "200"
link="$(json "$tmp/last.json" url)"
[[ "$link" == /api/v1/desktop/parear\?ticket=* ]] || fail "link de balanca ausente"
# Sem login: vai para o /login e conclui o vinculo logo depois de entrar.
anon="$tmp/anonimo"
code="$(curl -s -c "$anon" -b "$anon" -o /dev/null -w '%{http_code} %{redirect_url}' "$base$link&destino=gerencia")"
[[ "$code" == "303 "*"/login" ]] || fail "sem login deveria ir para o /login (obtido: $code)"
ok "sem login vai para o /login"
expect "gerente entra depois do link do Agent" "$(login "$anon" ci_gerente)" "303"
code="$(curl -s -c "$anon" -b "$anon" -o /dev/null -w '%{http_code} %{redirect_url}' "$base/api/v1/desktop/parear")"
[[ "$code" == "302 "*"/gerencia" ]] || fail "depois do login deveria concluir o vinculo e abrir a gerencia (obtido: $code)"
ok "depois do login conclui o vinculo e abre a gerencia"
grep -q 'deliveryzim_balanca' "$anon" || fail "cookie da balanca nao gravado depois do login"
ok "cookie da balanca gravado depois do login"
code="$(curl -s -b "$other" -c "$tmp/outro-cookies" -o /dev/null -w '%{http_code}' "$base$link")"
expect "outro restaurante abre o proprio painel" "$code" "302"
! grep -q 'deliveryzim_balanca' "$tmp/outro-cookies" || fail "outro restaurante recebeu a balanca"
ok "outro restaurante nao assume balanca"
expect "navegador do caixa recebe o vinculo" "$(curl -s -b "$manager" -c "$manager" -o /dev/null -w '%{http_code}' "$base$link")" "302"
curl -s -b "$manager" "$base/api/v1/desktop/balancas" > "$tmp/balancas-pareadas.json"
expect "balanca correta associada ao navegador" "$(json "$tmp/balancas-pareadas.json" selecionada.id)" "$device"

php -r '$d=json_decode(file_get_contents($argv[1]),true);foreach($d["balancas"]??[] as $b){if($b["id"]===(int)$argv[2] && $b["peso_kg"]==="0.350" && $b["estavel"]===true)exit(0);}exit(1);' "$tmp/balancas.json" "$device" || fail "peso estavel nao aparece no restaurante"
ok "peso estavel mostrado no restaurante"

code="$(curl -s -b "$other" -o "$tmp/outra-loja.json" -w '%{http_code}' "$base/api/v1/desktop/balancas")"
expect "outra loja consulta apenas o proprio escopo" "$code" "200"
php -r '$d=json_decode(file_get_contents($argv[1]),true);foreach($d["balancas"]??[] as $b){if($b["id"]===(int)$argv[2])exit(1);}exit(0);' "$tmp/outra-loja.json" "$device" || fail "vazamento entre restaurantes"
ok "sem vazamento de balanca para outra loja"
expect "outro restaurante nao recebe dispositivo selecionado" "$(json "$tmp/outra-loja.json" selecionada)" "null"
# Mesmo com mais de uma balanca da mesma loja, este computador nao troca automaticamente de origem.
code="$(curl -s -b "$manager" -o "$tmp/vinculo2.json" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" -d '{"nome":"SEGUNDO-CAIXA-CI"}' "$base/api/v1/desktop/vincular")"
expect "segundo computador vinculado" "$code" "201"
token2="$(json "$tmp/vinculo2.json" token)"
expect "outro equipamento envia peso" "$(post /api/v1/desktop/pesagem "$token2" '{"peso_kg":"0.700","estavel":true}')" "200"
curl -s -b "$manager" "$base/api/v1/desktop/balancas" > "$tmp/balancas-multiplas.json"
expect "multiplas balancas nao alteram origem do caixa" "$(json "$tmp/balancas-multiplas.json" selecionada.id)" "$device"

expect "entregador nao consulta pesagens" "$(curl -s -b "$courier" -o /dev/null -w '%{http_code}' "$base/api/v1/desktop/balancas")" "403"
expect "sem sessao nao consulta pesagens" "$(curl -s -o /dev/null -w '%{http_code}' "$base/api/v1/desktop/balancas")" "403"

expect "limpa leitura instavel" "$(post /api/v1/desktop/pesagem "$token" '{"peso_kg":null,"estavel":false}')" "200"
curl -s -b "$manager" "$base/api/v1/desktop/balancas" > "$tmp/balancas.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true);foreach($d["balancas"]??[] as $b){if($b["id"]===(int)$argv[2] && $b["peso_kg"]===null && $b["estavel"]===false)exit(0);}exit(1);' "$tmp/balancas.json" "$device" || fail "balanca instavel ainda exposta"
ok "leitura instavel nao fica disponivel"
expect "revoga dispositivo" "$(post /api/v1/desktop/desconectar "$token" '{}')" "200"
expect "dispositivo revogado nao envia peso" "$(post /api/v1/desktop/pesagem "$token" '{"peso_kg":"0.350","estavel":true}')" "401"
curl -s -b "$manager" "$base/api/v1/desktop/balancas" > "$tmp/balancas-revogadas.json"
device2="$(json "$tmp/vinculo2.json" device_id)"
expect "revogada nao e usada; a unica ligada que sobrou entra" "$(json "$tmp/balancas-revogadas.json" selecionada.id)" "$device2"
expect "revoga segundo agente" "$(post /api/v1/desktop/desconectar "$token2" '{}')" "200"

curl --fail --silent --show-error "$base/assets/js/balanca-caixa.js" -o "$tmp/bridge.js"
grep -q 'data-balanca-automatica' "$tmp/bridge.js" || fail "checkbox automatico ausente"
! grep -q 'data-balanca-seletor' "$tmp/bridge.js" || fail "seletor antigo encontrado"
ok "captura automatica com fallback manual e sem seletor"
echo "API de balancas validada."
