#!/usr/bin/env bash
# Teste HTTP do MODO CAIXA de ponta a ponta: acesso por cargo, abrir/fechar caixa (contagem cega), entradas e saidas,
# cadastro de mesas, comanda (varias por mesa com nome, lancar, cancelar item, servico, desconto, dividir, transferir, receber em varias formas),
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
esperar "nao exclui mesa com comanda aberta" "$(aviso "$cx" "$c/mesas/gerenciar")" "Esta mesa tem comanda aberta. Feche ou cancele as comandas antes de excluir."

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

echo "Varias comandas na mesma mesa (uma por pessoa, com nome)"
r="$(enviar "$cx" "$c/mesas/$m1/abrir")"
ca="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
[[ -n "$ca" ]] && ok "primeira comanda da mesa 1 ($ca)" || falha "mesa 1 nao abriu ($r)"
pagina "$cx" "$c/comandas/$ca" >/dev/null
contem "comanda mostra a area das comandas da mesa" "$tmp/pagina.html" 'Comanda da Mesa 1'
contem "botao nova comanda" "$tmp/pagina.html" "/caixa/mesas/$m1/comandas"
contem "opcao de dar nome" "$tmp/pagina.html" 'Dar o nome da pessoa a esta comanda'
r="$(enviar "$cx" "$c/mesas/$m1/comandas" nome="João" voltar_comanda="$ca")"
cb="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
[[ -n "$cb" && "$cb" != "$ca" ]] && ok "nova comanda para quem chegou depois ($cb)" || falha "nova comanda nao abriu ($r)"
esperar "mensagem da nova comanda" "$(aviso "$cx" "$c/comandas/$cb")" "Comanda de João aberta na Mesa 1."
contem "titulo com o nome da pessoa" "$tmp/aviso.html" 'Mesa 1 · João'
contem "abas mostram as duas comandas" "$tmp/aviso.html" 'Comandas da Mesa 1'
contem "aba da primeira comanda sem nome" "$tmp/aviso.html" 'Comanda 1</span>'
contem "aba atual marcada" "$tmp/aviso.html" 'aria-current="page"'
r="$(enviar "$cx" "$c/mesas/$m1/comandas" nome="$(printf 'N%.0s' {1..41})" voltar_comanda="$cb")"
esperar "nome com mais de 40 letras recusado" "$(echo "$r" | cut -d' ' -f2)" "$c/comandas/$cb"
esperar "aviso do nome longo" "$(aviso "$cx" "$c/comandas/$cb")" "Use até 40 letras no nome."
pagina "$cx" "$c/mesas" >/dev/null
contem "mapa mostra 2 comandas na mesa" "$tmp/pagina.html" '2 comandas'
contem "mapa mostra o nome da pessoa" "$tmp/pagina.html" 'class="mesa-nomes"'
r="$(enviar "$cx" "$c/mesas/$m1/abrir")"
esperar "tocar na mesa abre a comanda mais antiga" "$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)" "$ca"
enviar "$cx" "$c/comandas/$ca/nome" nome="Maria" >/dev/null
esperar "dar nome a comanda" "$(aviso "$cx" "$c/comandas/$ca")" "Comanda agora é de Maria."
contem "titulo com o novo nome" "$tmp/aviso.html" 'Mesa 1 · Maria'
enviar "$cx" "$c/comandas/$cb/itens" produto="$produto" quantidade=1 >/dev/null
r="$(enviar "$cx" "$c/comandas/$cb/pagamentos" forma=dinheiro valor= recebido=100,00)"
m="$(aviso "$cx" "$c/comandas/$cb")"
[[ "$m" == *"Conta fechada. A mesa continua com 1 comanda aberta." ]] && ok "pagar a conta do João mantem a da Maria ($m)" || falha "pagamento de uma comanda ($m)"
contem "comanda paga mostra a que ainda esta aberta" "$tmp/aviso.html" "comandas/$ca"
# Repoe a cerveja vendida ao Joao para os testes seguintes terem o mesmo estoque de antes.
enviar "$gj" "$g/estoque/insumos/$insumo/repor" quantidade=1 >/dev/null
pagina "$cx" "$c/mesas" >/dev/null
nao_contem "mesa 1 continua ocupada" "$tmp/pagina.html" 'Abrir comanda da mesa 1"'
r="$(enviar "$cx" "$c/mesas/${mesas[2]}/abrir")"
cc="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
enviar "$cx" "$c/comandas/$cc/transferir" mesa="$m1" >/dev/null
esperar "levar a comanda para mesa ocupada junta as comandas" "$(aviso "$cx" "$c/comandas/$cc")" "Comanda levada para a Mesa 1, junto com as outras comandas da mesa."
r="$(enviar "$cx" "$c/comandas/$ca/cancelar")"
esperar "cancelar uma comanda vai para a outra da mesa" "$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)" "$cc"
enviar "$cx" "$c/comandas/$cc/cancelar" >/dev/null
pagina "$cx" "$c/mesas" >/dev/null
contem "sem comandas a mesa 1 fica livre" "$tmp/pagina.html" 'Abrir comanda da mesa 1"'

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
sessao_wpp="$(ls -t "$(php -r 'echo sys_get_temp_dir();')/cardapim-fake-integracoes" | grep -o 'wpp-deliveryzim_plataforma_[a-f0-9]*' | head -1 | sed 's/^wpp-//')"
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

echo "Entrega feita no caixa (venda no balcao vira pedido de entrega)"
jpost() { # url [campo=valor ...] -> codigo; resposta em $tmp/j.json
  local url="$1" t args=()
  shift
  t="$(token "$cx" "$c/historico")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/j.json" -c "$cx" -b "$cx" -w '%{http_code}' -H 'Accept: application/json' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
banco() { php -r 'require "bootstrap/app.php"; $d = \Cardapim\Core\Database::connect(); $q = $d->prepare($argv[1]); $q->execute(array_slice($argv, 2)); echo implode("|", (array) $q->fetch(PDO::FETCH_NUM));' "$@"; }
r="$(enviar "$cx" "$c/balcao" nome=)"
eb="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
[[ -n "$eb" ]] && ok "venda no balcao para entrega aberta ($eb)" || falha "balcao para entrega nao abriu"
antes="$(estoque)"
enviar "$cx" "$c/comandas/$eb/itens" produto="$produto" quantidade=2 >/dev/null
pagina "$cx" "$c/comandas/$eb" >/dev/null
contem "aba Entrega na venda do balcao" "$tmp/pagina.html" 'data-entrega-ativo'
contem "caixinha Pedido para entrega" "$tmp/pagina.html" 'Pedido para entrega'
contem "popup Preparar pedido na tela" "$tmp/pagina.html" 'data-dialogo-preparar'
contem "carrega o script da entrega" "$tmp/pagina.html" '/assets/js/caixa-entrega.js'
r="$(enviar "$cx" "$c/mesas/${mesas[3]}/abrir")"
em="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
pagina "$cx" "$c/comandas/$em" >/dev/null
nao_contem "comanda de mesa nao tem a aba Entrega" "$tmp/pagina.html" 'data-entrega-ativo'
enviar "$cx" "$c/comandas/$em/entrega" nome=Teste logradouro="Rua Haddock Lobo" numero=100 "bairro=Cerqueira César" quando=agora forma=pix >/dev/null
esperar "mesa nao vira entrega" "$(aviso "$cx" "$c/comandas/$em")" "A entrega só pode ser feita a partir de uma venda no balcão."
enviar "$cx" "$c/comandas/$em/cancelar" >/dev/null

esperar "buscar telefone conhecido" "$(jpost "$c/comandas/$eb/entrega/telefone" telefone='(85) 97777-0001')" "200"
esperar "telefone puxa o ultimo endereco" "$(json "$tmp/j.json" endereco.logradouro)" "Rua Haddock Lobo"
esperar "telefone puxa o nome" "$(json "$tmp/j.json" encontrado)" "true"
jpost "$c/comandas/$eb/entrega/telefone" telefone=123 >/dev/null
esperar "telefone sem DDD recusado" "$(json "$tmp/j.json" ok)" "false"
jpost "$c/comandas/$eb/entrega/telefone" telefone='85 90000-0000' >/dev/null
esperar "telefone novo: cliente novo" "$(json "$tmp/j.json" encontrado)" "false"
esperar "telefone sem CSRF recusado" "$(curl -s -o /dev/null -b "$cx" -w '%{http_code}' -H 'Accept: application/json' -d telefone=85977770001 "$c/comandas/$eb/entrega/telefone")" "401"
esperar "motoqueiro nao usa a entrega do caixa" "$(curl -s -o /dev/null -b "$mj" -w '%{http_code}' -H 'Accept: application/json' -d telefone=85977770001 "$c/comandas/$eb/entrega/telefone")" "401"

jpost "$c/comandas/$eb/entrega/bairro" logradouro="Rua Haddock Lobo" >/dev/null
esperar "rua com um bairro: preenche" "$(json "$tmp/j.json" bairros.0.bairro)" "Cerqueira César"
esperar "rua com um bairro: CEP junto" "$(json "$tmp/j.json" bairros.0.cep)" "01414000"
jpost "$c/comandas/$eb/entrega/bairro" logradouro="Rua Augusta" >/dev/null
esperar "rua com dois bairros: caixa escolhe" "$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["bairros"] ?? []);' "$tmp/j.json")" "2"
jpost "$c/comandas/$eb/entrega/bairro" logradouro="Rua Que Nao Existe" >/dev/null
esperar "rua desconhecida: digitar o bairro" "$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["bairros"] ?? [1]);' "$tmp/j.json")" "0"

esperar "calcular dentro da area" "$(jpost "$c/comandas/$eb/entrega/calcular" logradouro="Rua Haddock Lobo" numero=100 "bairro=Cerqueira César")" "200"
esperar "taxa do bairro configurada" "$(json "$tmp/j.json" taxa_centavos)" "500"
esperar "total = itens + taxa" "$(json "$tmp/j.json" total_centavos)" "$((preco * 2 + 500))"
jpost "$c/comandas/$eb/entrega/calcular" logradouro="Avenida Brigadeiro Faria Lima" numero=3000 "bairro=Itaim Bibi" >/dev/null
esperar "fora da area: sem entrega" "$(json "$tmp/j.json" entrega)" "false"
enviar "$cx" "$c/comandas/$eb/entrega" nome=Bia logradouro="Avenida Brigadeiro Faria Lima" numero=3000 "bairro=Itaim Bibi" quando=agora forma=pix >/dev/null
case "$(aviso "$cx" "$c/comandas/$eb")" in *'O pedido não foi enviado.'*) ok "fora da area bloqueia o envio";; *) falha "fora da area enviou";; esac
enviar "$cx" "$c/comandas/$eb/entrega" nome=Bia logradouro="Rua Haddock Lobo" numero=100 "bairro=Cerqueira César" forma=pix >/dev/null
esperar "sem escolher quando paga recusado" "$(aviso "$cx" "$c/comandas/$eb")" "Escolha quando o cliente paga: agora ou na entrega."
enviar "$cx" "$c/comandas/$eb/entrega" nome=Bia logradouro="Rua Haddock Lobo" numero=100 "bairro=Cerqueira César" quando=entrega forma=dinheiro valor=1,00 >/dev/null
case "$(aviso "$cx" "$c/comandas/$eb")" in *'Troco para:'*) ok "troco menor que o total recusado";; *) falha "troco menor aceito";; esac
esperar "ainda e venda de balcao" "$(banco 'SELECT tipo FROM pedidos WHERE id = ?' "$eb")" "balcao"

r="$(enviar "$cx" "$c/comandas/$eb/entrega" nome=Bia telefone='(85) 97777-0002' logradouro="Rua Haddock Lobo" numero=100 complemento="apto 2" "bairro=Cerqueira César" cep=01414000 quando=agora forma=pix)"
case "$r" in *"/caixa/delivery/$eb"*) ok "preparar pedido leva ao pedido do delivery";; *) falha "preparar pedido ($r)";; esac
case "$(aviso "$cx" "$c/delivery/$eb")" in *'enviado para a cozinha e para o delivery'*'Pix'*'recebido'*) ok "aviso: enviado e Pix recebido";; *) falha "aviso do preparar ($(aviso "$cx" "$c/delivery/$eb"))";; esac
contem "pedido marcado Feito no caixa" "$tmp/aviso.html" 'Feito no caixa'
contem "pedido mostra pago no caixa" "$tmp/aviso.html" 'Pago no caixa (Pix)'
esperar "pedido de entrega em preparo, pago, taxa e endereco" "$(banco 'SELECT tipo, status, pagamento_status, forma_pagamento, taxa_entrega, taxa_servico, entrega_bairro, origem FROM pedidos WHERE id = ?' "$eb")" "entrega|em_preparo|pago|pix_entrega|5.00|0.00|Cerqueira César|balcao"
esperar "pagamento entrou no caixa uma vez" "$(banco 'SELECT COUNT(*), SUM(valor) FROM pedido_pagamentos WHERE id_pedido = ?' "$eb")" "1|$(php -r 'echo number_format(((int) $argv[1] * 2 + 500) / 100, 2, ".", "");' "$preco")"
esperar "ESTOQUE: baixado so no lancamento (2)" "$(php -r "echo $antes - $(estoque);")" "2"
pagina "$cx" "$c/delivery" >/dev/null
contem "fila do delivery com selo Caixa" "$tmp/pagina.html" 'selo-caixa'
enviar "$cx" "$c/delivery/$eb/despachar" entregador="$entregador" >/dev/null
enviar "$cx" "$c/delivery/$eb/concluir" >/dev/null
esperar "pago na hora: conclui sem cobrar de novo" "$(aviso "$cx" "$c/delivery/$eb")" "Pedido #$(banco 'SELECT numero FROM pedidos WHERE id = ?' "$eb") concluído."
esperar "pagamento continua uma vez so" "$(banco 'SELECT COUNT(*) FROM pedido_pagamentos WHERE id_pedido = ?' "$eb")" "1"

r="$(enviar "$cx" "$c/balcao" nome=)"
eb2="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
antes="$(estoque)"
enviar "$cx" "$c/comandas/$eb2/itens" produto="$produto" quantidade=1 >/dev/null
enviar "$cx" "$c/comandas/$eb2/entrega" nome=Caio logradouro="Rua Haddock Lobo" numero=200 "bairro=Cerqueira César" quando=entrega forma=debito >/dev/null
esperar "na entrega: a receber em debito" "$(banco 'SELECT status, pagamento_status, forma_pagamento, total FROM pedidos WHERE id = ?' "$eb2")" "em_preparo|pendente|debito|$(php -r 'echo number_format(((int) $argv[1] + 500) / 100, 2, ".", "");' "$preco")"
esperar "na entrega: nada entra no caixa ainda" "$(banco 'SELECT COUNT(*) FROM pedido_pagamentos WHERE id_pedido = ?' "$eb2")" "0"
enviar "$cx" "$c/delivery/$eb2/cancelar" "motivo=Cliente desistiu da entrega" >/dev/null
esperar "ESTOQUE: cancelar a entrega do caixa devolve" "$(estoque)" "$antes"

echo "Taxa por forma de pagamento nas contas (caixa, mesa e balcao que vira entrega)"
# So cartao e Pix aqui: o dinheiro da gaveta e conferido logo abaixo, no fechamento.
rs() { php -r 'echo "R$ " . number_format($argv[1] / 100, 2, ",", ".");' "$1"; }
dec() { php -r 'echo number_format($argv[1] / 100, 2, ".", "");' "$1"; }
campo_valor() { php -r 'echo number_format($argv[1] / 100, 2, ",", "");' "$1"; }
rid="$(banco 'SELECT id FROM restaurantes WHERE slug = ?' "$slug")"
php -r 'require "bootstrap/app.php"; $d = \Cardapim\Core\Database::connect(); $d->prepare("DELETE FROM restaurante_taxas_pagamento WHERE id_restaurante = ?")->execute([$argv[1]]); $i = $d->prepare("INSERT INTO restaurante_taxas_pagamento (id_restaurante, forma, tipo, valor) VALUES (?, ?, ?, ?)"); foreach ([["credito", "percentual", "3.50"], ["debito", "fixo", "1.00"], ["pix_entrega", "percentual", "1.00"]] as $r) { $i->execute([$argv[1], ...$r]); }' "$rid"
estoque_taxa="$(estoque)"
r="$(enviar "$cx" "$c/balcao" nome=Taxa)"
tb="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
enviar "$cx" "$c/comandas/$tb/itens" produto="$produto" quantidade=2 >/dev/null
tconta=$((preco * 2))
pagina "$cx" "$c/comandas/$tb/pagar" >/dev/null
contem "taxa do credito ao lado da forma" "$tmp/pagina.html" '+ 3,5%'
contem "taxa do debito ao lado da forma" "$tmp/pagina.html" '+ R$ 1,00'
contem "regras da taxa para a tela" "$tmp/pagina.html" 'data-pagar-taxas='
contem "bloco Cobrar do cliente" "$tmp/pagina.html" 'Cobrar do cliente'
parte=$((tconta / 2)); tx=$(( (parte * 350 + 5000) / 10000 ))
enviar "$cx" "$c/comandas/$tb/pagamentos" forma=credito "valor=$(campo_valor "$parte")" >/dev/null
esperar "sem a taxa vista: avisa a taxa e nao grava" "$(aviso "$cx" "$c/comandas/$tb/pagar")" "Crédito tem taxa de $(rs "$tx"). Cobre $(rs $((parte + tx))) do cliente e toque em Receber de novo."
contem "o segundo toque ja leva a taxa certa" "$tmp/aviso.html" "name=\"taxa_vista\" value=\"$tx\""
esperar "nada gravado antes de confirmar a taxa" "$(banco 'SELECT COUNT(*) FROM pedido_pagamentos WHERE id_pedido = ?' "$tb")" "0"
enviar "$cx" "$c/comandas/$tb/pagamentos" forma=credito "valor=$(campo_valor "$parte")" taxa_vista=1 >/dev/null
case "$(aviso "$cx" "$c/comandas/$tb/pagar")" in "Crédito tem taxa de "*) ok "taxa diferente da tela recusada";; *) falha "taxa diferente da tela aceita";; esac
enviar "$cx" "$c/comandas/$tb/pagamentos" forma=credito "valor=$(campo_valor "$parte")" "taxa_vista=$tx" >/dev/null
esperar "credito com taxa recebido" "$(aviso "$cx" "$c/comandas/$tb/pagar")" "Crédito $(rs $((parte + tx))) recebido (inclui taxa de $(rs "$tx"))."
contem "falta pagar continua sem a taxa" "$tmp/aviso.html" "data-pagar-restante=\"$((tconta - parte))\""
contem "ja recebido mostra a taxa" "$tmp/aviso.html" "inclui taxa de $(rs "$tx")"
esperar "pagamento guarda valor com taxa e o rotulo" "$(banco 'SELECT valor, taxa, taxa_rotulo FROM pedido_pagamentos WHERE id_pedido = ?' "$tb")" "$(dec $((parte + tx)))|$(dec "$tx")|Taxa do crédito (3,5%)"
esperar "conta soma a taxa no total" "$(banco 'SELECT subtotal, taxa_pagamento, total FROM pedidos WHERE id = ?' "$tb")" "$(dec "$tconta")|$(dec "$tx")|$(dec $((tconta + tx)))"
pg="$(banco 'SELECT id FROM pedido_pagamentos WHERE id_pedido = ?' "$tb")"
enviar "$cx" "$c/comandas/$tb/pagamentos/$pg/remover" >/dev/null
esperar "remover o pagamento devolve a taxa" "$(aviso "$cx" "$c/comandas/$tb/pagar")" "Pagamento removido. A taxa de $(rs "$tx") saiu da conta."
esperar "conta volta sem taxa" "$(banco 'SELECT taxa_pagamento, total FROM pedidos WHERE id = ?' "$tb")" "0.00|$(dec "$tconta")"
enviar "$cx" "$c/comandas/$tb/pagamentos" forma=credito "valor=$(campo_valor "$parte")" "taxa_vista=$tx" >/dev/null
resto=$((tconta - parte))
enviar "$cx" "$c/comandas/$tb/pagamentos" forma=debito valor= taxa_vista=100 >/dev/null
esperar "debito com taxa fixa fecha a conta" "$(aviso "$cx" "$c/comandas/$tb")" "Débito $(rs $((resto + 100))) recebido (inclui taxa de R\$ 1,00). Conta fechada."
esperar "conta fechada com as duas taxas" "$(banco 'SELECT status, taxa_pagamento, taxa_pagamento_rotulo, total FROM pedidos WHERE id = ?' "$tb")" "concluido|$(dec $((tx + 100)))|Taxas de pagamento|$(dec $((tconta + tx + 100)))"
esperar "pagamentos fecham com o total (nota fiscal)" "$(banco 'SELECT SUM(valor) FROM pedido_pagamentos WHERE id_pedido = ?' "$tb")" "$(dec $((tconta + tx + 100)))"

r="$(enviar "$cx" "$c/mesas/$m1/abrir")"
tm="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
enviar "$cx" "$c/comandas/$tm/itens" produto="$produto" quantidade=1 >/dev/null
serv=$(( (preco * 1000 + 5000) / 10000 ))
enviar "$cx" "$c/comandas/$tm/pagamentos" forma=pix valor=5,00 taxa_vista=5 >/dev/null
esperar "Pix registrado usa a taxa do Pix no caixa" "$(aviso "$cx" "$c/comandas/$tm/pagar")" "Pix R\$ 5,05 recebido (inclui taxa de R\$ 0,05)."
enviar "$cx" "$c/comandas/$tm/ajustes" servico=1 desconto=1,00 "motivo_desconto=Cliente da casa" >/dev/null
esperar "desconto depois de um pagamento com taxa" "$(aviso "$cx" "$c/comandas/$tm")" "Conta atualizada."
esperar "total = itens + 10% + taxa - desconto" "$(banco 'SELECT total FROM pedidos WHERE id = ?' "$tm")" "$(dec $((preco + serv + 5 - 100)))"
contem "linha da taxa na conta da comanda" "$tmp/aviso.html" 'Taxa do Pix (1%)'
enviar "$cx" "$c/comandas/$tm/ajustes" servico=0 desconto=1,00 "motivo_desconto=Cliente da casa" >/dev/null
esperar "tirar os 10% mantem a taxa" "$(banco 'SELECT total FROM pedidos WHERE id = ?' "$tm")" "$(dec $((preco + 5 - 100)))"
resto=$((preco - 100 - 500)); tx2=$(( (resto * 350 + 5000) / 10000 ))
enviar "$cx" "$c/comandas/$tm/pagamentos" forma=credito valor= "taxa_vista=$tx2" >/dev/null
case "$(aviso "$cx" "$c/comandas/$tm")" in *"Conta fechada e mesa liberada."*) ok "resto no credito com taxa fecha a mesa";; *) falha "mesa com taxa nao fechou";; esac
esperar "mesa fechada com as taxas" "$(banco 'SELECT status, taxa_pagamento, total FROM pedidos WHERE id = ?' "$tm")" "concluido|$(dec $((5 + tx2)))|$(dec $((preco - 100 + 5 + tx2)))"

r="$(enviar "$cx" "$c/balcao" nome=)"
te="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
enviar "$cx" "$c/comandas/$te/itens" produto="$produto" quantidade=1 >/dev/null
base=$((preco + 500)); tx3=$(( (base * 350 + 5000) / 10000 ))
jpost "$c/comandas/$te/entrega/calcular" logradouro="Rua Haddock Lobo" numero=100 "bairro=Cerqueira César" >/dev/null
esperar "calculo da entrega traz a taxa do credito" "$(json "$tmp/j.json" taxas_pagamento.credito.centavos)" "$tx3"
esperar "calculo da entrega traz o total com a taxa" "$(json "$tmp/j.json" taxas_pagamento.credito.total_centavos)" "$((base + tx3))"
esperar "dinheiro sem taxa na entrega" "$(json "$tmp/j.json" taxas_pagamento.dinheiro)" "null"
enviar "$cx" "$c/comandas/$te/entrega" nome=Dani logradouro="Rua Haddock Lobo" numero=100 "bairro=Cerqueira César" quando=agora forma=credito taxa_vista=1 >/dev/null
esperar "taxa diferente na entrega recusada" "$(aviso "$cx" "$c/comandas/$te")" "A taxa da forma de pagamento mudou. Confira o total na aba Entrega e envie de novo."
r="$(enviar "$cx" "$c/comandas/$te/entrega" nome=Dani logradouro="Rua Haddock Lobo" numero=100 "bairro=Cerqueira César" quando=agora forma=credito "taxa_vista=$tx3")"
case "$(aviso "$cx" "$c/delivery/$te")" in *"recebido (inclui taxa de $(rs "$tx3"))."*) ok "entrega paga agora no credito com taxa";; *) falha "aviso da entrega com taxa ($(aviso "$cx" "$c/delivery/$te"))";; esac
esperar "pedido de entrega com a taxa no total" "$(banco 'SELECT tipo, pagamento_status, taxa_pagamento, taxa_pagamento_rotulo, total FROM pedidos WHERE id = ?' "$te")" "entrega|pago|$(dec "$tx3")|Taxa do crédito (3,5%)|$(dec $((base + tx3)))"
esperar "pagamento da entrega com a taxa" "$(banco 'SELECT COUNT(*), SUM(valor), SUM(taxa) FROM pedido_pagamentos WHERE id_pedido = ?' "$te")" "1|$(dec $((base + tx3)))|$(dec "$tx3")"
enviar "$cx" "$c/delivery/$te/despachar" entregador="$entregador" >/dev/null
enviar "$cx" "$c/delivery/$te/concluir" >/dev/null
esperar "concluir a entrega paga nao cobra de novo" "$(banco 'SELECT status, (SELECT COUNT(*) FROM pedido_pagamentos WHERE id_pedido = pedidos.id) FROM pedidos WHERE id = ?' "$te")" "concluido|1"
r="$(enviar "$cx" "$c/balcao" nome=)"
te2="$(echo "$r" | grep -o 'comandas/[0-9]*' | cut -d/ -f2)"
enviar "$cx" "$c/comandas/$te2/itens" produto="$produto" quantidade=1 >/dev/null
enviar "$cx" "$c/comandas/$te2/entrega" nome=Edu logradouro="Rua Haddock Lobo" numero=300 "bairro=Cerqueira César" quando=entrega forma=debito taxa_vista=100 >/dev/null
esperar "na entrega no debito: total ja com a taxa" "$(banco 'SELECT pagamento_status, forma_pagamento, taxa_pagamento, total FROM pedidos WHERE id = ?' "$te2")" "pendente|debito|1.00|$(dec $((base + 100)))"
enviar "$cx" "$c/delivery/$te2/cancelar" "motivo=Teste da taxa" >/dev/null
php -r 'require "bootstrap/app.php"; \Cardapim\Core\Database::connect()->prepare("DELETE FROM restaurante_taxas_pagamento WHERE id_restaurante = ?")->execute([$argv[1]]);' "$rid"
vendidas="$(php -r "echo (int) round($estoque_taxa - $(estoque));")"
[[ "$vendidas" -gt 0 ]] && enviar "$gj" "$g/estoque/insumos/$insumo/repor" "quantidade=$vendidas" >/dev/null
esperar "estoque reposto depois dos testes da taxa" "$(estoque)" "$estoque_taxa"

echo "Fechar o caixa (contagem cega)"
pagina "$cx" "$c" >/dev/null
contem "resumo mostra vendas em dinheiro" "$tmp/pagina.html" 'Dinheiro na gaveta'
esperado="$(php -r 'if (preg_match("~Dinheiro na gaveta</span>\s*<strong>R\\$ ([\d.,]+)</strong>~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/pagina.html")"
# 100 de troco + dinheiro da mesa (o que faltava) + conta do Joao (1 item + 10%) + delivery em dinheiro + 50 de reforco - 30 de despesa
joao=$((preco + (preco * 1000 + 5000) / 10000))
conta=$((10000 + restante + joao + preco * 2 + 500 + 5000 - 3000))
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

echo "Comandas do periodo"
curl -s -b "$cx" "$c/comandas?periodo=mes&status=todas" -o "$tmp/hist.html"
contem "lista de comandas do periodo" "$tmp/hist.html" 'Comandas do período'
contem "filtro de periodo" "$tmp/hist.html" 'Este mês'
contem "comanda fechada na lista" "$tmp/hist.html" 'Fechada'
det="$(php -r 'if (preg_match("~/comandas/(\d+)/detalhes~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/hist.html")"
[[ -n "$det" ]] && ok "link para o detalhe ($det)" || falha "sem link para o detalhe"
curl -s -b "$cx" "$c/comandas/$det/detalhes" -o "$tmp/det.html"
contem "detalhe mostra quem e quando" "$tmp/det.html" 'Quem e quando'
contem "detalhe mostra pagamentos" "$tmp/det.html" 'Pagamentos'
contem "detalhe mostra o historico" "$tmp/det.html" 'Histórico'
curl -s -b "$cx" "$c/comandas?periodo=hoje&status=canceladas" -o "$tmp/hist.html"
contem "filtro de canceladas marcado" "$tmp/hist.html" 'status=canceladas" aria-current="true"'
esperar "comanda de outro restaurante ou inexistente" "$(curl -s -o /dev/null -b "$cx" -w '%{http_code}' "$c/comandas/999999999/detalhes")" "303"

echo "Limpeza"
t="$(token "$aj" "$base/painel/integracoes/whatsapp")"
curl -s -o /dev/null -c "$aj" -b "$aj" --data-urlencode "_csrf=$t" "$base/painel/integracoes/whatsapp/desconectar"
ok "WhatsApp de teste desligado"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no caixa."
  exit 1
fi
echo "OK: modo caixa (mesas, comandas, caixa, entradas/saidas, delivery com despacho e baixa de estoque)."
