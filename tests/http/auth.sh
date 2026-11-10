#!/usr/bin/env bash
# Teste HTTP de login e permissoes por cargo. Uso: bash tests/http/auth.sh http://127.0.0.1:18080 <slug>
# Requer as contas ci_* criadas por tests/http/criar-contas-ci.php (somente banco de CI/local).
set -Eeuo pipefail
base="$1"
slug="$2"
senha='Senha#CI2026'
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { # descricao valor_obtido valor_esperado
  if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi
}
csrf() { # jar
  curl -s -c "$1" -b "$1" "$base/login" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'
}
login() { # jar usuario senha -> imprime "codigo location"
  local token
  token="$(csrf "$1")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' \
    --data-urlencode "_csrf=$token" --data-urlencode "identificacao=$2" --data-urlencode "senha=$3" "$base/login"
}
codigo() { curl -s -o /dev/null -b "$1" -w '%{http_code}' "$base$2"; }
destino() { curl -s -o /dev/null -b "$1" -w '%{redirect_url}' "$base$2"; }

echo "Sem login"
esperar "/painel redireciona para login" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$base/painel")" "302 $base/login"
esperar "/$slug/gerencia redireciona para login" "$(curl -s -o /dev/null -w '%{http_code}' "$base/$slug/gerencia")" "302"
esperar "POST /login sem CSRF recusado" "$(curl -s -o /dev/null -w '%{http_code}' --data 'identificacao=ci_gerente&senha=x' "$base/login")" "419"

echo "Credenciais"
esperar "senha errada" "$(login "$tmp/x" ci_gerente 'errada')" "401 "
esperar "usuario inexistente" "$(login "$tmp/y" nao_existe "$senha")" "401 "
esperar "conta desativada" "$(login "$tmp/z" ci_inativo "$senha")" "403 "
esperar "restaurante suspenso" "$(login "$tmp/s" ci_suspenso "$senha")" "403 "

echo "Gerente"
esperar "login -> gerencia" "$(login "$tmp/g" ci_gerente "$senha")" "303 $base/$slug/gerencia"
esperar "gerencia" "$(codigo "$tmp/g" "/$slug/gerencia")" "200"
esperar "caixa do proprio restaurante" "$(codigo "$tmp/g" "/$slug/caixa")" "200"
esperar "entregas do proprio restaurante" "$(codigo "$tmp/g" "/$slug/entregas")" "200"
esperar "painel bloqueado" "$(codigo "$tmp/g" /painel)" "403"
esperar "outro restaurante bloqueado" "$(codigo "$tmp/g" /ci-outro-restaurante/gerencia)" "403"
esperar "/login com sessao vai para a area" "$(destino "$tmp/g" /login)" "$base/$slug/gerencia"

echo "Caixa"
esperar "login por e-mail -> caixa" "$(login "$tmp/c" CI.CAIXA@example.test "$senha")" "303 $base/$slug/caixa"
esperar "gerencia bloqueada" "$(codigo "$tmp/c" "/$slug/gerencia")" "403"
esperar "entregas bloqueada" "$(codigo "$tmp/c" "/$slug/entregas")" "403"

echo "Entregador"
esperar "login -> entregas" "$(login "$tmp/m" ci_motoqueiro "$senha")" "303 $base/$slug/entregas"
esperar "caixa bloqueado" "$(codigo "$tmp/m" "/$slug/caixa")" "403"

echo "Garcom"
esperar "login -> minha-conta" "$(login "$tmp/w" ci_garcom "$senha")" "303 $base/minha-conta"
esperar "gerencia bloqueada" "$(codigo "$tmp/w" "/$slug/gerencia")" "403"

echo "Administrador master"
esperar "login -> painel" "$(login "$tmp/a" ci_master "$senha")" "303 $base/painel"
esperar "painel" "$(codigo "$tmp/a" /painel)" "200"
esperar "acessa qualquer restaurante" "$(codigo "$tmp/a" /ci-outro-restaurante/gerencia)" "200"

echo "Retorno a pagina pedida e logout"
curl -s -o /dev/null -c "$tmp/r" -b "$tmp/r" "$base/$slug/caixa"
esperar "volta para /caixa apos login do gerente" "$(login "$tmp/r" ci_gerente "$senha")" "303 $base/$slug/caixa"
token="$(curl -s -b "$tmp/g" "$base/$slug/gerencia" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//')"
esperar "sair" "$(curl -s -o /dev/null -c "$tmp/g" -b "$tmp/g" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$token" "$base/sair")" "303 $base/login?saiu=1"
esperar "apos sair, gerencia pede login" "$(codigo "$tmp/g" "/$slug/gerencia")" "302"
esperar "paginas logadas sem cache" "$(curl -s -D - -o /dev/null -b "$tmp/a" "$base/painel" | grep -ci '^cache-control: no-store')" "1"

if (( falhas > 0 )); then echo "$falhas falha(s)."; exit 1; fi
echo "Login e permissoes por cargo validados."
