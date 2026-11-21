#!/usr/bin/env bash
# Impressao por produto (vias de producao): categoria/produto marcados pelos apelidos das impressoras do Agent,
# "So os itens novos" do garcom e do caixa divididos por estacao, item sem marcacao na impressora padrao da
# producao, estacao desligada bloqueia o envio inteiro e pedido do delivery aceito manda as vias.
# Uso: bash tests/http/impressao-produto.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
c="$base/$slug/caixa"
w="$base/$slug/garcom"
g="$base/$slug/gerencia"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
senha='Senha#CI2026'
falhas=0

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
enviar() {
  local jar="$1" url="$2" origem="$3" t args=()
  shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
imprimir() {
  local jar="$1" url="$2" origem="$3" t args=()
  shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/imp.json" -c "$jar" -b "$jar" -w '%{http_code}' -H 'Accept: application/json' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
vincular() {
  local jar="$1" nome="$2" csrf
  curl -s -b "$jar" -o "$tmp/csrf.json" "$base/api/v1/desktop/csrf"
  csrf="$(json "$tmp/csrf.json" csrf)"
  curl -s -b "$jar" -o "$tmp/vinculo.json" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    -d "{\"nome\":\"$nome\"}" "$base/api/v1/desktop/vincular"
  json "$tmp/vinculo.json" token
}
registrar() { curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d "$2" "$base/api/v1/desktop/impressoras"; }
agente() { curl -s -o "$tmp/ag.json" -w '%{http_code}' -H "Authorization: Bearer $1" "$base/api/v1/desktop/eventos?desde=999999999&impressoes=1&impressoras=1"; }
cupom_da() { # chave -> json dos blocos do cupom dessa impressora ("" se nao veio)
  php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["impressoes"]??[] as $j){ if($j["impressora"]===$argv[2]){ echo json_encode(["id"=>$j["id"],"titulo"=>$j["titulo"],"blocos"=>$j["blocos"]],JSON_UNESCAPED_UNICODE); exit; } }' "$tmp/ag.json" "$1"
}
qtd_cupons() { php -r '$d=json_decode(file_get_contents($argv[1]),true); echo count($d["impressoes"]??[]);' "$tmp/ag.json"; }
confirmar_todos() {
  for id in $(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["impressoes"]??[] as $j) echo $j["id"], "\n";' "$tmp/ag.json"); do
    curl -s -o /dev/null -X POST -H "Authorization: Bearer $ta" -H 'Content-Type: application/json' -d '{"ok":true}' "$base/api/v1/desktop/impressoes/$id/resultado"
  done
}

cx="$tmp/caixa"; gj="$tmp/gerente"; wj="$tmp/garcom"
esperar "caixa autenticado" "$(login "$cx" ci_caixa)" "303"
esperar "gerente autenticado" "$(login "$gj" ci_gerente)" "303"
esperar "garcom autenticado" "$(login "$wj" ci_garcom)" "303"

echo "Impressoras e producao"
ta="$(vincular "$cx" "PC-SALAO-PRODUCAO")"
tb="$(vincular "$gj" "PC-CHURRASQUEIRA-PRODUCAO")"
esperar "salao: Cozinha e Bar" "$(registrar "$ta" '{"impressoras":[{"chave":"coz0000000001","apelido":"Cozinha"},{"chave":"bar0000000001","apelido":"Bar"}]}')" "200"
esperar "churrasqueira (computador desligado)" "$(registrar "$tb" '{"impressoras":[{"chave":"chu0000000001","apelido":"Churrasqueira"}]}')" "200"
agente "$ta" >/dev/null
curl -s -b "$gj" "$g/integracoes/impressoras" -o "$tmp/ger.html"
contem "bloco Impressao por produto na gerencia" "$tmp/ger.html" 'Impressão por produto'
id_de() { php -r 'if (preg_match("~id=\"p-producao-(\d+)\"[^>]*>\s*<span>" . preg_quote($argv[2], "~") . "</span>~u", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/ger.html" "$1"; }
cozinha="$(id_de Cozinha)"; bar="$(id_de Bar)"; churras="$(id_de Churrasqueira)"
[[ -n "$cozinha" && -n "$bar" && -n "$churras" ]] && ok "impressoras pelos apelidos ($cozinha, $bar, $churras)" || falha "apelidos nao encontrados"
enviar "$gj" "$g/integracoes/impressoras" "$g/integracoes/impressoras" "producao=$cozinha" >/dev/null

echo "Categoria e produto pelos apelidos"
curl -s -b "$wj" "$w" -o "$tmp/mesas.html"
mesa="$(php -r 'if (preg_match("~/garcom/mesas/(\d+)/abrir~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/mesas.html")"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
curl -s -b "$wj" "$w/comandas/$comanda" -o "$tmp/com.html"
# Cerveja (vai para o Bar pela categoria) e um produto de OUTRA categoria (sem marcacao: Cozinha).
read -r cat_cerveja cerveja cat_outro outro < <(php -r '
  $html = file_get_contents($argv[1]); $prods = [];
  foreach (preg_split("~<section class=\"produtos-grupo\" id=\"cat-~", $html) as $i => $sec) {
    if ($i === 0 || !preg_match("~^c(\d+)\"~", $sec, $cm)) continue; // id da categoria na tela: "c12"
    foreach (explode("<li class=\"produto-linha", $sec) as $li) {
      if (preg_match("~data-nome=\"([^\"]*)\".*?name=\"qtd\[(\d+)\]\" value=\"0\" min=\"0\" max=\"(\d+)\"~s", $li, $m) && (int) $m[3] >= 6 && !str_contains($li, "data-comps")) $prods[] = [$cm[1], $m[2], $m[1]];
    }
  }
  $cerv = null; foreach ($prods as $p) { if (str_contains($p[2], "Cerveja")) { $cerv = $p; break; } }
  if ($cerv === null) exit;
  foreach ($prods as $p) { if ($p[0] !== $cerv[0]) { echo $cerv[0], " ", $cerv[1], " ", $p[0], " ", $p[1], "\n"; exit; } }' "$tmp/com.html") || true
[[ -n "${cerveja:-}" && -n "${outro:-}" ]] && ok "produtos de categorias diferentes ($cerveja/$cat_cerveja, $outro/$cat_outro)" || falha "produtos nao encontrados"
curl -s -b "$gj" "$g/estoque/categorias" -o "$tmp/cats.html"
contem "categorias com impressora de producao" "$tmp/cats.html" 'Impressora de produção por categoria'
enviar "$gj" "$g/estoque/categorias/$cat_cerveja/impressoras" "$g/estoque/categorias" "impressoras[]=$bar" >/dev/null
curl -s -b "$gj" "$g/estoque/categorias" -o "$tmp/cats.html"
contem "categoria aplicada" "$tmp/cats.html" 'Impressoras aplicadas aos'
curl -s -b "$gj" "$g/estoque/produtos/$cerveja" -o "$tmp/prod.html"
contem "ficha do produto mostra Sai na impressora" "$tmp/prod.html" 'Sai na impressora'
grep -q "id=\"impressora-$bar\" name=\"impressoras\[\]\" value=\"$bar\" checked" "$tmp/prod.html" && ok "Cerveja marcada no Bar" || falha "Cerveja sem o Bar na ficha"

echo "Garcom: lancar ja imprime cada item na impressora do produto"
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$cerveja]=1" "qtd[$outro]=1" >/dev/null
curl -s -b "$wj" "$w/comandas/$comanda" -o "$tmp/com.html"
contem "garcom ve que imprimiu ao lancar" "$tmp/com.html" 'itens lançados. Enviado para'
grep -q 'Só os itens novos' "$tmp/com.html" && falha "garcom ainda tem so os itens novos" || ok "garcom sem a opcao so os itens novos"
agente "$ta" >/dev/null
esperar "dois cupons (Bar e Cozinha)" "$(qtd_cupons)" "2"
vbar="$(cupom_da bar0000000001)"; vcoz="$(cupom_da coz0000000001)"
case "$vbar" in *'PRODUÇÃO · BAR'*'MESA '*Cerveja*) ok "Bar recebe a cerveja com a mesa";; *) falha "via do Bar ($vbar)";; esac
[[ "$(php -r 'echo substr_count($argv[1], "\"t\":\"item\"");' "$vbar")" == "1" ]] && ok "Bar so com 1 item" || falha "Bar com itens a mais"
case "$vcoz" in *'PRODUÇÃO · COZINHA'*) ok "Cozinha recebe o item sem marcacao (padrao)";; *) falha "via da Cozinha ($vcoz)";; esac
[[ "$vcoz" != *Cerveja* ]] && ok "cerveja nao sai na cozinha" || falha "cerveja saiu na cozinha"
confirmar_todos
esperar "itens impressos ao lancar nao sao mais novos" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=novos)" "409"

echo "Estacao desligada bloqueia o envio inteiro"
enviar "$gj" "$g/estoque/categorias/$cat_outro/impressoras" "$g/estoque/categorias" "impressoras[]=$churras" >/dev/null
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$outro]=1" "qtd[$cerveja]=1" >/dev/null
curl -s -b "$wj" "$w/comandas/$comanda" -o "$tmp/com.html"
contem "lanca mesmo com a Churrasqueira desligada e avisa" "$tmp/com.html" 'Não imprimiu: A impressora Churrasqueira está desligada'
agente "$ta" >/dev/null
esperar "nada foi enviado ao Bar" "$(qtd_cupons)" "0"
enviar "$gj" "$g/estoque/categorias/$cat_outro/impressoras" "$g/estoque/categorias" >/dev/null
esperar "sem marcacao volta para a padrao" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=novos)" "202"
agente "$ta" >/dev/null
esperar "os mesmos itens novos saem (Bar e Cozinha)" "$(qtd_cupons)" "2"
confirmar_todos
esperar "comanda inteira continua inteira" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=toda)" "202"
agente "$ta" >/dev/null
esperar "pre-conta em um cupom so" "$(qtd_cupons)" "1"
confirmar_todos
enviar "$wj" "$w/comandas/$comanda/cancelar" "$w/comandas/$comanda" "motivo=Cliente desistiu" >/dev/null

echo "Caixa: itens novos para producao"
r="$(enviar "$cx" "$c/balcao" "$c/mesas" nome=Producao)"
venda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
enviar "$cx" "$c/comandas/$venda/itens" "$c/comandas/$venda" produto="$cerveja" quantidade=1 >/dev/null
curl -s -b "$cx" "$c/comandas/$venda" -o "$tmp/cx.html"
contem "caixa tem o botao Novos itens em destaque" "$tmp/cx.html" 'btn-primary btn-sm" type="submit" title="Envia os itens novos'
contem "contagem dos novos itens" "$tmp/cx.html" 'Novos itens (1)'
esperar "caixa envia para a producao" "$(imprimir "$cx" "$c/imprimir/comanda/$venda" "$c/comandas/$venda" modo=novos)" "202"
agente "$ta" >/dev/null
case "$(cupom_da bar0000000001)" in *'BALCÃO'*Cerveja*) ok "balcao: cerveja no Bar";; *) falha "via do balcao";; esac
confirmar_todos
enviar "$cx" "$c/comandas/$venda/cancelar" "$c/comandas/$venda" "motivo=Teste" >/dev/null

echo "Testar impressao (gerencia): o que sai em cada impressora, sem gravar pedido"
curl -s -b "$gj" "$g/integracoes/impressoras" -o "$tmp/ger.html"
contem "bloco Testar impressao" "$tmp/ger.html" 'Testar impressão'
teste() { # campos... -> codigo; resposta em $tmp/teste.json
  local t args=()
  t="$(token "$gj" "$g/integracoes/impressoras")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/teste.json" -b "$gj" -c "$gj" -w '%{http_code}' -H 'Accept: application/json' --data-urlencode "_csrf=$t" "${args[@]}" "$g/integracoes/impressoras/teste"
}
cupom_teste() { php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["cupons"]??[] as $c){ if($c["id"]===(int)$argv[2]){ echo $c["blocos"]===null?"NADA":json_encode($c["blocos"],JSON_UNESCAPED_UNICODE); exit; } } echo "AUSENTE";' "$tmp/teste.json" "$1"; }
esperar "teste do garcom" "$(teste situacao=garcom "produto[]=$cerveja" "quantidade[]=2" "observacao[]=bem gelada" "produto[]=$outro" "quantidade[]=1" "observacao[]=")" "200"
case "$(cupom_teste "$bar")" in *'PRODUÇÃO · BAR'*'MESA 18'*Cerveja*'bem gelada'*) ok "teste: cerveja no Bar com a observacao";; *) falha "teste Bar ($(cupom_teste "$bar"))";; esac
[[ "$(cupom_teste "$cozinha")" != *Cerveja* && "$(cupom_teste "$cozinha")" == *'PRODUÇÃO · COZINHA'* ]] && ok "teste: cozinha com o item sem marcacao e sem a cerveja" || falha "teste Cozinha ($(cupom_teste "$cozinha"))"
esperar "teste: churrasqueira nao recebe nada" "$(cupom_teste "$churras")" "NADA"
case "$(json "$tmp/teste.json" avisos)" in *'sai na padrão'*) ok "teste avisa o produto sem impressora";; *) falha "aviso sem impressora ($(json "$tmp/teste.json" avisos))";; esac
agente "$ta" >/dev/null
esperar "ver como sai nao manda nada ao Agent" "$(qtd_cupons)" "0"
esperar "teste da conta" "$(teste situacao=conta "produto[]=$cerveja" "quantidade[]=1" "produto[]=$outro" "quantidade[]=1")" "200"
esperar "conta: um cupom so" "$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo count($d["cupons"]??[]);' "$tmp/teste.json")" "1"
case "$(json "$tmp/teste.json" cupons)" in *CONTA*Cerveja*TOTAL*) ok "conta completa com todos os itens";; *) falha "conta do teste";; esac
esperar "imprimir teste de verdade" "$(teste situacao=caixa "produto[]=$cerveja" "quantidade[]=1" imprimir=1)" "200"
agente "$ta" >/dev/null
case "$(cupom_da bar0000000001)" in *'"TESTE"'*'BALCÃO'*Cerveja*) ok "Agent recebe o teste com TESTE no topo";; *) falha "cupom de teste ($(cupom_da bar0000000001))";; esac
confirmar_todos
esperar "teste sem produto recusado" "$(teste situacao=garcom)" "422"
esperar "teste sem CSRF recusado" "$(curl -s -o /dev/null -w '%{http_code}' -b "$gj" -H 'Accept: application/json' -d 'situacao=garcom' "$g/integracoes/impressoras/teste")" "401"
esperar "garcom nao usa o teste" "$(curl -s -o /dev/null -w '%{http_code}' -b "$wj" -H 'Accept: application/json' -d 'situacao=garcom' "$g/integracoes/impressoras/teste")" "401"

echo "Impressao por produto desligada: lancar nao imprime"
enviar "$gj" "$g/integracoes/impressoras" "$g/integracoes/impressoras" "producao=0" >/dev/null
curl -s -b "$wj" "$w" -o "$tmp/mesas.html"
mesa="$(php -r 'if (preg_match("~/garcom/mesas/(\d+)/abrir~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/mesas.html")"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$outro]=1" >/dev/null
agente "$ta" >/dev/null
esperar "desligada: lancar nao imprime" "$(qtd_cupons)" "0"
enviar "$wj" "$w/comandas/$comanda/cancelar" "$w/comandas/$comanda" "motivo=Cliente desistiu" >/dev/null
enviar "$gj" "$g/integracoes/impressoras" "$g/integracoes/impressoras" "producao=$cozinha" >/dev/null

echo "Delivery aceito manda as vias"
pedido="$(php tests/http/desktop-pedido-ci.php "$slug")"
agente "$ta" >/dev/null
esperar "pedido novo ainda nao manda via de producao" "$(qtd_cupons)" "0"
enviar "$cx" "$c/delivery/$pedido/aceitar" "$c/delivery/$pedido" >/dev/null
agente "$ta" >/dev/null
case "$(cupom_da coz0000000001)" in *'PRODUÇÃO · COZINHA'*'PEDIDO #'*'Item do Agent CI'*) ok "aceito: via na Cozinha";; *) falha "via do delivery ($(cupom_da coz0000000001))";; esac
confirmar_todos

# Limpa: categoria, pontos e Agents.
enviar "$gj" "$g/estoque/categorias/$cat_cerveja/impressoras" "$g/estoque/categorias" >/dev/null
enviar "$gj" "$g/integracoes/impressoras" "$g/integracoes/impressoras" >/dev/null
curl -s -o /dev/null -X POST -H "Authorization: Bearer $ta" "$base/api/v1/desktop/desconectar"
curl -s -o /dev/null -X POST -H "Authorization: Bearer $tb" "$base/api/v1/desktop/desconectar"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) na impressao por produto."
  exit 1
fi
echo "Impressao por produto validada (categorias, estacoes, padrao, estacao desligada, caixa e delivery)."
