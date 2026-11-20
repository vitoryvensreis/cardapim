#!/usr/bin/env bash
# Imprimir pelo Deliveryzim Agent: o caixa (conta, pedido do delivery, resumo do caixa) e o garcom (comanda inteira
# ou so os itens novos) pedem o cupom; o Agent do restaurante reserva, imprime e devolve o resultado.
# Tambem: observacao do pedido de balcao sai no cupom, Agent antigo nao pega cupom, isolamento entre restaurantes.
# Uso: bash tests/http/impressao.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
c="$base/$slug/caixa"
w="$base/$slug/garcom"
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
enviar() { # jar url pagina-do-token [campo=valor ...] -> "codigo location"
  local jar="$1" url="$2" origem="$3" t args=()
  shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
imprimir() { # jar url pagina-do-token [campo=valor ...] -> codigo HTTP; resposta em $tmp/imp.json
  local jar="$1" url="$2" origem="$3" t args=()
  shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/imp.json" -c "$jar" -b "$jar" -w '%{http_code}' -H 'Accept: application/json' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
situacao() { curl -s -b "$1" -H 'Accept: application/json' -o "$tmp/sit.json" "$base$2"; json "$tmp/sit.json" status; }
vincular() {
  local jar="$1" nome="$2" csrf
  curl -s -b "$jar" -o "$tmp/csrf.json" "$base/api/v1/desktop/csrf"
  csrf="$(json "$tmp/csrf.json" csrf)"
  curl -s -b "$jar" -o "$tmp/vinculo.json" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    -d "{\"nome\":\"$nome\"}" "$base/api/v1/desktop/vincular"
  json "$tmp/vinculo.json" token
}
agente() { # token [impressoes] -> codigo; resposta em $tmp/ag.json
  local q="desde=999999999"
  [[ "${2:-}" == "1" ]] && q="$q&impressoes=1"
  curl -s -o "$tmp/ag.json" -w '%{http_code}' -H "Authorization: Bearer $1" "$base/api/v1/desktop/eventos?$q"
}
resultado() { # token id corpo-json -> codigo
  curl -s -o "$tmp/res.json" -w '%{http_code}' -X POST -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
    -d "$3" "$base/api/v1/desktop/impressoes/$2/resultado"
}
cupom() { # campo do primeiro cupom da ultima consulta do Agent
  php -r '$d=json_decode(file_get_contents($argv[1]),true); $j=$d["impressoes"][0]??null; if(!$j){echo "";exit;}
    if($argv[2]==="texto"){echo json_encode($j["blocos"],JSON_UNESCAPED_UNICODE);} elseif($argv[2]==="itens"){echo count(array_filter($j["blocos"],fn($b)=>$b["t"]==="item"));}
    elseif($argv[2]==="qtd"){echo count($d["impressoes"]);} else {echo $j[$argv[2]]??"";}' "$tmp/ag.json" "$1"
}

cx="$tmp/caixa"; wj="$tmp/garcom"; outro="$tmp/outro"
esperar "caixa autenticado" "$(login "$cx" ci_caixa)" "303"
esperar "garcom autenticado" "$(login "$wj" ci_garcom)" "303"
esperar "gerente de outro restaurante autenticado" "$(login "$outro" ci_outro_gerente)" "303"

echo "Balcao com observacao"
r="$(enviar "$cx" "$c/balcao" "$c/mesas" nome=Maria)"
balcao="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
[[ -n "$balcao" ]] && ok "venda no balcao aberta ($balcao)" || falha "balcao nao abriu"
curl -s -b "$cx" -o "$tmp/pagina.html" "$c/comandas/$balcao"
contem "balcao oferece a observacao do pedido" "$tmp/pagina.html" 'Adicionar observação ao pedido'
read -r produto _ < <(php -r 'preg_match_all("~data-produto-id=\"(\d+)\" data-produto-nome=\"([^\"]*)\" data-produto-centavos=\"(\d+)\"~", file_get_contents($argv[1]), $m, PREG_SET_ORDER); foreach ($m as $p) { if (str_contains($p[2], "Cerveja")) { echo $p[1], " ", $p[3], "\n"; exit; } }' "$tmp/pagina.html") || true
[[ -n "${produto:-}" ]] && ok "produto para lancar ($produto)" || falha "produto nao encontrado"
enviar "$cx" "$c/comandas/$balcao/itens" "$c/comandas/$balcao" produto="$produto" quantidade=1 >/dev/null
enviar "$cx" "$c/comandas/$balcao/observacao" "$c/comandas/$balcao" "observacao=Para viagem, embalar separado" >/dev/null
curl -s -b "$cx" -o "$tmp/pagina.html" "$c/comandas/$balcao"
contem "observacao aparece na conta" "$tmp/pagina.html" 'Para viagem, embalar separado'
contem "botao Imprimir conta manda para o Agent" "$tmp/pagina.html" "action=\"/$slug/caixa/imprimir/comanda/$balcao\""
contem "carrega o script de impressao pelo Agent" "$tmp/pagina.html" '/assets/js/imprimir-agent.js'
grep -q 'window.print' <(curl -s "$base/assets/js/caixa.js") && falha "caixa ainda abre a impressao do navegador" || ok "caixa nao abre a impressao do navegador"
longa="$(php -r 'echo str_repeat("a", 301);')"
enviar "$cx" "$c/comandas/$balcao/observacao" "$c/comandas/$balcao" "observacao=$longa" >/dev/null
curl -s -b "$cx" -o "$tmp/pagina.html" "$c/comandas/$balcao"
contem "observacao longa recusada" "$tmp/pagina.html" 'até 300 caracteres'

echo "Sem Agent que imprime"
esperar "pedido de impressao recusado sem Agent" "$(imprimir "$cx" "$c/imprimir/comanda/$balcao" "$c/comandas/$balcao")" "409"
esperar "resposta explica o motivo" "$(json "$tmp/imp.json" ok)" "false"
token_ag="$(vincular "$cx" "CAIXA-IMPRESSORA-CI")"
[[ "$token_ag" == dzag_* ]] && ok "Agent vinculado" || falha "Agent nao vinculado"
esperar "Agent antigo consulta sem saber imprimir" "$(agente "$token_ag")" "200"
esperar "Agent antigo nao recebe a lista de cupons" "$(json "$tmp/ag.json" impressoes)" "null"
imprimir "$cx" "$c/imprimir/comanda/$balcao" "$c/comandas/$balcao" >/dev/null
case "$(json "$tmp/imp.json" erro)" in *desatualizado*) ok "avisa para atualizar o Agent";; *) falha "aviso de Agent antigo ($(json "$tmp/imp.json" erro))";; esac

echo "Conta do balcao pelo Agent"
esperar "Agent novo consulta (sabe imprimir)" "$(agente "$token_ag" 1)" "200"
esperar "sem cupons pendentes" "$(json "$tmp/ag.json" impressoes)" "[]"
esperar "caixa pede a conta" "$(imprimir "$cx" "$c/imprimir/comanda/$balcao" "$c/comandas/$balcao")" "202"
sit="$(json "$tmp/imp.json" situacao)"
[[ "$sit" == "/$slug/caixa/imprimir/"* ]] && ok "resposta traz o endereco da situacao" || falha "sem endereco da situacao ($sit)"
esperar "aguardando o computador" "$(situacao "$cx" "$sit")" "aguardando"
agente "$token_ag" 1 >/dev/null
esperar "Agent recebe 1 cupom" "$(cupom qtd)" "1"
id_cupom="$(cupom id)"
case "$(cupom texto)" in *'BALCÃO'*'Cliente: Maria'*'OBSERVAÇÃO'*'Para viagem, embalar separado'*'TOTAL'*) ok "cupom com balcao, cliente, observacao e total";; *) falha "conteudo do cupom ($(cupom texto))";; esac
agente "$token_ag" 1 >/dev/null
esperar "cupom nao vem duas vezes" "$(json "$tmp/ag.json" impressoes)" "[]"
esperar "imprimindo" "$(situacao "$cx" "$sit")" "imprimindo"
esperar "resultado exige token" "$(resultado falso "$id_cupom" '{"ok":true}')" "401"
esperar "resultado invalido" "$(resultado "$token_ag" "$id_cupom" '{"ok":"sim"}')" "422"
esperar "Agent confirma a impressao" "$(resultado "$token_ag" "$id_cupom" '{"ok":true}')" "200"
esperar "caixa ve Impresso" "$(situacao "$cx" "$sit")" "impresso"
esperar "resultado nao muda depois" "$(resultado "$token_ag" "$id_cupom" '{"ok":false,"erro":"x"}')" "404"
esperar "garcom nao consulta cupom do caixa pelo caixa" "$(curl -s -o /dev/null -w '%{http_code}' -b "$wj" -H 'Accept: application/json' "$base$sit")" "401"

echo "Falha na impressora volta para o caixa"
imprimir "$cx" "$c/imprimir/comanda/$balcao" "$c/comandas/$balcao" >/dev/null
sit="$(json "$tmp/imp.json" situacao)"
agente "$token_ag" 1 >/dev/null
resultado "$token_ag" "$(cupom id)" '{"ok":false,"erro":"Selecione uma impressora nas configuracoes."}' >/dev/null
esperar "caixa ve a falha" "$(situacao "$cx" "$sit")" "erro"
case "$(json "$tmp/sit.json" mensagem)" in *"Selecione uma impressora"*) ok "mensagem da falha";; *) falha "mensagem da falha ($(json "$tmp/sit.json" mensagem))";; esac

echo "Isolamento entre restaurantes"
token_outro="$(vincular "$outro" "OUTRO-IMPRESSORA-CI")"
agente "$token_outro" 1 >/dev/null
imprimir "$cx" "$c/imprimir/comanda/$balcao" "$c/comandas/$balcao" >/dev/null
agente "$token_outro" 1 >/dev/null
esperar "Agent de outro restaurante nao pega o cupom" "$(json "$tmp/ag.json" impressoes)" "[]"
agente "$token_ag" 1 >/dev/null
id_cupom="$(cupom id)"
esperar "Agent do restaurante pega o cupom" "$(cupom qtd)" "1"
esperar "outro restaurante nao informa resultado" "$(resultado "$token_outro" "$id_cupom" '{"ok":true}')" "404"
resultado "$token_ag" "$id_cupom" '{"ok":true}' >/dev/null
esperar "comanda de outro restaurante nao imprime" "$(imprimir "$cx" "$c/imprimir/comanda/999999999" "$c/mesas")" "409"
esperar "imprimir sem CSRF" "$(curl -s -o /dev/null -w '%{http_code}' -b "$cx" -H 'Accept: application/json' -X POST "$c/imprimir/comanda/$balcao")" "401"

echo "Pedido do delivery e resumo do caixa"
pedido="$(php tests/http/desktop-pedido-ci.php "$slug")"
curl -s -b "$cx" -o "$tmp/pagina.html" "$c/delivery/$pedido"
contem "delivery com Imprimir pelo Agent" "$tmp/pagina.html" "action=\"/$slug/caixa/imprimir/pedido/$pedido\""
esperar "caixa imprime o pedido" "$(imprimir "$cx" "$c/imprimir/pedido/$pedido" "$c/delivery/$pedido")" "202"
agente "$token_ag" 1 >/dev/null
case "$(cupom texto)" in *'PEDIDO #'*'RETIRADA'*'TOTAL'*) ok "cupom do pedido de retirada";; *) falha "cupom do pedido ($(cupom texto))";; esac
resultado "$token_ag" "$(cupom id)" '{"ok":true}' >/dev/null
caixa_id="$(curl -s -b "$cx" "$c/historico" | grep -o "/caixa/historico/[0-9]*" | head -1 | grep -o '[0-9]*$' || true)"
if [[ -n "$caixa_id" ]]; then
  esperar "caixa imprime o resumo do caixa" "$(imprimir "$cx" "$c/imprimir/caixa/$caixa_id" "$c/historico/$caixa_id")" "202"
  agente "$token_ag" 1 >/dev/null
  case "$(cupom texto)" in *'Troco inicial'*'Dinheiro esperado'*) ok "cupom do resumo do caixa";; *) falha "cupom do caixa ($(cupom texto))";; esac
  resultado "$token_ag" "$(cupom id)" '{"ok":true}' >/dev/null
else
  ok "sem caixa no historico para imprimir (pulado)"
fi
enviar "$cx" "$c/comandas/$balcao/cancelar" "$c/comandas/$balcao" "motivo=Teste de impressao" >/dev/null

echo "Garcom: comanda inteira e so os itens novos"
curl -s -b "$wj" -o "$tmp/pagina.html" "$w"
mesa="$(php -r 'if (preg_match("~/garcom/mesas/(\d+)/abrir~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
[[ -n "$comanda" ]] && ok "mesa aberta pelo garcom ($comanda)" || falha "mesa nao abriu"
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$produto]=1" "obs[$produto]=bem gelada" >/dev/null
curl -s -b "$wj" -o "$tmp/pagina.html" "$w/comandas/$comanda"
contem "garcom tem o botao Imprimir" "$tmp/pagina.html" 'class="comanda-imprimir"'
contem "opcao comanda inteira" "$tmp/pagina.html" 'value="toda"'
contem "so os itens novos com a contagem" "$tmp/pagina.html" 'Só os itens novos (1)'
esperar "garcom imprime sem escolher" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda")" "409"
esperar "garcom imprime so os itens novos" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=novos)" "202"
sit="$(json "$tmp/imp.json" situacao)"
[[ "$sit" == "/$slug/garcom/impressoes/"* ]] && ok "situacao pelo painel do garcom" || falha "situacao do garcom ($sit)"
agente "$token_ag" 1 >/dev/null
case "$(cupom texto)" in *'"titulo","texto":"MESA '*'ITENS NOVOS'*'bem gelada'*) ok "cupom identifica a mesa e traz os itens novos";; *) falha "cupom dos itens novos ($(cupom texto))";; esac
esperar "um item novo" "$(cupom itens)" "1"
resultado "$token_ag" "$(cupom id)" '{"ok":true}' >/dev/null
esperar "garcom ve Impresso" "$(situacao "$wj" "$sit")" "impresso"
esperar "nada novo depois de imprimir" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=novos)" "409"
case "$(json "$tmp/imp.json" erro)" in *"Nenhum item novo"*) ok "aviso de nenhum item novo";; *) falha "aviso nenhum item novo";; esac
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$produto]=2" >/dev/null
curl -s -b "$wj" -o "$tmp/pagina.html" "$w/comandas/$comanda"
contem "conta o item lancado depois" "$tmp/pagina.html" 'Só os itens novos (1)'
imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=novos >/dev/null
agente "$token_ag" 1 >/dev/null
esperar "so o item lancado depois" "$(cupom itens)" "1"
resultado "$token_ag" "$(cupom id)" '{"ok":true}' >/dev/null
esperar "garcom imprime a comanda inteira" "$(imprimir "$wj" "$w/comandas/$comanda/imprimir" "$w/comandas/$comanda" modo=toda)" "202"
agente "$token_ag" 1 >/dev/null
case "$(cupom texto)" in *'MESA '*'PRÉ-CONTA'*'TOTAL'*) ok "pre-conta com a mesa e o total";; *) falha "pre-conta ($(cupom texto))";; esac
esperar "comanda inteira com os dois itens" "$(cupom itens)" "2"
resultado "$token_ag" "$(cupom id)" '{"ok":true}' >/dev/null
esperar "garcom sem CSRF" "$(curl -s -o /dev/null -w '%{http_code}' -b "$wj" -H 'Accept: application/json' -X POST -d 'modo=toda' "$w/comandas/$comanda/imprimir")" "401"
enviar "$wj" "$w/comandas/$comanda/cancelar" "$w/comandas/$comanda" "motivo=Cliente desistiu" >/dev/null

curl -s -o /dev/null -X POST -H "Authorization: Bearer $token_ag" "$base/api/v1/desktop/desconectar"
curl -s -o /dev/null -X POST -H "Authorization: Bearer $token_outro" "$base/api/v1/desktop/desconectar"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) na impressao pelo Agent."
  exit 1
fi
echo "Impressao pelo Agent validada (caixa, garcom, balcao com observacao)."
