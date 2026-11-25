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
grep -q 'Pesquisar NCM pela BrasilAPI' "$tmp/prod"
test "$(curl -s -b "$tmp/g" -o "$tmp/ncm-invalid" -w '%{http_code}' "$g/fiscal/classificacoes/ncm?q=1")" = 422
grep -q 'Reaproveitar classificação' "$tmp/prod"
grep -q 'Consultar código de barras pela NeXT' "$tmp/prod"
grep -q 'Classificação individual em massa' "$tmp/on"
grep -q 'Importar XML ou CSV' "$tmp/on"
grep -q 'Conferência fiscal' "$tmp/on"
test "$(curl -s -b "$tmp/g" -o "$tmp/imp" -w '%{http_code}' "$g/fiscal/produtos/importar")" = 200
grep -q 'Ler e revisar arquivo' "$tmp/imp"
test "$(curl -s -b "$tmp/g" -o "$tmp/cat" -w '%{http_code}' "$g/fiscal/produtos/catalogos")" = 200
grep -q 'Catálogo ainda não disponibilizado' "$tmp/cat"
test "$(curl -s -b "$tmp/g" -o "$tmp/val" -w '%{http_code}' "$g/fiscal/produtos/validacao")" = 200
grep -q 'Conferência fiscal dos produtos' "$tmp/val"
printf 'nome;ncm;origem\nItem teste;;0\n' > "$tmp/fiscal.csv"
test "$(curl -s -b "$tmp/g" -c "$tmp/g" -o "$tmp/vincular" -w '%{http_code}' -F "_csrf=$csrf" -F 'tipo=csv' -F "arquivo=@$tmp/fiscal.csv" "$g/fiscal/produtos/importar/ler")" = 200
imp="$(grep -o 'name="importacao" value="[a-f0-9]*"' "$tmp/vincular" | head -1 | cut -d'"' -f4)"
test -n "$imp"
test "$(curl -s -b "$tmp/g" -c "$tmp/g" -o "$tmp/prev" -w '%{http_code}' --data-urlencode "_csrf=$csrf" --data-urlencode "importacao=$imp" --data-urlencode "destinos[0]=$pid" "$g/fiscal/produtos/importar/previa")" = 200
imp="$(grep -o 'name="importacao" value="[a-f0-9]*"' "$tmp/prev" | head -1 | cut -d'"' -f4)"
test -n "$imp"
test "$(curl -s -b "$tmp/g" -c "$tmp/g" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$csrf" --data-urlencode "importacao=$imp" "$g/fiscal/produtos/importar/aplicar")" = 303

test "$(post "$g/fiscal/classificacoes/gtin" 'gtin=7894900011517')" = 422
curl -s -b "$tmp/g" "$g/estoque/produtos/$pid" > "$tmp/ed"
grep -q 'Configurar dados fiscais' "$tmp/ed"
test "$(curl -s -b "$tmp/g" -o "$tmp/perfis" -w '%{http_code}' "$g/fiscal/perfis")" = 200
grep -q 'Modelos para começar' "$tmp/perfis"
test "$(curl -s -b "$tmp/g" -o "$tmp/form" -w '%{http_code}' "$g/fiscal/perfis/novo")" = 200
grep -q 'Nome do perfil' "$tmp/form"
curl -s -b "$tmp/g" "$g/estoque/produtos/novo" > "$tmp/novo-on"
grep -q 'titulo-dados-fiscais' "$tmp/novo-on"
# Revogar com módulo ativo: card, menu e rotas desaparecem; autorização local permanece.
ta="$(curl -s -c "$tmp/a" "$base/login" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | cut -d'"' -f4)"
curl -s -b "$tmp/a" -c "$tmp/a" -o /dev/null --data-urlencode "_csrf=$ta" --data-urlencode 'identificacao=ci_master' --data-urlencode 'senha=Senha#CI2026' "$base/login"
curl -s -b "$tmp/a" -G --data-urlencode "busca_restaurante=$slug" "$base/painel/fiscal" > "$tmp/lib"
ac="$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$tmp/lib" | head -1 | cut -d'"' -f4)"
rid="$(grep -o 'name="id_restaurante" value="[0-9]*"' "$tmp/lib" | head -1 | cut -d'"' -f4)"
adminpost() { curl -s -b "$tmp/a" -c "$tmp/a" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$ac" -d "id_restaurante=$rid&permitido=$1" "$base/painel/fiscal/liberar-restaurante"; }
test "$(adminpost 0)" = 303
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' "$g/fiscal/produtos")" = 403
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' "$g/fiscal/produtos/importar")" = 403
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' "$g/fiscal/produtos/catalogos")" = 403
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' "$g/fiscal/classificacoes/ncm?q=22021000")" = 403
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' "$g/fiscal/produtos/validacao")" = 403
test "$(post "$g/fiscal/produtos/importar/aplicar" 'importacao=invalida')" = 403
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' "$base/painel/fiscal/catalogos")" = 403
test "$(curl -s -b "$tmp/a" -o "$tmp/admincat" -w '%{http_code}' "$base/painel/fiscal/catalogos")" = 200
grep -q 'Registrar uma versão' "$tmp/admincat"
test "$(post "$g/fiscal/habilitar" 'habilitado=1')" = 403
test "$(post "$g/integracoes/impressoras/fiscal" 'impressora_fiscal=padrao')" = 403
curl -s -b "$tmp/g" "$g/estoque/produtos/$pid" > "$tmp/ed-off"
! grep -q 'titulo-dados-fiscais' "$tmp/ed-off"
! grep -q "href=\"/$slug/gerencia/fiscal\"" "$tmp/ed-off"
curl -s -b "$tmp/g" "$g/integracoes/impressoras" > "$tmp/i-off"
! grep -q 'Salvar impressora fiscal' "$tmp/i-off"
test "$(adminpost 1)" = 303
test "$(curl -s -b "$tmp/g" -o "$tmp/restaurado" -w '%{http_code}' "$g/fiscal/produtos")" = 200
test "$(post "$g/fiscal/habilitar" 'habilitado=0')" = 303
test "$(adminpost 0)" = 303
echo 'Cadastro fiscal oculto/desbloqueado corretamente; rotas e interface verificadas.'
