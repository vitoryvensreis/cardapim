#!/usr/bin/env bash
# Teste HTTP de ponta a ponta: aba Entrega e pagamento (gerente), finalizacao do pedido pelo cliente
# (entrega por bairro/raio, retirada, dinheiro com troco, Pix pelo Asaas com aviso e consulta) e acompanhamento.
# Usa o servidor FALSO tests/fakes/integracoes-fake.php (Asaas, WPPConnect, CEP e mapa).
# Uso: bash tests/http/pedidos.sh <base> <slug> <base-do-fake>
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
senha='Senha#CI2026'
g="$base/$slug/gerencia"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
json() { php -r '$d = json_decode(file_get_contents($argv[1]), true); $v = $d; foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : ($v === null ? "null" : json_encode($v)));' "$1" "$2"; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login_equipe() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
enviar() { # jar url [campo=valor ...] -> "codigo location"
  local jar="$1" url="$2" t args=()
  shift 2
  t="$(token "$jar" "$g/entrega-pagamento")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
sessao() { curl -s -c "$1" -b "$1" -o "$tmp/sessao.json" "$base/api/v1/cliente"; json "$tmp/sessao.json" csrf; }
post() { # jar csrf caminho json -> codigo http (corpo em $tmp/r.json)
  curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" --data "$4" "$base$3"
}
get() { curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' "$base$2"; }
ultimo_codigo() { curl -s "$fake/_teste/wpp-mensagens" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $l = $d["linhas"] ?? []; for ($i = count($l) - 1; $i >= 0; $i--) { if (strpos($l[$i], $argv[1]) !== false && preg_match("/\*(\d{6})\*/", $l[$i], $m)) { echo $m[1]; exit; } }' "$1"; }
chave() { php -r 'echo bin2hex(random_bytes(16));'; }

echo "Gerente: Entrega e pagamento"
gj="$tmp/gerente"
esperar "gerente entra" "$(login_equipe "$gj" ci_gerente)" "303"
esperar "aba abre" "$(curl -s -o "$tmp/aba.html" -b "$gj" -w '%{http_code}' "$g/entrega-pagamento")" "200"
contem "aba tem formas de pagamento" "$tmp/aba.html" 'name="pag_dinheiro"'
contem "Pix pelo app bloqueado sem Asaas" "$tmp/aba.html" 'Precisa da sua conta Asaas conectada'
contem "menu mostra a aba" "$tmp/aba.html" '/gerencia/entrega-pagamento'
oj="$tmp/outro"
login_equipe "$oj" ci_outro_gerente >/dev/null
esperar "gerente de outro restaurante nao abre (403)" "$(curl -s -o /dev/null -b "$oj" -w '%{http_code}' "$g/entrega-pagamento")" "403"
esperar "gerente de outro restaurante nao salva (403)" "$(curl -s -o /dev/null -b "$oj" -w '%{http_code}' -X POST "$g/entrega-pagamento/areas")" "403"
esperar "POST sem CSRF volta com erro" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -X POST "$g/entrega-pagamento")" "303"
esperar "horario: sempre aberto (para o teste)" "$(enviar "$gj" "$g/perfil/horarios" modo=sempre | cut -d' ' -f1)" "303"
esperar "Pix pelo app sem Asaas recusado (422)" "$(enviar "$gj" "$g/entrega-pagamento" aceita_entrega=1 pag_pix_online=1 | cut -d' ' -f1)" "422"
esperar "modo sem forma de pagamento recusado (422)" "$(enviar "$gj" "$g/entrega-pagamento" aceita_entrega=1 | cut -d' ' -f1)" "422"
esperar "salvar entrega, retirada e formas" "$(enviar "$gj" "$g/entrega-pagamento" aceita_entrega=1 aceita_retirada=1 entrega_minutos_min=30 entrega_minutos_max=50 retirada_minutos=20 pag_pix_entrega=1 pag_debito=1 pag_credito=1 pag_dinheiro=1)" "303 $g/entrega-pagamento"
esperar "localizacao pelo aparelho" "$(enviar "$gj" "$g/entrega-pagamento/localizacao" origem=aparelho latitude=-23.5614 longitude=-46.6559 | cut -d' ' -f1)" "303"
esperar "localizacao invalida recusada" "$(enviar "$gj" "$g/entrega-pagamento/localizacao" origem=aparelho latitude=abc longitude=1 | cut -d' ' -f1)" "303"
esperar "faixa ate 3 km por R\$ 7,50 (minimo R\$ 10)" "$(enviar "$gj" "$g/entrega-pagamento/areas" tipo=raio raio_km=3 taxa=7,50 pedido_minimo=10,00 | cut -d' ' -f1)" "303"
esperar "faixa repetida recusada (422)" "$(enviar "$gj" "$g/entrega-pagamento/areas" tipo=raio raio_km=3,0 taxa=1 | cut -d' ' -f1)" "422"
esperar "bairro Pinheiros por R\$ 9,00" "$(enviar "$gj" "$g/entrega-pagamento/areas" tipo=bairro bairro=Pinheiros cidade='São Paulo' taxa=9,00 | cut -d' ' -f1)" "303"
esperar "bairro sem nome recusado (422)" "$(enviar "$gj" "$g/entrega-pagamento/areas" tipo=bairro bairro= cidade='São Paulo' | cut -d' ' -f1)" "422"
curl -s -b "$gj" "$g/entrega-pagamento" -o "$tmp/aba.html"
contem "lista mostra a faixa" "$tmp/aba.html" 'Até 3 km'
contem "lista mostra o bairro" "$tmp/aba.html" 'Pinheiros'
contem "localizacao definida" "$tmp/aba.html" 'Localização do restaurante definida'
curl -s -b "$gj" "$g/entrega-pagamento/testar?cep=01414000&numero=10" -o "$tmp/teste.html"
contem "testar endereco: dentro do raio" "$tmp/teste.html" 'Entrega disponível'
curl -s -b "$gj" "$g/entrega-pagamento/testar?cep=04538133&numero=10" -o "$tmp/teste.html"
contem "testar endereco: fora do raio" "$tmp/teste.html" 'Fora da área de entrega'

echo "Cliente entra pelo WhatsApp da plataforma"
aj="$tmp/admin"
esperar "administrador entra" "$(login_equipe "$aj" ci_master)" "303"
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/conectar"
sessao_wpp="$(ls -t "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-cardapim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
curl -s "$fake/_teste/wpp-conectar/$sessao_wpp" >/dev/null
curl -s -b "$aj" "$base/painel/integracoes/whatsapp/estado" | grep -q '"estado":"conectado"' && ok "WhatsApp da plataforma conectado" || falha "WhatsApp da plataforma nao conectou"
entrar_cliente() { # jar telefone nome
  local c csrf
  csrf="$(sessao "$1")"
  post "$1" "$csrf" /api/v1/cliente/codigo "{\"telefone\":\"$2\"}" >/dev/null
  c="$(ultimo_codigo "$sessao_wpp|55$2|")"
  post "$1" "$csrf" /api/v1/cliente/entrar "{\"codigo\":\"$c\"}" >/dev/null
  csrf="$(json "$tmp/r.json" csrf)"
  if [[ "$(json "$tmp/r.json" etapa)" == "nome" ]]; then post "$1" "$csrf" /api/v1/cliente/cadastro "{\"nome\":\"$3\"}" >/dev/null; fi
  json "$tmp/r.json" cliente.primeiro_nome
}
cj="$tmp/cliente"
esperar "cliente 1 conectado" "$(entrar_cliente "$cj" 85977776666 'Pedido Teste CI')" "Pedido"
cj2="$tmp/cliente2"
sleep 1
esperar "cliente 2 conectado" "$(entrar_cliente "$cj2" 85966665555 'Outra Pessoa CI')" "Outra"
csrf="$(sessao "$cj")"

echo "CEP, entrega e paginas"
esperar "CEP encontrado" "$(get "$cj" /api/v1/cep/01414000)" "200"
esperar "rua do CEP" "$(json "$tmp/r.json" logradouro)" "Rua Haddock Lobo"
esperar "CEP inexistente" "$(get "$cj" /api/v1/cep/99999999)" "404"
dentro='{"cep":"01414000","logradouro":"Rua Haddock Lobo","numero":"100","bairro":"Cerqueira César","cidade":"São Paulo","estado":"SP"}'
bairro='{"cep":"05422000","logradouro":"Rua dos Pinheiros","numero":"50","bairro":"Pinheiros","cidade":"São Paulo","estado":"SP"}'
fora='{"cep":"04538133","logradouro":"Avenida Brigadeiro Faria Lima","numero":"3000","bairro":"Itaim Bibi","cidade":"São Paulo","estado":"SP"}'
esperar "entrega sem CSRF (419)" "$(curl -s -o /dev/null -w '%{http_code}' -b "$cj" -H 'Content-Type: application/json' --data "{\"endereco\":$dentro}" "$base/api/v1/restaurantes/$slug/entrega")" "419"
esperar "conferir entrega (dentro do raio)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/entrega" "{\"endereco\":$dentro}")" "200"
esperar "entrega disponivel" "$(json "$tmp/r.json" entrega)" "true"
esperar "taxa da faixa de km" "$(json "$tmp/r.json" taxa_centavos)" "750"
esperar "pedido minimo da faixa" "$(json "$tmp/r.json" pedido_minimo_centavos)" "1000"
post "$cj" "$csrf" "/api/v1/restaurantes/$slug/entrega" "{\"endereco\":$bairro}" >/dev/null
esperar "bairro cadastrado tem prioridade sobre o raio" "$(json "$tmp/r.json" taxa_centavos)" "900"
post "$cj" "$csrf" "/api/v1/restaurantes/$slug/entrega" "{\"endereco\":$fora}" >/dev/null
esperar "fora do raio" "$(json "$tmp/r.json" entrega)" "false"
contem "mensagem diz a distancia" "$tmp/r.json" 'entregamos at'
esperar "endereco incompleto (422)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/entrega" '{"endereco":{"bairro":"x"}}')" "422"
esperar "pagina finalizar" "$(curl -s -o "$tmp/f.html" -w '%{http_code}' "$base/$slug/finalizar")" "200"
contem "finalizar tem o formulario" "$tmp/f.html" 'data-ck-form'
contem "finalizar carrega o script" "$tmp/f.html" '/assets/js/checkout.js'
esperar "pagina do pedido" "$(curl -s -o "$tmp/p.html" -w '%{http_code}' "$base/$slug/pedido/1")" "200"
esperar "pagina do pedido com id invalido (404)" "$(curl -s -o /dev/null -w '%{http_code}' "$base/$slug/pedido/abc")" "404"

echo "Pedido com entrega e dinheiro"
curl -s "$base/$slug" -o "$tmp/cardapio.html"
# Produto com bastante estoque (a cerveja do cardapio de exemplo); senao, o primeiro disponivel.
php -r 'preg_match_all("/<button[^>]*data-produto=\"(\d+)\"[^>]*data-nome=\"([^\"]*)\"[^>]*data-centavos=\"(\d+)\"[^>]*>/s", file_get_contents($argv[1]), $m, PREG_SET_ORDER); $ok = array_values(array_filter($m, fn ($p) => !str_contains($p[0], "disabled"))); usort($ok, fn ($a, $b) => (int) str_contains($b[2], "Cerveja") <=> (int) str_contains($a[2], "Cerveja")); if ($ok) { echo $ok[0][1], " ", $ok[0][3], "\n"; }' "$tmp/cardapio.html" > "$tmp/produto.txt"
read -r produto preco < "$tmp/produto.txt" || true
[[ -n "${produto:-}" ]] && ok "produto disponivel encontrado ($produto)" || falha "nenhum produto disponivel no cardapio"
sub=$((preco * 2)); total=$((sub + 750))
k1="$(chave)"
corpo="{\"chave\":\"$k1\",\"tipo\":\"entrega\",\"forma_pagamento\":\"dinheiro\",\"troco_para\":\"1000,00\",\"observacao\":\"Interfone 12\",\"itens\":[{\"id\":$produto,\"quantidade\":2,\"observacao\":\"sem cebola\"}],\"endereco\":$dentro}"
anon="$tmp/anonimo"
csrf_anon="$(sessao "$anon")"
esperar "sem login (401)" "$(post "$anon" "$csrf_anon" "/api/v1/restaurantes/$slug/pedidos" "$corpo")" "401"
esperar "sem CSRF (419)" "$(curl -s -o /dev/null -w '%{http_code}' -b "$cj" -H 'Content-Type: application/json' --data "$corpo" "$base/api/v1/restaurantes/$slug/pedidos")" "419"
esperar "troco menor que o total (422)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "${corpo/1000,00/1,00}")" "422"
esperar "forma nao aceita (422)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "${corpo/\"dinheiro\"/\"pix_online\"}")" "422"
esperar "fora da area (422)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "{\"chave\":\"$(chave)\",\"tipo\":\"entrega\",\"forma_pagamento\":\"debito\",\"itens\":[{\"id\":$produto,\"quantidade\":1}],\"endereco\":$fora}")" "422"
esperar "produto inexistente (409)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "{\"chave\":\"$(chave)\",\"tipo\":\"retirada\",\"forma_pagamento\":\"debito\",\"itens\":[{\"id\":999999,\"quantidade\":1}]}")" "409"
esperar "criar pedido" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$corpo")" "201"
pedido1="$(json "$tmp/r.json" pedido.id)"
numero1="$(json "$tmp/r.json" pedido.numero)"
[[ "$pedido1" =~ ^[0-9]+$ ]] && ok "pedido criado (#$numero1)" || falha "pedido sem id"
esperar "mesmo envio repetido nao duplica" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$corpo")" "200"
esperar "repetido devolve o mesmo pedido" "$(json "$tmp/r.json" pedido.id)" "$pedido1"
esperar "acompanhar pedido" "$(get "$cj" "/api/v1/pedidos/$pedido1")" "200"
esperar "status recebido" "$(json "$tmp/r.json" status)" "recebido"
esperar "total calculado no servidor" "$(json "$tmp/r.json" total_centavos)" "$total"
esperar "taxa no pedido" "$(json "$tmp/r.json" taxa_centavos)" "750"
esperar "troco guardado" "$(json "$tmp/r.json" troco_para_centavos)" "100000"
contem "observacao do item" "$tmp/r.json" 'sem cebola'
contem "endereco no pedido" "$tmp/r.json" 'Rua Haddock Lobo'
esperar "outro cliente nao ve o pedido (404)" "$(get "$cj2" "/api/v1/pedidos/$pedido1")" "404"
esperar "sem login nao ve o pedido (401)" "$(get "$anon" "/api/v1/pedidos/$pedido1")" "401"
esperar "endereco lembrado para o proximo pedido" "$(get "$cj" /api/v1/cliente/endereco)" "200"
esperar "rua lembrada" "$(json "$tmp/r.json" endereco.logradouro)" "Rua Haddock Lobo"
esperar "sacola vazia (422)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "{\"chave\":\"$(chave)\",\"tipo\":\"entrega\",\"forma_pagamento\":\"debito\",\"itens\":[],\"endereco\":$dentro}")" "422"

echo "Retirada com cartao"
esperar "pedido para retirar" "$(post "$cj2" "$(sessao "$cj2")" "/api/v1/restaurantes/$slug/pedidos" "{\"chave\":\"$(chave)\",\"tipo\":\"retirada\",\"forma_pagamento\":\"credito\",\"itens\":[{\"id\":$produto,\"quantidade\":1}]}")" "201"
pedido2="$(json "$tmp/r.json" pedido.id)"
esperar "numero seguinte" "$(json "$tmp/r.json" pedido.numero)" "$((numero1 + 1))"
get "$cj2" "/api/v1/pedidos/$pedido2" >/dev/null
esperar "retirada sem taxa" "$(json "$tmp/r.json" taxa_centavos)" "0"
contem "forma na retirada" "$tmp/r.json" 'na retirada'

echo "Pix pelo app (Asaas do restaurante)"
esperar "conectar Asaas (fake)" "$(enviar "$gj" "$g/integracoes/asaas" ambiente=sandbox chave='$aact_ci_valida_1234567890abcdef' email_avisos=financeiro@example.test | cut -d' ' -f1)" "303"
esperar "ligar Pix pelo app" "$(enviar "$gj" "$g/entrega-pagamento" aceita_entrega=1 aceita_retirada=1 pag_pix_online=1 pag_dinheiro=1 pag_debito=1 pag_credito=1 pag_pix_entrega=1 | cut -d' ' -f1)" "303"
pix="{\"chave\":\"$(chave)\",\"tipo\":\"retirada\",\"forma_pagamento\":\"pix_online\",\"itens\":[{\"id\":$produto,\"quantidade\":1}]}"
esperar "Pix sem CPF (422)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$pix")" "422"
esperar "erro aponta o CPF" "$(json "$tmp/r.json" campo)" "cpf"
pix="${pix%\}},\"cpf\":\"529.982.247-25\"}"
esperar "pedido com Pix" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "$pix")" "201"
pedido3="$(json "$tmp/r.json" pedido.id)"
get "$cj" "/api/v1/pedidos/$pedido3" >/dev/null
esperar "aguardando pagamento" "$(json "$tmp/r.json" status)" "aguardando_pagamento"
contem "codigo Pix copia e cola" "$tmp/r.json" '"copia_cola":"000201'
curl -s "$fake/_teste/asaas-cobrancas" > "$tmp/cob.json"
cobranca="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); foreach ($d as $c) { if ($c["externalReference"] === "pedido:" . $argv[2]) { echo $c["id"]; } }' "$tmp/cob.json" "$pedido3")"
[[ -n "$cobranca" ]] && ok "cobranca criada no Asaas com a referencia do pedido" || falha "cobranca nao criada"
curl -s "$fake/_teste/asaas-pagar/$cobranca" > "$tmp/pago.json"
valor="$(json "$tmp/pago.json" value)"
curl -s "$fake/_teste/asaas-webhook" > "$tmp/wh.json"
tk="$(json "$tmp/wh.json" authToken)"
caminho_wh="$(json "$tmp/wh.json" url | sed 's~^https\?://[^/]*~~')"
evento="{\"id\":\"evt_ci_$pedido3\",\"event\":\"PAYMENT_RECEIVED\",\"payment\":{\"id\":\"$cobranca\",\"value\":$valor,\"externalReference\":\"pedido:$pedido3\",\"billingType\":\"PIX\"}}"
esperar "aviso do Asaas aceito" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$caminho_wh")" "200"
esperar "aviso repetido nao quebra" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$caminho_wh")" "200"
get "$cj" "/api/v1/pedidos/$pedido3" >/dev/null
esperar "Pix confirmado: pedido recebido" "$(json "$tmp/r.json" status)" "recebido"
esperar "pagamento pago" "$(json "$tmp/r.json" pagamento_status)" "pago"
esperar "sem codigo Pix depois de pago" "$(json "$tmp/r.json" pix)" "null"

echo "Pix confirmado pela consulta (sem aviso)"
esperar "outro pedido com Pix (CPF ja guardado)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "{\"chave\":\"$(chave)\",\"tipo\":\"retirada\",\"forma_pagamento\":\"pix_online\",\"itens\":[{\"id\":$produto,\"quantidade\":1}]}")" "201"
pedido4="$(json "$tmp/r.json" pedido.id)"
get "$cj" "/api/v1/pedidos/$pedido4" >/dev/null
cobranca4="$(curl -s "$fake/_teste/asaas-cobrancas" | php -r '$d = json_decode(stream_get_contents(STDIN), true); foreach ($d as $c) { if ($c["externalReference"] === "pedido:" . $argv[1]) { echo $c["id"]; } }' "$pedido4")"
curl -s "$fake/_teste/asaas-pagar/$cobranca4" >/dev/null
sleep 9
get "$cj" "/api/v1/pedidos/$pedido4" >/dev/null
esperar "consulta ao Asaas confirma o Pix" "$(json "$tmp/r.json" status)" "recebido"

echo "Limpeza"
enviar "$gj" "$g/integracoes/asaas/desconectar" >/dev/null
enviar "$gj" "$g/entrega-pagamento" aceita_entrega=1 aceita_retirada=1 pag_dinheiro=1 pag_debito=1 pag_credito=1 pag_pix_entrega=1 >/dev/null
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/desconectar"
ok "integracoes de teste desligadas"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) nos pedidos."
  exit 1
fi
echo "OK: entrega e pagamento, finalizacao do pedido (entrega, retirada, Pix) e acompanhamento."
