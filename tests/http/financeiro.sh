#!/usr/bin/env bash
# Teste HTTP da aba FINANCEIRO do gerente: acesso por cargo e por restaurante, telas e periodos, planilha (CSV),
# contas a pagar e a receber (cadastro, repeticao mensal, validacao, baixa, desfazer, editar, excluir) e o efeito
# da baixa no resultado do periodo. Funciona com os dados que os outros testes ja deixaram (compara antes/depois).
# Uso: bash tests/http/financeiro.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
senha='Senha#CI2026'
g="$base/$slug/gerencia"
f="$g/financeiro"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0
sufixo="$(date +%s)$RANDOM"
hoje="$(php -r 'date_default_timezone_set("America/Fortaleza"); echo date("Y-m-d");')"
ontem="$(php -r 'date_default_timezone_set("America/Fortaleza"); echo date("Y-m-d", strtotime("-1 day"));')"
amanha="$(php -r 'date_default_timezone_set("America/Fortaleza"); echo date("Y-m-d", strtotime("+1 day"));')"

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
enviar() { # jar url [campo=valor ...] -> "codigo location"
  local jar="$1" url="$2" t args=()
  shift 2
  t="$(token "$jar" "$f/contas/nova")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
# Id da conta pela descricao (na lista "todas").
id_conta() { curl -s -b "$1" "$f/contas?situacao=todas" | php -r '$h = stream_get_contents(STDIN); if (preg_match("~/financeiro/contas/(\d+)\"><strong>" . preg_quote(htmlspecialchars($argv[1], ENT_QUOTES), "~") . "</strong>~", $h, $m)) echo $m[1];' "$2"; }
# Valor (em centavos) de uma linha do resumo da planilha do periodo.
planilha_valor() { curl -s -b "$1" "$f/relatorio?$2" | php -r '$l = explode("\n", stream_get_contents(STDIN)); foreach ($l as $x) { $c = str_getcsv(trim($x), ";", "\"", ""); if (($c[0] ?? "") === $argv[1]) { echo (int) round((float) str_replace(",", ".", $c[1]) * 100); exit; } } echo "ausente";' "$3"; }

echo "Acesso"
gj="$tmp/gerente"; cx="$tmp/caixa"; oj="$tmp/outro"
esperar "gerente entra" "$(login_equipe "$gj" ci_gerente)" "303"
esperar "caixa entra" "$(login_equipe "$cx" ci_caixa)" "303"
esperar "gerente de outro restaurante entra" "$(login_equipe "$oj" ci_outro_gerente)" "303"
esperar "caixa nao abre o financeiro (403)" "$(pagina "$cx" "$f")" "403"
esperar "caixa nao baixa a planilha (403)" "$(pagina "$cx" "$f/relatorio")" "403"
esperar "gerente de outro restaurante nao abre (403)" "$(pagina "$oj" "$f/contas")" "403"
esperar "sem login vai para o login" "$(curl -s -o /dev/null -w '%{http_code}' "$f")" "302"

echo "Telas"
esperar "resumo abre" "$(pagina "$gj" "$f")" "200"
nao_contem "nao esta mais em construcao" "$tmp/pagina.html" 'Em construção'
contem "abas do financeiro" "$tmp/pagina.html" 'Partes do financeiro'
contem "atalho este mes ativo" "$tmp/pagina.html" 'aria-current="true">Este mês'
contem "carrega o css do financeiro" "$tmp/pagina.html" '/assets/css/financeiro.css'
contem "menu marca Financeiro" "$tmp/pagina.html" "href=\"/$slug/gerencia/financeiro\" aria-current=\"page\""
for caminho in "extrato" "extrato?periodo=hoje&sentido=saida" "contas" "contas?situacao=vencidas&tipo=pagar" "contas/nova" "contas/nova?tipo=receber" "fechamentos?periodo=7dias"; do
  esperar "/financeiro/$caminho abre" "$(pagina "$gj" "$f/$caminho")" "200"
done
pagina "$gj" "$f?periodo=personalizado&de=$ontem&ate=$hoje" >/dev/null
contem "periodo personalizado marcado" "$tmp/pagina.html" 'btn-sm is-ativo'
pagina "$gj" "$f?periodo=personalizado&de=lixo" >/dev/null
contem "periodo invalido avisa e mostra o mes" "$tmp/pagina.html" 'Escolha as duas datas'
pagina "$gj" "$f?periodo=personalizado&de=2020-01-01&ate=$hoje" >/dev/null
contem "periodo limitado a 1 ano" "$tmp/pagina.html" 'O período máximo é de 1 ano'
esperar "periodo com texto estranho nao quebra" "$(pagina "$gj" "$f?periodo=%3Cscript%3E&de[]=1")" "200"

echo "Planilha"
curl -s -b "$gj" -D "$tmp/cab.txt" -o "$tmp/rel.csv" "$f/relatorio?periodo=hoje"
contem "planilha e CSV" "$tmp/cab.txt" 'text/csv'
contem "planilha baixa como arquivo" "$tmp/cab.txt" 'attachment; filename="financeiro-'
contem "planilha tem o resumo" "$tmp/rel.csv" 'Relatório financeiro'
contem "planilha tem o extrato" "$tmp/rel.csv" 'Entrada ou saída'
saiu_antes="$(planilha_valor "$gj" "periodo=hoje" "Saiu")"
entrou_antes="$(planilha_valor "$gj" "periodo=hoje" "Entrou")"
[[ "$saiu_antes" =~ ^[0-9]+$ ]] && ok "planilha traz o total que saiu" || falha "planilha sem total que saiu ($saiu_antes)"

echo "Contas: cadastro e validacao"
esperar "sem valor nao cadastra (422)" "$(enviar "$gj" "$f/contas" tipo=pagar "descricao=Luz $sufixo" categoria=contas_consumo valor= "vencimento=$hoje" repetir=1 | cut -d' ' -f1)" "422"
contem "explica o valor" "$tmp/resp.html" 'Digite o valor em reais'
esperar "categoria de outro tipo nao vale (422)" "$(enviar "$gj" "$f/contas" tipo=receber "descricao=Encomenda $sufixo" categoria=aluguel valor=10,00 "vencimento=$hoje" repetir=1 | cut -d' ' -f1)" "422"
contem "explica a categoria" "$tmp/resp.html" 'Escolha a categoria da lista'
contem "mantem o que foi digitado" "$tmp/resp.html" "Encomenda $sufixo"
esperar "repetir fora do limite (422)" "$(enviar "$gj" "$f/contas" tipo=pagar "descricao=Internet $sufixo" categoria=internet valor=99,90 "vencimento=$hoje" repetir=13 | cut -d' ' -f1)" "422"
esperar "data invalida (422)" "$(enviar "$gj" "$f/contas" tipo=pagar "descricao=Internet $sufixo" categoria=internet valor=99,90 vencimento=2026-02-30 repetir=1 | cut -d' ' -f1)" "422"

esperar "cadastra conta a pagar vencida" "$(enviar "$gj" "$f/contas" tipo=pagar "descricao=Fornecedor $sufixo" categoria=fornecedor valor=1.234,56 "vencimento=$ontem" repetir=1)" "303 $f/contas"
esperar "mensagem de cadastro" "$(aviso "$gj" "$f/contas")" "Conta a pagar \"Fornecedor $sufixo\" cadastrada."
contem "aparece na lista em aberto" "$tmp/aviso.html" "Fornecedor $sufixo"
contem "marcada como vencida" "$tmp/aviso.html" 'Vencida'
contem "botao Paguei" "$tmp/aviso.html" 'Paguei'
esperar "repete por 3 meses" "$(enviar "$gj" "$f/contas" tipo=pagar "descricao=Aluguel $sufixo" categoria=aluguel valor=2.000,00 vencimento=2026-01-31 repetir=3 | cut -d' ' -f1)" "303"
esperar "mensagem da repeticao" "$(aviso "$gj" "$f/contas")" "3 contas a pagar de R\$ 2.000,00 cadastradas, uma por mês."
contem "parcela 1/3" "$tmp/aviso.html" "Aluguel $sufixo (1/3)"
contem "parcela 3/3" "$tmp/aviso.html" "Aluguel $sufixo (3/3)"
contem "fevereiro vira o ultimo dia (28/02)" "$tmp/aviso.html" '28/02/2026'
contem "marco volta para o dia 31" "$tmp/aviso.html" '31/03/2026'
esperar "cadastra conta a receber" "$(enviar "$gj" "$f/contas" tipo=receber "descricao=Encomenda $sufixo" categoria=encomenda valor=300 "vencimento=$amanha" repetir=1 | cut -d' ' -f1)" "303"
pagina "$gj" "$f/contas?tipo=receber" >/dev/null
contem "filtro a receber mostra a conta" "$tmp/pagina.html" "Encomenda $sufixo"
nao_contem "filtro a receber esconde as a pagar" "$tmp/pagina.html" "Fornecedor $sufixo"
contem "vence amanha" "$tmp/pagina.html" 'Vence amanhã'
contem "botao Recebi" "$tmp/pagina.html" 'Recebi'
pagina "$gj" "$f" >/dev/null
contem "resumo avisa da conta vencida" "$tmp/pagina.html" 'Ver contas vencidas'

echo "Contas: baixa, desfazer, editar e excluir"
idf="$(id_conta "$gj" "Fornecedor $sufixo")"
[[ -n "$idf" ]] && ok "acha a conta na lista" || falha "conta nao encontrada na lista"
esperar "data futura nao vale" "$(enviar "$gj" "$f/contas/$idf/baixar" "pago_em=$amanha" >/dev/null; aviso "$gj" "$f/contas")" "Escolha uma data válida, de hoje ou antes."
esperar "forma inventada nao vale" "$(enviar "$gj" "$f/contas/$idf/baixar" forma=cheque >/dev/null; aviso "$gj" "$f/contas")" "Escolha uma forma de pagamento da lista."
esperar "marca como paga (hoje)" "$(enviar "$gj" "$f/contas/$idf/baixar" | cut -d' ' -f1)" "303"
esperar "mensagem da baixa" "$(aviso "$gj" "$f/contas")" "\"Fornecedor $sufixo\" marcada como paga."
pagina "$gj" "$f/contas" >/dev/null
nao_contem "sai da lista em aberto" "$tmp/pagina.html" "Fornecedor $sufixo"
esperar "nao baixa duas vezes" "$(enviar "$gj" "$f/contas/$idf/baixar" >/dev/null; aviso "$gj" "$f/contas")" "Esta conta já está marcada como paga."
pagina "$gj" "$f/contas?situacao=pagas" >/dev/null
contem "aparece nas pagas" "$tmp/pagina.html" "Fornecedor $sufixo"
contem "com botao desfazer" "$tmp/pagina.html" 'Desfazer'
esperar "baixa entra no que saiu hoje" "$(planilha_valor "$gj" "periodo=hoje" "Saiu")" "$((saiu_antes + 123456))"
esperar "entrou nao muda" "$(planilha_valor "$gj" "periodo=hoje" "Entrou")" "$entrou_antes"
curl -s -b "$gj" "$f/relatorio?periodo=hoje" > "$tmp/rel2.csv"
contem "extrato da planilha tem a conta" "$tmp/rel2.csv" "Fornecedor $sufixo"
pagina "$gj" "$f/extrato?periodo=hoje&sentido=saida" >/dev/null
contem "extrato mostra a conta paga" "$tmp/pagina.html" "Fornecedor $sufixo"
esperar "desfaz a baixa" "$(enviar "$gj" "$f/contas/$idf/reabrir" >/dev/null; aviso "$gj" "$f/contas")" "\"Fornecedor $sufixo\" voltou para em aberto."
esperar "saiu volta ao valor de antes" "$(planilha_valor "$gj" "periodo=hoje" "Saiu")" "$saiu_antes"
esperar "baixa com data e forma" "$(enviar "$gj" "$f/contas/$idf/baixar" "pago_em=$ontem" forma=pix | cut -d' ' -f1)" "303"
esperar "conta paga ontem nao entra em hoje" "$(planilha_valor "$gj" "periodo=hoje" "Saiu")" "$saiu_antes"
pagina "$gj" "$f/contas/$idf" >/dev/null
contem "tela da conta mostra quando foi paga" "$tmp/pagina.html" '(Pix)'
esperar "edita a conta" "$(enviar "$gj" "$f/contas/$idf" tipo=pagar "descricao=Fornecedor $sufixo" categoria=fornecedor valor=1.000,00 "vencimento=$ontem")" "303 $f/contas"
pagina "$gj" "$f/contas/$idf" >/dev/null
contem "valor editado" "$tmp/pagina.html" 'value="1.000,00"'
nao_contem "editar nao repete" "$tmp/pagina.html" 'name="repetir"'

ido="$(id_conta "$gj" "Encomenda $sufixo")"
esperar "outro restaurante nao exclui a conta" "$(enviar "$oj" "$base/ci-outro-restaurante/gerencia/financeiro/contas/$ido/excluir" >/dev/null; aviso "$oj" "$base/ci-outro-restaurante/gerencia/financeiro/contas")" "Conta não encontrada. Ela pode já ter sido excluída."
esperar "outro restaurante nao abre a conta" "$(curl -s -o /dev/null -b "$oj" -w '%{http_code} %{redirect_url}' "$base/ci-outro-restaurante/gerencia/financeiro/contas/$ido")" "303 $base/ci-outro-restaurante/gerencia/financeiro/contas"
esperar "conta continua existindo" "$(pagina "$gj" "$f/contas/$ido")" "200"
esperar "caixa nao exclui (403)" "$(enviar "$cx" "$f/contas/$ido/excluir" | cut -d' ' -f1)" "403"
esperar "excluir sem token nao exclui" "$(curl -s -o /dev/null -b "$gj" -c "$gj" -w '%{http_code}' --data '_csrf=errado' "$f/contas/$ido/excluir"; aviso "$gj" "$f/contas")" "303Sua sessão expirou. Tente de novo."
esperar "exclui pela tela da conta" "$(enviar "$gj" "$f/contas/$ido/excluir")" "303 $f/contas"
esperar "mensagem da exclusao" "$(aviso "$gj" "$f/contas")" "Conta \"Encomenda $sufixo\" excluída."
esperar "conta excluida nao abre" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code} %{redirect_url}' "$f/contas/$ido")" "303 $f/contas"

echo "Planilha nao executa formulas"
esperar "cadastra conta com texto de formula" "$(enviar "$gj" "$f/contas" tipo=pagar "descricao==SOMA(1;1) $sufixo" categoria=outra_despesa valor=1,00 "vencimento=$hoje" repetir=1 | cut -d' ' -f1)" "303"
idx="$(id_conta "$gj" "=SOMA(1;1) $sufixo")"
enviar "$gj" "$f/contas/$idx/baixar" >/dev/null
curl -s -b "$gj" "$f/relatorio?periodo=hoje" > "$tmp/rel3.csv"
contem "formula vira texto" "$tmp/rel3.csv" "'=SOMA(1;1) $sufixo"
enviar "$gj" "$f/contas/$idx/excluir" >/dev/null
enviar "$gj" "$f/contas/$idf/excluir" >/dev/null
for n in 1 2 3; do enviar "$gj" "$f/contas/$(id_conta "$gj" "Aluguel $sufixo ($n/3)")/excluir" >/dev/null; done
pagina "$gj" "$f/contas?situacao=todas" >/dev/null
pagina "$gj" "$f/contas?situacao=todas" >/dev/null
nao_contem "limpou as contas do teste" "$tmp/pagina.html" "$sufixo"

if (( falhas > 0 )); then
  echo "Financeiro: $falhas falha(s)."
  exit 1
fi
echo "Financeiro: tudo certo."
