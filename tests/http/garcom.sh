#!/usr/bin/env bash
# Teste HTTP do PAINEL DO GARCOM de ponta a ponta: acesso por cargo, mesas, abrir mesa, lancar varios itens com
# observacao, remover item, 10% de servico, receber em dinheiro com troco, Pix com QR Code pelo Asaas do restaurante
# (confirmado pelo aviso e pela consulta, sem entrar duas vezes), trocar a forma de pagamento e consulta do cardapio.
# Usa o servidor FALSO tests/fakes/integracoes-fake.php (Asaas).
# Uso: bash tests/http/garcom.sh <base> <slug> <base-do-fake>
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
senha='Senha#CI2026'
w="$base/$slug/garcom"
c="$base/$slug/caixa"
g="$base/$slug/gerencia"
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
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
enviar() { # jar url pagina-do-token [campo=valor ...] -> "codigo location"
  local jar="$1" url="$2" origem="$3" t args=()
  shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
pagina() { curl -s -c "$1" -b "$1" -o "$tmp/pagina.html" -w '%{http_code}' "$2"; }
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
cents() { php -r 'echo (int) round((float) str_replace(",", ".", str_replace(".", "", $argv[1])) * 100);' "$1"; }
reais() { php -r 'echo "R$ " . number_format($argv[1] / 100, 2, ",", ".");' "$1"; }
pix_json() { curl -s -b "$1" -H 'Accept: application/json' -o "$tmp/pix.json" "$2"; }
cobranca_de() { curl -s "$fake/_teste/asaas-cobrancas" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $id = ""; foreach ($d as $c) { if ($c["externalReference"] === "comanda:" . $argv[1] && $c["status"] !== "DELETED") { $id = $c["id"]; } } echo $id;' "$1"; }
status_cobranca() { curl -s "$fake/_teste/asaas-cobrancas" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d[$argv[1]]["status"] ?? "";' "$1"; }

echo "Acesso ao painel do garcom"
wj="$tmp/garcom"; gj="$tmp/gerente"; cx="$tmp/caixa"; mj="$tmp/moto"
esperar "garcom entra e vai direto para as mesas" "$(login_equipe "$wj" ci_garcom)" "303 $w"
login_equipe "$gj" ci_gerente >/dev/null
login_equipe "$cx" ci_caixa >/dev/null
login_equipe "$mj" ci_motoqueiro >/dev/null
esperar "tela de mesas abre" "$(pagina "$wj" "$w")" "200"
contem "titulo Mesas" "$tmp/pagina.html" '<h1>Mesas</h1>'
contem "carrega o script do garcom" "$tmp/pagina.html" '/assets/js/garcom.js'
contem "menu com Mesas e Cardapio" "$tmp/pagina.html" "href=\"/$slug/garcom/cardapio\""
contem "filtro de livres e ocupadas" "$tmp/pagina.html" 'data-filtro-mesa="livre"'
nao_contem "nenhum estilo inline" "$tmp/pagina.html" 'style="'
esperar "gerente tambem abre o painel do garcom" "$(pagina "$gj" "$w")" "200"
esperar "caixa nao abre o painel do garcom (403)" "$(pagina "$cx" "$w")" "403"
esperar "motoqueiro nao abre o painel do garcom (403)" "$(pagina "$mj" "$w")" "403"
esperar "garcom nao abre o modo caixa (403)" "$(pagina "$wj" "$c")" "403"
esperar "garcom nao abre a gerencia (403)" "$(pagina "$wj" "$g/estoque")" "403"
esperar "sem login vai para o login" "$(curl -s -o /dev/null -w '%{http_code}' "$w")" "302"
esperar "POST sem CSRF volta" "$(curl -s -o /dev/null -b "$wj" -w '%{http_code}' -X POST "$w/mesas/1/abrir")" "303"

echo "Caixa aberto para receber"
pagina "$cx" "$c" >/dev/null
abriu_caixa=0
if grep -q 'name="valor_abertura"' "$tmp/pagina.html"; then
  enviar "$cx" "$c/abrir" "$c" valor_abertura=100,00 >/dev/null
  abriu_caixa=1
fi
pagina "$cx" "$c" >/dev/null
nao_contem "caixa aberto" "$tmp/pagina.html" 'name="valor_abertura"'

echo "Abrir a mesa e lancar itens"
pagina "$wj" "$w" >/dev/null
mesa="$(php -r 'if (preg_match("~/garcom/mesas/(\d+)/abrir~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
[[ -n "$mesa" ]] && ok "mesa livre encontrada ($mesa)" || falha "nenhuma mesa livre"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
[[ -n "$comanda" ]] && ok "abrir a mesa leva a conta ($comanda)" || falha "abrir a mesa nao levou a conta ($r)"
r2="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
esperar "abrir de novo volta a mesma conta" "$(echo "$r2" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)" "$comanda"
esperar "conta abre" "$(pagina "$wj" "$w/comandas/$comanda")" "200"
contem "mesa aberta sem itens" "$tmp/pagina.html" 'Mesa aberta'
contem "folha do cardapio na tela" "$tmp/pagina.html" 'id="adicionar"'
contem "botao fechar conta desligado sem itens" "$tmp/pagina.html" 'aria-disabled="true"'
# Dois produtos que estao a venda agora (o estoque muda conforme os outros testes).
produtos="$(php -r 'foreach (explode("<li class=\"produto-linha", file_get_contents($argv[1])) as $li) { if (preg_match("~data-preco=\"(\d+)\".*?name=\"qtd\[(\d+)\]\" value=\"0\" min=\"0\" max=\"(\d+)\"~s", $li, $m) && (int) $m[3] >= 8) { echo $m[2], " ", $m[1], "\n"; } }' "$tmp/pagina.html")"
read -r cerveja preco_cerveja < <(echo "$produtos" | sed -n 1p) || true
read -r outro preco_outro < <(echo "$produtos" | sed -n 2p) || true
[[ -n "${cerveja:-}" && -n "${outro:-}" ]] && ok "produtos no cardapio da folha ($cerveja, $outro)" || falha "produtos nao encontrados na folha"

enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$cerveja]=0" >/dev/null
esperar "sem produto escolhido" "$(aviso "$wj" "$w/comandas/$comanda")" "Escolha pelo menos um produto."
enviar "$wj" "$w/comandas/$comanda/itens" "$w" "qtd[$cerveja]=2" "obs[$cerveja]=bem gelada" "qtd[$outro]=1" "obs[$outro]=" "qtd[999999]=1" >/dev/null
msg="$(aviso "$wj" "$w/comandas/$comanda")"
case "$msg" in "3 itens lançados."*) ok "lancou 3 itens de uma vez";; *) falha "lancar varios ($msg)";; esac
contem "aviso do produto inexistente" "$tmp/aviso.html" 'Produto não encontrado'
contem "conta mostra 2x" "$tmp/aviso.html" '>2x<'
contem "conta mostra a observacao" "$tmp/aviso.html" 'bem gelada'
subtotal=$((preco_cerveja * 2 + preco_outro))
contem "total dos itens com 10%" "$tmp/aviso.html" "$(reais $((subtotal + (subtotal * 1000 + 5000) / 10000)))"
item="$(php -r 'if (preg_match_all("~/itens/(\d+)/cancelar~", file_get_contents($argv[1]), $m)) echo end($m[1]);' "$tmp/aviso.html")"
enviar "$wj" "$w/comandas/$comanda/itens/$item/cancelar" "$w" "motivo=Cliente desistiu" >/dev/null
msg="$(aviso "$wj" "$w/comandas/$comanda")"
case "$msg" in *"cancelado"*) ok "remover item com motivo";; *) falha "remover item ($msg)";; esac
subtotal=$((preco_cerveja * 2))

echo "Fechar conta: 10% e dinheiro com troco"
esperar "tela de fechar a conta" "$(pagina "$wj" "$w/comandas/$comanda/conta")" "200"
contem "10% ligado por padrao" "$tmp/pagina.html" 'role="switch" aria-checked="true"'
nao_contem "sem Asaas nao mostra QR Code" "$tmp/pagina.html" 'QR Code Pix'
contem "formas de pagamento" "$tmp/pagina.html" 'name="forma_tela" value="dinheiro"'
enviar "$wj" "$w/comandas/$comanda/servico" "$w" servico=0 >/dev/null
esperar "tirar os 10%" "$(aviso "$wj" "$w/comandas/$comanda/conta")" "10% de serviço retirado."
contem "agora sem os 10%" "$tmp/aviso.html" 'role="switch" aria-checked="false"'
contem "total sem servico" "$tmp/aviso.html" "data-restante=\"$subtotal\""
enviar "$wj" "$w/comandas/$comanda/servico" "$w" servico=1 >/dev/null
esperar "colocar os 10% de volta" "$(aviso "$wj" "$w/comandas/$comanda/conta")" "10% de serviço incluído."
total=$((subtotal + (subtotal * 1000 + 5000) / 10000))
contem "total com servico" "$tmp/aviso.html" "data-restante=\"$total\""
parte=$((total / 2))
parte_txt="$(php -r 'echo number_format($argv[1] / 100, 2, ",", "");' "$parte")"
enviar "$wj" "$w/comandas/$comanda/pagamentos" "$w" forma=dinheiro "valor=$parte_txt" recebido=50,00 >/dev/null
msg="$(aviso "$wj" "$w/comandas/$comanda/conta")"
case "$msg" in "Dinheiro"*"recebido."*) ok "recebeu parte em dinheiro";; *) falha "receber dinheiro ($msg)";; esac
contem "mostra o troco para devolver" "$tmp/aviso.html" 'Devolva de troco'
contem "troco certo" "$tmp/aviso.html" "$(reais $((5000 - parte)))"
falta=$((total - parte))
contem "falta pagar o resto" "$tmp/aviso.html" "data-restante=\"$falta\""

echo "Pix com QR Code (Asaas do restaurante)"
esperar "conectar Asaas (fake)" "$(enviar "$gj" "$g/integracoes/asaas" "$g/integracoes/asaas" ambiente=sandbox chave='$aact_ci_valida_1234567890abcdef' email_avisos=financeiro@example.test | cut -d' ' -f1)" "303"
pagina "$wj" "$w/comandas/$comanda/conta" >/dev/null
contem "com Asaas aparece o QR Code Pix" "$tmp/pagina.html" 'QR Code Pix'
r="$(enviar "$wj" "$w/comandas/$comanda/pix" "$w")"
pix="$(echo "$r" | grep -o 'pix/[0-9]*' | cut -d/ -f2 || true)"
[[ -n "$pix" ]] && ok "gerar Pix leva ao QR Code ($pix)" || falha "gerar Pix nao levou ao QR ($r $(aviso "$wj" "$w/comandas/$comanda/conta"))"
esperar "tela do QR Code" "$(pagina "$wj" "$w/pix/$pix")" "200"
contem "codigo do Pix para o QR" "$tmp/pagina.html" 'data-qr="000201'
contem "esperando o pagamento" "$tmp/pagina.html" 'Esperando o pagamento'
contem "valor do Pix = o que falta" "$tmp/pagina.html" "$(reais "$falta")"
contem "carrega o gerador de QR" "$tmp/pagina.html" '/assets/vendor/qrcode-generator/qrcode.js'
pix_json "$wj" "$w/pix/$pix"
esperar "situacao: esperando" "$(json "$tmp/pix.json" status)" "pendente"
r="$(enviar "$wj" "$w/comandas/$comanda/pix" "$w")"
esperar "gerar de novo mostra o mesmo QR" "$(echo "$r" | grep -o 'pix/[0-9]*' | cut -d/ -f2 || true)" "$pix"
cobranca="$(cobranca_de "$comanda")"
[[ -n "$cobranca" ]] && ok "cobranca no Asaas com a referencia da conta" || falha "cobranca da conta nao criada"
esperar "valor da cobranca" "$(curl -s "$fake/_teste/asaas-cobrancas" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo (int) round($d[$argv[1]]["value"] * 100);' "$cobranca")" "$falta"
curl -s "$fake/_teste/asaas-pagar/$cobranca" > "$tmp/pago.json"
valor="$(json "$tmp/pago.json" value)"
curl -s "$fake/_teste/asaas-webhook" > "$tmp/wh.json"
tk="$(json "$tmp/wh.json" authToken)"
caminho_wh="$(json "$tmp/wh.json" url | sed 's~^https\?://[^/]*~~')"
evento="{\"id\":\"evt_mesa_$comanda\",\"event\":\"PAYMENT_RECEIVED\",\"payment\":{\"id\":\"$cobranca\",\"value\":$valor,\"externalReference\":\"comanda:$comanda\",\"billingType\":\"PIX\"}}"
esperar "aviso do Asaas aceito" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$caminho_wh")" "200"
esperar "aviso repetido nao quebra" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$caminho_wh")" "200"
pix_json "$wj" "$w/pix/$pix"
esperar "Pix pago" "$(json "$tmp/pix.json" status)" "pago"
esperar "conta fechada sozinha" "$(json "$tmp/pix.json" conta_fechada)" "true"
pagina "$wj" "$w/comandas/$comanda/conta" >/dev/null
contem "tela de conta paga" "$tmp/pagina.html" 'Conta paga'
esperar "Pix entrou uma vez so" "$(grep -c 'Pix pelo app' "$tmp/pagina.html")" "1"
contem "dinheiro tambem no resumo" "$tmp/pagina.html" 'Dinheiro'
esperar "conta paga leva para a tela de pago" "$(curl -s -o /dev/null -b "$wj" -w '%{http_code} %{redirect_url}' "$w/comandas/$comanda")" "303 $w/comandas/$comanda/conta"
pagina "$wj" "$w" >/dev/null
contem "mesa livre de novo" "$tmp/pagina.html" "/garcom/mesas/$mesa/abrir"
pagina "$cx" "$c/historico" >/dev/null

echo "Pix confirmado pela consulta (sem aviso) e trocar a forma"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
comanda2="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
enviar "$wj" "$w/comandas/$comanda2/itens" "$w" "qtd[$cerveja]=1" >/dev/null
r="$(enviar "$wj" "$w/comandas/$comanda2/pix" "$w")"
pix2="$(echo "$r" | grep -o 'pix/[0-9]*' | cut -d/ -f2 || true)"
cobranca2="$(cobranca_de "$comanda2")"
enviar "$wj" "$w/comandas/$comanda2/itens" "$w" "qtd[$cerveja]=1" >/dev/null
r="$(enviar "$wj" "$w/comandas/$comanda2/pix" "$w")"
pix3="$(echo "$r" | grep -o 'pix/[0-9]*' | cut -d/ -f2 || true)"
[[ -n "$pix3" && "$pix3" != "$pix2" ]] && ok "conta mudou: QR novo com o valor novo" || falha "QR nao foi trocado ($pix2 -> $pix3)"
esperar "cobranca antiga removida do Asaas" "$(status_cobranca "$cobranca2")" "DELETED"
r="$(enviar "$wj" "$w/pix/$pix3/cancelar" "$w")"
esperar "trocar a forma de pagamento" "$(aviso "$wj" "$w/comandas/$comanda2/conta")" "Pix cancelado. Escolha outra forma de pagamento."
pix_json "$wj" "$w/pix/$pix3"
esperar "Pix trocado nao vale mais" "$(json "$tmp/pix.json" status)" "cancelado"
r="$(enviar "$wj" "$w/comandas/$comanda2/pix" "$w")"
pix4="$(echo "$r" | grep -o 'pix/[0-9]*' | cut -d/ -f2 || true)"
curl -s "$fake/_teste/asaas-pagar/$(cobranca_de "$comanda2")" >/dev/null
sleep 6
pix_json "$wj" "$w/pix/$pix4"
esperar "consulta ao Asaas confirma o Pix" "$(json "$tmp/pix.json" status)" "pago"
esperar "conta fechada pela consulta" "$(json "$tmp/pix.json" conta_fechada)" "true"

echo "Cartao de debito"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
comanda3="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
enviar "$wj" "$w/comandas/$comanda3/itens" "$w" "qtd[$cerveja]=2" >/dev/null
enviar "$wj" "$w/comandas/$comanda3/pagamentos" "$w" forma=debito valor= >/dev/null
msg="$(aviso "$wj" "$w/comandas/$comanda3/conta")"
case "$msg" in *"Conta fechada e mesa liberada."*) ok "debito fecha a conta";; *) falha "debito ($msg)";; esac

echo "Cancelar a comanda"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
vazia="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
pagina "$wj" "$w/comandas/$vazia" >/dev/null
contem "botao de cancelar a comanda" "$tmp/pagina.html" "comandas/$vazia/cancelar"
contem "cancelar pede confirmacao" "$tmp/pagina.html" 'data-confirm-acao="Cancelar comanda"'
esperar "cancelar sem CSRF volta" "$(curl -s -o /dev/null -b "$wj" -w '%{http_code}' -X POST "$w/comandas/$vazia/cancelar")" "303"
r="$(enviar "$wj" "$w/comandas/$vazia/cancelar" "$w" "motivo=Mesa aberta por engano")"
esperar "cancelar mesa vazia volta para as mesas" "$(echo "$r" | cut -d' ' -f2)" "$w"
msg="$(aviso "$wj" "$w")"
case "$msg" in *"cancelada. A mesa está livre."*) ok "mesa vazia cancelada";; *) falha "cancelar mesa vazia ($msg)";; esac
contem "mesa livre depois de cancelar" "$tmp/aviso.html" "/garcom/mesas/$mesa/abrir"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
com_itens="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
[[ "$com_itens" != "$vazia" ]] && ok "abrir de novo cria outra conta" || falha "reaproveitou a conta cancelada"
enviar "$wj" "$w/comandas/$com_itens/itens" "$w" "qtd[$cerveja]=2" >/dev/null
enviar "$wj" "$w/comandas/$com_itens/cancelar" "$w" "motivo=Cliente foi embora" >/dev/null
msg="$(aviso "$wj" "$w")"
case "$msg" in *"estoque foi devolvido"*) ok "cancelar com itens devolve o estoque";; *) falha "cancelar com itens ($msg)";; esac
esperar "conta cancelada volta para as mesas" "$(curl -s -o /dev/null -b "$wj" -w '%{http_code} %{redirect_url}' "$w/comandas/$com_itens")" "303 $w"
esperar "fechar conta de comanda cancelada tambem volta" "$(curl -s -o /dev/null -b "$wj" -w '%{http_code} %{redirect_url}' "$w/comandas/$com_itens/conta")" "303 $w"
msg="$(aviso "$wj" "$w")"
case "$msg" in *"esta conta foi cancelada."*) ok "aviso de conta cancelada";; *) falha "aviso de conta cancelada ($msg)";; esac
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
paga="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
enviar "$wj" "$w/comandas/$paga/itens" "$w" "qtd[$cerveja]=2" >/dev/null
enviar "$wj" "$w/comandas/$paga/pagamentos" "$w" forma=dinheiro "valor=$(php -r 'echo number_format($argv[1] / 100, 2, ",", "");' "$preco_cerveja")" >/dev/null
pagina "$wj" "$w/comandas/$paga" >/dev/null
nao_contem "conta com pagamento sem botao de cancelar" "$tmp/pagina.html" "comandas/$paga/cancelar"
enviar "$wj" "$w/comandas/$paga/cancelar" "$w" "motivo=Cliente desistiu" >/dev/null
esperar "conta com pagamento nao cancela" "$(aviso "$wj" "$w/comandas/$paga")" "Esta conta já tem pagamento. Peça ao caixa para cancelar."
enviar "$wj" "$w/comandas/$paga/pagamentos" "$w" forma=debito valor= >/dev/null
msg="$(aviso "$wj" "$w/comandas/$paga/conta")"
case "$msg" in *"Conta fechada e mesa liberada."*) ok "resto no debito fecha a conta";; *) falha "fechar a conta paga em parte ($msg)";; esac
pagina "$cx" "$c/mesas" >/dev/null
contem "caixa ve a mesa livre" "$tmp/pagina.html" "Abrir comanda da mesa"

echo "Varias comandas na mesma mesa (garcom)"
r="$(enviar "$wj" "$w/mesas/$mesa/abrir" "$w")"
g1="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
pagina "$wj" "$w/comandas/$g1" >/dev/null
contem "garcom ve o botao nova comanda" "$tmp/pagina.html" "/garcom/mesas/$mesa/comandas"
r="$(enviar "$wj" "$w/mesas/$mesa/comandas" "$w/comandas/$g1" nome="Ana" voltar_comanda="$g1")"
g2="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
[[ -n "$g2" && "$g2" != "$g1" ]] && ok "garcom abre comanda para quem chegou ($g2)" || falha "garcom nova comanda ($r)"
msg="$(aviso "$wj" "$w/comandas/$g2")"
case "$msg" in "Comanda de Ana aberta na Mesa "*) ok "mensagem da comanda da Ana";; *) falha "mensagem da comanda da Ana ($msg)";; esac
contem "abas no celular do garcom" "$tmp/aviso.html" 'class="comandas-mesa-lista"'
contem "aba da primeira comanda" "$tmp/aviso.html" "comandas/$g1"
pagina "$wj" "$w" >/dev/null
contem "mapa do garcom mostra 2 comandas" "$tmp/pagina.html" '2 comandas'
enviar "$wj" "$w/comandas/$g1/nome" "$w/comandas/$g1" nome="Bruno" >/dev/null
esperar "garcom da nome a comanda" "$(aviso "$wj" "$w/comandas/$g1")" "Comanda agora é de Bruno."
enviar "$wj" "$w/comandas/$g2/itens" "$w" "qtd[$cerveja]=1" >/dev/null
enviar "$wj" "$w/comandas/$g2/pagamentos" "$w" forma=debito valor= >/dev/null
msg="$(aviso "$wj" "$w/comandas/$g2/conta")"
case "$msg" in *"A mesa continua com 1 comanda aberta."*) ok "conta da Ana paga, a do Bruno continua";; *) falha "pagar uma das comandas ($msg)";; esac
contem "tela de conta paga avisa a comanda aberta" "$tmp/aviso.html" 'ainda tem 1 comanda aberta'
contem "tela de conta paga leva para a do Bruno" "$tmp/aviso.html" "comandas/$g1"
r="$(enviar "$wj" "$w/mesas/$mesa/comandas" "$w/comandas/$g1" nome="Carla" voltar_comanda="$g1")"
g3="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2 || true)"
r="$(enviar "$wj" "$w/comandas/$g1/cancelar" "$w/comandas/$g1" "motivo=Cliente foi embora")"
esperar "cancelar a do Bruno vai para a da Carla" "$(echo "$r" | cut -d' ' -f2)" "$w/comandas/$g3"
enviar "$wj" "$w/comandas/$g3/cancelar" "$w/comandas/$g3" "motivo=Mesa aberta por engano" >/dev/null
pagina "$wj" "$w" >/dev/null
contem "sem comandas a mesa volta a ficar livre" "$tmp/pagina.html" "/garcom/mesas/$mesa/abrir"

echo "Consulta do cardapio"
esperar "cardapio abre" "$(pagina "$wj" "$w/cardapio")" "200"
contem "titulo Cardapio" "$tmp/pagina.html" '<h1>Cardápio</h1>'
contem "produto com preco" "$tmp/pagina.html" 'Cerveja'
contem "selo de disponibilidade" "$tmp/pagina.html" 'produto-estado'
contem "filtro em falta" "$tmp/pagina.html" 'data-filtro-produto="falta"'
contem "aba ativa" "$tmp/pagina.html" 'aria-current="page"'

echo "Limpeza"
enviar "$gj" "$g/integracoes/asaas/desconectar" "$g/integracoes/asaas" >/dev/null
if [[ "$abriu_caixa" == "1" ]]; then
  pagina "$cx" "$c" >/dev/null
  esperado="$(php -r 'if (preg_match("~Dinheiro na gaveta</span>\s*<strong>R\\$ ([\d.,]+)</strong>~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
  enviar "$cx" "$c/fechar" "$c" "dinheiro_contado=$esperado" >/dev/null
  pagina "$cx" "$c" >/dev/null
  contem "caixa fechado de novo" "$tmp/pagina.html" 'name="valor_abertura"'
fi

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no painel do garcom."
  exit 1
fi
echo "OK: painel do garcom (mesas, lancar, 10%, dinheiro com troco, Pix com QR Code, debito e cardapio)."
