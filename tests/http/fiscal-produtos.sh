#!/usr/bin/env bash
set -Eeuo pipefail
base="$1"; slug="$2"; g="$base/$slug/gerencia"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
t="$(curl -s -c "$tmp/g" "$base/login" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | cut -d'"' -f4)"
curl -s -b "$tmp/g" -c "$tmp/g" -o /dev/null --data-urlencode "_csrf=$t" --data-urlencode 'identificacao=ci_gerente' --data-urlencode 'senha=Senha#CI2026' "$base/login"
curl -s -b "$tmp/g" "$g/fiscal" > "$tmp/f"
csrf="$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$tmp/f" | head -1 | cut -d'"' -f4)"
post() { curl -s -b "$tmp/g" -c "$tmp/g" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$csrf" --data-urlencode "$2" "$1"; }
test "$(post "$g/fiscal/habilitar" 'habilitado=0')" = 303
test "$(curl -s -b "$tmp/g" -o "$tmp/off" -w '%{http_code}' "$g/fiscal/produtos")" = 403
test "$(post "$g/fiscal/perfis/salvar" 'nome=Bloqueado')" = 403
curl -s -b "$tmp/g" "$g/estoque/produtos/novo" > "$tmp/novo"
! grep -q 'titulo-dados-fiscais' "$tmp/novo"
! grep -q 'fiscal-produtos.js' "$tmp/novo"
test "$(post "$g/fiscal/habilitar" 'habilitado=1')" = 303
test "$(curl -s -b "$tmp/g" -o "$tmp/on" -w '%{http_code}' "$g/fiscal/produtos")" = 200
grep -q 'Aplicar perfil em massa' "$tmp/on"
pid="$(grep -o 'name="produtos\[\]" value="[0-9]*"' "$tmp/on" | head -1 | cut -d'"' -f4)"
test -n "$pid"
test "$(curl -s -b "$tmp/g" -o "$tmp/prod" -w '%{http_code}' "$g/fiscal/produtos/$pid")" = 200
grep -q 'Pesquisar NCM oficial' "$tmp/prod"
grep -q 'Reaproveitar classificação' "$tmp/prod"
curl -s -b "$tmp/g" "$g/estoque/produtos/$pid" > "$tmp/ed"
grep -q 'Configurar dados fiscais' "$tmp/ed"
test "$(curl -s -b "$tmp/g" -o "$tmp/perfis" -w '%{http_code}' "$g/fiscal/perfis")" = 200
grep -q 'Modelos para começar' "$tmp/perfis"
test "$(curl -s -b "$tmp/g" -o "$tmp/form" -w '%{http_code}' "$g/fiscal/perfis/novo")" = 200
grep -q 'Nome do perfil' "$tmp/form"
curl -s -b "$tmp/g" "$g/estoque/produtos/novo" > "$tmp/novo-on"
grep -q 'titulo-dados-fiscais' "$tmp/novo-on"
test "$(post "$g/fiscal/habilitar" 'habilitado=0')" = 303
echo 'Cadastro fiscal oculto/desbloqueado corretamente; rotas e interface verificadas.'
