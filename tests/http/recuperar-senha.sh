#!/usr/bin/env bash
# Teste HTTP do "Esqueci minha senha": pedido sem revelar contas, link do e-mail (MAIL_DRIVER=log), link sai da URL,
# senha nova com validacoes, uso unico, aparelhos lembrados desconectados, conta inativa sem e-mail e equipe logada.
# No fim a senha volta para a original (as outras suites usam a mesma conta).
# Uso: bash tests/http/recuperar-senha.sh <base>
set -Eeuo pipefail
base="$1"
senha='Senha#CI2026'
nova='Caju-feira-7319'
conta='ci_outro_gerente'
email='ci.outro.gerente@example.test'
raiz="$(cd "$(dirname "$0")/../.." && pwd)"
maillog="$raiz/storage/logs/mail.log"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login() { # jar usuario senha [lembrar] -> "codigo location"
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$3" ${4:+--data-urlencode lembrar=1} "$base/login"
}
pedir() { # jar identificacao -> "codigo location"
  local t; t="$(token "$1" "$base/recuperar-senha")"
  curl -s -o "$tmp/resp.html" -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" "$base/recuperar-senha"
}
salvar() { # jar senha confirmacao -> "codigo location"
  local t; t="$(token "$1" "$base/recuperar-senha/nova")"
  curl -s -o "$tmp/resp.html" -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "senha=$2" --data-urlencode "senha_confirmacao=$3" "$base/recuperar-senha/nova"
}
link_do_email() { # linhas antes -> caminho /recuperar-senha/<token> do e-mail novo (espera ate 3 s)
  local antes="$1" l
  for _ in 1 2 3 4 5 6; do
    l="$(tail -n +"$((antes + 1))" "$maillog" 2>/dev/null | grep -o '/recuperar-senha/[0-9]*\.[0-9]*\.[A-Za-z0-9_-]*' | tail -1 || true)"
    [[ -n "$l" ]] && { printf '%s' "$l"; return 0; }
    sleep 0.5
  done
}
linhas() { if [[ -f "$maillog" ]]; then wc -l < "$maillog"; else echo 0; fi; }

j="$tmp/visitante"; lj="$tmp/lembrado"; gj="$tmp/gerente"

echo "Tela e pedido"
esperar "tela do esqueci a senha" "$(curl -s -o "$tmp/p.html" -w '%{http_code}' "$base/recuperar-senha")" "200"
contem "campo de e-mail ou usuario" "$tmp/p.html" 'name="identificacao"'
curl -s "$base/login" > "$tmp/login.html"
contem "login tem o link Esqueci minha senha" "$tmp/login.html" 'href="/recuperar-senha"'
esperar "sem CSRF nao envia (419)" "$(curl -s -o /dev/null -w '%{http_code}' --data 'identificacao=x' "$base/recuperar-senha")" "419"
esperar "vazio pede o e-mail (422)" "$(pedir "$j" '' | cut -d' ' -f1)" "422"
antes="$(linhas)"
esperar "conta inexistente: mesma resposta" "$(pedir "$j" 'ninguem.aqui@example.test')" "303 $base/recuperar-senha"
curl -s -b "$j" -c "$j" "$base/recuperar-senha" > "$tmp/enviado.html"
contem "mensagem nao revela se existe" "$tmp/enviado.html" 'Se a conta existir'
esperar "conta inativa: mesma resposta" "$(pedir "$j" 'ci_inativo')" "303 $base/recuperar-senha"
curl -s -o /dev/null -b "$j" -c "$j" "$base/recuperar-senha"
sleep 1
esperar "nenhum e-mail para inexistente ou inativa" "$(linhas)" "$antes"

echo "Link do e-mail"
login "$lj" "$conta" "$senha" lembrar >/dev/null
antes="$(linhas)"
esperar "pedido pelo usuario" "$(pedir "$j" "$conta")" "303 $base/recuperar-senha"
caminho="$(link_do_email "$antes")"
[[ -n "$caminho" ]] && ok "e-mail com o link enviado" || falha "e-mail com o link nao encontrado"
if grep -q "Para: $email | Assunto: Redefinir sua senha" "$maillog"; then ok "e-mail para o endereco da conta"; else falha "destinatario errado"; fi
adulterado="${caminho%?}A"; [[ "$adulterado" == "$caminho" ]] && adulterado="${caminho%?}B"
esperar "link adulterado nao vale (410)" "$(curl -s -o "$tmp/inv.html" -w '%{http_code}' "$base$adulterado")" "410"
contem "explica link vencido" "$tmp/inv.html" 'Link vencido ou já usado'
esperar "link certo sai da barra de endereco" "$(curl -s -o /dev/null -c "$j" -b "$j" -w '%{http_code} %{redirect_url}' "$base$caminho")" "303 $base/recuperar-senha/nova"
esperar "tela da senha nova" "$(curl -s -o "$tmp/nova.html" -c "$j" -b "$j" -w '%{http_code}' "$base/recuperar-senha/nova")" "200"
contem "mostra a conta" "$tmp/nova.html" "$conta"
esperar "nova sem o link na sessao (410)" "$(curl -s -o /dev/null -w '%{http_code}' "$base/recuperar-senha/nova")" "410"

echo "Senha nova"
esperar "senha curta (422)" "$(salvar "$j" 'abc' 'abc' | cut -d' ' -f1)" "422"
contem "explica o tamanho" "$tmp/resp.html" 'pelo menos 8'
esperar "senhas diferentes (422)" "$(salvar "$j" "$nova" 'Outra-coisa-1234' | cut -d' ' -f1)" "422"
contem "explica a repeticao" "$tmp/resp.html" 'não são iguais'
esperar "mesma senha de antes (422)" "$(salvar "$j" "$senha" "$senha" | cut -d' ' -f1)" "422"
esperar "grava a senha nova" "$(salvar "$j" "$nova" "$nova")" "303 $base/login?senha=alterada"
curl -s -b "$j" -c "$j" "$base/login?senha=alterada" > "$tmp/l.html"
contem "aviso de senha alterada no login" "$tmp/l.html" 'Senha alterada.'
if grep -q "Para: $email | Assunto: Sua senha foi alterada" "$maillog"; then ok "aviso de senha alterada por e-mail"; else falha "aviso de senha alterada nao enviado"; fi
esperar "link nao vale de novo (410)" "$(curl -s -o /dev/null -w '%{http_code}' "$base$caminho")" "410"
esperar "senha antiga nao entra" "$(login "$gj" "$conta" "$senha" | cut -d' ' -f1)" "401"
esperar "senha nova entra" "$(login "$gj" "$conta" "$nova" | cut -d' ' -f1)" "303"
sed -i '/cardapim_sessao/d' "$lj"
esperar "aparelho lembrado foi desconectado" "$(curl -s -o /dev/null -b "$lj" -w '%{http_code} %{redirect_url}' "$base/ci-outro-restaurante/gerencia")" "302 $base/login"

echo "Equipe logada"
esperar "logado vai para o painel" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' "$base/recuperar-senha")" "303"

echo "Volta a senha original"
antes="$(linhas)"
pedir "$tmp/v2" "$email" >/dev/null
caminho="$(link_do_email "$antes")"
curl -s -o /dev/null -c "$tmp/v2" -b "$tmp/v2" "$base$caminho"
esperar "senha original de volta" "$(salvar "$tmp/v2" "$senha" "$senha")" "303 $base/login?senha=alterada"
esperar "senha original entra" "$(login "$gj" "$conta" "$senha" | cut -d' ' -f1)" "303"

echo
if [[ "$falhas" -gt 0 ]]; then echo "FALHOU: $falhas verificacao(oes) da recuperacao de senha."; exit 1; fi
echo "OK: esqueci minha senha (link por e-mail, uso unico, aparelhos desconectados)."
