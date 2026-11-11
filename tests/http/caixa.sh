#!/usr/bin/env bash
# Teste HTTP do MODO CAIXA de ponta a ponta: acesso por cargo, abrir/fechar caixa (contagem cega), entradas e saidas,
# cadastro de mesas, comanda (lancar, cancelar item, servico, desconto, dividir, transferir, receber em varias formas),
# venda no balcao e delivery (aceitar, pronto, despachar para o motoqueiro, entregue, cancelar).
# Confere a BAIXA DE ESTOQUE dos insumos: no pedido do cardapio, no item da comanda, NAO de novo no despacho,
# e a devolucao ao cancelar. Usa o servidor FALSO tests/fakes/integracoes-fake.php (WhatsApp da plataforma).
# Uso: bash tests/http/caixa.sh <base> <slug> <base-do-fake>
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
enviar() { # jar url [campo=valor ...] -> "codigo location"
  local jar="$1" url="$2" t args=()
  shift 2
  t="$(token "$jar" "$c/historico")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
pagina() { curl -s -c "$1" -b "$1" -o "$tmp/pagina.html" -w '%{http_code}' "$2"; }
# Mensagem (flash) da proxima tela.
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
cents() { php -r 'echo (int) round((float) str_replace(",", ".", str_replace(".", "", $argv[1])) * 100);' "$1"; }
sessao() { curl -s -c "$1" -b "$1" -o "$tmp/sessao.json" "$base/api/v1/cliente"; json "$tmp/sessao.json" csrf; }
post() { curl -s -c "$1" -b "$1" -o "$tmp/r.json" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" --data "$4" "$base$3"; }
ultimo_codigo() { curl -s "$fake/_teste/wpp-mensagens" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $l = $d["linhas"] ?? []; for ($i = count($l) - 1; $i >= 0; $i--) { if (strpos($l[$i], $argv[1]) !== false && preg_match("/\*(\d{6})\*/", $l[$i], $m)) { echo $m[1]; exit; } }' "$1"; }
chave() { php -r 'echo bin2hex(random_bytes(16));'; }

echo "Acesso ao modo caixa"
cx="$tmp/caixa"; gj="$tmp/gerente"; mj="$tmp/moto"; oj="$tmp/outro"
esperar "caixa entra" "$(login_equipe "$cx" ci_caixa)" "303"
esperar "gerente entra" "$(login_equipe "$gj" ci_gerente)" "303"
esperar "motoqueiro entra" "$(login_equipe "$mj" ci_motoqueiro)" "303"
esperar "gerente de outro restaurante entra" "$(login_equipe "$oj" ci_outro_gerente)" "303"
esperar "caixa abre o modo caixa" "$(pagina "$cx" "$c")" "200"
contem "menu lateral do caixa" "$tmp/pagina.html" 'Mesas e comandas'
contem "fila de delivery colada no menu" "$tmp/pagina.html" 'data-fila'
contem "carrega o script do caixa" "$tmp/pagina.html" '/assets/js/caixa.js'
nao_contem "caixa nao ve voltar a gerencia" "$tmp/pagina.html" 'Voltar à gerência'
pagina "$gj" "$c" >/dev/null
contem "gerente ve voltar a gerencia" "$tmp/pagina.html" 'Voltar à gerência'
esperar "motoqueiro nao abre o caixa (403)" "$(pagina "$mj" "$c")" "403"
esperar "gerente de outro restaurante nao abre (403)" "$(pagina "$oj" "$c/mesas")" "403"
esperar "gerente de outro restaurante nao cria mesa (403)" "$(curl -s -o /dev/null -b "$oj" -w '%{http_code}' -X POST "$c/mesas")" "403"
esperar "sem login vai para o login" "$(curl -s -o /dev/null -w '%{http_code}' "$c")" "302"
esperar "gerencia/mesas leva ao cadastro de mesas" "$(curl -s -o /dev/null -b "$gj" -w '%{redirect_url}' "$g/mesas")" "$c/mesas/gerenciar"
for tela in mesas mesas/gerenciar delivery movimentos historico; do
  esperar "tela /caixa/$tela abre" "$(pagina "$cx" "$c/$tela")" "200"
done
esperar "fila em pedaco (sem layout)" "$(pagina "$cx" "$c/delivery/fila")" "200"
nao_contem "fila sem o layout" "$tmp/pagina.html" '<html'

echo "Abrir o caixa"
esperar "POST sem CSRF volta com erro" "$(curl -s -o /dev/null -b "$cx" -w '%{http_code}' -X POST "$c/abrir")" "303"
esperar "fechar com caixa fechado volta" "$(curl -s -o /dev/null -b "$cx" -w '%{http_code}' "$c/fechar")" "303"
enviar "$cx" "$c/abrir" valor_abertura=abc >/dev/null
esperar "troco inicial invalido" "$(aviso "$cx" "$c")" "Confira o valor do troco inicial."
enviar "$cx" "$c/abrir" valor_abertura=100,00 >/dev/null
esperar "caixa aberto com troco" "$(aviso "$cx" "$c")" "Caixa aberto com R\$ 100,00 de troco. Boas vendas!"
enviar "$gj" "$c/abrir" valor_abertura=10,00 >/dev/null
esperar "nao abre dois caixas" "$(aviso "$gj" "$c")" "O caixa já está aberto."

echo "Cadastro de mesas"
pagina "$cx" "$c/mesas" >/dev/null
contem "restaurante ja vem com 5 mesas" "$tmp/pagina.html" 'Abrir comanda da mesa 5"'
contem "mesa desenhada (tampo e cadeiras)" "$tmp/pagina.html" 'class="mesa-tampo"'
contem "card + para nova mesa" "$tmp/pagina.html" '/caixa/mesas/nova'
enviar "$cx" "$c/mesas/varias" de=1 ate=5 >/dev/null
esperar "mesas 1 a 5 ja existem" "$(aviso "$cx" "$c/mesas/gerenciar")" "Essas mesas já existem."
enviar "$cx" "$c/mesas/varias" de=6 ate=7 >/dev/null
esperar "criar mesas 6 a 7" "$(aviso "$cx" "$c/mesas/gerenciar")" "2 mesa(s) criada(s): mesas 6 a 7."
enviar "$cx" "$c/mesas" numero=3 >/dev/null
esperar "mesa repetida recusada" "$(aviso "$cx" "$c/mesas/gerenciar")" "Confira os campos destacados."
enviar "$cx" "$c/mesas" numero=10 apelido=Varanda lugares=4 >/dev/null
esperar "mesa 10 com nome e lugares" "$(aviso "$cx" "$c/mesas/gerenciar")" "Mesa 10 criada."
enviar "$cx" "$c/mesas" numero=11 lugares=100 >/dev/null
esperar "lugares fora do limite recusado" "$(aviso "$cx" "$c/mesas/gerenciar")" "Confira os campos destacados."
pagina "$cx" "$c/mesas/gerenciar" >/dev/null
php -r 'preg_match_all("~/caixa/mesas/(\d+)/excluir~", file_get_contents($argv[1]), $m); echo implode(" ", array_unique($m[1]));' "$tmp/pagina.html" > "$tmp/mesas.txt"
read -r -a mesas < "$tmp/mesas.txt" || true
esperar "8 mesas no cadastro" "${#mesas[@]}" "8"
m1="${mesas[0]}"; m2="${mesas[1]}"; m10="${mesas[7]}"
enviar "$cx" "$c/mesas/$m10" numero=12 apelido=Varanda lugares=6 ativo=1 >/dev/null
esperar "editar mesa (numero e lugares)" "$(aviso "$cx" "$c/mesas/gerenciar")" "Mesa 12 salva."
enviar "$cx" "$c/mesas/nova" >/dev/null
esperar "+ cria a proxima mesa" "$(aviso "$cx" "$c/mesas")" "Mesa 13 criada."
pagina "$cx" "$c/mesas" >/dev/null
contem "mapa mostra mesa livre" "$tmp/pagina.html" 'Abrir comanda da mesa 1'
contem "mapa mostra o nome da mesa" "$tmp/pagina.html" 'Varanda'

echo "Estoque antes (insumo da cerveja)"
curl -s -b "$gj" "$g/estoque/insumos" -o "$tmp/insumos.html"
insumo="$(php -r 'if (preg_match("~estoque/insumos/(\d+)\"[^>]*>(?:(?!</li>).)*?Cerveja~s", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/insumos.html")"
[[ -n "$insumo" ]] && ok "insumo da cerveja encontrado ($insumo)" || falha "insumo da cerveja nao encontrado"
estoque() { curl -s -b "$gj" "$g/estoque/insumos/$insumo" | php -r 'if (preg_match("~name=\"estoque_atual\"[^>]*value=\"([^\"]*)\"~", stream_get_contents(STDIN), $m)) echo (float) str_replace(",", ".", str_replace(".", "", $m[1]));'; }
e0="$(estoque)"
ok "estoque inicial: $e0"

echo "Comanda da mesa"
r="$(enviar "$cx" "$c/mesas/$m1/abrir")"
comanda="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
[[ -n "$comanda" ]] && ok "abrir mesa 1 leva a comanda ($comanda)" || falha "abrir mesa nao levou a comanda ($r)"
r2="$(enviar "$cx" "$c/mesas/$m1/abrir")"
esperar "abrir de novo volta a mesma comanda" "$(echo "$r2" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)" "$comanda"
pagina "$cx" "$c/comandas/$comanda" >/dev/null
contem "comanda mostra o cardapio" "$tmp/pagina.html" 'data-produto-abrir'
contem "comanda mostra a conta vazia" "$tmp/pagina.html" 'Nenhum item ainda'
read -r produto preco < <(php -r 'preg_match_all("~data-produto-id=\"(\d+)\" data-produto-nome=\"([^\"]*)\" data-produto-centavos=\"(\d+)\"~", file_get_contents($argv[1]), $m, PREG_SET_ORDER); foreach ($m as $p) { if (str_contains($p[2], "Cerveja")) { echo $p[1], " ", $p[3], "\n"; exit; } }' "$tmp/pagina.html") || true
[[ -n "${produto:-}" ]] && ok "cerveja no cardapio da comanda ($produto, $preco centavos)" || falha "cerveja nao encontrada na comanda"
enviar "$cx" "$c/comandas/$comanda/itens" produto="$produto" quantidade=0 >/dev/null
esperar "quantidade zero recusada" "$(aviso "$cx" "$c/comandas/$comanda")" "Quantidade de 1 a 99."
enviar "$cx" "$c/comandas/$comanda/itens" produto=999999 quantidade=1 >/dev/null
esperar "produto de fora recusado" "$(aviso "$cx" "$c/comandas/$comanda")" "Produto não encontrado no cardápio."
enviar "$cx" "$c/comandas/$comanda/itens" produto="$produto" quantidade=2 observacao=gelada >/dev/null
m="$(aviso "$cx" "$c/comandas/$comanda")"; [[ "$m" == "2x "*" lançado." ]] && ok "item lancado ($m)" || falha "item nao lancado ($m)"
e1="$(estoque)"
esperar "ESTOQUE: lancar 2 cervejas baixa 2 do insumo" "$(php -r "echo $e0 - $e1;")" "2"
pagina "$cx" "$c/comandas/$comanda" >/dev/null
item="$(php -r 'if (preg_match("~/itens/(\d+)/cancelar~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
contem "observacao do item aparece" "$tmp/pagina.html" 'gelada'
enviar "$cx" "$c/comandas/$comanda/itens/$item/cancelar" motivo=x >/dev/null
esperar "cancelar item sem motivo recusado" "$(aviso "$cx" "$c/comandas/$comanda")" "Conte o motivo do cancelamento (ex.: cliente desistiu, lançado errado)."
enviar "$cx" "$c/comandas/$comanda/itens/$item/cancelar" "motivo=Lançado errado" >/dev/null
m="$(aviso "$cx" "$c/comandas/$comanda")"; [[ "$m" == "2x "*" cancelado. O estoque foi devolvido." ]] && ok "item cancelado ($m)" || falha "item nao cancelado ($m)"
esperar "ESTOQUE: cancelar o item devolve ao insumo" "$(estoque)" "$e0"
enviar "$cx" "$c/comandas/$comanda/itens" produto="$produto" quantidade=3 >/dev/null
esperar "ESTOQUE: lancar 3 baixa 3" "$(php -r "echo $e0 - $(estoque);")" "3"
sub=$((preco * 3)); servico=$(( (sub * 1000 + 5000) / 10000 )); total=$((sub + servico))
pagina "$cx" "$c/comandas/$comanda" >/dev/null
contem "servico de 10% na mesa" "$tmp/pagina.html" 'Serviço (10%)'
contem "total com servico" "$tmp/pagina.html" "$(php -r "echo 'R\$ ' . number_format($total / 100, 2, ',', '.');")"
enviar "$cx" "$c/comandas/$comanda/ajustes" servico=1 desconto=1,00 >/dev/null
esperar "desconto sem motivo recusado" "$(aviso "$cx" "$c/comandas/$comanda")" "Confira os campos destacados."
enviar "$cx" "$c/comandas/$comanda/ajustes" servico=1 desconto=1,00 "motivo_desconto=Cliente da casa" pessoas=2 >/dev/null
esperar "desconto e divisao aplicados" "$(aviso "$cx" "$c/comandas/$comanda")" "Conta atualizada."
total=$((total - 100))
pagina "$cx" "$c/comandas/$comanda" >/dev/null
contem "divisao por 2 aparece" "$tmp/pagina.html" 'Dividido por 2'
contem "motivo do desconto aparece" "$tmp/pagina.html" 'Cliente da casa'
enviar "$cx" "$c/comandas/$comanda/transferir" mesa="$m2" >/dev/null
esperar "transferir para a mesa 2" "$(aviso "$cx" "$c/comandas/$comanda")" "Comanda levada para a Mesa 2."
pagina "$cx" "$c/mesas" >/dev/null
contem "mesa 1 ficou livre" "$tmp/pagina.html" 'Abrir comanda da mesa 1'
nao_contem "mesa 2 ocupada (nao aparece como livre)" "$tmp/pagina.html" 'Abrir comanda da mesa 2"'
enviar "$cx" "$c/mesas/$m2/excluir" >/dev/null
esperar "nao exclui mesa com comanda aberta" "$(aviso "$cx" "$c/mesas/gerenciar")" "Esta mesa tem uma comanda aberta. Feche ou cancele a comanda antes de excluir."

echo "Receber a conta (varias formas e troco)"
esperar "tela de receber abre" "$(pagina "$cx" "$c/comandas/$comanda/pagar")" "200"
contem "mostra quanto falta" "$tmp/pagina.html" "data-pagar-restante=\"$total\""
enviar "$cx" "$c/comandas/$comanda/pagamentos" forma=debito valor=10,00 >/dev/null
esperar "parte no debito" "$(aviso "$cx" "$c/comandas/$comanda/pagar")" "Débito R\$ 10,00 recebido."
restante=$((total - 1000))
enviar "$cx" "$c/comandas/$comanda/pagamentos" forma=credito valor=9999,00 >/dev/null
esperar "cartao acima do que falta recusado" "$(aviso "$cx" "$c/comandas/$comanda/pagar")" "Valor maior que o que falta pagar ($(php -r "echo 'R\$ ' . number_format($restante / 100, 2, ',', '.');"))."
troco=$((10000 - restante))
r="$(enviar "$cx" "$c/comandas/$comanda/pagamentos" forma=dinheiro valor= recebido=100,00)"
esperar "dinheiro com troco fecha a conta" "$(aviso "$cx" "$c/comandas/$comanda")" "Dinheiro $(php -r "echo 'R\$ ' . number_format($restante / 100, 2, ',', '.');") recebido. Troco: $(php -r "echo 'R\$ ' . number_format($troco / 100, 2, ',', '.');"). Conta fechada e mesa liberada."
contem "troco em destaque depois de fechar" "$tmp/aviso.html" 'Devolva de troco'
contem "valor do troco em destaque" "$tmp/aviso.html" "$(php -r "echo 'R\$ ' . number_format($troco / 100, 2, ',', '.');")</strong>"
pagina "$cx" "$c/comandas/$comanda" >/dev/null
contem "comanda paga" "$tmp/pagina.html" 'Conta paga'
nao_contem "troco aparece uma vez so" "$tmp/pagina.html" 'Devolva de troco'
pagina "$cx" "$c/mesas" >/dev/null
contem "mesa 2 liberada" "$tmp/pagina.html" 'Abrir comanda da mesa 2'
enviar "$cx" "$c/comandas/$comanda/itens" produto="$produto" quantidade=1 >/dev/null
esperar "conta fechada nao recebe item" "$(aviso "$cx" "$c/comandas/$comanda")" "Esta comanda já foi fechada."

echo "Venda no balcao cancelada devolve o estoque"
r="$(enviar "$cx" "$c/balcao" nome=Joana)"
balcao="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
[[ -n "$balcao" ]] && ok "venda no balcao aberta ($balcao)" || falha "balcao nao abriu"
antes="$(estoque)"
enviar "$cx" "$c/comandas/$balcao/itens" produto="$produto" quantidade=4 >/dev/null
esperar "ESTOQUE: balcao baixa 4" "$(php -r "echo $antes - $(estoque);")" "4"
pagina "$cx" "$c/comandas/$balcao" >/dev/null
nao_contem "balcao sem taxa de servico" "$tmp/pagina.html" 'Serviço (10%)</dt>'
enviar "$cx" "$c/comandas/$balcao/cancelar" "motivo=Cliente desistiu" >/dev/null
esperar "comanda do balcao cancelada" "$(aviso "$cx" "$c/mesas")" "Comanda cancelada. O estoque dos itens foi devolvido."
esperar "ESTOQUE: cancelar comanda devolve tudo" "$(estoque)" "$antes"

echo "Entradas e saidas"
enviar "$cx" "$c/movimentos" tipo=saida categoria=sangria valor=99999,00 >/dev/null
esperar "saida maior que a gaveta recusada" "$(aviso "$cx" "$c/movimentos?tipo=saida")" "Saída maior que o dinheiro da gaveta."
enviar "$cx" "$c/movimentos" tipo=saida categoria=despesa valor=5,00 >/dev/null
esperar "despesa sem descricao recusada" "$(aviso "$cx" "$c/movimentos?tipo=saida")" "Confira os campos destacados."
enviar "$cx" "$c/movimentos" tipo=entrada categoria=reforco valor=50,00 >/dev/null
esperar "reforco de troco" "$(aviso "$cx" "$c/movimentos?tipo=entrada")" "Entrada de R\$ 50,00 registrada."
enviar "$cx" "$c/movimentos" tipo=saida categoria=despesa valor=30,00 "descricao=Gelo" >/dev/null
esperar "saida para despesa" "$(aviso "$cx" "$c/movimentos?tipo=saida")" "Saída de R\$ 30,00 registrada."
pagina "$cx" "$c/movimentos" >/dev/null
contem "lista mostra a despesa" "$tmp/pagina.html" 'Gelo'

echo "Delivery: pedido do cardapio, despacho e baixa de estoque"
esperar "salvar entrega e pagamento" "$(t="$(token "$gj" "$g/entrega-pagamento")"; curl -s -o /dev/null -b "$gj" -c "$gj" -w '%{http_code}' --data-urlencode "_csrf=$t" -d aceita_entrega=1 -d aceita_retirada=1 -d pag_dinheiro=1 -d pag_debito=1 "$g/entrega-pagamento")" "303"
t="$(token "$gj" "$g/entrega-pagamento")"
curl -s -o /dev/null -b "$gj" -c "$gj" --data-urlencode "_csrf=$t" -d tipo=bairro --data-urlencode "bairro=Cerqueira César" --data-urlencode "cidade=São Paulo" -d taxa=5,00 "$g/entrega-pagamento/areas"
aj="$tmp/admin"
login_equipe "$aj" ci_master >/dev/null
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/conectar"
sessao_wpp="$(ls -t "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-cardapim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
curl -s "$fake/_teste/wpp-conectar/$sessao_wpp" >/dev/null
curl -s -b "$aj" "$base/painel/integracoes/whatsapp/estado" >/dev/null
cj="$tmp/cliente"
csrf="$(sessao "$cj")"
post "$cj" "$csrf" /api/v1/cliente/codigo '{"telefone":"85977770001"}' >/dev/null
post "$cj" "$csrf" /api/v1/cliente/entrar "{\"codigo\":\"$(ultimo_codigo "$sessao_wpp|5585977770001|")\"}" >/dev/null
csrf="$(json "$tmp/r.json" csrf)"
if [[ "$(json "$tmp/r.json" etapa)" == "nome" ]]; then post "$cj" "$csrf" /api/v1/cliente/cadastro '{"nome":"Cliente Caixa"}' >/dev/null; csrf="$(json "$tmp/r.json" csrf)"; fi
esperar "cliente entrou" "$(json "$tmp/r.json" cliente.primeiro_nome)" "Cliente"
endereco='{"cep":"01414000","logradouro":"Rua Haddock Lobo","numero":"100","bairro":"Cerqueira César","cidade":"São Paulo","estado":"SP"}'
antes="$(estoque)"
esperar "cliente faz pedido com entrega (dinheiro, troco para 100)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "{\"chave\":\"$(chave)\",\"tipo\":\"entrega\",\"forma_pagamento\":\"dinheiro\",\"troco_para\":\"100,00\",\"itens\":[{\"id\":$produto,\"quantidade\":2}],\"endereco\":$endereco}")" "201"
pedido="$(json "$tmp/r.json" pedido.id)"
numero="$(json "$tmp/r.json" pedido.numero)"
depois_pedido="$(estoque)"
esperar "ESTOQUE: pedido do cardapio baixa 2 ao ser feito" "$(php -r "echo $antes - $depois_pedido;")" "2"
pagina "$cx" "$c/delivery/fila" >/dev/null
contem "pedido novo aparece na fila do menu" "$tmp/pagina.html" "data-fila-id=\"$pedido\""
contem "pedido novo marcado como Novo" "$tmp/pagina.html" 'data-fila-status="recebido"'
esperar "pedido abre no caixa" "$(pagina "$cx" "$c/delivery/$pedido")" "200"
contem "mostra o troco para levar" "$tmp/pagina.html" 'Troco para R$ 100,00'
enviar "$cx" "$c/delivery/$pedido/concluir" >/dev/null
esperar "nao entrega antes de despachar" "$(aviso "$cx" "$c/delivery/$pedido")" "Despache o pedido para um entregador antes de marcar como entregue."
enviar "$cx" "$c/delivery/$pedido/aceitar" >/dev/null
esperar "aceitar e preparar" "$(aviso "$cx" "$c/delivery/$pedido")" "Pedido #$numero: pedido aceito. em preparo."
enviar "$cx" "$c/delivery/$pedido/aceitar" >/dev/null
esperar "aceitar de novo recusado" "$(aviso "$cx" "$c/delivery/$pedido")" "Este pedido já mudou de situação. Confira na tela."
enviar "$cx" "$c/delivery/$pedido/pronto" >/dev/null
esperar "pedido pronto" "$(aviso "$cx" "$c/delivery/$pedido")" "Pedido #$numero: pedido pronto."
pagina "$cx" "$c/delivery/$pedido" >/dev/null
entregador="$(php -r 'if (preg_match("~name=\"entregador\" value=\"(\d+)\"~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
[[ -n "$entregador" ]] && ok "motoqueiro da equipe na lista ($entregador)" || falha "nenhum motoqueiro para escolher"
enviar "$cx" "$c/delivery/$pedido/despachar" >/dev/null
esperar "despachar sem escolher recusado" "$(aviso "$cx" "$c/delivery/$pedido")" "Escolha um entregador da sua equipe."
enviar "$cx" "$c/delivery/$pedido/despachar" entregador=999999 >/dev/null
esperar "entregador de fora recusado" "$(aviso "$cx" "$c/delivery/$pedido")" "Escolha um entregador da sua equipe."
enviar "$cx" "$c/delivery/$pedido/despachar" entregador="$entregador" >/dev/null
esperar "despachado para o motoqueiro" "$(aviso "$cx" "$c/delivery/$pedido")" "Pedido #$numero saiu com CI Motoqueiro."
esperar "ESTOQUE: despacho NAO baixa de novo" "$(estoque)" "$depois_pedido"
curl -s -b "$cj" -o "$tmp/r.json" "$base/api/v1/pedidos/$pedido"
esperar "cliente ve que saiu para entrega" "$(json "$tmp/r.json" status)" "saiu_entrega"
enviar "$cx" "$c/delivery/$pedido/concluir" >/dev/null
esperar "entregue e recebido no caixa" "$(aviso "$cx" "$c/delivery/$pedido")" "Pedido #$numero concluído. Pagamento de $(php -r "echo 'R\$ ' . number_format(($preco * 2 + 500) / 100, 2, ',', '.');") no caixa."
curl -s -b "$cj" -o "$tmp/r.json" "$base/api/v1/pedidos/$pedido"
esperar "cliente ve entregue" "$(json "$tmp/r.json" status)" "concluido"
esperar "ESTOQUE: concluir NAO baixa de novo" "$(estoque)" "$depois_pedido"
pagina "$cx" "$c/delivery/fila" >/dev/null
nao_contem "concluido sai da fila" "$tmp/pagina.html" "data-fila-id=\"$pedido\""

esperar "segundo pedido (retirada, debito)" "$(post "$cj" "$csrf" "/api/v1/restaurantes/$slug/pedidos" "{\"chave\":\"$(chave)\",\"tipo\":\"retirada\",\"forma_pagamento\":\"debito\",\"itens\":[{\"id\":$produto,\"quantidade\":1}]}")" "201"
pedido2="$(json "$tmp/r.json" pedido.id)"
enviar "$cx" "$c/delivery/$pedido2/despachar" entregador="$entregador" >/dev/null
esperar "retirada nao sai para entrega" "$(aviso "$cx" "$c/delivery/$pedido2")" "Pedido para retirar no local não sai para entrega."
enviar "$cx" "$c/delivery/$pedido2/cancelar" motivo=x >/dev/null
esperar "cancelar sem motivo recusado" "$(aviso "$cx" "$c/delivery/$pedido2")" "Conte o motivo do cancelamento (o cliente vê esta mensagem)."
antes="$(estoque)"
enviar "$cx" "$c/delivery/$pedido2/cancelar" "motivo=Acabou a cerveja gelada" >/dev/null
esperar "pedido cancelado pelo caixa" "$(aviso "$cx" "$c/delivery/$pedido2")" "Pedido #$(php -r 'echo (int) $argv[1] + 0;' "$((numero + 1))") cancelado. O estoque foi devolvido."
esperar "ESTOQUE: cancelar pedido devolve" "$(php -r "echo $(estoque) - $antes;")" "1"

echo "Fechar o caixa (contagem cega)"
pagina "$cx" "$c" >/dev/null
contem "resumo mostra vendas em dinheiro" "$tmp/pagina.html" 'Dinheiro na gaveta'
esperado="$(php -r 'if (preg_match("~Dinheiro na gaveta</span>\s*<strong>R\\$ ([\d.,]+)</strong>~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
# 100 de troco + dinheiro da mesa (o que faltava) + delivery em dinheiro + 50 de reforco - 30 de despesa
conta=$((10000 + restante + preco * 2 + 500 + 5000 - 3000))
esperar "dinheiro esperado na gaveta bate com a conta" "$(cents "$esperado")" "$conta"
esperar "tela de fechar abre" "$(pagina "$cx" "$c/fechar")" "200"
nao_contem "fechamento cego (nao mostra o esperado)" "$tmp/pagina.html" "R\$ $esperado"
r="$(enviar "$cx" "$c/fechar" dinheiro_contado=)"
esperar "fechar sem contar recusado" "$(aviso "$cx" "$c/fechar")" "Informe quanto dinheiro você contou na gaveta."
r="$(enviar "$cx" "$c/fechar" "dinheiro_contado=$esperado" "observacao=Tudo certo")"
historico="$(echo "$r" | grep -o 'historico/[0-9]*' | cut -d/ -f2)"
esperar "caixa fechado sem diferenca" "$(aviso "$cx" "$c/historico/$historico")" "Caixa fechado: sem diferença."
contem "historico mostra que bateu" "$tmp/aviso.html" 'O dinheiro bateu certinho'
pagina "$cx" "$c/historico" >/dev/null
contem "fechamento na lista" "$tmp/pagina.html" 'Certo'
enviar "$cx" "$c/movimentos" tipo=entrada categoria=reforco valor=1,00 >/dev/null
esperar "sem caixa aberto nao registra entrada" "$(aviso "$cx" "$c/movimentos")" "Abra o caixa antes de registrar entradas e saídas."
r="$(enviar "$cx" "$c/mesas/$m1/abrir")"
comanda2="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
enviar "$cx" "$c/comandas/$comanda2/itens" produto="$produto" quantidade=1 >/dev/null
enviar "$cx" "$c/comandas/$comanda2/pagamentos" forma=pix >/dev/null
esperar "sem caixa aberto nao recebe" "$(aviso "$cx" "$c/comandas/$comanda2/pagar")" "Abra o caixa para receber pagamentos."
enviar "$cx" "$c/comandas/$comanda2/cancelar" >/dev/null
esperar "cancelar comanda sem motivo" "$(aviso "$cx" "$c/mesas")" "Comanda cancelada. O estoque dos itens foi devolvido."

echo "Excluir mesas"
enviar "$cx" "$c/mesas/${mesas[4]}/excluir" >/dev/null
esperar "mesa sem historico excluida" "$(aviso "$cx" "$c/mesas/gerenciar")" "Mesa 5 excluída."
enviar "$cx" "$c/mesas/$m2/excluir" >/dev/null
esperar "mesa com historico excluida (pedidos ficam)" "$(aviso "$cx" "$c/mesas/gerenciar")" "Mesa 2 excluída."
enviar "$cx" "$c/mesas" numero=2 >/dev/null
esperar "numero da mesa excluida pode ser usado de novo" "$(aviso "$cx" "$c/mesas/gerenciar")" "Mesa 2 criada."

echo "Limpeza"
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/desconectar"
ok "WhatsApp de teste desligado"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no caixa."
  exit 1
fi
echo "OK: modo caixa (mesas, comandas, caixa, entradas/saidas, delivery com despacho e baixa de estoque)."
