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
# Nenhum restaurante é autorizado por padrão.
test "$(curl -s -b "$tmp/g" -o "$tmp/negado" -w '%{http_code}' "$g/fiscal")" = 403
curl -s -b "$tmp/g" "$g" > "$tmp/sem-menu"
! grep -q "href=\"/$slug/gerencia/fiscal\"" "$tmp/sem-menu"
test "$(curl -s -b "$tmp/a" -G --data-urlencode "busca_restaurante=$slug" -o "$tmp/lib" -w '%{http_code}' "$base/painel/fiscal")" = 200
ac="$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$tmp/lib" | head -1 | cut -d'"' -f4)"
rid="$(grep -o 'name="id_restaurante" value="[0-9]*"' "$tmp/lib" | head -1 | cut -d'"' -f4)"
test -n "$rid"
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' -d "id_restaurante=$rid&permitido=1" "$base/painel/fiscal/liberar-restaurante")" = 403
adminpost() { curl -s -b "$tmp/a" -c "$tmp/a" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$ac" "$@" ; }
test "$(adminpost -d "id_restaurante=$rid&permitido=1" "$base/painel/fiscal/liberar-restaurante")" = 303
test "$(curl -s -b "$tmp/g" -o "$tmp/f" -w '%{http_code}' "$g/fiscal")" = 200
curl -s -b "$tmp/g" "$g" > "$tmp/com-menu"
grep -q "href=\"/$slug/gerencia/fiscal\"" "$tmp/com-menu"
test "$(adminpost -d 'visivel=0' "$base/painel/fiscal/visibilidade")" = 303
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' "$g/fiscal")" = 403
test "$(adminpost -d 'visivel=1' "$base/painel/fiscal/visibilidade")" = 303
test "$(curl -s -b "$tmp/g" -o "$tmp/f" -w '%{http_code}' "$g/fiscal")" = 200
grep -q 'Central fiscal' "$tmp/f"
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' "$base/painel/fiscal")" = 403
curl -s -b "$tmp/g" "$g/integracoes/impressoras" > "$tmp/i"
grep -q 'Cupom completo' "$tmp/i" || grep -q 'Nenhuma impressora' "$tmp/i"
! grep -q 'Salvar impressora fiscal' "$tmp/i"
test "$(curl -s -b "$tmp/a" -o "$tmp/ahtml" -w '%{http_code}' "$base/painel/fiscal")" = 200
grep -q 'Restaurantes fiscais' "$tmp/ahtml"
csrf="$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$tmp/f" | head -1 | cut -d'"' -f4)"
post() {
  curl -s -b "$tmp/g" -c "$tmp/g" -o /dev/null -w '%{http_code}' --data-urlencode "_csrf=$csrf" --data-urlencode "$2" "$1"
}
test "$(post "$g/fiscal/habilitar" 'habilitado=1')" = 303
curl -s -b "$tmp/g" "$g/fiscal" > "$tmp/empresa"
grep -q 'Repreencher pelo CNPJ' "$tmp/empresa"
grep -q 'data-fiscal-inicial="1"' "$tmp/empresa"
grep -q 'Buscar endereço pelo CEP' "$tmp/empresa"
grep -q 'Não possuo ou não sei informar' "$tmp/empresa"
! grep -q 'name="ibge"' "$tmp/empresa"
grep -q 'Configuração automática' "$tmp/empresa"
! grep -q 'name="serie"' "$tmp/empresa"
! grep -q 'name="csc"' "$tmp/empresa"
! grep -q 'name="certificado"' "$tmp/empresa"
test "$(post "$g/fiscal/ambiente" 'ambiente=2')" = 303
curl -s -b "$tmp/g" "$g/fiscal" > "$tmp/hom"
grep -q 'name="serie"' "$tmp/hom"
grep -q 'name="certificado"' "$tmp/hom"
test "$(post "$g/fiscal/ambiente" 'ambiente=3')" = 303
curl -s -b "$tmp/g" "$g/fiscal" > "$tmp/sim"
grep -q 'Configuração automática' "$tmp/sim"
! grep -q 'name="serie"' "$tmp/sim"
# Sem CSRF não chega à consulta externa; CI não depende da disponibilidade de APIs públicas.
test "$(curl -s -b "$tmp/g" -o /dev/null -w '%{http_code}' -d 'atualizar=1' "$g/fiscal/consultar-empresa")" = 303
test "$(curl -s -o /dev/null -w '%{http_code}' -d 'atualizar=1' "$g/fiscal/consultar-empresa")" = 302 || test "$(curl -s -o /dev/null -w '%{http_code}' -d 'atualizar=1' "$g/fiscal/consultar-empresa")" = 303
curl -s -b "$tmp/g" "$g/integracoes/impressoras" > "$tmp/i2"
grep -q 'Salvar impressora fiscal' "$tmp/i2"
grep -q 'Usar padrão do Cupom completo' "$tmp/i2"
test "$(post "$g/integracoes/impressoras/fiscal" 'impressora_fiscal=padrao')" = 303
curl -s -b "$tmp/g" "$g/integracoes/impressoras" > "$tmp/i3"
grep -q 'Impressora fiscal salva' "$tmp/i3"
test "$(post "$g/fiscal/habilitar" 'habilitado=0')" = 303
curl -s -b "$tmp/g" "$g/integracoes/impressoras" > "$tmp/i4"
! grep -q 'Salvar impressora fiscal' "$tmp/i4"
echo 'Rotas fiscais, acesso, card condicional e salvamento independente confirmados.'
