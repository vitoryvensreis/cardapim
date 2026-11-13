#!/usr/bin/env bash
# Teste HTTP das paginas legais (textos finais), do aviso de cookies (LGPD: Google Fonts so com aceite)
# e da 99Food "Em breve" na landing e em Gerencia > Integracoes.
# Uso: bash tests/http/legal-cookies.sh <base> <slug>
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
login() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}

echo "Paginas legais"
for caminho in /privacidade /termos-de-uso /cookies /exclusao-de-dados /tratamento-de-dados; do
  curl -s "$base$caminho" > "$tmp/l.html"
  contem "$caminho tem o resumo" "$tmp/l.html" 'Em poucas palavras'
  nao_contem "$caminho sem aviso de revisao" "$tmp/l.html" 'Documento em revisão'
  contem "$caminho mostra o canal do titular" "$tmp/l.html" 'mailto:privacidade@'
done
curl -s "$base/cookies" > "$tmp/c.html"
contem "cookies lista a sessao" "$tmp/c.html" 'cardapim_sessao'
contem "cookies lista o consentimento" "$tmp/c.html" 'cardapim_consentimento'
contem "cookies permite mudar a escolha" "$tmp/c.html" 'data-consentimento-abrir'
curl -s "$base/termos-de-uso" > "$tmp/t.html"
contem "termos explicam o cancelamento" "$tmp/t.html" 'sem multa'

echo "Aviso de cookies"
curl -s "$base/" > "$tmp/h.html"
contem "aviso aparece sem escolha" "$tmp/h.html" 'data-consentimento aria-labelledby="consentimento-titulo">'
nao_contem "sem escolha nao carrega Google Fonts" "$tmp/h.html" 'fonts.googleapis.com'
contem "script do aviso carregado" "$tmp/h.html" '/assets/js/consentimento.js'
contem "rodape reabre as preferencias" "$tmp/h.html" 'Preferências de cookies'
curl -s -b 'cardapim_consentimento=todos.20261112' "$base/" > "$tmp/h2.html"
contem "aceitou: carrega Google Fonts" "$tmp/h2.html" 'fonts.googleapis.com'
contem "aceitou: aviso escondido" "$tmp/h2.html" 'aria-labelledby="consentimento-titulo" hidden'
curl -s -b 'cardapim_consentimento=necessarios.20261112' "$base/" > "$tmp/h3.html"
nao_contem "so necessarios: sem Google Fonts" "$tmp/h3.html" 'fonts.googleapis.com'
contem "so necessarios: aviso escondido" "$tmp/h3.html" 'aria-labelledby="consentimento-titulo" hidden'
curl -s -b 'cardapim_consentimento=<script>' "$base/" > "$tmp/h4.html"
nao_contem "cookie invalido nao libera fontes" "$tmp/h4.html" 'fonts.googleapis.com'
curl -s "$base/$slug" > "$tmp/cd.html"
contem "cardapio tambem tem o aviso" "$tmp/cd.html" 'data-consentimento '

echo "99Food em breve"
contem "landing mostra 99Food" "$tmp/h.html" '99Food'
gj="$tmp/g"; login "$gj" ci_gerente
curl -s -b "$gj" "$base/$slug/gerencia/integracoes" > "$tmp/i.html"
contem "integracoes mostra o cartao 99Food" "$tmp/i.html" 'is-99food'
contem "cartao 99Food sem link, botao desativado" "$tmp/i.html" 'Disponível em breve'
contem "painel tambem tem o aviso de cookies" "$tmp/i.html" 'data-consentimento '
code="$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' "$base/cookies")"
if [[ "$code" == "200" ]]; then ok "equipe logada abre a politica de cookies"; else falha "equipe logada em /cookies (obtido: $code)"; fi

echo
if [[ "$falhas" -gt 0 ]]; then echo "FALHOU: $falhas verificacao(oes) de legal/cookies/99Food."; exit 1; fi
echo "OK: paginas legais, aviso de cookies e 99Food em breve."
