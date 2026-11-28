#!/usr/bin/env bash
# Teste HTTP dos AVISOS DO PEDIDO pelo WhatsApp DO RESTAURANTE (fila no banco, envio depois da resposta, sem repetir)
# e do menu do gerente sem Entregas/Mesas (ficam so no modo caixa).
# Usa o servidor FALSO tests/fakes/integracoes-fake.php (Evolution API: WhatsApp da plataforma e do restaurante).
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
plat="$(sessao_nova deliveryzim_plataforma_)"
curl -s "$fake/_teste/wpp-conectar/$plat" >/dev/null
curl -s -b "$aj" "$base/painel/integracoes/whatsapp/estado" >/dev/null
esperar "WhatsApp do restaurante: conectar" "$(enviar "$gj" "$g/integracoes/whatsapp/conectar" "$g/integracoes/whatsapp" | cut -d' ' -f1)" "303"
loja="$(sessao_nova deliveryzim_r)"
curl -s "$fake/_teste/wpp-conectar/$loja" >/dev/null
curl -s -b "$gj" "$g/integracoes/whatsapp/estado" -o "$tmp/estado.json"
esperar "WhatsApp do restaurante conectado" "$(json "$tmp/estado.json" estado)" "conectado"
curl -s -b "$gj" "$g/integracoes/whatsapp" -o "$tmp/wpp.html"
contem "tela mostra os avisos automaticos" "$tmp/wpp.html" 'Avisos automáticos para os clientes'
contem "todos os avisos ligados por padrao" "$tmp/wpp.html" 'value="saiu_entrega" checked'
esperar "codigo de conexao exige a sessao (CSRF)" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -d telefone=85988811631 "$g/integracoes/whatsapp/codigo")" "401"
esperar "codigo de conexao: servidor so com QR Code recusa com aviso" "$(enviar "$gj" "$g/integracoes/whatsapp/codigo" "$g/integracoes/whatsapp" telefone=85988811631 | cut -d' ' -f1)" "422"
contem "codigo de conexao: explica o motivo" "$tmp/resp.html" 'QR Code'
curl -s -b "$gj" "$g" -o "$tmp/vg.html"
nao_contem "visao geral sem alerta de WhatsApp com a loja conectada" "$tmp/vg.html" 'WhatsApp precisa de atenção'

echo "Saudacao diaria pelo WhatsApp do restaurante"
contem "configuracao de saudacao na tela" "$tmp/wpp.html" "Mensagem de boas-vindas"
# O usuario de teste pode ter horarios do perfil; abrir 24h temporariamente.
sempre_antes="$(php -r 'require "bootstrap/app.php"; echo \Cardapim\Core\Database::connect()->query("SELECT sempre_aberto FROM restaurantes WHERE slug = '\''restaurante-modelo-cardapim'\''")->fetchColumn();')"
esperar "horario de teste sempre aberto" "$(enviar "$gj" "$g/perfil/horarios" "$g/perfil" modo=sempre | cut -d' ' -f1)" "303"
esperar "gerente ativa a saudacao" "$(enviar "$gj" "$g/integracoes/whatsapp/saudacao" "$g/integracoes/whatsapp" saudacao_ativo=1 'saudacao_mensagem=Bem-vindo ao {{restaurante}}! Cardápio {{cardapio}}' | cut -d' ' -f1)" "303"
t_evento="$(date +%s)"
wpp_callback="$base/webhooks/evolution/$EVOLUTION_WEBHOOK_TOKEN"
# Formato do webhook da Evolution (messages.upsert); apikey = token da instancia da loja.
evo() { printf '{"event":"messages.upsert","instance":"%s","apikey":"%s","data":{"key":{"remoteJid":"%s","fromMe":false,"id":"CI%s"},"messageTimestamp":%s,"message":{"conversation":"oi"}}}' "$loja" "${2:-tok-$loja}" "$1" "$RANDOM" "$t_evento"; }
esperar "segredo incorreto bloqueado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d '{}' "$base/webhooks/evolution/invalido")" "401"
esperar "token de outra instancia bloqueado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "$(evo 5585999990000@s.whatsapp.net tok-outra)" "$wpp_callback")" "401"
esperar "evento que nao e mensagem e ignorado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "{\"event\":\"connection.update\",\"instance\":\"$loja\"}" "$wpp_callback")" "200"
evento="$(evo 5585999990000@s.whatsapp.net)"
esperar "recebe mensagem" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "$evento" "$wpp_callback")" "200"
sleep 1
esperar "primeira saudacao enviada" "$(quantas 'Bem-vindo ao.* Cardápio')" "1"
mensagens
contem "saudacao contem link exclusivo da loja" "$tmp/msgs.txt" "/$slug"
esperar "webhook duplicado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "$evento" "$wpp_callback")" "200"
sleep 1
esperar "uma unica saudacao por dia" "$(quantas 'Bem-vindo ao.* Cardápio')" "1"
# Endereco LID e opaco: manter @lid e nao tentar converter para um telefone.
lid='1234567890123456@lid'
evento_lid="$(evo "$lid")"
esperar "webhook recebe contato LID" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "$evento_lid" "$wpp_callback")" "200"
sleep 1
esperar "saudacao LID enviada" "$(quantas 'Bem-vindo ao.* Cardápio')" "2"
mensagens
contem "resposta enderecada ao LID original" "$tmp/msgs.txt" "$loja|$lid|"
esperar "LID repetido responde HTTP 200" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "$evento_lid" "$wpp_callback")" "200"
sleep 1
esperar "LID sem duplicacao no dia" "$(quantas 'Bem-vindo ao.* Cardápio')" "2"
grupo="$(evo 120363000000000000@g.us)"
curl -s -o /dev/null -H 'Content-Type: application/json' -d "$grupo" "$wpp_callback"
esperar "grupo nao recebe saudacao" "$(quantas 'Bem-vindo ao.* Cardápio')" "2"
curl -s -b "$gj" "$g/integracoes/whatsapp" -o "$tmp/wpp.html"
contem "diagnostico mostra a saudacao enviada" "$tmp/wpp.html" 'Boas-vindas enviada'
contem "diagnostico mostra repetida no dia" "$tmp/wpp.html" 'Já recebeu as boas-vindas hoje'
contem "diagnostico mostra o grupo ignorado" "$tmp/wpp.html" 'Grupo, status ou número de fora do Brasil'
contem "webhook da instancia conferido" "$tmp/wpp.html" 'Recebendo mensagens'
contem "envio mostra so o final do numero" "$tmp/wpp.html" 'Final 0000'
# Instancia com o webhook apontando para outro lugar: a tela avisa e o botao corrige.
curl -s -o /dev/null -H "apikey: tok-$loja" -H 'Content-Type: application/json' -d '{"webhook":{"enabled":true,"url":"https://outro.exemplo/x","events":["MESSAGES_UPSERT"]}}' "$fake/evo/webhook/set/$loja"
curl -s -b "$gj" "$g/integracoes/whatsapp" -o "$tmp/wpp.html"
contem "tela avisa webhook errado" "$tmp/wpp.html" 'não está avisando o sistema'
esperar "corrigir recebimento" "$(enviar "$gj" "$g/integracoes/whatsapp/saudacao/webhook" "$g/integracoes/whatsapp" | cut -d' ' -f1)" "303"
curl -s -b "$gj" "$g/integracoes/whatsapp" -o "$tmp/wpp.html"
contem "webhook corrigido" "$tmp/wpp.html" 'Recebendo mensagens'
# Webhook do WAHA: mesmo destino (saudacao), protegido pelo segredo da URL + assinatura HMAC-SHA512 com o token da loja.
waha_cb="$base/webhooks/waha/$WAHA_WEBHOOK_TOKEN"
waha() { printf '{"id":"evt_ci%s","event":"message","session":"%s","payload":{"id":"CI%s","from":"%s","fromMe":false,"timestamp":%s,"body":"oi"}}' "$RANDOM" "$loja" "$RANDOM" "$1" "$t_evento"; }
assinar() { printf %s "$1" | openssl dgst -sha512 -hmac "$2" | sed 's/^.* //'; }
esperar "WAHA: segredo incorreto bloqueado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data-binary '{}' "$base/webhooks/waha/invalido")" "401"
esperar "WAHA: evento que nao e mensagem ignorado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data-binary "{\"event\":\"session.status\",\"session\":\"$loja\",\"payload\":{}}" "$waha_cb")" "200"
corpo_waha="$(waha 5585999990001@c.us)"
esperar "WAHA: sem assinatura bloqueado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data-binary "$corpo_waha" "$waha_cb")" "401"
esperar "WAHA: assinatura de outra loja bloqueada" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H "X-Webhook-Hmac: $(assinar "$corpo_waha" tok-outra)" --data-binary "$corpo_waha" "$waha_cb")" "401"
esperar "WAHA: mensagem assinada recebida" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H "X-Webhook-Hmac: $(assinar "$corpo_waha" "tok-$loja")" -H 'X-Webhook-Hmac-Algorithm: sha512' --data-binary "$corpo_waha" "$waha_cb")" "200"
sleep 1
esperar "WAHA: saudacao enviada para o contato novo" "$(quantas 'Bem-vindo ao.* Cardápio')" "3"
enviar "$gj" "$g/integracoes/whatsapp/saudacao" "$g/integracoes/whatsapp" 'saudacao_mensagem=Mensagem de teste' >/dev/null
# Restaurar somente modo 24h; os turnos originais foram preservados.
php -r 'require "bootstrap/app.php"; \Cardapim\Core\Database::connect()->prepare("UPDATE restaurantes SET sempre_aberto=? WHERE slug=?")->execute([(int)$argv[1],$argv[2]]);' "$sempre_antes" "$slug"

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
