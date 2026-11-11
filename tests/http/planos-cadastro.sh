#!/usr/bin/env bash
# Teste HTTP de PLANOS (painel da plataforma), CONFIGURACOES da marca e CADASTRO + ASSINATURA de ponta a ponta:
# plano publico e privado (link de convite, vitalicio gratis), nome e logos da plataforma, cadastro gratis pelo convite,
# cadastro pago (cliente + assinatura no Asaas falso, fatura com link para pagar), aviso de pagamento liberando o
# restaurante, fatura atrasada bloqueando (so o gerente entra, so na Assinatura), cancelar e desfazer.
# Uso: bash tests/http/planos-cadastro.sh <base> <slug-do-restaurante-modelo> <base-do-fake>
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
senha='Senha#CI2026'
p="$base/painel"
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
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$3" "$base/login"
}
enviar() { # jar url-completa pagina-do-token campos... -> "codigo location"
  local jar="$1" url="$2" origem="$3" t args=(); shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -c "$1" -w '%{http_code}' "$2"; }
flash() { curl -s -b "$1" "$2" | grep -o 'painel-flash-[a-z]*' | head -1; }
publico() { curl -s -o /dev/null -w '%{http_code}' "$base/$1"; }
# Documentos validos e unicos para cada execucao.
cnpj() { php -r '$b = str_pad((string) random_int(10000000, 99999999), 8, "0") . "0001"; $c = static function (string $s, array $p): int { $t = 0; foreach ($p as $i => $w) { $t += (int) $s[$i] * $w; } $r = $t % 11; return $r < 2 ? 0 : 11 - $r; }; $b .= $c($b, [5,4,3,2,9,8,7,6,5,4,3,2]); $b .= $c($b, [6,5,4,3,2,9,8,7,6,5,4,3,2]); echo $b;'; }
cpf() { php -r 'do { $b = (string) random_int(100000000, 999999999); } while (count(array_unique(str_split($b))) === 1); foreach ([10, 11] as $n) { $t = 0; for ($i = 0; $i < $n - 1; $i++) { $t += (int) $b[$i] * ($n - $i); } $r = ($t * 10) % 11; $b .= $r === 10 ? 0 : $r; } echo $b;'; }
id=$(date +%s)$RANDOM
json() { php -r '$d = json_decode(file_get_contents($argv[1]), true); $v = $d; foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; } echo is_scalar($v) ? $v : ""; ' "$1" "$2"; }
cadastrar() { # jar nome-restaurante usuario plano_id|convite=codigo -> "codigo location"
  local jar="$1" rest="$2" usu="$3" plano="$4" t; rm -f "$jar"
  t="$(token "$jar" "$base/cadastro")"
  local campo_plano=(--data-urlencode "plano_id=$plano")
  [[ "$plano" == convite=* ]] && campo_plano=(--data-urlencode "$plano")
  curl -s -o "$tmp/cad.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${campo_plano[@]}" \
    --data-urlencode "nome=Teste" --data-urlencode "sobrenome=Cadastro" --data-urlencode "cpf=$(cpf)" --data-urlencode "whatsapp=(85) 99876-5432" \
    --data-urlencode "nome_restaurante=$rest" --data-urlencode "cnpj=$(cnpj)" --data-urlencode "usuario=$usu" --data-urlencode "email=$usu@example.test" \
    --data-urlencode "senha=Senha-1234-ok" --data-urlencode "aceite_termos=1" "$base/cadastro"
}

aj="$tmp/adm"; gj="$tmp/ger"
esperar "administrador entra" "$(login "$aj" ci_master "$senha")" "303"
login "$gj" ci_gerente "$senha" >/dev/null

echo "Planos (painel da plataforma)"
esperar "lista de planos abre" "$(pagina "$aj" "$p/planos")" "200"
contem "menu com Planos" "$tmp/pagina.html" 'href="/painel/planos"'
nao_contem "planos nao estao mais em construcao" "$tmp/pagina.html" 'Em construção'
esperar "gerente nao abre planos (403)" "$(pagina "$gj" "$p/planos")" "403"
esperar "plano sem nome recusado (422)" "$(enviar "$aj" "$p/planos" "$p/planos/novo" nome= periodo=mensal valor=10 visibilidade=publico ativo=1 | cut -d' ' -f1)" "422"
contem "erro no campo nome" "$tmp/resp.html" 'Dê um nome'
r="$(enviar "$aj" "$p/planos" "$p/planos/novo" "nome=Parceiro CI $id" periodo=vitalicio valor=99 visibilidade=privado ativo=1)"
esperar "criar plano privado vitalicio" "${r%%#*}" "303 $p/planos"
pid="${r##*#plano-}"
pagina "$aj" "$p/planos" >/dev/null
contem "plano mostra so com convite" "$tmp/pagina.html" 'Só com convite'
convite="$(grep -o "/cadastro?convite=[a-f0-9]\{24\}" "$tmp/pagina.html" | head -1 | sed 's/.*convite=//')"
[[ ${#convite} -eq 24 ]] && ok "link de convite gerado" || falha "link de convite nao gerado"
pagina "$aj" "$p/planos/$pid" >/dev/null
contem "vitalicio salvo como gratis" "$tmp/pagina.html" 'value="vitalicio" checked'
curl -s "$base/" > "$tmp/home.html"
nao_contem "plano privado nao aparece no site" "$tmp/home.html" "Parceiro CI $id"
r="$(enviar "$aj" "$p/planos" "$p/planos/novo" "nome=Publico CI $id" periodo=mensal valor=59,90 visibilidade=publico ativo=1)"
pub="${r##*#plano-}"
curl -s "$base/" > "$tmp/home.html"
contem "plano publico aparece no site" "$tmp/home.html" "Publico CI $id"
esperar "pausar plano" "$(enviar "$aj" "$p/planos/$pub/ativo" "$p/planos" | cut -d'#' -f1)" "303 $p/planos"
curl -s "$base/" > "$tmp/home.html"
nao_contem "plano pausado sai do site" "$tmp/home.html" "Publico CI $id"
enviar "$aj" "$p/planos/$pub/ativo" "$p/planos" >/dev/null
esperar "trocar link de convite" "$(enviar "$aj" "$p/planos/$pid/convite" "$p/planos" | cut -d'#' -f1)" "303 $p/planos"
pagina "$aj" "$p/planos" >/dev/null
novo="$(grep -o "/cadastro?convite=[a-f0-9]\{24\}" "$tmp/pagina.html" | head -1 | sed 's/.*convite=//')"
[[ -n "$novo" && "$novo" != "$convite" ]] && ok "link novo diferente do antigo" || falha "link nao trocou"
curl -s "$base/cadastro?convite=$convite" > "$tmp/c.html"
contem "link antigo deixa de valer" "$tmp/c.html" 'não vale mais'
convite="$novo"
r="$(enviar "$aj" "$p/planos" "$p/planos/novo" "nome=Apagar CI $id" periodo=anual valor=100 visibilidade=publico ativo=1)"
apagar="${r##*#plano-}"
esperar "excluir plano sem uso" "$(enviar "$aj" "$p/planos/$apagar/excluir" "$p/planos")" "303 $p/planos"
esperar "plano excluido some" "$(pagina "$aj" "$p/planos/$apagar" ; echo)" "303"

echo "Configuracoes da marca"
esperar "configuracoes abrem" "$(pagina "$aj" "$p/configuracoes")" "200"
contem "campo do nome da plataforma" "$tmp/pagina.html" 'name="nome_sistema"'
esperar "nome vazio recusado (422)" "$(enviar "$aj" "$p/configuracoes/textos" "$p/configuracoes" nome_sistema= descricao_padrao=x texto_rodape=y | cut -d' ' -f1)" "422"
esperar "salvar nome e textos" "$(enviar "$aj" "$p/configuracoes/textos" "$p/configuracoes" "nome_sistema=Marca CI" "descricao_padrao=Gestao CI" "texto_rodape=Rodape CI")" "303 $p/configuracoes"
pagina "$aj" "$p/configuracoes" >/dev/null
contem "nome salvo no banco" "$tmp/pagina.html" 'value="Marca CI"'
printf '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#651424"/></svg>' > "$tmp/logo.svg"
printf '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' > "$tmp/ruim.svg"
php -r '$i = imagecreatetruecolor(600, 600); imagefill($i, 0, 0, imagecolorallocate($i, 101, 20, 36)); imagepng($i, $argv[1]);' "$tmp/icone.png"
envio() { local t; t="$(token "$aj" "$p/configuracoes")"; curl -s -o /dev/null -b "$aj" -c "$aj" -w '%{http_code}' -F "_csrf=$t" -F "chave=$1" -F "arquivo=@$2;type=$3" "$p/configuracoes/logo"; }
esperar "enviar logo SVG" "$(envio logo_preta "$tmp/logo.svg" image/svg+xml)" "303"
esperar "logo enviada com sucesso" "$(flash "$aj" "$p/configuracoes")" "painel-flash-sucesso"
pagina "$aj" "$p/configuracoes" >/dev/null
logo="$(grep -o '/midia/marca/[a-f0-9]\{32\}\.svg' "$tmp/pagina.html" | head -1)"
[[ -n "$logo" ]] && ok "logo nova aparece na tela" || falha "logo nova nao aparece"
curl -s -D "$tmp/h.txt" -o /dev/null "$base$logo"
contem "logo servida como SVG" "$tmp/h.txt" 'image/svg+xml'
contem "SVG servido com sandbox (sem script)" "$tmp/h.txt" 'sandbox'
esperar "SVG com script recusado" "$(envio logo_branca "$tmp/ruim.svg" image/svg+xml)" "303"
esperar "aviso de SVG recusado" "$(flash "$aj" "$p/configuracoes")" "painel-flash-erro"
esperar "icone do app PNG 600x600" "$(envio icone_app "$tmp/icone.png" image/png)" "303"
pagina "$aj" "$p/configuracoes" >/dev/null
icone="$(grep -o '/midia/marca/[a-f0-9]\{32\}-512\.png' "$tmp/pagina.html" | head -1)"
esperar "icone 512 gerado" "$(curl -s -o /dev/null -w '%{http_code}' "$base$icone")" "200"
esperar "icone 192 gerado" "$(curl -s -o /dev/null -w '%{http_code}' "$base${icone%-512.png}-192.png")" "200"
esperar "icone maskable gerado" "$(curl -s -o /dev/null -w '%{http_code}' "$base${icone%-512.png}-maskable.png")" "200"
esperar "voltar logo ao padrao" "$(enviar "$aj" "$p/configuracoes/logo/padrao" "$p/configuracoes" chave=logo_preta | cut -d'#' -f1)" "303 $p/configuracoes"
pagina "$aj" "$p/configuracoes" >/dev/null
contem "logo original de volta" "$tmp/pagina.html" '/assets/img/logo-preta.svg'
esperar "gerente nao abre configuracoes (403)" "$(pagina "$gj" "$p/configuracoes")" "403"
enviar "$aj" "$p/configuracoes/textos" "$p/configuracoes" "nome_sistema=Deliveryzim" "descricao_padrao=Gestão de restaurantes do jeito simples." "texto_rodape=Menos complicação. Mais restaurante." >/dev/null

echo "Cadastro gratis pelo convite"
curl -s -c "$tmp/v.jar" -b "$tmp/v.jar" "$base/cadastro?convite=$convite" > "$tmp/c.html"
contem "convite mostra o plano exclusivo" "$tmp/c.html" "Parceiro CI $id"
contem "cadastro liberado (sem aviso de previa)" "$tmp/c.html" 'Criar conta e continuar'
t="$(token "$tmp/v.jar" "$base/cadastro")"
esperar "cadastro incompleto recusado (422)" "$(curl -s -o "$tmp/cad.html" -b "$tmp/v.jar" -c "$tmp/v.jar" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "convite=$convite" --data-urlencode "nome=A" "$base/cadastro")" "422"
contem "erro de CNPJ aparece" "$tmp/cad.html" 'CNPJ inválido'
contem "convite mantido no formulario" "$tmp/cad.html" "value=\"$convite\""
pj="$tmp/parceiro"
r="$(cadastrar "$pj" "Parceiro CI $id" "parceiro$id" "convite=$convite")"
slugp="$(echo "${r#* }" | sed 's~.*/\([a-z0-9-]*\)/gerencia.*~\1~')"
esperar "cadastro gratis vai direto para o painel" "${r%% *} ${r#* }" "303 $base/$slugp/gerencia"
esperar "gerente novo ja esta logado" "$(pagina "$pj" "$base/$slugp/gerencia")" "200"
esperar "cardapio do parceiro ja esta no ar" "$(publico "$slugp")" "200"
pagina "$pj" "$base/$slugp/gerencia/assinatura" >/dev/null
contem "assinatura sem cobranca" "$tmp/pagina.html" 'Sem cobrança'
nao_contem "nenhuma fatura para pagar" "$tmp/pagina.html" 'Pagar agora'
r="$(cadastrar "$tmp/dup" "Outro CI $id" "parceiro$id" "convite=$convite")"
esperar "usuario repetido recusado (422)" "${r%% *}" "422"

echo "Cadastro pago com Asaas da plataforma"
esperar "conectar Asaas da plataforma" "$(enviar "$aj" "$p/integracoes/asaas" "$p/integracoes" ambiente=sandbox chave='$aact_ci_valida_1234567890abcdef')" "303 $p/integracoes/asaas"
curl -s "$fake/_teste/asaas-webhook" > "$tmp/wh.json"
url="$(json "$tmp/wh.json" url)"; tk="$(json "$tmp/wh.json" authToken)"
wh="/webhooks/asaas/${url##*/webhooks/asaas/}"
rj="$tmp/pago"
r="$(cadastrar "$rj" "Pago CI $id" "pago$id" "$pub")"
slugr="$(echo "${r#* }" | sed 's~.*/\([a-z0-9-]*\)/gerencia.*~\1~')"
esperar "cadastro pago vai para a Assinatura" "${r%% *} ${r#* }" "303 $base/$slugr/gerencia/assinatura"
pagina "$rj" "$base/$slugr/gerencia/assinatura" >/dev/null
contem "fatura com botao Pagar agora" "$tmp/pagina.html" 'Pagar agora'
contem "link da fatura do Asaas" "$tmp/pagina.html" 'https://sandbox.asaas.com/i/'
contem "aguardando o primeiro pagamento" "$tmp/pagina.html" 'Aguardando pagamento'
esperar "cardapio fora do ar antes de pagar" "$(publico "$slugr")" "404"
esperar "mas o gerente ja monta o restaurante" "$(pagina "$rj" "$base/$slugr/gerencia/estoque")" "200"
curl -s "$fake/_teste/asaas-assinaturas" > "$tmp/subs.json"
sub="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); foreach ($d as $s) { if (($s["externalReference"] ?? "") !== "" && str_contains((string) ($s["description"] ?? ""), $argv[2])) { echo $s["id"]; } }' "$tmp/subs.json" "Pago CI $id")"
[[ -n "$sub" ]] && ok "assinatura criada no Asaas" || falha "assinatura nao criada no Asaas"
contem "assinatura mensal no Asaas" "$tmp/subs.json" '"cycle":"MONTHLY"'
curl -s "$fake/_teste/asaas-assinatura-pagar/$sub" > "$tmp/pay.json"
evento="$(php -r '$p = json_decode(file_get_contents($argv[1]), true); echo json_encode(["id" => "evt_ci_" . $p["id"], "event" => "PAYMENT_RECEIVED", "payment" => $p]);' "$tmp/pay.json")"
esperar "aviso de pagamento aceito" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$wh")" "200"
esperar "aviso repetido nao quebra" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$evento" "$base$wh")" "200"
esperar "pagou: cardapio no ar" "$(publico "$slugr")" "200"
pagina "$rj" "$base/$slugr/gerencia/assinatura" >/dev/null
contem "fatura paga na lista" "$tmp/pagina.html" '>Paga<'
contem "assinatura ativa" "$tmp/pagina.html" '>Ativa<'

echo "Fatura atrasada bloqueia e pagamento libera"
atrasada="$(php -r '$p = json_decode(file_get_contents($argv[1]), true); $p["id"] = "pay_ci_atraso_" . $argv[2]; $p["status"] = "OVERDUE"; $p["dueDate"] = "2020-01-10"; unset($p["paymentDate"]); echo json_encode(["id" => "evt_ci_atraso_" . $argv[2], "event" => "PAYMENT_OVERDUE", "payment" => $p]);' "$tmp/pay.json" "$id")"
esperar "aviso de fatura vencida aceito" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$atrasada" "$base$wh")" "200"
esperar "gerente vai direto para a Assinatura" "$(curl -s -o /dev/null -b "$rj" -w '%{http_code} %{redirect_url}' "$base/$slugr/gerencia/estoque")" "303 $base/$slugr/gerencia/assinatura"
pagina "$rj" "$base/$slugr/gerencia/assinatura" >/dev/null
contem "aviso de acesso pausado" "$tmp/pagina.html" 'Acesso pausado'
contem "fatura vencida para pagar" "$tmp/pagina.html" 'Fatura vencida em'
esperar "gerente nao entra no caixa bloqueado" "$(pagina "$rj" "$base/$slugr/caixa")" "403"
esperar "cardapio sai do ar com atraso" "$(publico "$slugr")" "404"
paga="$(php -r '$e = json_decode($argv[1], true); $e["id"] .= "_pago"; $e["event"] = "PAYMENT_RECEIVED"; $e["payment"]["status"] = "RECEIVED"; $e["payment"]["paymentDate"] = date("Y-m-d"); echo json_encode($e);' "$atrasada")"
esperar "aviso de pagamento da atrasada" "$(curl -s -o /dev/null -w '%{http_code}' -H "asaas-access-token: $tk" -H 'Content-Type: application/json' --data "$paga" "$base$wh")" "200"
esperar "pagou: painel liberado" "$(pagina "$rj" "$base/$slugr/gerencia/estoque")" "200"
esperar "pagou: cardapio volta" "$(publico "$slugr")" "200"

echo "Cancelar e desfazer"
esperar "cancelar assinatura" "$(enviar "$rj" "$base/$slugr/gerencia/assinatura/cancelar" "$base/$slugr/gerencia/assinatura")" "303 $base/$slugr/gerencia/assinatura"
curl -s "$fake/_teste/asaas-assinaturas" > "$tmp/subs.json"
esperar "assinatura removida no Asaas" "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d[$argv[2]]["status"] ?? "";' "$tmp/subs.json" "$sub")" "DELETED"
pagina "$rj" "$base/$slugr/gerencia/assinatura" >/dev/null
contem "mostra cancelada" "$tmp/pagina.html" 'Desfazer cancelamento'
esperar "desfazer cancelamento" "$(enviar "$rj" "$base/$slugr/gerencia/assinatura/reativar" "$base/$slugr/gerencia/assinatura")" "303 $base/$slugr/gerencia/assinatura"
curl -s "$fake/_teste/asaas-assinaturas" > "$tmp/subs.json"
esperar "nova assinatura no Asaas ao desfazer" "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); $n = 0; foreach ($d as $s) { if (str_contains((string) ($s["description"] ?? ""), $argv[2]) && $s["status"] === "ACTIVE") { $n++; } } echo $n;' "$tmp/subs.json" "Pago CI $id")" "1"

if (( falhas > 0 )); then
  echo "FALHOU: $falhas verificacao(oes) de planos, marca e cadastro."
  exit 1
fi
echo "OK: planos (publico/convite/vitalicio), marca da plataforma, cadastro e assinatura no Asaas."
