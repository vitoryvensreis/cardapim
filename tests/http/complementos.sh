#!/usr/bin/env bash
# Teste HTTP dos COMPLEMENTOS: gerente cadastra grupo de complementos (borda obrigatoria) e liga ao produto;
# cardapio online, sacola, pedido online, caixa e garcom cobram o preco com os complementos, baixam o insumo do complemento
# e mostram a escolha na conta e na cozinha. Escolha obrigatoria faltando ou opcao pausada sao recusadas.
# Uso: bash tests/http/complementos.sh <base> <slug> <base-do-fake>
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
token() { curl -s -c "$1" -b "$1" -o "$tmp/token.html" "$2"; grep -o -m1 'name="_csrf" value="[a-f0-9]*"' "$tmp/token.html" | sed 's/.*value="//; s/"$//' || true; }
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


echo "Gerente: grupo de complementos"
pagina "$gj" "$g/estoque/complementos" >/dev/null
contem "aba Complementos" "$tmp/pagina.html" 'Complementos'
nao_contem "complementos liberados (migracao aplicada)" "$tmp/pagina.html" 'estão sendo liberados'
insumo_nome="CI Catupiry $r4"
esperar "insumo do complemento" "$(enviar "$gj" "$g/estoque/insumos" "$g/estoque/insumos/novo" nome="$insumo_nome" unidade=kg estoque_atual='1' estoque_minimo='0' custo_unitario='30,00' ativo=1 | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/insumos" >/dev/null
insumo="$(php -r 'if (preg_match("~estoque/insumos/(\d+)\"[^>]*>(?:(?!</li>).)*?" . preg_quote($argv[2], "~") . "~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$insumo_nome")"
[[ -n "$insumo" ]] && ok "insumo criado ($insumo)" || falha "insumo nao encontrado"
estoque() { curl -s -b "$gj" "$g/estoque/insumos/$insumo" | php -r 'if (preg_match("~name=\"estoque_atual\"[^>]*value=\"([^\"]*)\"~", stream_get_contents(STDIN), $m)) echo number_format((float) str_replace(",", ".", str_replace(".", "", $m[1])), 3, ",", "");'; }
grupo_nome="CI Borda $r4"
esperar "grupo sem opcao recusado (422)" "$(enviar "$gj" "$g/estoque/complementos" "$g/estoque/complementos/novo" nome="$grupo_nome" minimo=1 maximo=1 | cut -d' ' -f1)" "422"
contem "explica que falta opcao" "$tmp/resp.html" 'Cadastre pelo menos uma opção'
esperar "minimo maior que maximo recusado (422)" "$(enviar "$gj" "$g/estoque/complementos" "$g/estoque/complementos/novo" nome="$grupo_nome" minimo=2 maximo=1 'opcoes[0][nome]=Catupiry' | cut -d' ' -f1)" "422"
esperar "cria o grupo com 2 opcoes" "$(enviar "$gj" "$g/estoque/complementos" "$g/estoque/complementos/novo" nome="$grupo_nome" minimo=1 maximo=1 \
  'opcoes[0][nome]=Catupiry CI' 'opcoes[0][preco]=8,00' 'opcoes[0][disponivel]=1' "opcoes[0][insumo]=$insumo" 'opcoes[0][quantidade]=0,050' \
  'opcoes[1][nome]=Sem borda CI' 'opcoes[1][preco]=' 'opcoes[1][disponivel]=1' | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/complementos" >/dev/null
contem "grupo listado" "$tmp/pagina.html" "$grupo_nome"
contem "preco da opcao" "$tmp/pagina.html" '+ R$ 8,00'
grupo="$(php -r 'if (preg_match("~<h2 id=\"grupo-(\d+)\">" . preg_quote(htmlspecialchars($argv[2]), "~") . "</h2>~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$grupo_nome")"
opcao_id() { php -r 'if (preg_match("~<strong>" . preg_quote($argv[2], "~") . "</strong>.*?opcoes/(\d+)/pausar~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$1"; }
cat_op="$(opcao_id 'Catupiry CI')"; sem_op="$(opcao_id 'Sem borda CI')"
[[ -n "$grupo" && -n "$cat_op" && -n "$sem_op" ]] && ok "ids do grupo e das opcoes ($grupo, $cat_op, $sem_op)" || falha "ids do grupo/opcoes nao encontrados"
pagina "$gj" "$g/estoque/complementos/$grupo" >/dev/null
contem "edicao traz a ficha da opcao" "$tmp/pagina.html" 'value="0,05"'

nome="CI Pizza $r4"
esperar "cadastra produto com a borda" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" nome="$nome" unidade_venda=un preco='40,00' controle=livre disponivel=1 categoria= "grupos[]=$grupo" "ordem_grupo[$grupo]=1" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/produtos" >/dev/null
pizza="$(php -r 'if (preg_match("~estoque/produtos/(\d+)\"[^>]*>(?:(?!</li>).)*?" . preg_quote($argv[2], "~") . "~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html" "$nome")"
[[ -n "$pizza" ]] && ok "produto criado ($pizza)" || falha "produto nao encontrado"
pagina "$gj" "$g/estoque/produtos/$pizza" >/dev/null
contem "edicao: grupo marcado" "$tmp/pagina.html" "value=\"$grupo\" checked"
contem "formulario aceita foto" "$tmp/pagina.html" 'name="foto"'
esperar "grupo em produto por quilo recusado (422)" "$(enviar "$gj" "$g/estoque/produtos" "$g/estoque/produtos/novo" nome="CI Kg $r4" unidade_venda=kg preco='40,00' controle=livre disponivel=1 categoria= "grupos[]=$grupo" | cut -d' ' -f1)" "422"

echo "Cardapio online e sacola"
curl -s "$base/$slug" -o "$tmp/cardapio.html"
contem "produto com grupos no cardapio" "$tmp/cardapio.html" "data-produto=\"$pizza\""
contem "grupos no botao do produto" "$tmp/cardapio.html" 'data-grupos='
contem "script dos complementos" "$tmp/cardapio.html" '/assets/js/complementos.js'
curl -s -o "$tmp/sacola.json" -H 'Content-Type: application/json' --data "{\"itens\":[{\"id\":$pizza,\"quantidade\":1}]}" "$base/api/v1/restaurantes/$slug/sacola"
esperar "sacola traz o grupo" "$(json "$tmp/sacola.json" itens.0.grupos.0.nome)" "$grupo_nome"
esperar "sacola traz o preco da opcao" "$(json "$tmp/sacola.json" itens.0.grupos.0.opcoes.0.centavos)" "800"
nao_contem "sacola nao expoe a ficha" "$tmp/sacola.json" 'ficha'

echo "Caixa: lancar com complemento"
pagina "$gj" "$c/mesas/gerenciar" >/dev/null
mesa="$(php -r 'preg_match_all("~/caixa/mesas/(\d+)/excluir~", file_get_contents($argv[1]), $m); echo end($m[1]);' "$tmp/pagina.html")"
r="$(enviar "$gj" "$c/mesas/$mesa/abrir" "$c/mesas")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 | head -1)"
[[ -n "$comanda" ]] && ok "comanda aberta ($comanda)" || falha "comanda nao abriu ($r)"
pagina "$gj" "$c/comandas/$comanda" >/dev/null
contem "botao do produto com os grupos" "$tmp/pagina.html" "data-produto-id=\"$pizza\""
enviar "$gj" "$c/comandas/$comanda/itens" "$c/comandas/$comanda" produto="$pizza" quantidade=1 >/dev/null
esperar "borda obrigatoria faltando" "$(aviso "$gj" "$c/comandas/$comanda")" "\"$nome\": Escolha uma opção em \"$grupo_nome\"."
enviar "$gj" "$c/comandas/$comanda/itens" "$c/comandas/$comanda" produto="$pizza" quantidade=2 "complementos[]=$cat_op" >/dev/null
aviso "$gj" "$c/comandas/$comanda" >/dev/null
contem "lancado" "$tmp/aviso.html" 'lançado'
contem "conta com 2 x (40 + 8)" "$tmp/aviso.html" 'R$ 96,00'
contem "conta mostra o complemento" "$tmp/aviso.html" "$grupo_nome: Catupiry CI"
esperar "estoque do complemento baixou 0,100 kg" "$(estoque)" "0,900"
item="$(php -r 'if (preg_match("~itens/(\d+)/cancelar\"[^>]*data-item-nome=\"[^\"]*CI Pizza~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/aviso.html")"
if [[ -n "$item" ]]; then
  enviar "$gj" "$c/comandas/$comanda/itens/$item/cancelar" "$c/comandas/$comanda" motivo='Lançado errado' >/dev/null
  esperar "cancelar devolve o estoque do complemento" "$(estoque)" "1,000"
else
  falha "item com complemento sem botao de cancelar"
fi

echo "Garcom: lancar com complemento"
esperar "garcom lanca com borda" "$(enviar "$wj" "$w/comandas/$comanda/itens" "$w/comandas/$comanda" "qtd[$pizza]=1" "comp[$pizza][]=$sem_op" | cut -d' ' -f1)" "303"
esperar "aviso de 1 item" "$(aviso "$wj" "$w/comandas/$comanda")" "1 item lançado."
contem "comanda do garcom mostra a escolha" "$tmp/aviso.html" "$grupo_nome: Sem borda CI"
pagina "$wj" "$w/comandas/$comanda" >/dev/null
contem "folha do garcom tem os complementos" "$tmp/pagina.html" "name=\"comp\[$pizza\]\[\]\""
esperar "garcom sem a borda" "$(enviar "$wj" "$w/comandas/$comanda/itens" "$w/comandas/$comanda" "qtd[$pizza]=1" | cut -d' ' -f1)" "303"
aviso "$wj" "$w/comandas/$comanda" >/dev/null
contem "garcom: borda obrigatoria faltando" "$tmp/aviso.html" 'Escolha uma opção'
enviar "$gj" "$c/comandas/$comanda/cancelar" "$c/comandas/$comanda" motivo='Teste' >/dev/null

echo "Pausar a opcao"
pagina "$gj" "$g/estoque/complementos" >/dev/null
esperar "pausar a opcao" "$(enviar "$gj" "$g/estoque/complementos/opcoes/$cat_op/pausar" "$g/estoque/complementos" | cut -d' ' -f1)" "303"
curl -s -o "$tmp/sacola.json" -H 'Content-Type: application/json' --data "{\"itens\":[{\"id\":$pizza,\"quantidade\":1}]}" "$base/api/v1/restaurantes/$slug/sacola"
esperar "opcao pausada aparece indisponivel" "$(json "$tmp/sacola.json" itens.0.grupos.0.opcoes.0.disponivel)" "false"
enviar "$gj" "$g/estoque/complementos/opcoes/$cat_op/pausar" "$g/estoque/complementos" >/dev/null

echo "Pedido online com complemento"
t="$(token "$gj" "$g/entrega-pagamento")"
curl -s -o /dev/null -b "$gj" -c "$gj" --data-urlencode "_csrf=$t" --data-urlencode modo=sempre "$g/perfil/horarios"
enviar "$gj" "$g/entrega-pagamento" "$g/entrega-pagamento" aceita_entrega=1 aceita_retirada=1 retirada_minutos=20 pag_pix_entrega=1 pag_debito=1 pag_credito=1 pag_dinheiro=1 >/dev/null
aj="$tmp/admin"
login_equipe "$aj" ci_master >/dev/null
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/conectar"
sessao_wpp="$(ls -t "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-cardapim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
curl -s "$fake/_teste/wpp-conectar/$sessao_wpp" >/dev/null
cj="$tmp/cliente"
fone="859444${r4}5"
csrf="$(sessao "$cj")"
post "$cj" "$csrf" /api/v1/cliente/codigo "{\"telefone\":\"$fone\"}" >/dev/null
cod="$(ultimo_codigo "$sessao_wpp|55$fone|")"
post "$cj" "$csrf" /api/v1/cliente/entrar "{\"codigo\":\"$cod\"}" >/dev/null
csrf="$(json "$tmp/r.json" csrf)"
if [[ "$(json "$tmp/r.json" etapa)" == "nome" ]]; then post "$cj" "$csrf" /api/v1/cliente/cadastro '{"nome":"Borda Cliente"}' >/dev/null; fi
csrf="$(sessao "$cj")"
pedido() { echo "{\"chave\":\"$(chave)\",\"tipo\":\"retirada\",\"forma_pagamento\":\"debito\",\"itens\":$1}"; }
esperar "sem a borda obrigatoria (409)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$(pedido "[{\"id\":$pizza,\"quantidade\":1}]")")" "409"
contem "mensagem cita o grupo" "$tmp/r.json" 'Escolha uma'
esperar "duas bordas recusadas (409)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$(pedido "[{\"id\":$pizza,\"quantidade\":1,\"complementos\":[{\"id\":$cat_op,\"quantidade\":1},{\"id\":$sem_op,\"quantidade\":1}]}]")")" "409"
esperar "pedido com a borda de catupiry" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$(pedido "[{\"id\":$pizza,\"quantidade\":1,\"observacao\":\"bem assada\",\"complementos\":[{\"id\":$cat_op,\"quantidade\":1}]}]")")" "201"
p1="$(json "$tmp/r.json" pedido.id)"
get "$cj" "/api/v1/pedidos/$p1" >/dev/null
esperar "subtotal 40 + 8 (R\$ 48,00)" "$(json "$tmp/r.json" subtotal_centavos)" "4800"
esperar "acompanhamento mostra o complemento" "$(json "$tmp/r.json" itens.0.complementos)" "$grupo_nome: Catupiry CI"
esperar "estoque do complemento baixou no pedido online" "$(estoque)" "0,950"
pagina "$gj" "$c/delivery/$p1" >/dev/null
contem "delivery mostra o complemento" "$tmp/pagina.html" "$grupo_nome: Catupiry CI"
enviar "$gj" "$c/delivery/$p1/aceitar" "$c/delivery/$p1" >/dev/null
curl -s -b "$gj" "$g/cozinha/conteudo" -o "$tmp/cozinha.html"
contem "cozinha mostra o complemento" "$tmp/cozinha.html" "$grupo_nome: Catupiry CI"

echo
if (( falhas > 0 )); then
  echo "$falhas verificacao(oes) falharam."
  exit 1
fi
echo "Complementos: tudo certo."
