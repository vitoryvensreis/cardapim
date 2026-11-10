#!/usr/bin/env bash
# Teste HTTP da aba Integracoes (Asaas, WhatsApp/WPPConnect e iFood) contra o servidor FALSO
# tests/fakes/integracoes-fake.php. Uso: bash tests/http/integracoes.sh <base> <slug> <base-do-fake>
# O servidor do Cardapim precisa estar com as variaveis apontando para o fake (ver CI).
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
g="$base/$slug/gerencia"
senha='Senha#CI2026'
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$senha" "$base/login"
}
enviar() { # jar caminho campos... -> "codigo location"
  local jar="$1" caminho="$2" t args=(); shift 2
  t="$(token "$jar" "$g/integracoes")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$g$caminho"
}
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -w '%{http_code}' "$2"; }
flash() { curl -s -b "$1" "$2" | grep -o 'painel-flash-[a-z]*' | head -1; }

gj="$tmp/g"
esperar "gerente entra" "$(login "$gj" ci_gerente)" "303"
for caminho in /integracoes /integracoes/asaas /integracoes/whatsapp /integracoes/ifood; do
  esperar "GET $caminho" "$(pagina "$gj" "$g$caminho")" "200"
done
cj="$tmp/c"; login "$cj" ci_caixa >/dev/null
esperar "caixa nao abre integracoes" "$(pagina "$cj" "$g/integracoes")" "403"
esperar "caixa nao consulta o WhatsApp" "$(curl -s -o /dev/null -b "$cj" -w '%{http_code}' "$g/integracoes/whatsapp/estado")" "401"

echo "Asaas"
esperar "chave recusada pelo Asaas (422)" "$(enviar "$gj" /integracoes/asaas ambiente=sandbox chave='$aact_ci_errada_000000000000000' | cut -d' ' -f1)" "422"
contem "mensagem clara da chave errada" "$tmp/resp.html" 'O Asaas recusou a chave'
esperar "conectar com chave valida" "$(enviar "$gj" /integracoes/asaas ambiente=sandbox chave='$aact_ci_valida_1234567890abcdef' email_avisos=financeiro@example.test)" "303 $g/integracoes/asaas"
pagina "$gj" "$g/integracoes/asaas" >/dev/null
contem "conta e saldo" "$tmp/pagina.html" 'Restaurante CI Ltda'
contem "chave mascarada" "$tmp/pagina.html" '...cdef'
nao_contem "chave inteira nunca aparece" "$tmp/pagina.html" 'aact_ci_valida_1234567890abcdef'
contem "avisos configurados automaticamente" "$tmp/pagina.html" 'Configurados'
curl -s "$fake/_teste/asaas-webhook" > "$tmp/wh.json"
url="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["url"] ?? "";' "$tmp/wh.json")"
tk="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["authToken"] ?? "";' "$tmp/wh.json")"
caminho_wh="${url#https://cardapim.test}"
[[ "$caminho_wh" == /webhooks/asaas/* && ${#tk} -ge 32 ]] && ok "webhook criado no Asaas com URL e token" || falha "webhook nao criado ($url)"
evento='{"id":"evt_ci_001","event":"PAYMENT_RECEIVED","payment":{"id":"pay_1","value":10}}'
esperar "webhook sem token recusado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data "$evento" "$base$caminho_wh")" "401"
esperar "webhook com token errado recusado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'asaas-access-token: errado' --data "$evento" "$base$caminho_wh")" "401"
esperar "webhook valido aceito" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$caminho_wh")" "200"
esperar "aviso repetido tambem responde 200" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" --data "$evento" "$base$caminho_wh")" "200"
esperar "webhook de id inexistente" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" --data "$evento" "$base/webhooks/asaas/00000000000000000000000000000000")" "401"
pagina "$gj" "$g/integracoes/asaas" >/dev/null
contem "aviso aparece em ultimos avisos" "$tmp/pagina.html" 'PAYMENT_RECEIVED'
esperar "testar conexao" "$(enviar "$gj" /integracoes/asaas/testar | cut -d' ' -f1)" "303"
esperar "teste com sucesso" "$(flash "$gj" "$g/integracoes/asaas")" "painel-flash-sucesso"
esperar "desconectar Asaas" "$(enviar "$gj" /integracoes/asaas/desconectar)" "303 $g/integracoes/asaas"
esperar "webhook antigo deixa de valer" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" --data "$evento" "$base$caminho_wh")" "401"

echo "WhatsApp"
esperar "conectar WhatsApp" "$(enviar "$gj" /integracoes/whatsapp/conectar)" "303 $g/integracoes/whatsapp"
estado="$(curl -s -b "$gj" "$g/integracoes/whatsapp/estado")"
[[ "$estado" == *'"estado":"qrcode"'* && "$estado" == *'data:image/png;base64'* ]] && ok "QR Code disponivel" || falha "QR Code ausente: $estado"
esperar "teste antes de conectar e recusado" "$(enviar "$gj" /integracoes/whatsapp/teste telefone='(85) 99999-1111' mensagem='Oi' | cut -d' ' -f1)" "303"
esperar "aviso de que precisa conectar" "$(flash "$gj" "$g/integracoes/whatsapp")" "painel-flash-erro"
sessao="$(ls "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-cardapim_r[0-9]*_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
[[ -n "$sessao" ]] && ok "sessao criada no WPPConnect: $sessao" || falha "sessao nao encontrada"
curl -s "$fake/_teste/wpp-conectar/$sessao" >/dev/null
estado="$(curl -s -b "$gj" "$g/integracoes/whatsapp/estado")"
[[ "$estado" == *'"estado":"conectado"'* && "$estado" == *'5585999990000'* ]] && ok "conectado apos ler o QR Code" || falha "nao conectou: $estado"
esperar "numero invalido (422)" "$(enviar "$gj" /integracoes/whatsapp/teste telefone='123' mensagem='Oi' | cut -d' ' -f1)" "422"
esperar "enviar mensagem de teste" "$(enviar "$gj" /integracoes/whatsapp/teste telefone='(85) 99999-1111' mensagem='Teste CI do Cardapim' | cut -d' ' -f1)" "303"
esperar "teste enviado com sucesso" "$(flash "$gj" "$g/integracoes/whatsapp")" "painel-flash-sucesso"
curl -s "$fake/_teste/wpp-mensagens" | grep -q '5585999991111|Teste CI do Cardapim' && ok "mensagem chegou ao WPPConnect com 55 + DDD" || falha "mensagem nao chegou"
esperar "desconectar WhatsApp" "$(enviar "$gj" /integracoes/whatsapp/desconectar)" "303 $g/integracoes/whatsapp"
estado="$(curl -s -b "$gj" "$g/integracoes/whatsapp/estado")"
[[ "$estado" == *'"estado":"desconectado"'* ]] && ok "desconectado" || falha "continua conectado: $estado"

echo "iFood"
esperar "gerar codigo de vinculo" "$(enviar "$gj" /integracoes/ifood/iniciar)" "303 $g/integracoes/ifood"
pagina "$gj" "$g/integracoes/ifood" >/dev/null
contem "codigo de vinculo na tela" "$tmp/pagina.html" 'ABCD-EFGH'
contem "link do Portal do Parceiro" "$tmp/pagina.html" 'portal.ifood.com.br/apps/code'
esperar "codigo de autorizacao errado" "$(enviar "$gj" /integracoes/ifood/concluir codigo='ERRADO-1' | cut -d' ' -f1)" "303"
esperar "aviso do codigo errado" "$(flash "$gj" "$g/integracoes/ifood")" "painel-flash-erro"
esperar "codigo de autorizacao certo" "$(enviar "$gj" /integracoes/ifood/concluir codigo='auth-ci-ok')" "303 $g/integracoes/ifood"
pagina "$gj" "$g/integracoes/ifood" >/dev/null
contem "escolher entre as lojas" "$tmp/pagina.html" 'Loja CI Praia'
esperar "loja de fora da lista recusada" "$(enviar "$gj" /integracoes/ifood/loja loja='99999999-0000-0000-0000-000000000000' | cut -d' ' -f1)" "303"
esperar "aviso da loja invalida" "$(flash "$gj" "$g/integracoes/ifood")" "painel-flash-erro"
esperar "escolher loja" "$(enviar "$gj" /integracoes/ifood/loja loja='66666666-7777-8888-9999-000000000000')" "303 $g/integracoes/ifood"
pagina "$gj" "$g/integracoes/ifood" >/dev/null
contem "loja conectada" "$tmp/pagina.html" 'Loja CI Praia'
esperar "testar iFood" "$(enviar "$gj" /integracoes/ifood/testar | cut -d' ' -f1)" "303"
esperar "teste do iFood ok" "$(flash "$gj" "$g/integracoes/ifood")" "painel-flash-sucesso"
pagina "$gj" "$g/integracoes" >/dev/null
contem "visao geral mostra a loja" "$tmp/pagina.html" 'Loja: Loja CI Praia'
esperar "desconectar iFood" "$(enviar "$gj" /integracoes/ifood/desconectar)" "303 $g/integracoes/ifood"

echo "Isolamento"
oj="$tmp/o"; login "$oj" ci_outro_gerente >/dev/null
esperar "gerente de outro restaurante nao conecta aqui" "$(curl -s -o /dev/null -b "$oj" -w '%{http_code}' -X POST "$g/integracoes/asaas/desconectar")" "403"
esperar "POST sem CSRF volta com erro" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -X POST "$g/integracoes/whatsapp/conectar")" "303"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no teste das integracoes."
  exit 1
fi
echo "OK: integracoes (Asaas com webhook, WhatsApp com QR Code e teste, iFood com vinculo e loja)."
