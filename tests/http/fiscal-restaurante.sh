#!/usr/bin/env bash
set -Eeuo pipefail
base="$1"
slug="$2"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
g="$base/$slug/gerencia"
login() {
  local jar="$1" user="$2" t
  t="$(curl -s -c "$jar" "$base/login" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | cut -d'"' -f4)"
  curl -s -b "$jar" -c "$jar" -o /dev/null --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$user" --data-urlencode 'senha=Senha#CI2026' "$base/login"
}
login "$tmp/g" ci_gerente
login "$tmp/a" ci_master
test "$(curl -s -b "$tmp/g" -o "$tmp/f" -w '%{http_code}' "$g/fiscal")" = 200
grep -q 'Central fiscal' "$tmp/f"
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' "$base/painel/fiscal")" = 403
curl -s -b "$tmp/g" "$g/integracoes/impressoras" > "$tmp/i"
grep -q 'Cupom completo' "$tmp/i" || grep -q 'Nenhuma impressora' "$tmp/i"
! grep -q 'Salvar impressora fiscal' "$tmp/i"
test "$(curl -s -b "$tmp/a" -o "$tmp/ahtml" -w '%{http_code}' "$base/painel/fiscal")" = 200
grep -q 'Restaurantes fiscais' "$tmp/ahtml"
echo 'Rotas fiscais, acesso e card oculto confirmados.'
