#!/usr/bin/env bash
# Teste HTTP do cardapio publico /{slug}, da sacola e do login do cliente pelo WhatsApp da plataforma.
# Usa o servidor FALSO tests/fakes/integracoes-fake.php (o codigo e lido da mensagem "enviada" ao fake).
# Uso: bash tests/http/cardapio.sh <base> <slug> <base-do-fake>
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
senha='Senha#CI2026'
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
json() { php -r '$d = json_decode(file_get_contents($argv[1]), true); $v = $d; foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : ($v === null ? "null" : json_encode($v)));' "$1" "$2"; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login_equipe() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
sessao() { curl -s -c "$1" -b "$1" -o "$tmp/sessao.json" "$base/api/v1/cliente"; json "$tmp/sessao.json" csrf; }
api() { # jar csrf caminho json -> codigo http (corpo em $tmp/r.json)
  curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" --data "$4" "$base/api/v1/cliente/$3"
}
ultimo_codigo() { curl -s "$fake/_teste/wpp-mensagens" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $l = $d["linhas"] ?? []; for ($i = count($l) - 1; $i >= 0; $i--) { if (strpos($l[$i], $argv[1]) !== false && preg_match("/\*(\d{6})\*/", $l[$i], $m)) { echo $m[1]; exit; } }' "$1"; }

echo "Cardapio"
esperar "pagina do restaurante" "$(curl -s -o "$tmp/c.html" -w '%{http_code}' "$base/$slug")" "200"
contem "nome do restaurante" "$tmp/c.html" 'id="cd-nome"'
contem "abas de categoria" "$tmp/c.html" 'data-aba="c'
contem "produtos com preco" "$tmp/c.html" 'data-centavos="'
contem "preco formatado" "$tmp/c.html" 'R$ '
contem "busca no cardapio" "$tmp/c.html" 'data-busca'
contem "barra da sacola" "$tmp/c.html" 'data-abrir-sacola'
contem "login pelo WhatsApp" "$tmp/c.html" 'autocomplete="one-time-code"'
contem "CSS do cardapio" "$tmp/c.html" '/assets/css/cardapio.css'
nao_contem "nunca mostra custo nem ficha" "$tmp/c.html" 'custo'
nao_contem "sem estilos inline" "$tmp/c.html" 'style="'
nao_contem "sem script inline" "$tmp/c.html" '<script>'
esperar "restaurante inexistente: 404" "$(curl -s -o /dev/null -w '%{http_code}' "$base/restaurante-que-nao-existe")" "404"
for arq in /assets/css/cardapio.css /assets/js/cardapio.js /assets/js/cliente.js; do
  esperar "asset $arq" "$(curl -s -o /dev/null -w '%{http_code}' "$base$arq")" "200"
done

echo "Sacola (conferencia no servidor)"
# Botoes dos produtos ocupam varias linhas no HTML: le com PHP (id, centavos e se esta desabilitado).
produtos="$(php -r 'preg_match_all("/<button[^>]*data-produto=\"(\d+)\"[^>]*data-centavos=\"(\d+)\"([^>]*)>/s", file_get_contents($argv[1]), $m, PREG_SET_ORDER); foreach ($m as $p) { echo $p[1], " ", $p[2], " ", (str_contains($p[3], "disabled") ? "indisponivel" : "ok"), "\n"; }' "$tmp/c.html")"
id1="$(echo "$produtos" | awk '$3=="ok"{print $1; exit}')"
centavos1="$(echo "$produtos" | awk '$3=="ok"{print $2; exit}')"
curl -s -o "$tmp/s.json" -H 'Content-Type: application/json' --data "{\"itens\":[{\"id\":$id1,\"quantidade\":2},{\"id\":99999999,\"quantidade\":1},{\"id\":\"x\"}]}" "$base/api/v1/restaurantes/$slug/sacola"
esperar "preco de agora vem do servidor" "$(json "$tmp/s.json" itens.0.centavos)" "$centavos1"
esperar "produto que nao existe volta como removido" "$(json "$tmp/s.json" removidos.0)" "99999999"
esperar "sacola invalida: 400" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data 'lixo' "$base/api/v1/restaurantes/$slug/sacola")" "400"
esperar "sacola de restaurante inexistente: 404" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data '{"itens":[]}' "$base/api/v1/restaurantes/nao-existe-mesmo/sacola")" "404"
indisp="$(echo "$produtos" | awk '$3=="indisponivel"{print $1; exit}')"
if [[ -n "$indisp" ]]; then
  curl -s -o "$tmp/s2.json" -H 'Content-Type: application/json' --data "{\"itens\":[{\"id\":$indisp,\"quantidade\":1}]}" "$base/api/v1/restaurantes/$slug/sacola"
  esperar "produto indisponivel marcado na sacola" "$(json "$tmp/s2.json" itens.0.disponivel)" "false"
fi

echo "Login do cliente: WhatsApp da plataforma ainda desconectado"
cj="$tmp/cliente"
csrf="$(sessao "$cj")"
[[ ${#csrf} -eq 64 ]] && ok "sessao entrega token CSRF" || falha "sem CSRF: $(cat "$tmp/sessao.json")"
esperar "ninguem conectado" "$(json "$tmp/sessao.json" cliente)" "null"
esperar "login indisponivel sem o WhatsApp da plataforma" "$(json "$tmp/sessao.json" login_disponivel)" "false"
esperar "pedir codigo sem WhatsApp da plataforma: 503" "$(api "$cj" "$csrf" codigo '{"telefone":"85988887777"}')" "503"

echo "Administrador conecta o WhatsApp da plataforma"
aj="$tmp/admin"
esperar "administrador entra" "$(login_equipe "$aj" ci_master)" "303"
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/conectar"
# A sessao mais recente (ls -t), pois outras suites podem ter deixado sessoes antigas.
sessao_wpp="$(ls -t "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-cardapim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
curl -s "$fake/_teste/wpp-conectar/$sessao_wpp" >/dev/null
curl -s -b "$aj" "$base/painel/integracoes/whatsapp/estado" | grep -q '"estado":"conectado"' && ok "WhatsApp da plataforma conectado" || falha "WhatsApp da plataforma nao conectou"

echo "Cadastro: numero -> codigo -> nome"
csrf="$(sessao "$cj")"
esperar "login disponivel" "$(json "$tmp/sessao.json" login_disponivel)" "true"
esperar "sem CSRF: 419" "$(curl -s -o /dev/null -w '%{http_code}' -b "$cj" -H 'Content-Type: application/json' --data '{"telefone":"85988887777"}' "$base/api/v1/cliente/codigo")" "419"
esperar "numero invalido: 422" "$(api "$cj" "$csrf" codigo '{"telefone":"123"}')" "422"
esperar "pedir codigo" "$(api "$cj" "$csrf" codigo '{"telefone":"(85) 98888-7777"}')" "200"
esperar "numero formatado na resposta" "$(json "$tmp/r.json" telefone)" "(85) 98888-7777"
codigo="$(ultimo_codigo "$sessao_wpp|5585988887777|")"
[[ "$codigo" =~ ^[0-9]{6}$ ]] && ok "codigo enviado pela sessao da plataforma com 55 + DDD" || falha "codigo nao chegou ao WhatsApp da plataforma"
esperar "pedir de novo antes de 60 s: 429" "$(api "$cj" "$csrf" codigo '{"telefone":"85988887777"}')" "429"
errado="$( [[ "$codigo" == "000000" ]] && echo 111111 || echo 000000 )"
esperar "codigo errado: 422" "$(api "$cj" "$csrf" entrar "{\"codigo\":\"$errado\"}")" "422"
contem "avisa quantas tentativas restam" "$tmp/r.json" 'tentativas'
esperar "codigo certo" "$(api "$cj" "$csrf" entrar "{\"codigo\":\"$codigo\"}")" "200"
esperar "numero novo pede o nome" "$(json "$tmp/r.json" etapa)" "nome"
esperar "ainda nao conectado antes do nome" "$(json "$tmp/r.json" cliente)" "null"
csrf="$(json "$tmp/r.json" csrf)"
esperar "codigo nao vale duas vezes" "$(api "$cj" "$csrf" entrar "{\"codigo\":\"$codigo\"}")" "422"
esperar "nome invalido: 422" "$(api "$cj" "$csrf" cadastro '{"nome":"1"}')" "422"
esperar "criar conta" "$(api "$cj" "$csrf" cadastro '{"nome":"  Cliente   Teste CI "}')" "200"
esperar "cliente conectado" "$(json "$tmp/r.json" cliente.primeiro_nome)" "Cliente"
esperar "nome limpo" "$(json "$tmp/r.json" cliente.nome)" "Cliente Teste CI"
grep -q 'cardapim_cliente' "$cj" && ok "aparelho lembrado (cookie do cliente)" || falha "cookie do cliente ausente"
nao_contem "resposta nao traz CPF nem endereco" "$tmp/r.json" 'cpf'

echo "Continua conectado e sai"
lj="$tmp/so-cookie"
grep -E 'cardapim_cliente' "$cj" > "$lj" || true
sessao "$lj" >/dev/null
esperar "aparelho lembrado entra sozinho" "$(json "$tmp/sessao.json" cliente.nome)" "Cliente Teste CI"
csrf="$(sessao "$cj")"
esperar "sair" "$(api "$cj" "$csrf" sair '{}')" "200"
esperar "desconectado" "$(json "$tmp/r.json" cliente)" "null"
grep -E 'cardapim_cliente' "$cj" | grep -q '[a-f0-9]\{24\}:' && falha "cookie continua valido apos sair" || ok "cookie apagado ao sair"

echo "Login de quem ja tem conta"
cj2="$tmp/cliente2"
csrf="$(sessao "$cj2")"
sleep 1
esperar "pedir codigo (outro aparelho)" "$(api "$cj2" "$csrf" codigo '{"telefone":"85988887777"}')" "200"
codigo="$(ultimo_codigo "$sessao_wpp|5585988887777|")"
esperar "entrar com o codigo" "$(api "$cj2" "$csrf" entrar "{\"codigo\":\"$codigo\"}")" "200"
esperar "conta existente entra direto" "$(json "$tmp/r.json" etapa)" "pronto"
esperar "mesmo cliente" "$(json "$tmp/r.json" cliente.nome)" "Cliente Teste CI"
contem "boas-vindas pelo nome" "$tmp/r.json" 'Cliente'

echo "Limpeza"
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/desconectar"
ok "WhatsApp da plataforma desconectado"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no cardapio."
  exit 1
fi
echo "OK: cardapio publico, sacola e login do cliente pelo WhatsApp da plataforma."
