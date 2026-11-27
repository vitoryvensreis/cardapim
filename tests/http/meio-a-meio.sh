#!/usr/bin/env bash
# Meio a Meio: gerente cadastra o produto (sabores escolhidos, minimo/maximo, regra de preco),
# validacoes (minimo > maximo, junto com combo, poucos sabores), garcom lanca respeitando os limites,
# uma linha so pelo preco fracionado e sacola do cardapio online com o grupo de sabores.
# Uso: bash tests/http/meio-a-meio.sh <base> <slug> <base-do-fake>
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
off=900000000000
esperar "gerente autenticado" "$(login "$gj" ci_gerente)" "303"
esperar "gerente libera remover itens e cancelar comanda ao garcom" "$(enviar "$gj" "$g/entrega-pagamento/garcom" "$g/entrega-pagamento" remover_itens=1 cancelar_comanda=1 | cut -d' ' -f1)" "303"
esperar "garcom autenticado" "$(login "$wj" ci_garcom)" "303"

echo "Cadastro do Meio a Meio"
curl -s -b "$wj" "$w" -o "$tmp/mesas.html"
mesa="$(php -r 'if (preg_match("~/garcom/mesas/(\d+)/abrir~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/mesas.html")"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
curl -s -b "$wj" "$w/comandas/$comanda" -o "$tmp/com.html"
mapfile -t prods < <(php -r 'foreach (explode("<li class=\"produto-linha", file_get_contents($argv[1])) as $li) { if (!str_contains($li, "data-comps") && preg_match("~data-preco=\"(\d+)\".*?name=\"qtd\[(\d+)\]\" value=\"0\" min=\"0\" max=\"(\d+)\"~s", $li, $m) && (int) $m[3] >= 6) echo $m[2], " ", $m[1], "\n"; }' "$tmp/com.html" | head -3)
read -r p1 c1 <<<"${prods[0]:-}"; read -r p2 c2 <<<"${prods[1]:-}"; read -r p3 c3 <<<"${prods[2]:-}"
[[ -n "${p3:-}" ]] && ok "sabores para o Meio a Meio ($p1 $p2 $p3)" || falha "produtos insuficientes"
nome="Pizza Meio CI $r4"
base_meio=(nome="$nome" unidade_venda=un preco="1,00" controle=livre disponivel=1 categoria=
  meio_enviado=1 meio_ativo=1 meio_origem=produtos "meio_sabores[]=$p1" "meio_sabores[]=$p2" "meio_sabores[]=$p3" meio_regra=fracionado)
esperar "minimo maior que o maximo recusado" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" "${base_meio[@]}" meio_minimo=3 meio_maximo=2 | cut -d' ' -f1)" "422"
esperar "Meio a Meio junto com combo recusado" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" "${base_meio[@]}" meio_minimo=2 meio_maximo=2 combo_enviado=1 combo_ativo=1 "combo_fixo_produto[0]=$p1" "combo_fixo_qtd[0]=1" | cut -d' ' -f1)" "422"
esperar "menos sabores que o maximo recusado" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" nome="$nome" unidade_venda=un preco="1,00" controle=livre disponivel=1 categoria= meio_enviado=1 meio_ativo=1 meio_origem=produtos "meio_sabores[]=$p1" meio_minimo=2 meio_maximo=2 meio_regra=fracionado | cut -d' ' -f1)" "422"
esperar "cadastra o Meio a Meio (2 sabores fixos)" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" "${base_meio[@]}" meio_minimo=2 meio_maximo=2 | cut -d' ' -f1)" "303"
curl -s -b "$gj" "$g/estoque/produtos" -o "$tmp/prods.html"
meio="$(php -r 'if (preg_match("~estoque/produtos/(\d+)\"[^>]*>(?:(?!</li>).)*?" . preg_quote($argv[2], "~") . "~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/prods.html" "$nome")"
[[ -n "$meio" ]] && ok "produto criado ($meio)" || falha "produto nao encontrado"
curl -s -b "$gj" "$g/estoque/produtos/$meio" -o "$tmp/ficha.html"
contem "ficha mostra o Meio a Meio ligado" "$tmp/ficha.html" 'name="meio_ativo" value="1" data-meio-ativo checked'
contem "ficha guarda os sabores" "$tmp/ficha.html" "name=\"meio_sabores\[\]\" value=\"$p2\" checked"
contem "ficha guarda a regra" "$tmp/ficha.html" 'name="meio_regra" value="fracionado" checked'
contem "ficha avisa que nao vai para o iFood" "$tmp/ficha.html" 'não é publicado no iFood'

echo "Garcom lanca o Meio a Meio"
curl -s -b "$wj" "$w/comandas/$comanda" -o "$tmp/com.html"
contem "garcom ve os sabores com a regra de preco" "$tmp/com.html" 'data-meio-regra="fracionado"'
contem "garcom ve o sabor como opcao" "$tmp/com.html" "name=\"comp\[$meio\]\[$((off + p1))\]\""
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$meio]=1" "comp[$meio][$((off + p1))]=1" >/dev/null
case "$(aviso "$wj" "$w/comandas/$comanda")" in *"pelo menos 2"*) ok "1 sabor recusado (minimo 2)";; *) falha "minimo de sabores ($(aviso "$wj" "$w/comandas/$comanda"))";; esac
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$meio]=1" "comp[$meio][$((off + p1))]=1" "comp[$meio][$((off + p2))]=1" "comp[$meio][$((off + p3))]=1" >/dev/null
case "$(aviso "$wj" "$w/comandas/$comanda")" in *"lançado"*) falha "3 sabores aceitos (maximo 2)";; *) ok "3 sabores recusados (maximo 2)";; esac
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$meio]=1" "comp[$meio][$((off + p1))]=1" "comp[$meio][$((off + p2))]=1" >/dev/null
case "$(aviso "$wj" "$w/comandas/$comanda")" in *"lançado"*) ok "2 sabores lancados";; *) falha "Meio a Meio nao lancado ($(aviso "$wj" "$w/comandas/$comanda"))";; esac
preco=$(( (c1 + c2 + 1) / 2 ))
curl -s -b "$gj" "$c/comandas/$comanda" -o "$tmp/cx.html"
contem "uma linha so, pelo preco fracionado (media dos sabores)" "$tmp/cx.html" "<dt>Itens (1)</dt><dd>$(reais "$preco")</dd>"
contem "conta mostra os sabores em fracao" "$tmp/cx.html" '1/2 '
enviar "$wj" "$w/comandas/$comanda/cancelar" "$w/comandas/$comanda" "motivo=Cliente desistiu" >/dev/null

echo "Cardapio online"
curl -s -o "$tmp/sacola.json" -H 'Content-Type: application/json' --data "{\"itens\":[{\"id\":$meio,\"quantidade\":1}]}" "$base/api/v1/restaurantes/$slug/sacola"
esperar "sacola traz o grupo de sabores com a regra" "$(json "$tmp/sacola.json" itens.0.grupos.0.meio.regra)" "fracionado"
esperar "sacola: 2 a 2 sabores" "$(json "$tmp/sacola.json" itens.0.grupos.0.minimo)-$(json "$tmp/sacola.json" itens.0.grupos.0.maximo)" "2-2"

echo "iFood"
curl -s -b "$gj" "$g/integracoes/ifood/publicar" -o "$tmp/ifood.html"
if grep -q "$nome" "$tmp/ifood.html"; then
  contem "publicar no iFood avisa o Meio a Meio" "$tmp/ifood.html" 'Meio a Meio'
else
  ok "tela do iFood sem o produto (integracao desligada no CI)"
fi

enviar "$gj" "$g/estoque/produtos/$meio/excluir" "$g/estoque/produtos/$meio" >/dev/null # nao atrapalha os testes seguintes

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no Meio a Meio."
  exit 1
fi
echo "Meio a Meio validado (cadastro, limites, preco fracionado, uma linha, sacola, aviso do iFood)."
