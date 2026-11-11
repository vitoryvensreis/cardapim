#!/usr/bin/env bash
# Teste HTTP de "cada um no seu lugar":
# - Equipe logada (administrador, gerente, caixa, garcom, motoqueiro) nao ve o site, o catalogo, o cadastro,
#   o login nem cardapios: volta para o proprio painel. O gerente ainda abre o cardapio do proprio restaurante.
# - Cliente no cardapio nao tem caminho para outros restaurantes (sem seta, sem link do catalogo) e as paginas
#   legais abertas pelo cardapio so oferecem "Voltar ao cardapio".
# Uso: bash tests/http/equipe-no-painel.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
senha='Senha#CI2026'
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login_equipe() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login" >/dev/null
}
desvio() { curl -s -o /dev/null -b "$1" -w '%{http_code} %{redirect_url}' "$base$2"; }

echo "Equipe logada fica no painel"
declare -A destinos=(
  [ci_master]="$base/painel"
  [ci_gerente]="$base/$slug/gerencia"
  [ci_caixa]="$base/$slug/caixa"
  [ci_garcom]="$base/$slug/garcom"
  [ci_motoqueiro]="$base/$slug/entregas"
)
for usuario in ci_master ci_gerente ci_caixa ci_garcom ci_motoqueiro; do
  jar="$tmp/$usuario"
  login_equipe "$jar" "$usuario"
  destino="${destinos[$usuario]}"
  for caminho in / /catalogo /cadastro /privacidade "/termos-de-uso?r=$slug"; do
    obtido="$(desvio "$jar" "$caminho")"
    esperar "$usuario em $caminho volta para o painel" "${obtido%%\?*}" "303 $destino"
  done
  obtido="$(desvio "$jar" /login)"
  esperar "$usuario em /login volta para o painel" "${obtido#* }" "$destino"
  if [[ "$usuario" != "ci_gerente" ]]; then
    esperar "$usuario no cardapio volta para o painel" "$(desvio "$jar" "/$slug")" "303 $destino"
    esperar "$usuario no finalizar volta para o painel" "$(desvio "$jar" "/$slug/finalizar")" "303 $destino"
  fi
done
esperar "gerente ainda abre o cardapio do proprio restaurante" "$(desvio "$tmp/ci_gerente" "/$slug")" "200 "
esperar "gerente nao abre o cardapio de outro restaurante" "$(desvio "$tmp/ci_gerente" /outro-restaurante-qualquer)" "303 $base/$slug/gerencia"

echo "Cliente fica no restaurante"
curl -s -D "$tmp/h.txt" -o "$tmp/cardapio.html" "$base/$slug"
nao_contem "cardapio sem link para o catalogo" "$tmp/cardapio.html" 'href="/catalogo"'
nao_contem "cardapio sem link para o site" "$tmp/cardapio.html" 'href="/"'
nao_contem "cardapio sem seta de voltar" "$tmp/cardapio.html" 'class="cd-topo-btn"'
contem "privacidade abre presa ao cardapio" "$tmp/cardapio.html" "href=\"/privacidade?r=$slug\""
contem "pagina publica sem cache (voltar do navegador pergunta ao servidor)" "$tmp/h.txt" 'no-store'
curl -s -o "$tmp/final.html" "$base/$slug/finalizar"
contem "finalizar volta para o cardapio deste restaurante" "$tmp/final.html" "class=\"cd-topo-btn\" href=\"/$slug\""
curl -s -o "$tmp/legal.html" "$base/privacidade?r=$slug"
contem "legal pelo cardapio mostra Voltar ao cardapio" "$tmp/legal.html" "href=\"/$slug\""
nao_contem "legal pelo cardapio sem catalogo" "$tmp/legal.html" 'href="/catalogo"'
nao_contem "legal pelo cardapio sem cadastro" "$tmp/legal.html" 'href="/cadastro"'
nao_contem "links do texto tambem presos ao cardapio" "$tmp/legal.html" 'href="/exclusao-de-dados"'
contem "outras politicas continuam presas ao cardapio" "$tmp/legal.html" "href=\"/termos-de-uso?r=$slug\""
curl -s -o "$tmp/legal2.html" "$base/privacidade?r=nao-existe-mesmo"
contem "restaurante desconhecido mostra a pagina normal" "$tmp/legal2.html" 'href="/catalogo"'
curl -s -o "$tmp/home.html" -w '' "$base/"
contem "visitante sem login ve o site normalmente" "$tmp/home.html" 'href="/cadastro"'

if (( falhas > 0 )); then
  echo "FALHOU: $falhas verificacao(oes) de equipe no painel."
  exit 1
fi
echo "OK: equipe no painel e cliente no restaurante."
