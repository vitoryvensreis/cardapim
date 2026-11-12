#!/usr/bin/env bash
# Teste HTTP do painel da plataforma: DASHBOARD (numeros e saude), LOGS DE AUDITORIA (filtros, planilha) e
# MINHA CONTA do administrador (dados, senha, verificacao em duas etapas, aparelhos), alem do icone do convite do app.
# Uso: bash tests/http/painel-adm.sh <base>
set -Eeuo pipefail
base="$1"
senha='Senha#CI2026'
p="$base/painel"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0
id="$(date +%s)$RANDOM"

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$3" "$base/login"
}
enviar() { # jar url campos... -> "codigo location"
  local jar="$1" url="$2" t args=(); shift 2
  t="$(token "$jar" "$p/minha-conta")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -c "$1" -w '%{http_code}' "$2"; }
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }

aj="$tmp/adm"; gj="$tmp/gerente"
login "$aj" ci_master "$senha" >/dev/null
login "$gj" ci_gerente "$senha" >/dev/null

echo "Convite do app"
curl -s "$base/login" > "$tmp/login.html"
contem "convite usa o icone do app (manifesto ou favicon)" "$tmp/login.html" 'class="pwa-icone" src="/'

echo "Dashboard"
esperar "dashboard abre" "$(pagina "$aj" "$p")" "200"
nao_contem "dashboard nao esta em construcao" "$tmp/pagina.html" 'Em construção'
contem "receita por mes" "$tmp/pagina.html" 'Receita por mês'
contem "restaurantes ativos" "$tmp/pagina.html" 'Restaurantes ativos'
contem "pedidos na plataforma" "$tmp/pagina.html" 'Pedidos na plataforma'
contem "restaurantes por situacao" "$tmp/pagina.html" 'Restaurantes por situação'
contem "saude: banco" "$tmp/pagina.html" 'Banco de dados'
contem "saude: e-mail" "$tmp/pagina.html" 'E-mail do sistema'
for per in hoje 7dias 30dias xx; do
  esperar "periodo $per" "$(pagina "$aj" "$p?periodo=$per")" "200"
done
contem "periodo invalido volta para 7 dias" "$tmp/pagina.html" 'aria-current="true">7 dias'
esperar "gerente nao abre o dashboard (403)" "$(pagina "$gj" "$p")" "403"

echo "Logs de auditoria"
esperar "logs abrem" "$(pagina "$aj" "$p/logs")" "200"
nao_contem "logs nao estao em construcao" "$tmp/pagina.html" 'Em construção'
contem "registro de login aparece" "$tmp/pagina.html" 'Acesso realizado como'
contem "mostra quem fez" "$tmp/pagina.html" 'ci_master'
esperar "filtro de entradas" "$(pagina "$aj" "$p/logs?tipo=acessos")" "200"
contem "entradas mostram login" "$tmp/pagina.html" 'logs-titulo"><strong>Login<'
nao_contem "entradas nao mostram alteracoes" "$tmp/pagina.html" 'Marca: textos alterados'
esperar "filtro por pessoa" "$(pagina "$aj" "$p/logs?pessoa=ci_gerente")" "200"
nao_contem "pessoa filtra os outros" "$tmp/pagina.html" '(ci_master)'
esperar "filtro da plataforma" "$(pagina "$aj" "$p/logs?restaurante=plataforma")" "200"
nao_contem "plataforma nao mostra gerente" "$tmp/pagina.html" '(ci_gerente)'
esperar "datas e textos estranhos nao quebram" "$(pagina "$aj" "$p/logs?de=2026-02-31&ate=xx&busca=%25_%27&tipo=zz&restaurante=-1&pagina=-5")" "200"
esperar "periodo sem registros" "$(pagina "$aj" "$p/logs?de=2020-01-01&ate=2020-01-02")" "200"
contem "explica sem registros" "$tmp/pagina.html" 'Nenhum registro com esses filtros'
curl -s -D "$tmp/cab.txt" -o "$tmp/logs.csv" -b "$aj" "$p/logs/planilha?tipo=acessos"
contem "planilha e CSV" "$tmp/cab.txt" 'text/csv'
contem "planilha baixa como arquivo" "$tmp/cab.txt" 'attachment; filename="logs-'
contem "planilha tem cabecalho" "$tmp/logs.csv" 'Data;Hora;"O que aconteceu"'
contem "planilha tem os logins" "$tmp/logs.csv" 'Login'
esperar "gerente nao ve os logs (403)" "$(pagina "$gj" "$p/logs")" "403"
esperar "gerente nao baixa a planilha (403)" "$(pagina "$gj" "$p/logs/planilha")" "403"

echo "Minha conta do administrador"
esperar "minha conta abre" "$(pagina "$aj" "$p/minha-conta")" "200"
nao_contem "minha conta nao esta em construcao" "$tmp/pagina.html" 'Em construção'
contem "mostra o usuario" "$tmp/pagina.html" 'ci_master'
contem "explica como trocar usuario e e-mail" "$tmp/pagina.html" 'peça a outro administrador em Usuários'
contem "formulario aponta para o painel" "$tmp/pagina.html" 'action="/painel/minha-conta/dados"'
esperar "nome vazio nao salva (422)" "$(enviar "$aj" "$p/minha-conta/dados" nome= sobrenome=Master | cut -d' ' -f1)" "422"
esperar "salva os dados" "$(enviar "$aj" "$p/minha-conta/dados" nome=CI "sobrenome=Master $id" whatsapp='(85) 98888-7777')" "303 $p/minha-conta"
aviso "$aj" "$p/minha-conta" > "$tmp/msg.txt"
contem "sobrenome novo aparece" "$tmp/aviso.html" "Master $id"
esperar "senha atual errada nao troca (422)" "$(enviar "$aj" "$p/minha-conta/senha" senha_atual=errada senha_nova=Nova-senha-1234 senha_confirmacao=Nova-senha-1234 | cut -d' ' -f1)" "422"
contem "explica a senha atual" "$tmp/resp.html" 'Senha atual incorreta'
esperar "tela do aplicativo autenticador" "$(pagina "$aj" "$p/minha-conta/dois-fatores/app")" "200"
contem "QR Code do autenticador" "$tmp/pagina.html" 'otpauth://'
contem "confirmar aponta para o painel" "$tmp/pagina.html" 'action="/painel/minha-conta/dois-fatores/app"'
esperar "codigo errado nao liga" "$(enviar "$aj" "$p/minha-conta/dois-fatores/app" codigo=000000 | cut -d' ' -f1)" "303"
pagina "$aj" "$p/minha-conta" >/dev/null
contem "continua sem verificacao" "$tmp/pagina.html" 'Proteção extra desligada'
esperar "codigos sem gerar volta" "$(pagina "$aj" "$p/minha-conta/codigos")" "303"
esperar "sair dos outros aparelhos" "$(enviar "$aj" "$p/minha-conta/aparelhos/sair-todos")" "303 $p/minha-conta#aparelhos"
esperar "aparelho inexistente avisa" "$(enviar "$aj" "$p/minha-conta/aparelhos/999999/sair" | cut -d' ' -f1)" "303"
esperar "sem CSRF volta sem mudar" "$(curl -s -o /dev/null -b "$aj" -w '%{http_code}' --data 'nome=Hacker&sobrenome=X' "$p/minha-conta/dados")" "303"
pagina "$aj" "$p/minha-conta" >/dev/null
nao_contem "dados sem CSRF nao gravados" "$tmp/pagina.html" 'value="Hacker"'
enviar "$aj" "$p/minha-conta/dados" nome=CI sobrenome=Master whatsapp= >/dev/null
esperar "gerente nao abre a minha conta do adm (403)" "$(pagina "$gj" "$p/minha-conta")" "403"
pagina "$aj" "$p/logs?busca=Minha+conta" >/dev/null
contem "alteracao da conta foi para os logs" "$tmp/pagina.html" 'Minha conta: dados alterados'

echo
if [[ "$falhas" -gt 0 ]]; then echo "FALHOU: $falhas verificacao(oes) do painel da plataforma."; exit 1; fi
echo "OK: dashboard, logs de auditoria e minha conta do administrador."
