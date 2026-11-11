#!/usr/bin/env bash
# Teste HTTP dos AVISOS DO PEDIDO pelo WhatsApp DO RESTAURANTE (fila no banco, envio depois da resposta, sem repetir)
# e do menu do gerente sem Entregas/Mesas (ficam so no modo caixa).
# Usa o servidor FALSO tests/fakes/integracoes-fake.php (WPPConnect da plataforma e do restaurante).
# Uso: bash tests/http/avisos.sh <base> <slug> <base-do-fake>
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
senha='Senha#CI2026'
c="$base/$slug/caixa"
g="$base/$slug/gerencia"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0
pasta_fake="$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes"

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
json() { php -r '$d = json_decode(file_get_contents($argv[1]), true); $v = $d; foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; } echo is_scalar($v) ? $v : json_encode($v);' "$1" "$2"; }
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
sessao() { curl -s -c "$1" -b "$1" -o "$tmp/sessao.json" "$base/api/v1/cliente"; json "$tmp/sessao.json" csrf; }
post() { curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" --data "$4" "$base$3"; }
chave() { php -r 'echo bin2hex(random_bytes(16));'; }
# Todas as mensagens que o WhatsApp falso recebeu (mensagens com varias linhas viram varias linhas do log).
mensagens() { curl -s "$fake/_teste/wpp-mensagens" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode("\n", $d["linhas"] ?? []);' > "$tmp/msgs.txt"; }
quantas() { mensagens; grep -c -- "$1" "$tmp/msgs.txt" || true; }
sessao_nova() { ls -t "$pasta_fake" | grep -o "wpp-$1[a-z0-9_]*" | head -1 | sed 's/^wpp-//'; }
ultimo_codigo() { mensagens; php -r '$l = file($argv[1], FILE_IGNORE_NEW_LINES); for ($i = count($l) - 1; $i >= 0; $i--) { if (strpos($l[$i], $argv[2]) !== false && preg_match("/\*(\d{6})\*/", $l[$i], $m)) { echo $m[1]; exit; } }' "$tmp/msgs.txt" "$1"; }

echo "Menu do gerente"
gj="$tmp/gerente"; cx="$tmp/caixa"; mj="$tmp/moto"; aj="$tmp/admin"
esperar "gerente entra" "$(login_equipe "$gj" ci_gerente)" "303"
esperar "caixa entra" "$(login_equipe "$cx" ci_caixa)" "303"
esperar "motoqueiro entra" "$(login_equipe "$mj" ci_motoqueiro)" "303"
curl -s -b "$gj" "$g/estoque" -o "$tmp/menu.html"
nao_contem "menu sem Entregas" "$tmp/menu.html" "href=\"/$slug/entregas\""
nao_contem "menu sem Mesas" "$tmp/menu.html" "href=\"/$slug/gerencia/mesas\""
contem "menu continua com o Caixa" "$tmp/menu.html" "href=\"/$slug/caixa\""
esperar "endereco antigo de mesas ainda leva ao caixa" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' "$g/mesas")" "303"

echo "Preparar restaurante e WhatsApps"
t="$(token "$gj" "$g/entrega-pagamento")"
curl -s -o /dev/null -b "$gj" -c "$gj" --data-urlencode "_csrf=$t" -d aceita_entrega=1 -d aceita_retirada=1 -d pag_dinheiro=1 -d pag_debito=1 "$g/entrega-pagamento"
t="$(token "$gj" "$g/entrega-pagamento")"
curl -s -o /dev/null -b "$gj" -c "$gj" --data-urlencode "_csrf=$t" -d tipo=bairro --data-urlencode "bairro=Cerqueira César" --data-urlencode "cidade=São Paulo" -d taxa=6,00 "$g/entrega-pagamento/areas"
login_equipe "$aj" ci_master >/dev/null
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/conectar"
plat="$(sessao_nova cardapim_plataforma_)"
curl -s "$fake/_teste/wpp-conectar/$plat" >/dev/null
curl -s -b "$aj" "$base/painel/integracoes/whatsapp/estado" >/dev/null
esperar "WhatsApp do restaurante: conectar" "$(enviar "$gj" "$g/integracoes/whatsapp/conectar" "$g/integracoes/whatsapp" | cut -d' ' -f1)" "303"
loja="$(sessao_nova cardapim_r)"
curl -s "$fake/_teste/wpp-conectar/$loja" >/dev/null
curl -s -b "$gj" "$g/integracoes/whatsapp/estado" -o "$tmp/estado.json"
esperar "WhatsApp do restaurante conectado" "$(json "$tmp/estado.json" estado)" "conectado"
curl -s -b "$gj" "$g/integracoes/whatsapp" -o "$tmp/wpp.html"
contem "tela mostra os avisos automaticos" "$tmp/wpp.html" 'Avisos automáticos para os clientes'
contem "todos os avisos ligados por padrao" "$tmp/wpp.html" 'value="saiu_entrega" checked'

entrar_cliente() { # jar telefone nome -> csrf
  local csrf
  csrf="$(sessao "$1")"
  post "$1" "$csrf" /api/v1/cliente/codigo "{\"telefone\":\"$2\"}" >/dev/null
  post "$1" "$csrf" /api/v1/cliente/entrar "{\"codigo\":\"$(ultimo_codigo "$plat|55$2|")\"}" >/dev/null
  csrf="$(json "$tmp/r.json" csrf)"
  if [[ "$(json "$tmp/r.json" etapa)" == "nome" ]]; then post "$1" "$csrf" /api/v1/cliente/cadastro "{\"nome\":\"$3\"}" >/dev/null; csrf="$(json "$tmp/r.json" csrf)"; fi
  echo "$csrf"
}
tel=85977772001
cj="$tmp/cliente"
ccsrf="$(entrar_cliente "$cj" "$tel" "Joana Avisos")"
curl -s "$base/$slug" -o "$tmp/cardapio.html"
produto="$(php -r 'preg_match_all("/<button[^>]*data-produto=\"(\d+)\"[^>]*>/s", file_get_contents($argv[1]), $m, PREG_SET_ORDER); foreach ($m as $p) { if (!str_contains($p[0], "disabled")) { echo $p[1]; exit; } }' "$tmp/cardapio.html")"
endereco='{"cep":"01414000","logradouro":"Rua Haddock Lobo","numero":"100","bairro":"Cerqueira César","cidade":"São Paulo","estado":"SP"}'
pedir() { post "$cj" "$ccsrf" "/api/v1/restaurantes/$slug/pedidos" "{\"chave\":\"$(chave)\",\"tipo\":\"entrega\",\"forma_pagamento\":\"dinheiro\",\"troco_para\":\"100,00\",\"itens\":[{\"id\":$produto,\"quantidade\":1}],\"endereco\":$endereco}"; }
para="$loja|55$tel|"

echo "Avisos ao longo do pedido"
esperar "cliente faz o pedido" "$(pedir)" "201"
pedido="$(json "$tmp/r.json" pedido.id)"; numero="$(json "$tmp/r.json" pedido.numero)"
sleep 1
mensagens
contem "aviso de pedido recebido sai do WhatsApp do restaurante" "$tmp/msgs.txt" "${para}*"
contem "recebido cumprimenta e confirma o numero" "$tmp/msgs.txt" "Olá, Joana! Recebemos seu pedido \*#$numero\*"
contem "recebido traz o link para acompanhar" "$tmp/msgs.txt" "/$slug/pedido/$pedido"
nao_contem "nao sai pelo WhatsApp da plataforma" "$tmp/msgs.txt" "$plat|55$tel|\*Restaurante"
esperar "caixa aceita" "$(enviar "$cx" "$c/delivery/$pedido/aceitar" "$c/historico" | cut -d' ' -f1)" "303"
sleep 1
esperar "aviso de em preparo" "$(quantas "\*#$numero\* já está sendo preparado")" "1"
esperar "caixa marca pronto" "$(enviar "$cx" "$c/delivery/$pedido/pronto" "$c/historico" | cut -d' ' -f1)" "303"
sleep 1
esperar "aviso de pronto" "$(quantas "\*#$numero\* está pronto e já vai sair")" "1"
curl -s -b "$cx" "$c/delivery/$pedido" -o "$tmp/d.html"
entregador="$(php -r 'if (preg_match("~name=\"entregador\" value=\"(\d+)\"~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/d.html")"
enviar "$cx" "$c/delivery/$pedido/despachar" "$c/historico" entregador="$entregador" >/dev/null
sleep 1
esperar "aviso de saiu para entrega com o entregador" "$(quantas "\*#$numero\* saiu para entrega com CI")" "1"
mensagens
contem "saiu para entrega diz quanto pagar e o troco" "$tmp/msgs.txt" 'Pagamento na entrega: R\$ [0-9,]* (dinheiro, troco para R\$ 100,00).'
contem "saiu para entrega traz o link do mapa" "$tmp/msgs.txt" "Veja a moto chegando no mapa: .*/$slug/pedido/$pedido"
enviar "$cx" "$c/abrir" "$c/historico" valor_abertura=50,00 >/dev/null
enviar "$mj" "$base/$slug/entregas/$pedido/entregue" "$base/$slug/entregas" >/dev/null
sleep 1
esperar "aviso de entregue" "$(quantas "Pedido \*#$numero\* entregue")" "1"
esperar "recebido foi enviado uma vez so" "$(quantas "Recebemos seu pedido \*#$numero\*")" "1"
curl -s -b "$gj" "$g/integracoes/whatsapp" -o "$tmp/wpp.html"
contem "ultimos avisos lista o pedido" "$tmp/wpp.html" "Pedido #$numero"
contem "ultimos avisos mostra enviado" "$tmp/wpp.html" 'Enviado'

echo "Escolher avisos e cancelamento"
esperar "gerente desliga o aviso de em preparo" "$(enviar "$gj" "$g/integracoes/whatsapp/avisos" "$g/integracoes/whatsapp" avisos[]=recebido avisos[]=pronto avisos[]=saiu_entrega avisos[]=concluido avisos[]=cancelado | cut -d' ' -f1)" "303"
curl -s -b "$gj" "$g/integracoes/whatsapp" -o "$tmp/wpp.html"
contem "aviso salvo com sucesso" "$tmp/wpp.html" 'Avisos salvos'
nao_contem "em preparo desmarcado" "$tmp/wpp.html" 'value="em_preparo" checked'
esperar "segundo pedido" "$(pedir)" "201"
p2="$(json "$tmp/r.json" pedido.id)"; n2="$(json "$tmp/r.json" pedido.numero)"
enviar "$cx" "$c/delivery/$p2/aceitar" "$c/historico" >/dev/null
sleep 1
esperar "em preparo desligado nao envia" "$(quantas "\*#$n2\* já está sendo preparado")" "0"
enviar "$cx" "$c/delivery/$p2/cancelar" "$c/historico" motivo="Acabou o gás" >/dev/null
sleep 1
esperar "aviso de cancelado" "$(quantas "\*#$n2\* foi cancelado")" "1"
contem "cancelado traz o motivo sem prefixo interno" "$tmp/msgs.txt" 'Motivo: Acabou o gás'

echo "WhatsApp do restaurante desconectado"
enviar "$gj" "$g/integracoes/whatsapp/desconectar" "$g/integracoes/whatsapp" >/dev/null
esperar "terceiro pedido" "$(pedir)" "201"
n3="$(json "$tmp/r.json" pedido.numero)"
sleep 1
esperar "sem WhatsApp conectado nada e enviado" "$(quantas "Recebemos seu pedido \*#$n3\*")" "0"
esperar "pagina continua abrindo" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' "$g/integracoes/whatsapp")" "200"

echo "Limpeza"
enviar "$gj" "$g/integracoes/whatsapp/avisos" "$g/integracoes/whatsapp" avisos[]=recebido avisos[]=em_preparo avisos[]=pronto avisos[]=saiu_entrega avisos[]=concluido avisos[]=cancelado >/dev/null
if curl -s -b "$cx" "$c" | grep -q 'Caixa aberto'; then
  conta="$(curl -s -b "$cx" "$c" | php -r 'if (preg_match("~Dinheiro na gaveta</span>\s*<strong>R\\$ ([\d.,]+)</strong>~", stream_get_contents(STDIN), $m)) echo $m[1];')"
  enviar "$cx" "$c/fechar" "$c/historico" "dinheiro_contado=$conta" >/dev/null
fi
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/desconectar"
ok "avisos religados, caixa fechado e WhatsApps de teste desligados"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) nos avisos."
  exit 1
fi
echo "OK: avisos do pedido pelo WhatsApp do restaurante e menu do gerente."
