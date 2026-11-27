#!/usr/bin/env bash
# Teste HTTP do PAINEL DO ENTREGADOR e do RASTREIO pelo cliente: entregas com o motoqueiro, localizacao em tempo real
# (so a ultima posicao; so o cliente do pedido ve; apagada ao terminar), confirmar entrega e historico.
# Usa o servidor FALSO tests/fakes/integracoes-fake.php (WhatsApp da plataforma).
# Uso: bash tests/http/entregas.sh <base> <slug> <base-do-fake>
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
senha='Senha#CI2026'
c="$base/$slug/caixa"
g="$base/$slug/gerencia"
e="$base/$slug/entregas"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

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
enviar() { # jar url pagina-do-token [campo=valor ...]
  local jar="$1" url="$2" origem="$3" t args=()
  shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
csrf_pagina() { curl -s -c "$1" -b "$1" "$2" | grep -o 'data-csrf="[a-f0-9]*"' | head -1 | sed 's/.*="//; s/"$//'; }
local_post() { curl -s -o "$tmp/local.json" -c "$1" -b "$1" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" --data "$3" "$e/localizacao"; }
sessao() { curl -s -c "$1" -b "$1" -o "$tmp/sessao.json" "$base/api/v1/cliente"; json "$tmp/sessao.json" csrf; }
post() { curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" --data "$4" "$base$3"; }
ultimo_codigo() { curl -s "$fake/_teste/wpp-mensagens" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $l = $d["linhas"] ?? []; for ($i = count($l) - 1; $i >= 0; $i--) { if (strpos($l[$i], $argv[1]) !== false && preg_match("/\*(\d{6})\*/", $l[$i], $m)) { echo $m[1]; exit; } }' "$1"; }
chave() { php -r 'echo bin2hex(random_bytes(16));'; }

echo "Acesso ao painel do entregador"
mj="$tmp/moto"; cx="$tmp/caixa"; gj="$tmp/gerente"
esperar "motoqueiro entra" "$(login_equipe "$mj" ci_motoqueiro)" "303"
esperar "caixa entra" "$(login_equipe "$cx" ci_caixa)" "303"
esperar "gerente entra" "$(login_equipe "$gj" ci_gerente)" "303"
esperar "painel abre" "$(curl -s -o "$tmp/p.html" -b "$mj" -w '%{http_code}' "$e")" "200"
contem "mostra as entregas com ele" "$tmp/p.html" 'Com você agora'
contem "localizacao liga sozinha (cartao de status)" "$tmp/p.html" 'data-local-status'
contem "tela de localizacao obrigatoria" "$tmp/p.html" 'data-local-bloqueio'
nao_contem "sem botao de parar de compartilhar" "$tmp/p.html" 'Parar de compartilhar'
contem "carrega o script do entregador" "$tmp/p.html" '/assets/js/entregador.js'
esperar "caixa nao abre o painel do entregador (403)" "$(curl -s -o /dev/null -b "$cx" -w '%{http_code}' "$e")" "403"
esperar "gerente vai para o delivery do caixa" "$(curl -s -o /dev/null -b "$gj" -w '%{redirect_url}' "$e")" "$c/delivery"
esperar "gerente nao envia localizacao (403)" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -H 'Content-Type: application/json' --data '{}' "$e/localizacao")" "403"

echo "Localizacao"
mcsrf="$(csrf_pagina "$mj" "$e")"
esperar "sem CSRF (419)" "$(local_post "$mj" "" '{"latitude":-3.73,"longitude":-38.52}')" "419"
esperar "coordenada invalida (422)" "$(local_post "$mj" "$mcsrf" '{"latitude":"abc","longitude":1}')" "422"
esperar "corpo invalido (400)" "$(local_post "$mj" "$mcsrf" 'nada')" "400"

echo "Pedido despachado para o motoqueiro"
t="$(token "$gj" "$g/entrega-pagamento")"
curl -s -o /dev/null -b "$gj" -c "$gj" --data-urlencode "_csrf=$t" -d aceita_entrega=1 -d aceita_retirada=1 -d pag_dinheiro=1 -d pag_debito=1 "$g/entrega-pagamento"
t="$(token "$gj" "$g/entrega-pagamento")"
curl -s -o /dev/null -b "$gj" -c "$gj" --data-urlencode "_csrf=$t" -d tipo=bairro --data-urlencode "bairro=Cerqueira César" --data-urlencode "cidade=São Paulo" -d taxa=6,00 "$g/entrega-pagamento/areas"
aj="$tmp/admin"
login_equipe "$aj" ci_master >/dev/null
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/conectar"
sessao_wpp="$(ls -t "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-deliveryzim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
curl -s "$fake/_teste/wpp-conectar/$sessao_wpp" >/dev/null
curl -s -b "$aj" "$base/painel/integracoes/whatsapp/estado" >/dev/null
entrar_cliente() { # jar telefone nome -> csrf
  local csrf
  csrf="$(sessao "$1")"
  post "$1" "$csrf" /api/v1/cliente/codigo "{\"telefone\":\"$2\"}" >/dev/null
  post "$1" "$csrf" /api/v1/cliente/entrar "{\"codigo\":\"$(ultimo_codigo "$sessao_wpp|55$2|")\"}" >/dev/null
  csrf="$(json "$tmp/r.json" csrf)"
  if [[ "$(json "$tmp/r.json" etapa)" == "nome" ]]; then post "$1" "$csrf" /api/v1/cliente/cadastro "{\"nome\":\"$3\"}" >/dev/null; csrf="$(json "$tmp/r.json" csrf)"; fi
  echo "$csrf"
}
cj="$tmp/cliente"; oc="$tmp/outro-cliente"
ccsrf="$(entrar_cliente "$cj" 85977771001 "Cliente Rastreio")"
entrar_cliente "$oc" 85977771002 "Outro Cliente" >/dev/null
curl -s "$base/$slug" -o "$tmp/cardapio.html"
produto="$(php -r 'preg_match_all("/<button[^>]*data-produto=\"(\d+)\"[^>]*data-nome=\"([^\"]*)\"[^>]*>/s", file_get_contents($argv[1]), $m, PREG_SET_ORDER); foreach ($m as $p) { if (!str_contains($p[0], "disabled") && str_contains($p[2], "Cerveja")) { echo $p[1]; exit; } } foreach ($m as $p) { if (!str_contains($p[0], "disabled")) { echo $p[1]; exit; } }' "$tmp/cardapio.html")"
endereco='{"cep":"01414000","logradouro":"Rua Haddock Lobo","numero":"100","bairro":"Cerqueira César","cidade":"São Paulo","estado":"SP"}'
esperar "cliente faz pedido de entrega" "$(post "$cj" "$ccsrf" "/api/v1/restaurantes/$slug/pedidos" "{\"chave\":\"$(chave)\",\"tipo\":\"entrega\",\"forma_pagamento\":\"dinheiro\",\"troco_para\":\"50,00\",\"itens\":[{\"id\":$produto,\"quantidade\":1}],\"endereco\":$endereco}")" "201"
pedido="$(json "$tmp/r.json" pedido.id)"
numero="$(json "$tmp/r.json" pedido.numero)"
curl -s -b "$cj" -o "$tmp/r.json" "$base/api/v1/pedidos/$pedido"
contem "cliente ve a etapa Pronto na linha do tempo" "$tmp/r.json" '"status":"pronto"'
echo "Meus pedidos (cliente)"
curl -s -b "$cj" -o "$tmp/mp.json" "$base/api/v1/cliente/pedidos?restaurante=$slug"
esperar "historico traz o pedido" "$(json "$tmp/mp.json" pedidos.0.id)" "$pedido"
esperar "pedido aparece em andamento" "$(json "$tmp/mp.json" em_andamento.0.id)" "$pedido"
esperar "link para acompanhar" "$(json "$tmp/mp.json" pedidos.0.url)" "/$slug/pedido/$pedido"
contem "resumo dos itens" "$tmp/mp.json" '"resumo":"1x '
curl -s -b "$cj" -o "$tmp/mp2.json" "$base/api/v1/cliente/pedidos"
esperar "todos os restaurantes traz o nome da loja" "$(json "$tmp/mp2.json" pedidos.0.restaurante.slug)" "$slug"
esperar "sem restaurante nao manda em_andamento" "$(json "$tmp/mp2.json" em_andamento)" "null"
curl -s -b "$oc" -o "$tmp/mp3.json" "$base/api/v1/cliente/pedidos?restaurante=$slug"
nao_contem "outro cliente nao ve o pedido" "$tmp/mp3.json" "\"id\":$pedido,"
esperar "sem login (401)" "$(curl -s -o /dev/null -w '%{http_code}' "$base/api/v1/cliente/pedidos")" "401"
esperar "pagina invalida (422)" "$(curl -s -o /dev/null -b "$cj" -w '%{http_code}' "$base/api/v1/cliente/pedidos?antes=abc")" "422"
esperar "restaurante inexistente (404)" "$(curl -s -o /dev/null -b "$cj" -w '%{http_code}' "$base/api/v1/cliente/pedidos?restaurante=nao-existe-mesmo")" "404"
curl -s -b "$cj" "$base/$slug" -o "$tmp/cd.html"
contem "cardapio tem o aviso de pedido em andamento" "$tmp/cd.html" 'data-pedidos-ativos'
contem "conta tem a lista de pedidos" "$tmp/cd.html" 'data-meus-pedidos'
contem "cardapio carrega meus-pedidos.js" "$tmp/cd.html" '/assets/js/meus-pedidos.js'
curl -s -b "$cj" -o "$tmp/rast.json" "$base/api/v1/pedidos/$pedido/rastreio"
esperar "antes de sair, sem rastreio" "$(json "$tmp/rast.json" ativo)" "false"
esperar "outro cliente nao ve o rastreio (404)" "$(curl -s -o /dev/null -b "$oc" -w '%{http_code}' "$base/api/v1/pedidos/$pedido/rastreio")" "404"
esperar "sem login nao ve o rastreio (401)" "$(curl -s -o /dev/null -w '%{http_code}' "$base/api/v1/pedidos/$pedido/rastreio")" "401"
echo "Trocar forma de pagamento no caixa (antes do despacho)"
banco() { php -r 'require "bootstrap/app.php"; $d = \Cardapim\Core\Database::connect(); $q = $d->prepare($argv[1]); $q->execute(array_slice($argv, 2)); echo implode("|", (array) $q->fetch(PDO::FETCH_NUM));' "$@"; }
dec() { php -r 'echo number_format($argv[1] / 100, 2, ".", "");' "$1"; }
rs() { php -r 'echo "R$ " . number_format($argv[1] / 100, 2, ",", ".");' "$1"; }
escolha_de() { php -r 'if (preg_match("~name=\"escolha\" value=\"(" . preg_quote($argv[2], "~") . "\|\d+)\"~", file_get_contents($argv[1]), $m)) echo $m[1];' "$1" "$2"; }
rid="$(banco 'SELECT id FROM restaurantes WHERE slug = ?' "$slug")"
php -r 'require "bootstrap/app.php"; $d = \Cardapim\Core\Database::connect(); $d->prepare("DELETE FROM restaurante_taxas_pagamento WHERE id_restaurante = ?")->execute([$argv[1]]); $d->prepare("INSERT INTO restaurante_taxas_pagamento (id_restaurante, forma, tipo, valor) VALUES (?, ?, ?, ?), (?, ?, ?, ?)")->execute([$argv[1], "credito", "percentual", "3.50", $argv[1], "debito", "fixo", "1.00"]);' "$rid"
base_troca=$(( $(php -r 'echo (int) round((float) $argv[1] * 100);' "$(banco 'SELECT total FROM pedidos WHERE id = ?' "$pedido")") ))
curl -s -b "$cx" -o "$tmp/d.html" "$c/delivery/$pedido"
contem "caixa ve Trocar forma de pagamento" "$tmp/d.html" 'Trocar forma de pagamento'
esperar "caixa: debito com a taxa fixa no popup" "$(escolha_de "$tmp/d.html" debito)" "debito|100"
nao_contem "caixa nao gera QR (Pix so registra)" "$tmp/d.html" 'value="pix_online|'
enviar "$cx" "$c/delivery/$pedido/forma" "$c/historico" "escolha=debito|100" >/dev/null
esperar "caixa troca para debito" "$(aviso "$cx" "$c/delivery/$pedido")" "Forma trocada para Cartão de débito. Taxa do débito (R\$ 1,00): + R\$ 1,00. Receber $(rs $((base_troca + 100)))."
esperar "pedido com a taxa do debito" "$(banco 'SELECT forma_pagamento, taxa_pagamento, total, troco_para IS NULL FROM pedidos WHERE id = ?' "$pedido")" "debito|1.00|$(dec $((base_troca + 100)))|1"
enviar "$cx" "$c/delivery/$pedido/forma" "$c/historico" "escolha=dinheiro|0" "troco_para=50,00" >/dev/null
esperar "caixa volta para dinheiro com troco" "$(aviso "$cx" "$c/delivery/$pedido")" "Forma trocada para Dinheiro. A taxa de R\$ 1,00 saiu. Receber $(rs "$base_troca")."
esperar "pedido de novo em dinheiro, sem taxa" "$(banco 'SELECT forma_pagamento, taxa_pagamento, total, troco_para FROM pedidos WHERE id = ?' "$pedido")" "dinheiro|0.00|$(dec "$base_troca")|50.00"
esperar "linha do tempo registra a troca" "$(banco "SELECT COUNT(*) FROM pedido_eventos WHERE id_pedido = ? AND descricao LIKE 'Forma trocada de %'" "$pedido")" "2"

curl -s -b "$cx" -o "$tmp/d.html" "$c/delivery/$pedido"
entregador="$(php -r 'if (preg_match("~name=\"entregador\" value=\"(\d+)\"~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/d.html")"
enviar "$cx" "$c/delivery/$pedido/despachar" "$c/historico" entregador="$entregador" >/dev/null
esperar "caixa despacha para o motoqueiro" "$(aviso "$cx" "$c/delivery/$pedido")" "Pedido #$numero saiu com CI Motoqueiro."
contem "caixa ve que a localizacao esta desligada" "$tmp/aviso.html" 'não está compartilhando a localização'
curl -s -b "$mj" -o "$tmp/p.html" "$e"
contem "entrega aparece no painel do motoqueiro" "$tmp/p.html" "Pedido #$numero"
contem "mostra quanto receber" "$tmp/p.html" 'Receber <strong>'
contem "mostra o troco para levar" "$tmp/p.html" 'Levar troco'
contem "link de rota no Google Maps" "$tmp/p.html" 'google.com/maps/dir/'
contem "link de rota no Waze" "$tmp/p.html" 'waze.com/ul'
curl -s -b "$cj" -o "$tmp/rast.json" "$base/api/v1/pedidos/$pedido/rastreio"
esperar "saiu, mas sem localizacao ainda" "$(json "$tmp/rast.json" motivo)" "sem_localizacao"
esperar "casa do cliente achada no despacho (mapa)" "$(json "$tmp/rast.json" destino.latitude)" "-23.56"

esperar "motoqueiro envia a localizacao" "$(local_post "$mj" "$mcsrf" '{"latitude":-23.5590,"longitude":-46.6640,"precisao":12,"direcao":90,"velocidade":8}')" "200"
esperar "envia de novo (atualiza a mesma linha)" "$(local_post "$mj" "$mcsrf" '{"latitude":-23.5595,"longitude":-46.6645,"precisao":10}')" "200"
curl -s -b "$cj" -o "$tmp/rast.json" "$base/api/v1/pedidos/$pedido/rastreio"
esperar "cliente ve o entregador no mapa" "$(json "$tmp/rast.json" ativo)" "true"
esperar "primeiro nome do entregador" "$(json "$tmp/rast.json" entregador.nome)" "CI"
esperar "posicao mais recente" "$(json "$tmp/rast.json" entregador.latitude)" "-23.5595"
esperar "localizacao ao vivo" "$(json "$tmp/rast.json" entregador.ao_vivo)" "true"
esperar "distancia ate a casa calculada" "$([[ "$(json "$tmp/rast.json" distancia_km)" != "null" ]] && echo sim)" "sim"
nao_contem "rastreio nao expoe telefone nem sobrenome" "$tmp/rast.json" 'Motoqueiro'
curl -s -b "$cx" -o "$tmp/d.html" "$c/delivery/$pedido"
contem "caixa ve a localizacao ao vivo" "$tmp/d.html" 'Localização ao vivo'
esperar "pagina do pedido carrega o mapa" "$(curl -s -o "$tmp/pp.html" -w '%{http_code}' "$base/$slug/pedido/$pedido")" "200"
contem "pagina do pedido tem o bloco do mapa" "$tmp/pp.html" 'data-ap-mapa'
contem "pagina do pedido carrega o Leaflet" "$tmp/pp.html" '/assets/vendor/leaflet/leaflet.js'
contem "pagina do pedido carrega o mapa proprio" "$tmp/pp.html" '/assets/vendor/protomaps-leaflet/protomaps-leaflet.js'
contem "pagina do pedido escolhe a camada do mapa" "$tmp/pp.html" '/assets/js/mapa-base.js'
nao_contem "sem arquivo do mapa, nao aponta para ele (usa o OpenStreetMap)" "$tmp/pp.html" 'data-mapa-arquivo'

echo "Trocar forma de pagamento pelo motoboy (sem pergunta no Entreguei)"
curl -s -b "$mj" -o "$tmp/p.html" "$e"
contem "botao Trocar forma de pagamento na entrega" "$tmp/p.html" "data-trocar-forma-abrir=\"troca-$pedido\""
tx_cred=$(( (base_troca * 350 + 5000) / 10000 ))
esperar "credito com a taxa calculada pelo servidor" "$(escolha_de "$tmp/p.html" credito)" "credito|$tx_cred"
nao_contem "sem Asaas, sem QR na troca" "$tmp/p.html" 'value="pix_online|'
enviar "$mj" "$e/$pedido/forma" "$e" "escolha=credito|1" >/dev/null
case "$(aviso "$mj" "$e")" in "A taxa mudou: "*) ok "taxa diferente da tela recusada";; *) falha "taxa diferente da tela aceita";; esac
esperar "nada mudou com a taxa errada" "$(banco 'SELECT forma_pagamento FROM pedidos WHERE id = ?' "$pedido")" "dinheiro"
enviar "$mj" "$e/$pedido/forma" "$e" "escolha=credito|$tx_cred" >/dev/null
esperar "motoboy troca para credito" "$(aviso "$mj" "$e")" "Forma trocada para Cartão de crédito. Taxa do crédito (3,5%): + $(rs "$tx_cred"). Receber $(rs $((base_troca + tx_cred)))."
contem "card mostra o novo valor a receber" "$tmp/aviso.html" "Receber <strong>$(rs $((base_troca + tx_cred)))</strong>"
contem "card mostra a taxa incluida" "$tmp/aviso.html" 'Inclui Taxa do crédito (3,5%)'
esperar "outro usuario nao troca a entrega do motoboy (403)" "$(curl -s -o /dev/null -b "$cx" -w '%{http_code}' -X POST "$e/$pedido/forma")" "403"
enviar "$mj" "$e/$pedido/forma" "$e" "escolha=dinheiro|0" "troco_para=50,00" >/dev/null
esperar "motoboy volta para dinheiro (a taxa sai)" "$(aviso "$mj" "$e")" "Forma trocada para Dinheiro. A taxa de $(rs "$tx_cred") saiu. Receber $(rs "$base_troca")."
php -r 'require "bootstrap/app.php"; \Cardapim\Core\Database::connect()->prepare("DELETE FROM restaurante_taxas_pagamento WHERE id_restaurante = ?")->execute([$argv[1]]);' "$rid"

echo "Pix por QR Code na entrega (motoboy com o Asaas do restaurante)"
esperar "conectar Asaas (fake)" "$(enviar "$gj" "$g/integracoes/asaas" "$g/integracoes/asaas" ambiente=sandbox chave='$aact_ci_valida_1234567890abcdef' email_avisos=financeiro@example.test | cut -d' ' -f1)" "303"
curl -s -b "$mj" -o "$tmp/p.html" "$e"
esperar "com Asaas aparece o Pix por QR Code" "$(escolha_de "$tmp/p.html" pix_online)" "pix_online|0"
contem "pede o CPF do cliente para o Pix" "$tmp/p.html" 'name="cpf"'
enviar "$mj" "$e/$pedido/forma" "$e" "escolha=pix_online|0" >/dev/null
esperar "sem CPF nao gera o Pix" "$(aviso "$mj" "$e")" "Digite o CPF do cliente para gerar o Pix (o banco pede)."
esperar "sem CPF a forma continua" "$(banco 'SELECT forma_pagamento FROM pedidos WHERE id = ?' "$pedido")" "dinheiro"
r="$(enviar "$mj" "$e/$pedido/forma" "$e" "escolha=pix_online|0" "cpf=529.982.247-25")"
esperar "gerar o Pix leva ao QR Code" "$(echo "$r" | cut -d' ' -f2)" "$e/$pedido/pix"
esperar "tela do QR da entrega" "$(curl -s -o "$tmp/pix.html" -b "$mj" -w '%{http_code}' "$e/$pedido/pix")" "200"
contem "QR Code do Asaas" "$tmp/pix.html" 'data-qr="000201'
esperar "pedido em Pix pelo app, ainda pendente" "$(banco 'SELECT forma_pagamento, pagamento_status, status FROM pedidos WHERE id = ?' "$pedido")" "pix_online|pendente|saiu_entrega"
enviar "$mj" "$e/$pedido/entregue" "$e" >/dev/null
esperar "Entreguei espera o Pix cair" "$(aviso "$mj" "$e")" "O Pix por QR Code ainda não caiu. Espere a confirmação na tela do Pix ou troque a forma de pagamento."
contem "card mostra o botao do QR" "$tmp/aviso.html" "entregas/$pedido/pix"
cobranca="$(curl -s "$fake/_teste/asaas-cobrancas" | php -r '$d = json_decode(stream_get_contents(STDIN), true); foreach ($d as $c) { if ($c["externalReference"] === "entrega:" . $argv[1] && $c["status"] !== "DELETED") { echo $c["id"]; } }' "$pedido")"
[[ -n "$cobranca" ]] && ok "cobranca com a referencia entrega:{id}" || falha "cobranca da entrega nao criada"
curl -s "$fake/_teste/asaas-pagar/$cobranca" > "$tmp/pago.json"
curl -s "$fake/_teste/asaas-webhook" > "$tmp/wh.json"
tk="$(json "$tmp/wh.json" authToken)"
caminho_wh="$(json "$tmp/wh.json" url | sed 's~^https\?://[^/]*~~')"
evento="{\"id\":\"evt_entrega_$pedido\",\"event\":\"PAYMENT_RECEIVED\",\"payment\":{\"id\":\"$cobranca\",\"value\":$(json "$tmp/pago.json" value),\"externalReference\":\"entrega:$pedido\",\"billingType\":\"PIX\"}}"
esperar "aviso do Asaas da entrega aceito" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$caminho_wh")" "200"
esperar "aviso repetido nao quebra" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$caminho_wh")" "200"
esperar "Pix da entrega confirmado (pedido segue em rota)" "$(banco 'SELECT pagamento_status, status FROM pedidos WHERE id = ?' "$pedido")" "pago|saiu_entrega"
esperar "tela do QR ve o Pix pago" "$(curl -s -b "$mj" -H 'Accept: application/json' "$e/$pedido/pix" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["status"] ?? "";')" "pago"
curl -s -b "$mj" -o "$tmp/p.html" "$e"
contem "card mostra Pix recebido" "$tmp/p.html" 'Pix recebido.'
nao_contem "pago nao troca mais a forma" "$tmp/p.html" "data-trocar-forma-abrir=\"troca-$pedido\""
enviar "$gj" "$g/integracoes/asaas/desconectar" "$g/integracoes/asaas" >/dev/null

echo "Confirmar entrega"
enviar "$mj" "$e/999999/entregue" "$e" >/dev/null
esperar "nao confirma entrega de outro" "$(aviso "$mj" "$e")" "Esta entrega não está com você."
enviar "$mj" "$e/$pedido/entregue" "$e" >/dev/null
m="$(aviso "$mj" "$e")"
if [[ "$m" == "O caixa do restaurante está fechado."* ]]; then
  ok "caixa fechado: pede para abrir antes"
  enviar "$cx" "$c/abrir" "$c/historico" valor_abertura=50,00 >/dev/null
  enviar "$mj" "$e/$pedido/entregue" "$e" >/dev/null
  m="$(aviso "$mj" "$e")"
fi
esperar "motoqueiro confirma a entrega" "$m" "Entrega do pedido #$numero confirmada. Bom trabalho!"
esperar "Pix da entrega entra no caixa como Pix pelo app" "$(banco 'SELECT forma, valor FROM pedido_pagamentos WHERE id_pedido = ?' "$pedido")" "pix_online|$(dec "$base_troca")"
contem "entrega vai para Feitas hoje" "$tmp/aviso.html" "<strong>#$numero</strong>"
nao_contem "sai de Com voce agora" "$tmp/aviso.html" "Pedido #$numero</strong>"
echo "Gerente decide se o entregador ve os valores das taxas"
contem "por padrao o entregador ve quanto ganhou" "$tmp/aviso.html" "em taxas"
esperar "gerente esconde os valores" "$(enviar "$gj" "$g/entrega-pagamento/entregador" "$g/entrega-pagamento" | cut -d' ' -f1)" "303"
curl -s -b "$mj" "$e" -o "$tmp/sem-valores.html"
nao_contem "entregador nao ve o total em taxas" "$tmp/sem-valores.html" "em taxas"
nao_contem "entregador nao ve a taxa de cada entrega" "$tmp/sem-valores.html" 'class="feitas-valor"'
contem "entregador continua vendo a entrega feita" "$tmp/sem-valores.html" "<strong>#$numero</strong>"
curl -s -b "$gj" "$g/equipe" -o "$tmp/ep.html"
contem "opcao do entregador fica na aba Equipe" "$tmp/ep.html" 'name="entregador_ve_valores"'
nao_contem "opcao aparece desligada para o gerente" "$tmp/ep.html" 'name="entregador_ve_valores" value="1" checked'
esperar "gerente volta a mostrar os valores" "$(enviar "$gj" "$g/equipe/regras/entregador" "$g/equipe" entregador_ve_valores=1 | cut -d' ' -f1)" "303"
curl -s -b "$mj" "$e" -o "$tmp/com-valores.html"
contem "entregador volta a ver as taxas" "$tmp/com-valores.html" "em taxas"
esperar "motoqueiro nao muda a opcao" "$(curl -s -o /dev/null -b "$mj" -w '%{http_code}' -X POST "$g/entrega-pagamento/entregador")" "403"
curl -s -b "$cj" -o "$tmp/r.json" "$base/api/v1/pedidos/$pedido"
esperar "cliente ve entregue" "$(json "$tmp/r.json" status)" "concluido"
curl -s -b "$cj" -o "$tmp/rast.json" "$base/api/v1/pedidos/$pedido/rastreio"
esperar "depois de entregue, sem rastreio" "$(json "$tmp/rast.json" ativo)" "false"
curl -s -b "$cj" -o "$tmp/mp.json" "$base/api/v1/cliente/pedidos?restaurante=$slug"
esperar "historico mostra entregue" "$(json "$tmp/mp.json" pedidos.0.status_rotulo)" "Entregue"
esperar "sai de em andamento" "$(json "$tmp/mp.json" em_andamento)" "[]"
enviar "$mj" "$e/$pedido/entregue" "$e" >/dev/null
esperar "nao confirma duas vezes" "$(aviso "$mj" "$e")" "Esta entrega já foi concluída ou cancelada."
esperar "parar de compartilhar" "$(curl -s -o /dev/null -b "$mj" -w '%{http_code}' -X POST -H "X-CSRF-Token: $mcsrf" "$e/localizacao/parar")" "200"

echo "Limpeza"
if curl -s -b "$cx" "$c" | grep -q 'Caixa aberto'; then
  conta="$(curl -s -b "$cx" "$c" | php -r 'if (preg_match("~Dinheiro na gaveta</span>\s*<strong>R\\$ ([\d.,]+)</strong>~", stream_get_contents(STDIN), $m)) echo $m[1];')"
  enviar "$cx" "$c/fechar" "$c/historico" "dinheiro_contado=$conta" >/dev/null
fi
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/desconectar"
ok "caixa fechado e WhatsApp de teste desligado"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) nas entregas."
  exit 1
fi
echo "OK: painel do entregador, localizacao em tempo real, rastreio e historico de pedidos do cliente."
