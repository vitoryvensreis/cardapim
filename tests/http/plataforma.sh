#!/usr/bin/env bash
# Teste HTTP do painel da PLATAFORMA (adm_master): menu, secoes em construcao e as integracoes da plataforma
# (Asaas das assinaturas e WhatsApp da plataforma) contra o servidor FALSO tests/fakes/integracoes-fake.php.
# Uso: bash tests/http/plataforma.sh <base> <slug-restaurante> <base-do-fake>
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
p="$base/painel"
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
enviar() { # jar url-completa campos... -> "codigo location"
  local jar="$1" url="$2" t args=(); shift 2
  t="$(token "$jar" "$p/integracoes")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -w '%{http_code}' "$2"; }
flash() { curl -s -b "$1" "$2" | grep -o 'painel-flash-[a-z]*' | head -1; }

aj="$tmp/a"; gj="$tmp/g"; cj="$tmp/c"
esperar "administrador entra e vai para /painel" "$(login "$aj" ci_master)" "303"
login "$gj" ci_gerente >/dev/null
login "$cj" ci_caixa >/dev/null

echo "Menu e secoes"
esperar "dashboard" "$(pagina "$aj" "$p")" "200"
for item in 'Dashboard' 'Restaurantes' 'Planos' 'Faturas' 'Usuários' 'Logs de auditoria' 'Integrações' 'Configurações' 'Suporte' 'Minha conta'; do
  contem "menu tem $item" "$tmp/pagina.html" ">$item</span>"
done
contem "menu marca a secao atual" "$tmp/pagina.html" 'href="/painel" aria-current="page"'
contem "atalho para as integracoes" "$tmp/pagina.html" 'href="/painel/integracoes"'
for secao in restaurantes planos faturas usuarios logs configuracoes suporte minha-conta; do
  esperar "/painel/$secao abre (em construcao)" "$(pagina "$aj" "$p/$secao")" "200"
done
contem "secao em construcao explica o que vem" "$tmp/pagina.html" 'Em construção'
esperar "secao inexistente: 404" "$(pagina "$aj" "$p/nao-existe")" "404"
esperar "sem login vai para o login" "$(curl -s -o /dev/null -w '%{http_code}' "$p/integracoes")" "302"
esperar "gerente nao entra no painel da plataforma" "$(pagina "$gj" "$p/integracoes")" "403"
esperar "caixa nao entra no painel da plataforma" "$(pagina "$cj" "$p/faturas")" "403"
esperar "gerente nao conecta o Asaas da plataforma" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -X POST "$p/integracoes/asaas/desconectar")" "403"
esperar "gerente nao consulta o WhatsApp da plataforma" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' "$p/integracoes/whatsapp/estado")" "401"

echo "Asaas da plataforma"
esperar "integracoes da plataforma" "$(pagina "$aj" "$p/integracoes")" "200"
contem "cartao do Asaas das assinaturas" "$tmp/pagina.html" 'assinaturas dos restaurantes'
contem "cartao do WhatsApp da plataforma" "$tmp/pagina.html" 'WhatsApp da plataforma'
esperar "chave errada (422)" "$(enviar "$aj" "$p/integracoes/asaas" ambiente=sandbox chave='$aact_ci_errada_000000000000000' | cut -d' ' -f1)" "422"
esperar "conectar com chave valida" "$(enviar "$aj" "$p/integracoes/asaas" ambiente=sandbox chave='$aact_ci_valida_1234567890abcdef')" "303 $p/integracoes/asaas"
pagina "$aj" "$p/integracoes/asaas" >/dev/null
contem "texto da plataforma" "$tmp/pagina.html" 'recebe o pagamento das assinaturas'
contem "chave mascarada" "$tmp/pagina.html" '...cdef'
nao_contem "chave inteira nunca aparece" "$tmp/pagina.html" 'aact_ci_valida_1234567890abcdef'
contem "avisos configurados" "$tmp/pagina.html" 'Configurados'
pagina "$gj" "$g/integracoes/asaas" >/dev/null
contem "Asaas do restaurante continua desconectado" "$tmp/pagina.html" 'Conectar conta Asaas'
curl -s "$fake/_teste/asaas-webhook" > "$tmp/wh.json"
url="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["url"] ?? "";' "$tmp/wh.json")"
tk="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["authToken"] ?? "";' "$tmp/wh.json")"
caminho_wh="${url#https://cardapim.test}"
[[ "$caminho_wh" == /webhooks/asaas/* ]] && ok "webhook da plataforma criado" || falha "webhook nao criado ($url)"
evento='{"id":"evt_plataforma_001","event":"PAYMENT_CONFIRMED","payment":{"id":"pay_assinatura_1","value":99.9}}'
esperar "aviso da plataforma com token errado" "$(curl -s -o /dev/null -w '%{http_code}' -H 'asaas-access-token: errado' --data "$evento" "$base$caminho_wh")" "401"
esperar "aviso da plataforma aceito" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$caminho_wh")" "200"
pagina "$aj" "$p/integracoes/asaas" >/dev/null
contem "aviso aparece no Asaas da plataforma" "$tmp/pagina.html" 'Pagamento confirmado'
pagina "$gj" "$g/integracoes/asaas" >/dev/null
nao_contem "aviso NAO aparece para o restaurante" "$tmp/pagina.html" 'Pagamento confirmado'
esperar "testar conexao" "$(enviar "$aj" "$p/integracoes/asaas/testar" | cut -d' ' -f1)" "303"
esperar "teste com sucesso" "$(flash "$aj" "$p/integracoes/asaas")" "painel-flash-sucesso"

echo "Asaas da plataforma: assinaturas"
php -r '$d=json_decode(file_get_contents($argv[1]),true); exit(in_array("SUBSCRIPTION_CREATED", $d["events"] ?? [], true) && in_array("SUBSCRIPTION_INACTIVATED", $d["events"] ?? [], true) && in_array("PAYMENT_OVERDUE", $d["events"] ?? [], true) ? 0 : 1);' "$tmp/wh.json" \
  && ok "avisos da plataforma incluem os eventos de assinatura" || falha "webhook sem eventos de assinatura"
pagina "$aj" "$p/integracoes/asaas" >/dev/null
contem "checklist da conta" "$tmp/pagina.html" 'Pronto para cobrar?'
contem "conta aprovada" "$tmp/pagina.html" 'Conta aprovada pelo Asaas'
contem "chave Pix encontrada" "$tmp/pagina.html" 'Chave Pix cadastrada'
contem "avisos ativos" "$tmp/pagina.html" 'Avisos ativos'
contem "regras de cobranca" "$tmp/pagina.html" 'Cobrança das assinaturas'
contem "padrao Pix e boleto" "$tmp/pagina.html" 'value="boleto" checked'
nao_contem "cartao desligado por padrao" "$tmp/pagina.html" 'value="cartao" checked'
curl -s "$fake/_teste/asaas-webhook-pausar" >/dev/null
enviar "$aj" "$p/integracoes/asaas/testar" >/dev/null
pagina "$aj" "$p/integracoes/asaas" >/dev/null
contem "fila pausada aparece" "$tmp/pagina.html" 'Avisos pausados pelo Asaas'
contem "botao para reativar" "$tmp/pagina.html" 'Reativar avisos'
esperar "reativar avisos" "$(enviar "$aj" "$p/integracoes/asaas/reativar")" "303 $p/integracoes/asaas"
pagina "$aj" "$p/integracoes/asaas" >/dev/null
contem "avisos ativos de novo" "$tmp/pagina.html" 'Avisos ativos'
esperar "regras invalidas (422)" "$(enviar "$aj" "$p/integracoes/asaas/cobranca" multa_percentual=5 juros_mensal=1 vencimento_dias=40 tolerancia_dias=7 | cut -d' ' -f1)" "422"
contem "sem forma de pagamento recusado" "$tmp/resp.html" 'Escolha pelo menos uma forma de pagamento.'
contem "multa acima de 2% recusada" "$tmp/resp.html" 'Use de 0 a 2%.'
contem "dias fora do limite" "$tmp/resp.html" 'Use um número de 0 a 30.'
esperar "desconto sem dias recusado" "$(enviar "$aj" "$p/integracoes/asaas/cobranca" 'formas[]=pix' desconto_percentual=5 desconto_dias=0 | cut -d' ' -f1)" "422"
esperar "salvar regras" "$(enviar "$aj" "$p/integracoes/asaas/cobranca" 'formas[]=pix' 'formas[]=boleto' 'formas[]=cartao' vencimento_dias=5 teste_dias=7 multa_percentual=2 juros_mensal=1,5 desconto_percentual=5 desconto_dias=3 tolerancia_dias=10 avisos_asaas=1)" "303 $p/integracoes/asaas#cobranca"
pagina "$aj" "$p/integracoes/asaas" >/dev/null
contem "regras salvas no resumo" "$tmp/pagina.html" 'Pix ou Boleto ou Cartão de crédito; 7 dias grátis; multa 2%; juros 1,5% ao mês; desconto 5% até 3 dias antes; suspende após 10 dias'
contem "cartao marcado" "$tmp/pagina.html" 'value="cartao" checked'
pagina "$gj" "$g/integracoes/asaas" >/dev/null
nao_contem "restaurante nao ve as regras da plataforma" "$tmp/pagina.html" 'Cobrança das assinaturas'
esperar "gerente nao salva regras da plataforma (403)" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -X POST "$p/integracoes/asaas/cobranca")" "403"
enviar "$aj" "$p/integracoes/asaas/cobranca" 'formas[]=pix' 'formas[]=boleto' vencimento_dias=3 teste_dias=0 multa_percentual=2 juros_mensal=1 desconto_percentual=0 desconto_dias=0 tolerancia_dias=7 avisos_asaas=1 >/dev/null
esperar "conta em analise (outra chave)" "$(enviar "$aj" "$p/integracoes/asaas" ambiente=sandbox chave='$aact_ci_valida_pendente_123456789' | cut -d' ' -f1)" "303"
pagina "$aj" "$p/integracoes/asaas" >/dev/null
contem "conta ainda nao aprovada" "$tmp/pagina.html" 'Conta ainda não aprovada'
contem "diz o que falta no Asaas" "$tmp/pagina.html" 'conta bancária (pendente)'
contem "sem chave Pix e um aviso" "$tmp/pagina.html" 'Sem chave Pix no Asaas'

echo "WhatsApp da plataforma"
esperar "conectar" "$(enviar "$aj" "$p/integracoes/whatsapp/conectar")" "303 $p/integracoes/whatsapp"
estado="$(curl -s -b "$aj" "$p/integracoes/whatsapp/estado")"
[[ "$estado" == *'"estado":"qrcode"'* ]] && ok "QR Code da plataforma" || falha "QR Code ausente: $estado"
sessao="$(ls "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-cardapim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
[[ -n "$sessao" ]] && ok "sessao propria da plataforma: $sessao" || falha "sessao da plataforma nao encontrada"
curl -s "$fake/_teste/wpp-conectar/$sessao" >/dev/null
estado="$(curl -s -b "$aj" "$p/integracoes/whatsapp/estado")"
[[ "$estado" == *'"estado":"conectado"'* ]] && ok "conectado" || falha "nao conectou: $estado"
pagina "$gj" "$g/integracoes/whatsapp" >/dev/null
contem "WhatsApp do restaurante continua separado" "$tmp/pagina.html" 'Conectar WhatsApp'
esperar "enviar mensagem de teste" "$(enviar "$aj" "$p/integracoes/whatsapp/teste" telefone='(85) 98888-2222' mensagem='Teste da plataforma' | cut -d' ' -f1)" "303"
esperar "teste enviado" "$(flash "$aj" "$p/integracoes/whatsapp")" "painel-flash-sucesso"
curl -s "$fake/_teste/wpp-mensagens" | grep -q "$sessao|5585988882222|Teste da plataforma" && ok "mensagem saiu pela sessao da plataforma" || falha "mensagem nao saiu pela sessao da plataforma"
pagina "$aj" "$p/integracoes" >/dev/null
contem "visao geral mostra o numero" "$tmp/pagina.html" 'Número: '
esperar "desconectar WhatsApp" "$(enviar "$aj" "$p/integracoes/whatsapp/desconectar")" "303 $p/integracoes/whatsapp"
esperar "desconectar Asaas" "$(enviar "$aj" "$p/integracoes/asaas/desconectar")" "303 $p/integracoes/asaas"
esperar "POST sem CSRF volta com erro" "$(curl -s -o /dev/null -b "$aj" -w '%{http_code}' -X POST "$p/integracoes/whatsapp/conectar")" "303"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no painel da plataforma."
  exit 1
fi
echo "OK: painel da plataforma (menu, secoes e integracoes Asaas/WhatsApp da plataforma)."
