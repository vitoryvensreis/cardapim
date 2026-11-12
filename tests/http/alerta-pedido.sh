#!/usr/bin/env bash
# Teste HTTP do AVISO DE PEDIDO NOVO (luz, tremida e som): aparece na gerencia (todas as abas) e no modo caixa,
# nao aparece para quem nao trata pedidos (garcom, entregador). O evento "pedido.novo" e conferido no banco
# por tests/http/verificar-alerta-ci.php. Exige o tempo real ligado (WS_ATIVO=1), como no CI.
# Uso: bash tests/http/alerta-pedido.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
senha='Senha#CI2026'
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login_equipe() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login" >/dev/null
}
pagina() { curl -s -c "$1" -b "$1" -o "$tmp/pagina.html" "$2"; }

gj="$tmp/g"; cx="$tmp/c"; wj="$tmp/w"; mj="$tmp/m"
login_equipe "$gj" ci_gerente; login_equipe "$cx" ci_caixa; login_equipe "$wj" ci_garcom; login_equipe "$mj" ci_motoqueiro

echo "Gerencia: todas as abas avisam"
for aba in "" /estoque /financeiro /equipe /perfil /minha-conta; do
  pagina "$gj" "$base/$slug/gerencia$aba"
  contem "gerencia$aba liga o aviso" "$tmp/pagina.html" "data-alerta-pedido=\"/$slug/caixa/delivery\""
done
contem "script do aviso" "$tmp/pagina.html" '/assets/js/alerta-pedido.js'
contem "css do aviso" "$tmp/pagina.html" '/assets/css/alerta-pedido.css'
contem "botao de som no topo" "$tmp/pagina.html" 'data-alerta-som'
contem "luz em volta da tela" "$tmp/pagina.html" 'data-alerta-luz'
contem "faixa com ver pedido" "$tmp/pagina.html" 'data-alerta-ver'
contem "faixa escondida ate chegar pedido" "$tmp/pagina.html" 'data-alerta-aviso hidden'
nao_contem "faixa sem aviso de tocar na tela (nao muda ao clicar)" "$tmp/pagina.html" 'Toque em qualquer lugar da tela'

echo "Modo caixa avisa"
for tela in "" /delivery /mesas; do
  pagina "$cx" "$base/$slug/caixa$tela"
  contem "caixa$tela liga o aviso" "$tmp/pagina.html" 'data-alerta-pedido='
done
pagina "$gj" "$base/$slug/caixa"
contem "gerente no modo caixa tambem" "$tmp/pagina.html" '/assets/js/alerta-pedido.js'
esperado="$(grep -c 'alerta-pedido.js' "$tmp/pagina.html")"
[[ "$esperado" == "1" ]] && ok "script carregado uma vez so" || falha "script carregado $esperado vezes"

echo "Quem nao trata pedidos nao recebe o aviso"
pagina "$wj" "$base/$slug/garcom"
nao_contem "garcom sem aviso de delivery" "$tmp/pagina.html" 'data-alerta-pedido'
pagina "$mj" "$base/$slug/entregas"
nao_contem "entregador sem aviso de delivery" "$tmp/pagina.html" 'data-alerta-pedido'

curl --fail --silent --show-error "$base/assets/js/alerta-pedido.js" -o "$tmp/alerta.js"
contem "som liga no primeiro toque (regra do navegador)" "$tmp/alerta.js" 'pointerdown'
contem "tenta ligar o som ja ao abrir (app instalado)" "$tmp/alerta.js" 'liberarAudio();'
contem "respeita reduzir movimento" "$tmp/alerta.js" 'prefers-reduced-motion'
contem "ouve pedido.novo" "$tmp/alerta.js" 'pedido.novo'

if (( falhas > 0 )); then
  echo "Aviso de pedido novo: $falhas falha(s)."
  exit 1
fi
echo "Aviso de pedido novo: tudo certo."
