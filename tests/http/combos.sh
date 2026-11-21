#!/usr/bin/env bash
# Combos: gerente cadastra o combo (inclusos, limite total, opcoes ligadas a produtos e maximo por opcao);
# garcom lanca (itens separados, preco do combo dividido), limites recusados, cancelar tira o combo inteiro
# e a sacola do cardapio online confere o combo e divide o valor (mesma regra do pedido).
# Uso: bash tests/http/combos.sh <base> <slug> <base-do-fake>
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
senha='Senha#CI2026'
g="$base/$slug/gerencia"
c="$base/$slug/caixa"
w="$base/$slug/garcom"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0
r4="$(php -r 'echo random_int(1000, 9999);')"

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
json() { php -r '$d=json_decode(file_get_contents($argv[1]),true); $x=$d; foreach(explode(".",$argv[2]) as $k){$x=is_array($x)&&array_key_exists($k,$x)?$x[$k]:null;} echo is_bool($x)?($x?"true":"false"):($x===null?"null":(is_scalar($x)?(string)$x:json_encode($x,JSON_UNESCAPED_UNICODE)));' "$1" "$2"; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
enviar() { # jar url pagina-do-token [campo=valor ...] -> "codigo location"
  local jar="$1" url="$2" origem="$3" t args=()
  shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
reais() { php -r 'echo "R$ " . number_format($argv[1] / 100, 2, ",", ".");' "$1"; }

gj="$tmp/gerente"; wj="$tmp/garcom"
esperar "gerente autenticado" "$(login "$gj" ci_gerente)" "303"
esperar "garcom autenticado" "$(login "$wj" ci_garcom)" "303"

echo "Cadastro do combo"
# Quatro produtos por unidade, sem complementos e com estoque (2 inclusos, 2 ligados as opcoes).
curl -s -b "$wj" "$w/cardapio" -o "$tmp/cardapio.html"
curl -s -b "$wj" "$w" -o "$tmp/mesas.html"
mesa="$(php -r 'if (preg_match("~/garcom/mesas/(\d+)/abrir~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/mesas.html")"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
curl -s -b "$wj" "$w/comandas/$comanda" -o "$tmp/com.html"
mapfile -t prods < <(php -r 'foreach (explode("<li class=\"produto-linha", file_get_contents($argv[1])) as $li) { if (!str_contains($li, "data-comps") && preg_match("~data-preco=\"(\d+)\".*?name=\"qtd\[(\d+)\]\" value=\"0\" min=\"0\" max=\"(\d+)\"~s", $li, $m) && (int) $m[3] >= 6) echo $m[2], " ", $m[1], "\n"; }' "$tmp/com.html" | head -4)
read -r p1 c1 <<<"${prods[0]:-}"; read -r p2 c2 <<<"${prods[1]:-}"; read -r p3 c3 <<<"${prods[2]:-}"; read -r p4 c4 <<<"${prods[3]:-}"
[[ -n "${p4:-}" ]] && ok "produtos para o combo ($p1 $p2 $p3 $p4)" || falha "produtos insuficientes"
grupo_nome="Espetos CI $r4"
enviar "$gj" "$g/estoque/complementos" "$g/estoque/complementos/novo" nome="$grupo_nome" minimo=2 maximo=2 \
  'opcoes[0][nome]=Carne CI' 'opcoes[0][disponivel]=1' 'opcoes[1][nome]=Frango CI' 'opcoes[1][disponivel]=1' \
  'opcoes[2][nome]=Picanha CI' 'opcoes[2][preco]=5,00' 'opcoes[2][disponivel]=1' >/dev/null
curl -s -b "$gj" "$g/estoque/complementos" -o "$tmp/comps.html"
grupo="$(php -r 'if (preg_match("~<h2 id=\"grupo-(\d+)\">" . preg_quote($argv[2], "~") . "</h2>~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/comps.html" "$grupo_nome")"
opcao_id() { php -r 'if (preg_match("~<strong>" . preg_quote($argv[2], "~") . "</strong>.*?opcoes/(\d+)/pausar~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/comps.html" "$1"; }
carne="$(opcao_id 'Carne CI')"; frango="$(opcao_id 'Frango CI')"; picanha="$(opcao_id 'Picanha CI')"
[[ -n "$grupo" && -n "$carne" && -n "$frango" && -n "$picanha" ]] && ok "grupo de escolhas ($grupo)" || falha "grupo nao criado"
cheio=$((c1 + c2 + c3 + c4)); preco=$((cheio - 500))
preco_txt="$(php -r 'echo number_format($argv[1] / 100, 2, ",", "");' "$preco")"
nome="Combo CI $r4"
base_combo=(nome="$nome" unidade_venda=un preco="$preco_txt" controle=livre disponivel=1 categoria= "grupos[]=$grupo" "ordem_grupo[$grupo]=1"
  combo_enviado=1 combo_ativo=1 "combo_fixo_produto[0]=$p1" "combo_fixo_qtd[0]=1" "combo_fixo_produto[1]=$p2" "combo_fixo_qtd[1]=1"
  "combo_opcao[$carne][produto]=$p3" "combo_opcao[$frango][produto]=$p4" "combo_opcao[$picanha][produto]=$p3" "combo_opcao[$picanha][maximo]=1")
esperar "maximo total menor que os inclusos recusado" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" "${base_combo[@]}" combo_maximo=1 | cut -d' ' -f1)" "422"
esperar "cadastra o combo" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" "${base_combo[@]}" combo_maximo=4 | cut -d' ' -f1)" "303"
curl -s -b "$gj" "$g/estoque/produtos" -o "$tmp/prods.html"
combo="$(php -r 'if (preg_match("~estoque/produtos/(\d+)\"[^>]*>(?:(?!</li>).)*?" . preg_quote($argv[2], "~") . "~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/prods.html" "$nome")"
curl -s -b "$gj" "$g/estoque/produtos/$combo" -o "$tmp/ficha.html"
contem "ficha mostra o combo ligado" "$tmp/ficha.html" 'name="combo_ativo" value="1" data-combo-ativo checked'
contem "ficha guarda o maximo da opcao" "$tmp/ficha.html" "name=\"combo_opcao\[$picanha\]\[maximo\]\" type=\"number\" min=\"1\" max=\"30\" inputmode=\"numeric\" placeholder=\"Máx.\" title=\"Máximo desta opção no combo (vazio = livre)\" value=\"1\""

echo "Garcom lanca o combo"
curl -s -b "$wj" "$w/comandas/$comanda" -o "$tmp/com.html"
contem "garcom ve os inclusos" "$tmp/com.html" 'Incluso: 1x'
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$combo]=1" "comp[$combo][$picanha]=2" >/dev/null
case "$(aviso "$wj" "$w/comandas/$comanda")" in *"No máximo 1 de"*) ok "maximo por opcao";; *) falha "maximo por opcao";; esac
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$combo]=1" "comp[$combo][$carne]=1" >/dev/null
case "$(aviso "$wj" "$w/comandas/$comanda")" in *"pelo menos 2"*) ok "minimo do grupo";; *) falha "minimo do grupo";; esac
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$combo]=1" "comp[$combo][$carne]=1" "comp[$combo][$frango]=1" "comp[$combo][$picanha]=0" >/dev/null
case "$(aviso "$wj" "$w/comandas/$comanda")" in *"lançado"*) ok "combo lancado";; *) falha "combo nao lancado ($(aviso "$wj" "$w/comandas/$comanda"))";; esac
curl -s -b "$gj" "$c/comandas/$comanda" -o "$tmp/cx.html"
contem "conta agrupa o combo" "$tmp/cx.html" "conta-combo-titulo\">$nome"
contem "4 itens separados pelo preco do combo" "$tmp/cx.html" "<dt>Itens (4)</dt><dd>$(reais "$preco")</dd>"
item="$(php -r 'if (preg_match("~/itens/(\d+)/cancelar\" data-item-nome=\"o ~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/cx.html")"
enviar "$wj" "$w/comandas/$comanda/itens/$item/cancelar" "$w" "motivo=Cliente desistiu" >/dev/null
case "$(aviso "$wj" "$w/comandas/$comanda")" in *"$nome cancelado"*) ok "cancelar tira o combo inteiro";; *) falha "cancelar combo";; esac
curl -s -b "$gj" "$c/comandas/$comanda" -o "$tmp/cx.html"
contem "nenhum item do combo sobra na conta" "$tmp/cx.html" "<dt>Itens (0)</dt>"
enviar "$wj" "$w/comandas/$comanda/cancelar" "$w/comandas/$comanda" "motivo=Cliente desistiu" >/dev/null

echo "Cardapio online com combo"
curl -s "$base/$slug" -o "$tmp/menu.html"
contem "cardapio leva os dados do combo" "$tmp/menu.html" 'data-combo='
curl -s -o "$tmp/sacola.json" -H 'Content-Type: application/json' --data "{\"itens\":[{\"id\":$combo,\"quantidade\":1}]}" "$base/api/v1/restaurantes/$slug/sacola"
esperar "sacola traz o maximo da opcao" "$(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["itens"][0]["grupos"][0]["opcoes"]??[] as $o){ if((int)$o["id"]===(int)$argv[2]) echo $o["maximo"]; }' "$tmp/sacola.json" "$picanha")" "1"
esperar "sacola: combo + picanha em 4 itens, valor fechado" "$(php tests/http/combo-sacola-ci.php "$slug" "$combo" "$carne:1" "$picanha:1")" "$((preco + 500)) 4 $((preco + 500))"
case "$(php tests/http/combo-sacola-ci.php "$slug" "$combo" "$picanha:2")" in invalida*"No máximo 1"*) ok "sacola recusa passar do maximo da opcao";; *) falha "sacola sem o limite da opcao";; esac

enviar "$gj" "$g/estoque/produtos/$combo/excluir" "$g/estoque/produtos/$combo" >/dev/null # nao atrapalha os testes seguintes

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) nos combos."
  exit 1
fi
echo "Combos validados (cadastro, limites, divisao do preco, cancelar inteiro, cardapio online)."
