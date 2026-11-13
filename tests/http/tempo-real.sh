#!/usr/bin/env bash
# Teste ponta a ponta do tempo real: API do ticket + servidor WebSocket (bin/tempo-real.php) + eventos da Outbox.
# Uso: bash tests/http/tempo-real.sh <base-http> <slug> <url-ws> <origem>
# Exige o Cardapim com WS_ATIVO=1/WS_URL e o servidor de tempo real rodando (ver CI).
set -Eeuo pipefail
base="$1"
slug="$2"
ws="$3"
origem="$4"
g="$base/$slug/gerencia"
senha='Senha#CI2026'
raiz="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; cat "$2"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
enviar() { # jar caminho campos... -> "codigo location"
  local jar="$1" caminho="$2" t args=(); shift 2
  t="$(token "$jar" "$g/estoque/categorias")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$g$caminho"
}
ticket() { # jar [slug] -> ticket (vazio se negado)
  curl -s -b "$1" "$base/api/v1/tempo-real/ticket?restaurante=${2:-$slug}" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? ($d["ticket"] ?? "") : "";'
}
cliente() { php "$raiz/tests/realtime/cliente.php" --url="$ws" --origem="$origem" "$@"; }

gj="$tmp/g"; cj="$tmp/c"; mj="$tmp/m"; oj="$tmp/o"
esperar "gerente entra" "$(login "$gj" ci_gerente)" "303"
esperar "caixa entra" "$(login "$cj" ci_caixa)" "303"
esperar "entregador entra" "$(login "$mj" ci_motoqueiro)" "303"
esperar "gerente de outro restaurante entra" "$(login "$oj" ci_outro_gerente)" "303"

echo "Ticket"
esperar "sem login: 401" "$(curl -s -o /dev/null -w '%{http_code}' "$base/api/v1/tempo-real/ticket?restaurante=$slug")" "401"
esperar "restaurante de outro: 403" "$(curl -s -o /dev/null -w '%{http_code}' -b "$oj" "$base/api/v1/tempo-real/ticket?restaurante=$slug")" "403"
esperar "slug invalido: 403" "$(curl -s -o /dev/null -w '%{http_code}' -b "$gj" "$base/api/v1/tempo-real/ticket?restaurante=../x")" "403"
curl -s -D "$tmp/h.txt" -o "$tmp/t.json" -b "$gj" "$base/api/v1/tempo-real/ticket?restaurante=$slug"
contem "ticket entregue ao gerente" "$tmp/t.json" '"ticket":"'
contem "endereco do WebSocket" "$tmp/t.json" "\"url\":\"$ws\""
contem "ticket nao vai para cache" "$tmp/h.txt" 'no-store'

echo "Aperto de mao"
esperar "sem ticket: 401" "$(cliente --ticket= --tempo=3 | tail -1)" "HTTP 401"
esperar "ticket adulterado: 401" "$(cliente --ticket="$(ticket "$gj")x" --tempo=3 | tail -1)" "HTTP 401"
esperar "outra origem: 403" "$(cliente --ticket="$(ticket "$gj")" --origem=https://site-malicioso.test --tempo=3 | tail -1)" "HTTP 403"
t="$(ticket "$gj")"
cliente --ticket="$t" --tempo=4 > "$tmp/primeira.txt" && ok "gerente conecta e assina" || falha "gerente nao conectou: $(cat "$tmp/primeira.txt")"
esperar "ticket usado de novo: 401" "$(cliente --ticket="$t" --tempo=3 | tail -1)" "HTTP 401"

echo "Eventos"
nome="Tempo real CI $(date +%s)"
cliente --ticket="$(ticket "$gj")" --canais=estoque,geral --esperar=estoque.alterado --tempo=12 > "$tmp/ger.txt" & pg=$!
cliente --ticket="$(ticket "$cj")" --canais=estoque,financeiro --esperar=estoque.alterado --tempo=12 > "$tmp/cai.txt" & pc=$!
cliente --ticket="$(ticket "$mj")" --canais=estoque,entregas --esperar=estoque.alterado --tempo=5 > "$tmp/ent.txt" & pm=$!
cliente --ticket="$(ticket "$oj" ci-outro-restaurante)" --canais=estoque --esperar=estoque.alterado --tempo=5 > "$tmp/out.txt" & po=$!
sleep 1.5
inicio=$(date +%s%N)
esperar "gerente cria categoria" "$(enviar "$gj" /estoque/categorias nome="$nome" | cut -d' ' -f1)" "303"
wait "$pg" && ok "gerente recebe estoque.alterado" || falha "gerente nao recebeu: $(cat "$tmp/ger.txt")"
fim=$(date +%s%N)
ms=$(( (fim - inicio) / 1000000 ))
if (( ms < 3000 )); then ok "entregue em ${ms} ms"; else falha "demorou ${ms} ms"; fi
wait "$pc" && ok "caixa recebe estoque.alterado" || falha "caixa nao recebeu: $(cat "$tmp/cai.txt")"
contem "caixa assinou estoque" "$tmp/cai.txt" '"canais":\["estoque"\]'
nao_contem "caixa nao pode ouvir financeiro" "$tmp/cai.txt" '"financeiro"\]'
set +e; wait "$pm"; rm_=$?; wait "$po"; ro=$?; set -e
esperar "entregador NAO recebe estoque" "$rm_" "3"
esperar "outro restaurante NAO recebe" "$ro" "3"
contem "evento traz area e acao" "$tmp/ger.txt" '"area":"categoria","acao":"criado"'
nao_contem "evento sem nome da categoria" "$tmp/ger.txt" "$nome"

echo "Reconexao"
id="$(grep -o '"id":[0-9]*,"tipo":"estoque.alterado"' "$tmp/ger.txt" | head -1 | grep -o '[0-9]*' | head -1)"
cliente --ticket="$(ticket "$gj")" --canais=estoque --desde=$((id - 1)) --esperar=estoque.alterado --tempo=5 > "$tmp/rep.txt" && ok "evento perdido reenviado ao reconectar" || falha "nao reenviou: $(cat "$tmp/rep.txt")"
cliente --ticket="$(ticket "$cj")" --canais=financeiro --desde=$((id - 1)) --esperar=estoque.alterado --tempo=3 > "$tmp/rep2.txt" && falha "reenvio ignorou permissao" || ok "reenvio respeita os canais"

echo "Mensagens"
cliente --ticket="$(ticket "$gj")" --mensagem='{"acao":"ping"}' --esperar=sistema.pong --tempo=4 >/dev/null && ok "ping/pong" || falha "sem pong"
cliente --ticket="$(ticket "$gj")" --mensagem='nao e json' --esperar=sistema.erro --tempo=4 >/dev/null && ok "mensagem invalida respondida com erro" || falha "mensagem invalida sem resposta"

echo "Status"
aj="$tmp/a"
esperar "adm master entra" "$(login "$aj" ci_master)" "303"
curl -s -b "$aj" -H 'Accept: application/json' "$base/status" > "$tmp/s.json"
contem "status (adm master) mostra o tempo real operando" "$tmp/s.json" '"tempo_real":{"situacao":"operacional"'
curl -s -H 'Accept: application/json' "$base/status" > "$tmp/s2.json"
nao_contem "status publico nao mostra detalhes" "$tmp/s2.json" 'tempo_real'

# Limpeza: remove a categoria do teste (gera outro evento, sem problema).
idc="$(curl -s -b "$gj" "$g/estoque/categorias" | grep -B30 -- "$nome" | grep -o 'categorias/[0-9]*/excluir' | tail -1 | grep -o '[0-9]*' || true)"
[[ -n "$idc" ]] && enviar "$gj" "/estoque/categorias/$idc/excluir" >/dev/null || true

if (( falhas > 0 )); then
  echo "Tempo real: $falhas falha(s)."
  exit 1
fi
echo "Tempo real: todos os cenarios OK."
