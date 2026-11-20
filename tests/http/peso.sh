#!/usr/bin/env bash
# Teste HTTP da VENDA POR PESO: gerente cadastra produto por quilo (preco do quilo, venda minima, porcoes do cardapio
# online e ficha por 1 kg); caixa e garcom lancam o peso (0,846 kg = R$ 50,68); estoque baixa e volta em gramas;
# cardapio online so mostra o produto com porcoes e o pedido so aceita uma porcao cadastrada.
# Uso: bash tests/http/peso.sh <base> <slug> <base-do-fake>
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
r4="$(printf '%04d' $((RANDOM % 10000)))"

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
json() { php -r '$d = json_decode(file_get_contents($argv[1]), true); $v = $d; foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : ($v === null ? "null" : json_encode($v)));' "$1" "$2"; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login_equipe() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
enviar() { # jar url pagina-do-token [campo=valor ...] -> "codigo location"
  local jar="$1" url="$2" pag="$3" t args=()
  shift 3
  t="$(token "$jar" "$pag")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -c "$1" -w '%{http_code}' "$2"; }
sessao() { curl -s -c "$1" -b "$1" -o "$tmp/sessao.json" "$base/api/v1/cliente"; json "$tmp/sessao.json" csrf; }
post() { curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" --data "$4" "$base$3"; }
get() { curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' "$base$2"; }
ultimo_codigo() { curl -s "$fake/_teste/wpp-mensagens" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $l = $d["linhas"] ?? []; for ($i = count($l) - 1; $i >= 0; $i--) { if (strpos($l[$i], $argv[1]) !== false && preg_match("/\*(\d{6})\*/", $l[$i], $m)) { echo $m[1]; exit; } }' "$1"; }
chave() { php -r 'echo bin2hex(random_bytes(16));'; }

gj="$tmp/gerente"; wj="$tmp/garcom"
esperar "gerente entra" "$(login_equipe "$gj" ci_gerente)" "303"
esperar "garcom entra" "$(login_equipe "$wj" ci_garcom)" "303"

echo "Gerente: produto por quilo"
insumo_nome="CI Picanha crua $r4"
esperar "insumo em kg" "$(enviar "$gj" "$g/estoque/insumos" "$g/estoque/insumos/novo" nome="$insumo_nome" unidade=kg estoque_atual='5' estoque_minimo='1' custo_unitario='40,00' ativo=1 | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/insumos" >/dev/null
insumo="$(php -r 'if (preg_match("~estoque/insumos/(\d+)\"[^>]*>(?:(?!</li>).)*?" . preg_quote($argv[2], "~") . "~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$insumo_nome")"
[[ -n "$insumo" ]] && ok "insumo criado ($insumo)" || falha "insumo nao encontrado"
estoque() { curl -s -b "$gj" "$g/estoque/insumos/$insumo" | php -r 'if (preg_match("~name=\"estoque_atual\"[^>]*value=\"([^\"]*)\"~", stream_get_contents(STDIN), $m)) echo number_format((float) str_replace(",", ".", str_replace(".", "", $m[1])), 3, ",", "");'; }
pagina "$gj" "$g/estoque/produtos/novo" >/dev/null
contem "formulario tem 'Como você vende'" "$tmp/pagina.html" 'Como você vende'
contem "opcao por quilo" "$tmp/pagina.html" 'value="kg"'
contem "campos das porcoes" "$tmp/pagina.html" 'name="porcoes\[\]"'
nome="CI Picanha $r4"
base_produto=(nome="$nome" unidade_venda=kg preco='59,90' controle=estoque "ficha_insumo[]=$insumo" "ficha_quantidade[]=1" disponivel=1 categoria=)
esperar "porcao invalida recusada (422)" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" "${base_produto[@]}" venda_minima='0,100' "porcoes[]=abc" | cut -d' ' -f1)" "422"
contem "mensagem da porcao" "$tmp/resp.html" 'Digite cada porção com até 3 casas'
esperar "minimo invalido recusado (422)" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" "${base_produto[@]}" venda_minima='x' | cut -d' ' -f1)" "422"
esperar "cadastra produto por quilo" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" "${base_produto[@]}" venda_minima='0,100' "porcoes[]=0,500" "porcoes[]=0,250" "porcoes[]=" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/produtos" >/dev/null
contem "lista mostra o preco do quilo" "$tmp/pagina.html" 'R$ 59,90/kg'
pic="$(php -r 'if (preg_match("~estoque/produtos/(\d+)\"[^>]*>(?:(?!</li>).)*?" . preg_quote($argv[2], "~") . "~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$nome")"
[[ -n "$pic" ]] && ok "produto criado ($pic)" || falha "produto nao encontrado na lista"
pagina "$gj" "$g/estoque/produtos/$pic" >/dev/null
contem "edicao: por quilo marcado" "$tmp/pagina.html" 'value="kg" checked'
contem "edicao: rotulo do preco" "$tmp/pagina.html" 'Preço do quilo'
contem "edicao: venda minima" "$tmp/pagina.html" 'value="0,100"'
contem "edicao: porcoes em ordem (250 g)" "$tmp/pagina.html" 'value="0,250"'
contem "edicao: ficha por 1 kg vendido" "$tmp/pagina.html" '1 kg vendido'
sorvete="CI Sorvete $r4"
esperar "produto por quilo sem porcao" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" nome="$sorvete" unidade_venda=kg preco='30,00' controle=livre disponivel=1 categoria= | cut -d' ' -f1)" "303"

echo "Caixa: lancar o peso"
pagina "$gj" "$c/mesas/gerenciar" >/dev/null
mesa="$(php -r 'preg_match_all("~/caixa/mesas/(\d+)/excluir~", file_get_contents($argv[1]), $m); echo end($m[1]);' "$tmp/pagina.html")"
r="$(enviar "$gj" "$c/mesas/$mesa/abrir" "$c/mesas")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 | head -1)"
[[ -n "$comanda" ]] && ok "comanda aberta ($comanda)" || falha "comanda nao abriu ($r)"
pagina "$gj" "$c/comandas/$comanda" >/dev/null
contem "botao do produto avisa a unidade" "$tmp/pagina.html" "data-produto-id=\"$pic\"[^>]*data-produto-centavos=\"5990\""
contem "campo do peso na folha do produto" "$tmp/pagina.html" 'data-mask="peso"'
enviar "$gj" "$c/comandas/$comanda/itens" "$c/comandas/$comanda" produto="$pic" quantidade='0,050' >/dev/null
esperar "abaixo do minimo recusado" "$(aviso "$gj" "$c/comandas/$comanda")" "\"$nome\": O mínimo para este produto é 100 g."
enviar "$gj" "$c/comandas/$comanda/itens" "$c/comandas/$comanda" produto="$pic" quantidade='0,8463' >/dev/null
aviso "$gj" "$c/comandas/$comanda" >/dev/null
contem "4 casas recusadas" "$tmp/aviso.html" 'até 3 casas'
enviar "$gj" "$c/comandas/$comanda/itens" "$c/comandas/$comanda" produto="$pic" quantidade='0,846' >/dev/null
esperar "lanca 0,846 kg" "$(aviso "$gj" "$c/comandas/$comanda")" "846 g $nome lançado."
contem "conta mostra 846 g" "$tmp/aviso.html" '846 g'
contem "conta mostra R\$ 50,68" "$tmp/aviso.html" 'R$ 50,68'
contem "conta mostra o preco do quilo" "$tmp/aviso.html" 'R$ 59,90/kg'
esperar "estoque baixou 0,846 kg" "$(estoque)" "4,154"
read -r cerveja < <(php -r 'preg_match_all("~data-produto-id=\"(\d+)\" data-produto-nome=\"([^\"]*)\"~", file_get_contents($argv[1]), $m, PREG_SET_ORDER); foreach ($m as $p) { if (str_contains($p[2], "Cerveja")) { echo $p[1], "\n"; exit; } }' "$tmp/aviso.html") || true
enviar "$gj" "$c/comandas/$comanda/itens" "$c/comandas/$comanda" produto="$cerveja" quantidade='0,5' >/dev/null
esperar "por unidade nao aceita 0,5" "$(aviso "$gj" "$c/comandas/$comanda")" "Quantidade de 1 a 99."
item="$(php -r 'if (preg_match("~itens/(\d+)/cancelar\"[^>]*data-item-nome=\"846 g~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/aviso.html")"
[[ -n "$item" ]] && ok "item pesado na conta ($item)" || falha "item pesado sem botao de cancelar"
enviar "$gj" "$c/comandas/$comanda/itens/$item/cancelar" "$c/comandas/$comanda" motivo='Lançado errado' >/dev/null
esperar "cancelar o item pesado" "$(aviso "$gj" "$c/comandas/$comanda")" "846 g $nome cancelado. O estoque foi devolvido."
esperar "estoque voltou para 5 kg" "$(estoque)" "5,000"

echo "Garcom: lancar o peso"
esperar "garcom lanca 0,300 kg" "$(enviar "$wj" "$w/comandas/$comanda/itens" "$w/comandas/$comanda" "qtd[$pic]=0,300" "obs[$pic]=ao ponto" "qtd[$cerveja]=0" | cut -d' ' -f1)" "303"
esperar "aviso de 1 item" "$(aviso "$wj" "$w/comandas/$comanda")" "1 item lançado."
contem "comanda do garcom mostra 300 g" "$tmp/aviso.html" '300 g'
contem "valor de 0,300 kg (R\$ 17,97)" "$tmp/aviso.html" 'R$ 17,97'
pagina "$wj" "$w/cardapio" >/dev/null
contem "cardapio do garcom mostra o preco do quilo" "$tmp/pagina.html" 'R$ 59,90/kg'
esperar "estoque baixou 0,300 kg" "$(estoque)" "4,700"
enviar "$gj" "$c/comandas/$comanda/cancelar" "$c/comandas/$comanda" motivo='Teste' >/dev/null
esperar "cancelar a comanda devolve o estoque" "$(estoque)" "5,000"

echo "Cardapio online"
curl -s "$base/$slug" -o "$tmp/cardapio.html"
contem "produto por quilo com porcoes aparece" "$tmp/cardapio.html" "data-produto=\"$pic\""
contem "porcoes no botao do produto" "$tmp/cardapio.html" 'data-porcoes="250,500"'
contem "preco da menor porcao" "$tmp/cardapio.html" '250 g por R$ 14,98'
nao_contem "produto por quilo sem porcao fica de fora" "$tmp/cardapio.html" "$sorvete"
curl -s -o "$tmp/sacola.json" -H 'Content-Type: application/json' --data "{\"itens\":[{\"id\":$pic,\"quantidade\":1,\"porcao\":250}]}" "$base/api/v1/restaurantes/$slug/sacola"
esperar "sacola conferida traz a unidade" "$(json "$tmp/sacola.json" itens.0.unidade)" "kg"
esperar "sacola conferida traz as porcoes" "$(json "$tmp/sacola.json" itens.0.porcoes)" "[250,500]"

echo "Pedido online com porcao"
t="$(token "$gj" "$g/entrega-pagamento")"
curl -s -o /dev/null -b "$gj" -c "$gj" --data-urlencode "_csrf=$t" --data-urlencode modo=sempre "$g/perfil/horarios"
enviar "$gj" "$g/entrega-pagamento" "$g/entrega-pagamento" aceita_entrega=1 aceita_retirada=1 retirada_minutos=20 pag_pix_entrega=1 pag_debito=1 pag_credito=1 pag_dinheiro=1 >/dev/null
aj="$tmp/admin"
login_equipe "$aj" ci_master >/dev/null
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/conectar"
sessao_wpp="$(ls -t "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-deliveryzim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
curl -s "$fake/_teste/wpp-conectar/$sessao_wpp" >/dev/null
curl -s -b "$aj" "$base/painel/integracoes/whatsapp/estado" | grep -q '"estado":"conectado"' && ok "WhatsApp da plataforma conectado" || falha "WhatsApp da plataforma nao conectou"
cj="$tmp/cliente"
fone="859333${r4}5"
csrf="$(sessao "$cj")"
post "$cj" "$csrf" /api/v1/cliente/codigo "{\"telefone\":\"$fone\"}" >/dev/null
cod="$(ultimo_codigo "$sessao_wpp|55$fone|")"
post "$cj" "$csrf" /api/v1/cliente/entrar "{\"codigo\":\"$cod\"}" >/dev/null
csrf="$(json "$tmp/r.json" csrf)"
if [[ "$(json "$tmp/r.json" etapa)" == "nome" ]]; then post "$cj" "$csrf" /api/v1/cliente/cadastro '{"nome":"Peso Cliente"}' >/dev/null; fi
csrf="$(sessao "$cj")"
[[ "$(json "$tmp/sessao.json" cliente.primeiro_nome)" == "Peso" ]] && ok "cliente entrou" || falha "cliente nao entrou"
pedido() { echo "{\"chave\":\"$(chave)\",\"tipo\":\"retirada\",\"forma_pagamento\":\"debito\",\"itens\":$1}"; }
esperar "porcao que nao existe (409)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$(pedido "[{\"id\":$pic,\"quantidade\":1,\"porcao\":300}]")")" "409"
esperar "produto por quilo sem porcao (409)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$(pedido "[{\"id\":$pic,\"quantidade\":1}]")")" "409"
esperar "porcao em produto por unidade (409)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$(pedido "[{\"id\":$cerveja,\"quantidade\":1,\"porcao\":250}]")")" "409"
esperar "pedido com 2 porcoes de 250 g" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$(pedido "[{\"id\":$pic,\"quantidade\":2,\"porcao\":250}]")")" "201"
p1="$(json "$tmp/r.json" pedido.id)"
get "$cj" "/api/v1/pedidos/$p1" >/dev/null
esperar "subtotal de 0,500 kg (R\$ 29,95)" "$(json "$tmp/r.json" subtotal_centavos)" "2995"
esperar "item mostra 500 g" "$(json "$tmp/r.json" itens.0.quantidade_texto)" "500 g"
esperar "estoque baixou 0,500 kg no pedido online" "$(estoque)" "4,500"

echo
if (( falhas > 0 )); then
  echo "$falhas verificacao(oes) falharam."
  exit 1
fi
echo "Venda por peso: tudo certo."
