#!/usr/bin/env bash
# Teste HTTP da VISAO GERAL do gerente: acesso por cargo e restaurante, blocos da tela, atualizacao sozinha
# e conferencia dos numeros de hoje com a planilha do Financeiro (mesma fonte, mesmo resultado).
# Uso: bash tests/http/visao-geral.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
senha='Senha#CI2026'
g="$base/$slug/gerencia"
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
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
pagina() { curl -s -c "$1" -b "$1" -o "$tmp/pagina.html" -w '%{http_code}' "$2"; }
# Valor (centavos ou numero) do indicador da visao geral pelo titulo.
indicador() { php -r '$h = file_get_contents($argv[1]); if (preg_match("~</svg>" . preg_quote($argv[2], "~") . "</span>\s*<strong>(.*?)</strong>~s", $h, $m)) { $v = html_entity_decode(strip_tags($m[1])); echo str_contains($v, "R$") ? (int) round((float) str_replace(["R$", " ", ".", ","], ["", "", "", "."], $v) * 100) : (int) $v; } else { echo "ausente"; }' "$tmp/pagina.html" "$1"; }
planilha() { curl -s -b "$1" "$g/financeiro/relatorio?periodo=hoje" | php -r '$l = explode("\n", stream_get_contents(STDIN)); foreach ($l as $x) { $c = str_getcsv(trim($x), ";", "\"", ""); if (($c[0] ?? "") === $argv[1]) { echo str_contains($c[1], ",") ? (int) round((float) str_replace(",", ".", $c[1]) * 100) : (int) $c[1]; exit; } } echo "ausente";' "$2"; }

echo "Acesso"
gj="$tmp/gerente"; cx="$tmp/caixa"; oj="$tmp/outro"; aj="$tmp/master"
esperar "gerente entra" "$(login_equipe "$gj" ci_gerente)" "303"
esperar "caixa entra" "$(login_equipe "$cx" ci_caixa)" "303"
esperar "gerente de outro restaurante entra" "$(login_equipe "$oj" ci_outro_gerente)" "303"
esperar "master entra" "$(login_equipe "$aj" ci_master)" "303"
esperar "caixa nao abre a visao geral (403)" "$(pagina "$cx" "$g")" "403"
esperar "outro restaurante nao abre (403)" "$(pagina "$oj" "$g")" "403"
esperar "master abre a visao geral do restaurante" "$(pagina "$aj" "$g")" "200"

echo "Tela"
esperar "gerente abre a visao geral" "$(pagina "$gj" "$g")" "200"
nao_contem "nao esta mais em construcao" "$tmp/pagina.html" 'Em construção'
contem "titulo" "$tmp/pagina.html" 'Visão geral</h1>'
contem "menu marca Visao geral" "$tmp/pagina.html" "href=\"/$slug/gerencia\" aria-current=\"page\""
for bloco in 'Vendas hoje' 'Pedidos hoje' 'Ticket médio' 'Mesas ocupadas' 'Pedidos em andamento' 'Caixa</h2>' 'Vendas dos últimos 7 dias' 'Mais vendidos' 'Vendas de hoje por hora' 'Estoque</h2>' 'Atalhos'; do
  contem "bloco: $bloco" "$tmp/pagina.html" "$bloco"
done
contem "atualiza sozinha (script)" "$tmp/pagina.html" '/assets/js/visao-geral.js'
contem "area que o script troca" "$tmp/pagina.html" 'data-visao-geral'
contem "css da visao geral" "$tmp/pagina.html" '/assets/css/visao-geral.css'
contem "link para o caixa" "$tmp/pagina.html" "href=\"/$slug/caixa\""
contem "link para a planilha do mes" "$tmp/pagina.html" 'financeiro/relatorio?periodo=mes'

echo "Numeros batem com o Financeiro"
esperar "vendas de hoje = total vendido da planilha" "$(indicador 'Vendas hoje')" "$(planilha "$gj" 'Total vendido')"
esperar "pedidos de hoje = pedidos concluidos da planilha" "$(indicador 'Pedidos hoje')" "$(planilha "$gj" 'Pedidos concluídos')"
esperar "ticket medio = ticket da planilha" "$(indicador 'Ticket médio')" "$(planilha "$gj" 'Ticket médio')"

if (( falhas > 0 )); then
  echo "Visao geral: $falhas falha(s)."
  exit 1
fi
echo "Visao geral: tudo certo."
