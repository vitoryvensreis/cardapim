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
contem "botao de compartilhar localizacao" "$tmp/p.html" 'data-local-botao'
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
sessao_wpp="$(ls -t "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-cardapim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
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
curl -s -b "$cj" -o "$tmp/rast.json" "$base/api/v1/pedidos/$pedido/rastreio"
esperar "antes de sair, sem rastreio" "$(json "$tmp/rast.json" ativo)" "false"
esperar "outro cliente nao ve o rastreio (404)" "$(curl -s -o /dev/null -b "$oc" -w '%{http_code}' "$base/api/v1/pedidos/$pedido/rastreio")" "404"
esperar "sem login nao ve o rastreio (401)" "$(curl -s -o /dev/null -w '%{http_code}' "$base/api/v1/pedidos/$pedido/rastreio")" "401"
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

esperar "motoqueiro envia a localizacao" "$(local_post "$mj" "$mcsrf" '{"latitude":-23.5590,"longitude":-46.6640,"precisao":12,"direcao":90,"velocidade":8}')" "200"
esperar "envia de novo (atualiza a mesma linha)" "$(local_post "$mj" "$mcsrf" '{"latitude":-23.5595,"longitude":-46.6645,"precisao":10}')" "200"
curl -s -b "$cj" -o "$tmp/rast.json" "$base/api/v1/pedidos/$pedido/rastreio"
esperar "cliente ve o entregador no mapa" "$(json "$tmp/rast.json" ativo)" "true"
esperar "primeiro nome do entregador" "$(json "$tmp/rast.json" entregador.nome)" "CI"
esperar "posicao mais recente" "$(json "$tmp/rast.json" entregador.latitude)" "-23.5595"
nao_contem "rastreio nao expoe telefone nem sobrenome" "$tmp/rast.json" 'Motoqueiro'
curl -s -b "$cx" -o "$tmp/d.html" "$c/delivery/$pedido"
contem "caixa ve a localizacao ao vivo" "$tmp/d.html" 'Localização ao vivo'
esperar "pagina do pedido carrega o mapa" "$(curl -s -o "$tmp/pp.html" -w '%{http_code}' "$base/$slug/pedido/$pedido")" "200"
contem "pagina do pedido tem o bloco do mapa" "$tmp/pp.html" 'data-ap-mapa'
contem "pagina do pedido carrega o Leaflet" "$tmp/pp.html" '/assets/vendor/leaflet/leaflet.js'

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
contem "entrega vai para Feitas hoje" "$tmp/aviso.html" "<strong>#$numero</strong>"
nao_contem "sai de Com voce agora" "$tmp/aviso.html" "Pedido #$numero</strong>"
curl -s -b "$cj" -o "$tmp/r.json" "$base/api/v1/pedidos/$pedido"
esperar "cliente ve entregue" "$(json "$tmp/r.json" status)" "concluido"
curl -s -b "$cj" -o "$tmp/rast.json" "$base/api/v1/pedidos/$pedido/rastreio"
esperar "depois de entregue, sem rastreio" "$(json "$tmp/rast.json" ativo)" "false"
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
echo "OK: painel do entregador, localizacao em tempo real e rastreio pelo cliente."
