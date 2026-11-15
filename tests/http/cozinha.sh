#!/usr/bin/env bash
# Tela da cozinha: acesso, somente leitura, fullscreen e ausencia de alarmes.
# Uso: bash tests/http/cozinha.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
g="$base/$slug/gerencia"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

token() {
  curl -s -c "$1" -b "$1" "$base/login" |
    grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 |
    sed 's/.*value="//; s/"$//'
}
login() {
  local t
  t="$(token "$1")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}'     --data-urlencode "_csrf=$t"     --data-urlencode "identificacao=$2"     --data-urlencode 'senha=Senha#CI2026' "$base/login"
}
status() { curl -s -o "$tmp/pagina.html" -b "$1" -w '%{http_code}' "$2"; }
tem() { grep -q -- "$1" "$tmp/pagina.html" || { echo "FALHOU: falta $1"; exit 1; }; }
nao_tem() { if grep -q -- "$1" "$tmp/pagina.html"; then echo "FALHOU: nao deveria ter $1"; exit 1; fi; }
igual() { if [[ "$1" != "$2" ]]; then echo "FALHOU: $3 ($1 != $2)"; exit 1; fi; }

# Sem autenticacao, nao entrega pedidos.
igual "$(curl -s -o /dev/null -w '%{http_code}' "$g/cozinha")" 302 'sem login'
igual "$(curl -s -o /dev/null -w '%{http_code}' "$g/cozinha/conteudo")" 302 'fragmento sem login'

login "$tmp/gerente" ci_gerente >/dev/null
login "$tmp/caixa" ci_caixa >/dev/null
login "$tmp/outro" ci_outro_gerente >/dev/null
login "$tmp/master" ci_master >/dev/null

igual "$(status "$tmp/caixa" "$g/cozinha")" 403 'caixa nao entra'
igual "$(status "$tmp/outro" "$g/cozinha")" 403 'outro restaurante nao entra'
igual "$(status "$tmp/master" "$g/cozinha")" 200 'master entra'
igual "$(status "$tmp/gerente" "$g/cozinha")" 200 'gerente entra'
tem 'class="page-cozinha"'
tem 'data-cozinha-board'
tem 'Fila de preparo'
tem 'Voltar à gerência'
tem '/assets/css/cozinha.css'
tem '/assets/js/cozinha.js'
nao_tem 'Somente visualização'
nao_tem '/assets/js/alerta-pedido.js'
nao_tem 'data-alerta-pedido='
nao_tem 'data-alerta-som'
nao_tem 'class="painel-side"'
nao_tem '<form'

igual "$(status "$tmp/gerente" "$g")" 200 'menu do gerente'
tem "href=\"/$slug/gerencia/cozinha\""
igual "$(status "$tmp/gerente" "$g/cozinha/conteudo")" 200 'fragmento'
nao_tem '<html'
nao_tem '<form'
igual "$(status "$tmp/outro" "$g/cozinha/conteudo")" 403 'fragmento isolado'
igual "$(curl -s -o /dev/null -b "$tmp/gerente" -w '%{http_code}' -X POST "$g/cozinha")" 405 'cozinha sem escrita'

echo 'Cozinha: HTTP, permissoes, fullscreen e silencio OK.'
