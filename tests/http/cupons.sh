#!/usr/bin/env bash
# Teste HTTP: CUPONS (gerente cria/edita/pausa/exclui; cliente usa no pedido online, limite e "1 por cliente")
# e FIDELIDADE (gerente configura; desconto automatico depois de N pedidos concluidos; cancelar devolve o premio).
# Uso: bash tests/http/cupons.sh <base> <slug> <base-do-fake>
set -Eeuo pipefail
base="$1"
slug="$2"
fake="$3"
senha='Senha#CI2026'
g="$base/$slug/gerencia"
c="$base/$slug/caixa"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0
r4="$(printf '%04d' $((RANDOM % 10000)))"

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
  t="$(token "$jar" "$g/cupons")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
sessao() { curl -s -c "$1" -b "$1" -o "$tmp/sessao.json" "$base/api/v1/cliente"; json "$tmp/sessao.json" csrf; }
post() { curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" --data "$4" "$base$3"; }
get() { curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' "$base$2"; }
ultimo_codigo() { curl -s "$fake/_teste/wpp-mensagens" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $l = $d["linhas"] ?? []; for ($i = count($l) - 1; $i >= 0; $i--) { if (strpos($l[$i], $argv[1]) !== false && preg_match("/\*(\d{6})\*/", $l[$i], $m)) { echo $m[1]; exit; } }' "$1"; }
chave() { php -r 'echo bin2hex(random_bytes(16));'; }

echo "Gerente: cupons"
gj="$tmp/gerente"
esperar "gerente entra" "$(login_equipe "$gj" ci_gerente)" "303"
esperar "aba Cupons abre" "$(curl -s -o "$tmp/l.html" -b "$gj" -w '%{http_code}' "$g/cupons")" "200"
contem "menu mostra Cupons e Fidelidade" "$tmp/l.html" '/gerencia/fidelidade'
oj="$tmp/outro"
login_equipe "$oj" ci_outro_gerente >/dev/null
esperar "gerente de outro restaurante nao abre (403)" "$(curl -s -o /dev/null -b "$oj" -w '%{http_code}' "$g/cupons")" "403"
esperar "POST sem CSRF volta" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -X POST "$g/cupons")" "303"
esperar "cupom sem codigo recusado (422)" "$(enviar "$gj" "$g/cupons" codigo= tipo=percentual valor=10 uso=varios | cut -d' ' -f1)" "422"
esperar "porcentagem acima de 100 recusada (422)" "$(enviar "$gj" "$g/cupons" codigo=XX$r4 tipo=percentual valor=150 uso=varios | cut -d' ' -f1)" "422"
dez="CIDEZ$r4"; unico="CIUM$r4"
esperar "cria cupom de 10% (1 por cliente)" "$(enviar "$gj" "$g/cupons" codigo="cidez$r4" tipo=percentual valor=10 uso=varios um_por_cliente=1)" "303 $g/cupons"
esperar "mensagem do cupom criado" "$(aviso "$gj" "$g/cupons")" "Cupom $dez criado. Já pode ser usado no cardápio online."
esperar "cria cupom de R\$ 5,00 que vale 1 vez" "$(enviar "$gj" "$g/cupons" codigo="$unico" tipo=fixo valor=5,00 uso=unico | cut -d' ' -f1)" "303"
esperar "codigo repetido recusado (422)" "$(enviar "$gj" "$g/cupons" codigo="$dez" tipo=fixo valor=1 uso=varios | cut -d' ' -f1)" "422"
contem "aviso de codigo repetido" "$tmp/resp.html" 'Já existe um cupom com este código.'
esperar "limite sem numero recusado (422)" "$(enviar "$gj" "$g/cupons" codigo=CILIM$r4 tipo=fixo valor=1 uso=limite limite_usos= | cut -d' ' -f1)" "422"
curl -s -b "$gj" "$g/cupons" -o "$tmp/l.html"
contem "lista mostra o cupom de 10%" "$tmp/l.html" "$dez"
contem "lista mostra a regra" "$tmp/l.html" 'cada cliente usa 1 vez'
contem "lista mostra 1 uso no total" "$tmp/l.html" '0 de 1'
id_unico="$(php -r 'if (preg_match("~/gerencia/cupons/(\d+)\"[^>]*><strong class=\"cl-codigo\">" . $argv[2] . "<~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/l.html" "$unico")"
[[ -n "$id_unico" ]] && ok "id do cupom unico ($id_unico)" || falha "id do cupom unico nao encontrado"
esperar "pausar cupom" "$(enviar "$gj" "$g/cupons/$id_unico/pausar" | cut -d' ' -f1)" "303"
esperar "mensagem de pausado" "$(aviso "$gj" "$g/cupons")" "Cupom $unico pausado. Os clientes não conseguem mais usar."
enviar "$gj" "$g/cupons/$id_unico/pausar" >/dev/null
esperar "editar abre" "$(curl -s -o "$tmp/e.html" -b "$gj" -w '%{http_code}' "$g/cupons/$id_unico")" "200"
contem "edicao mostra o valor" "$tmp/e.html" 'value="5,00"'

echo "Gerente: fidelidade"
esperar "aba Fidelidade abre" "$(curl -s -o "$tmp/f.html" -b "$gj" -w '%{http_code}' "$g/fidelidade")" "200"
contem "comeca desligada" "$tmp/f.html" 'Programa desligado'
esperar "quantidade zero recusada (422)" "$(enviar "$gj" "$g/fidelidade" ativo=1 compras_necessarias=0 tipo=fixo valor=3,00 quais=todos | cut -d' ' -f1)" "422"
esperar "minimo sem valor recusado (422)" "$(enviar "$gj" "$g/fidelidade" ativo=1 compras_necessarias=1 tipo=fixo valor=3,00 quais=minimo valor_minimo= | cut -d' ' -f1)" "422"
esperar "liga: a cada 1 pedido, R\$ 3,00" "$(enviar "$gj" "$g/fidelidade" ativo=1 compras_necessarias=1 tipo=fixo valor=3,00 quais=todos)" "303 $g/fidelidade"
m="$(aviso "$gj" "$g/fidelidade")"; [[ "$m" == "Programa de fidelidade ligado: a cada 1 pedido, ganhe R\$ 3,00 de desconto no próximo." ]] && ok "mensagem da fidelidade ligada" || falha "fidelidade ($m)"
contem "pagina mostra programa ligado" "$tmp/aviso.html" 'Programa ligado'

echo "Pedido online: entrega/pagamento e clientes"
enviar "$gj" "$g/perfil/horarios" modo=sempre >/dev/null
esperar "retirada e formas ligadas" "$(enviar "$gj" "$g/entrega-pagamento" aceita_entrega=1 aceita_retirada=1 retirada_minutos=20 pag_pix_entrega=1 pag_debito=1 pag_credito=1 pag_dinheiro=1 | cut -d' ' -f1)" "303"
aj="$tmp/admin"
login_equipe "$aj" ci_master >/dev/null
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/conectar"
sessao_wpp="$(ls -t "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-cardapim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
curl -s "$fake/_teste/wpp-conectar/$sessao_wpp" >/dev/null
curl -s -b "$aj" "$base/painel/integracoes/whatsapp/estado" | grep -q '"estado":"conectado"' && ok "WhatsApp da plataforma conectado" || falha "WhatsApp da plataforma nao conectou"
entrar_cliente() { # jar telefone nome
  local cod csrf
  csrf="$(sessao "$1")"
  post "$1" "$csrf" /api/v1/cliente/codigo "{\"telefone\":\"$2\"}" >/dev/null
  cod="$(ultimo_codigo "$sessao_wpp|55$2|")"
  post "$1" "$csrf" /api/v1/cliente/entrar "{\"codigo\":\"$cod\"}" >/dev/null
  csrf="$(json "$tmp/r.json" csrf)"
  if [[ "$(json "$tmp/r.json" etapa)" == "nome" ]]; then post "$1" "$csrf" /api/v1/cliente/cadastro "{\"nome\":\"$3\"}" >/dev/null; fi
  json "$tmp/r.json" cliente.primeiro_nome
}
ca="$tmp/ca"; cb="$tmp/cb"
esperar "cliente Ana entra" "$(entrar_cliente "$ca" 859444$r4"1" 'Ana Cupom')" "Ana"
sleep 1
esperar "cliente Bia entra" "$(entrar_cliente "$cb" 859444$r4"2" 'Bia Cupom')" "Bia"
curl -s "$base/$slug" -o "$tmp/cardapio.html"
php -r 'preg_match_all("/<button[^>]*data-produto=\"(\d+)\"[^>]*data-nome=\"([^\"]*)\"[^>]*data-centavos=\"(\d+)\"[^>]*>/s", file_get_contents($argv[1]), $m, PREG_SET_ORDER); $ok = array_values(array_filter($m, fn ($p) => !str_contains($p[0], "disabled"))); usort($ok, fn ($a, $b) => (int) str_contains($b[2], "Cerveja") <=> (int) str_contains($a[2], "Cerveja")); if ($ok) { echo $ok[0][1], " ", $ok[0][3], "\n"; }' "$tmp/cardapio.html" > "$tmp/produto.txt"
read -r produto preco < "$tmp/produto.txt" || true
[[ -n "${produto:-}" ]] && ok "produto disponivel ($produto, $preco centavos)" || falha "nenhum produto disponivel"
sub=$((preco * 2)); dez_c=$(( (sub * 1000 + 5000) / 10000 ))
itens="[{\"id\":$produto,\"quantidade\":2}]"
pedido() { echo "{\"chave\":\"$(chave)\",\"tipo\":\"retirada\",\"forma_pagamento\":\"debito\",\"itens\":$itens$1}"; }
csa="$(sessao "$ca")"; csb="$(sessao "$cb")"

echo "Cupom no pedido"
esperar "previa sem cupom: fidelidade 0 de 1" "$(post "$ca" "$csa" "/api/v1/restaurantes/$slug/desconto" "{\"itens\":$itens}")" "200"
esperar "cliente novo ainda sem premio" "$(json "$tmp/r.json" fidelidade.ganhou)" "false"
esperar "cupom que nao existe (422)" "$(post "$ca" "$csa" "/api/v1/restaurantes/$slug/desconto" "{\"itens\":$itens,\"cupom\":\"NAOEXISTE\"}")" "422"
esperar "mensagem de cupom nao encontrado" "$(json "$tmp/r.json" mensagem)" "Cupom não encontrado. Confira as letras e os números."
esperar "previa com cupom de 10% (minusculas e espaco)" "$(post "$ca" "$csa" "/api/v1/restaurantes/$slug/desconto" "{\"itens\":$itens,\"cupom\":\" cidez$r4 \"}")" "200"
esperar "desconto de 10% calculado no servidor" "$(json "$tmp/r.json" desconto_centavos)" "$dez_c"
esperar "origem cupom" "$(json "$tmp/r.json" origem)" "cupom"
esperar "pedido com cupom" "$(post "$ca" "$csa" "/api/v1/restaurantes/$slug/pedidos" "$(pedido ",\"cupom\":\"$dez\"")")" "201"
p1="$(json "$tmp/r.json" pedido.id)"
get "$ca" "/api/v1/pedidos/$p1" >/dev/null
esperar "pedido guarda o desconto" "$(json "$tmp/r.json" desconto_centavos)" "$dez_c"
esperar "total com desconto" "$(json "$tmp/r.json" total_centavos)" "$((sub - dez_c))"
esperar "rotulo do desconto" "$(json "$tmp/r.json" desconto_rotulo)" "Cupom $dez"
esperar "mesmo cliente nao usa de novo (422)" "$(post "$ca" "$csa" "/api/v1/restaurantes/$slug/pedidos" "$(pedido ",\"cupom\":\"$dez\"")")" "422"
esperar "aviso de cupom ja usado" "$(json "$tmp/r.json" mensagem)" "Você já usou este cupom."
esperar "outro cliente pode usar o de 10%" "$(post "$cb" "$csb" "/api/v1/restaurantes/$slug/desconto" "{\"itens\":$itens,\"cupom\":\"$dez\"}")" "200"
esperar "Ana usa o cupom de 1 vez" "$(post "$ca" "$csa" "/api/v1/restaurantes/$slug/pedidos" "$(pedido ",\"cupom\":\"$unico\"")")" "201"
p2="$(json "$tmp/r.json" pedido.id)"
esperar "Bia nao consegue: ja usado (422)" "$(post "$cb" "$csb" "/api/v1/restaurantes/$slug/pedidos" "$(pedido ",\"cupom\":\"$unico\"")")" "422"
esperar "aviso de esgotado" "$(json "$tmp/r.json" mensagem)" "Este cupom já foi usado o máximo de vezes."
curl -s -b "$gj" "$g/cupons" -o "$tmp/l.html"
contem "gerente ve 1 de 1 uso" "$tmp/l.html" '1 de 1'
contem "cupom unico aparece esgotado" "$tmp/l.html" 'Esgotado'
esperar "cupom usado nao e excluido" "$(enviar "$gj" "$g/cupons/$id_unico/excluir" | cut -d' ' -f1)" "303"
esperar "aviso para pausar em vez de excluir" "$(aviso "$gj" "$g/cupons")" "O cupom $unico já foi usado em pedidos e fica no histórico. Use \"Pausar\" para ninguém mais usar."
curl -s -b "$gj" "$c/delivery/$p1" -o "$tmp/d.html"
contem "caixa ve o cupom no pedido" "$tmp/d.html" "Cupom $dez"

echo "Fidelidade no pedido"
caixa_aberto=0
curl -s -b "$gj" "$c" -o "$tmp/cx.html"
if grep -q 'name="valor_abertura"' "$tmp/cx.html"; then enviar "$gj" "$c/abrir" valor_abertura=10,00 >/dev/null; caixa_aberto=1; fi
enviar "$gj" "$c/delivery/$p1/concluir" >/dev/null
m="$(aviso "$gj" "$c/delivery/$p1")"; [[ -n "$m" ]] && ok "pedido com cupom retirado ($m)" || falha "concluir pedido"
post "$ca" "$csa" "/api/v1/restaurantes/$slug/desconto" "{\"itens\":$itens}" >/dev/null
esperar "1 pedido concluido: ganhou o premio" "$(json "$tmp/r.json" fidelidade.ganhou)" "true"
esperar "premio entra sozinho" "$(json "$tmp/r.json" origem)" "fidelidade"
esperar "R\$ 3,00 de desconto" "$(json "$tmp/r.json" desconto_centavos)" "300"
post "$ca" "$csa" "/api/v1/restaurantes/$slug/desconto" "{\"itens\":$itens,\"cupom\":\"$dez\"}" >/dev/null
esperar "cupom recusado nao some com o premio (Ana ja usou o cupom)" "$(json "$tmp/r.json" fidelidade.ganhou)" "true"
esperar "pedido com o premio da fidelidade" "$(post "$ca" "$csa" "/api/v1/restaurantes/$slug/pedidos" "$(pedido "")")" "201"
p3="$(json "$tmp/r.json" pedido.id)"
get "$ca" "/api/v1/pedidos/$p3" >/dev/null
esperar "pedido com R\$ 3,00 de fidelidade" "$(json "$tmp/r.json" desconto_centavos)" "300"
esperar "rotulo da fidelidade" "$(json "$tmp/r.json" desconto_rotulo)" "Desconto fidelidade"
post "$ca" "$csa" "/api/v1/restaurantes/$slug/desconto" "{\"itens\":$itens}" >/dev/null
esperar "premio usado: contagem zera" "$(json "$tmp/r.json" fidelidade.ganhou)" "false"
esperar "sem desconto ate o proximo" "$(json "$tmp/r.json" desconto_centavos)" "0"
enviar "$gj" "$c/delivery/$p3/cancelar" "motivo=Teste de fidelidade" >/dev/null
post "$ca" "$csa" "/api/v1/restaurantes/$slug/desconto" "{\"itens\":$itens}" >/dev/null
esperar "pedido cancelado devolve o premio" "$(json "$tmp/r.json" fidelidade.ganhou)" "true"
curl -s -b "$gj" "$g/fidelidade" -o "$tmp/f.html"
contem "numeros da fidelidade na aba" "$tmp/f.html" 'Prêmios usados'
esperar "desliga a fidelidade" "$(enviar "$gj" "$g/fidelidade" compras_necessarias=1 tipo=fixo valor=3,00 quais=todos | cut -d' ' -f1)" "303"
post "$ca" "$csa" "/api/v1/restaurantes/$slug/desconto" "{\"itens\":$itens}" >/dev/null
esperar "desligada: sem cartao fidelidade" "$(json "$tmp/r.json" fidelidade)" "null"

echo "Limpeza"
enviar "$gj" "$c/delivery/$p2/cancelar" "motivo=Fim do teste" >/dev/null
if [[ "$caixa_aberto" == "1" ]]; then
  curl -s -b "$gj" "$c" -o "$tmp/cx.html"
  esperado="$(php -r 'if (preg_match("~Dinheiro na gaveta</span>\s*<strong>R\\$ ([\d.,]+)</strong>~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/cx.html")"
  enviar "$gj" "$c/fechar" "dinheiro_contado=$esperado" >/dev/null
fi
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/desconectar"
ok "limpeza feita"

echo
if [[ "$falhas" -gt 0 ]]; then echo "FALHOU: $falhas verificacao(oes) de cupons e fidelidade."; exit 1; fi
echo "OK: cupons e fidelidade (gerente e pedido online)."
