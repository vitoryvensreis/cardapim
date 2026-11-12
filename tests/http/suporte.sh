#!/usr/bin/env bash
# Teste HTTP do SUPORTE (gerente e plataforma), das FATURAS da plataforma e do E-MAIL DE TESTE:
# gerente abre, responde e resolve chamado; outro restaurante nao ve; caixa nao entra; administrador lista, responde,
# muda a situacao e ve o selo no menu; avisos por e-mail (MAIL_DRIVER=log); faturas sem quebrar; e-mail de teste.
# Uso: bash tests/http/suporte.sh <base> <slug-do-restaurante-modelo>
set -Eeuo pipefail
base="$1"
slug="$2"
senha='Senha#CI2026'
p="$base/painel"
g="$base/$slug/gerencia"
raiz="$(cd "$(dirname "$0")/../.." && pwd)"
maillog="$raiz/storage/logs/mail.log"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0
id="$(date +%s)$RANDOM"

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
token() { curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
login() {
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$3" "$base/login"
}
enviar() { # jar pagina-do-token url campos... -> "codigo location"
  local jar="$1" origem="$2" url="$3" t args=(); shift 3
  t="$(token "$jar" "$origem")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -c "$1" -w '%{http_code}' "$2"; }
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
esperar_log() { # texto -> espera o envio depois da resposta (ate 3 s)
  for _ in 1 2 3 4 5 6; do grep -q -- "$1" "$maillog" 2>/dev/null && return 0; sleep 0.5; done; return 1
}

aj="$tmp/adm"; gj="$tmp/gerente"; cj="$tmp/caixa"; bj="$tmp/outro"
login "$aj" ci_master "$senha" >/dev/null
login "$gj" ci_gerente "$senha" >/dev/null
login "$cj" ci_caixa "$senha" >/dev/null

echo "Gerente: abrir chamado"
esperar "gerente abre Suporte" "$(pagina "$gj" "$g/suporte")" "200"
nao_contem "suporte nao esta mais em construcao" "$tmp/pagina.html" 'Em construção'
contem "botao para abrir chamado" "$tmp/pagina.html" "$slug/gerencia/suporte/novo"
esperar "formulario do chamado" "$(pagina "$gj" "$g/suporte/novo")" "200"
esperar "caixa nao entra no suporte (403)" "$(pagina "$cj" "$g/suporte")" "403"
esperar "sem dados nao abre (422)" "$(enviar "$gj" "$g/suporte/novo" "$g/suporte" assunto= categoria=xx mensagem=curto | cut -d' ' -f1)" "422"
contem "explica o assunto" "$tmp/resp.html" 'Escreva o assunto'
contem "explica a mensagem" "$tmp/resp.html" 'Conte o que aconteceu'
r="$(enviar "$gj" "$g/suporte/novo" "$g/suporte" "assunto=Impressora CI $id" categoria=problema "mensagem=A impressora da cozinha parou de imprimir <b>hoje</b>.")"
cid="$(printf '%s' "$r" | grep -o '/suporte/[0-9]*' | grep -o '[0-9]*$' || true)"
[[ -n "$cid" ]] && ok "chamado aberto (#$cid)" || falha "abrir chamado nao redirecionou ($r)"
aviso "$gj" "$g/suporte/$cid" > "$tmp/msg.txt"
contem "aviso de chamado aberto" "$tmp/msg.txt" "Chamado #$cid"
contem "conversa mostra a mensagem" "$tmp/aviso.html" 'A impressora da cozinha parou'
contem "texto escapado (sem HTML)" "$tmp/aviso.html" '&lt;b&gt;hoje&lt;/b&gt;'
contem "selo aguardando o suporte" "$tmp/aviso.html" 'Aguardando o suporte'
if esperar_log "\[Suporte\] Chamado #$cid"; then ok "administrador avisado por e-mail"; else falha "e-mail do chamado novo nao registrado"; fi
esperar "chamado inexistente volta para a lista" "$(pagina "$gj" "$g/suporte/99999999")" "303"

echo "Plataforma: listar e responder"
esperar "administrador abre Suporte" "$(pagina "$aj" "$p/suporte")" "200"
nao_contem "suporte da plataforma nao esta em construcao" "$tmp/pagina.html" 'Em construção'
contem "chamado na lista" "$tmp/pagina.html" "Impressora CI $id"
contem "selo no menu da plataforma" "$tmp/pagina.html" 'painel-tag">[0-9]* novo'
esperar "busca pelo numero" "$(pagina "$aj" "$p/suporte?busca=$cid&filtro=todos")" "200"
contem "busca acha o chamado" "$tmp/pagina.html" "Impressora CI $id"
esperar "busca estranha nao quebra" "$(pagina "$aj" "$p/suporte?busca=%25_%27&filtro=xx&pagina=-2")" "200"
esperar "gerente nao abre o suporte da plataforma (403)" "$(pagina "$gj" "$p/suporte")" "403"
esperar "administrador abre o chamado" "$(pagina "$aj" "$p/suporte/$cid")" "200"
contem "mostra o restaurante" "$tmp/pagina.html" "/painel/restaurantes/"
esperar "resposta vazia nao vai" "$(enviar "$aj" "$p/suporte/$cid" "$p/suporte/$cid/responder" mensagem= | cut -d' ' -f1)" "303"
aviso "$aj" "$p/suporte/$cid" > "$tmp/msg.txt"
contem "explica a resposta vazia" "$tmp/msg.txt" 'Escreva'
esperar "administrador responde" "$(enviar "$aj" "$p/suporte/$cid" "$p/suporte/$cid/responder" "mensagem=Reinicie a impressora $id, por favor.")" "303 $p/suporte/$cid#fim"
if esperar_log "Chamado #$cid respondido"; then ok "gerente avisado por e-mail"; else falha "e-mail da resposta nao registrado"; fi
pagina "$aj" "$p/suporte/$cid" >/dev/null
contem "selo esperando o restaurante" "$tmp/pagina.html" 'Esperando o restaurante'

echo "Gerente: resposta nova e resolver"
pagina "$gj" "$g/visao-geral" >/dev/null
contem "selo de resposta nova no menu do gerente" "$tmp/pagina.html" 'Resposta nova'
pagina "$gj" "$g/suporte/$cid" >/dev/null
contem "gerente ve a resposta" "$tmp/pagina.html" "Reinicie a impressora $id"
pagina "$gj" "$g/visao-geral" >/dev/null
nao_contem "selo some depois de ver" "$tmp/pagina.html" 'Resposta nova'
esperar "gerente resolve" "$(enviar "$gj" "$g/suporte/$cid" "$g/suporte/$cid/resolver" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/suporte?filtro=resolvidos" >/dev/null
contem "chamado em resolvidos" "$tmp/pagina.html" "Impressora CI $id"
esperar "gerente responde de novo" "$(enviar "$gj" "$g/suporte/$cid" "$g/suporte/$cid/responder" "mensagem=Voltou a falhar depois de uma hora.")" "303 $g/suporte/$cid#fim"
pagina "$gj" "$g/suporte/$cid" >/dev/null
contem "resposta reabre o chamado" "$tmp/pagina.html" 'Aguardando o suporte'

echo "Plataforma: situacao"
esperar "marca em atendimento" "$(enviar "$aj" "$p/suporte/$cid" "$p/suporte/$cid/status" status=em_atendimento)" "303 $p/suporte/$cid"
esperar "situacao invalida nao muda" "$(enviar "$aj" "$p/suporte/$cid" "$p/suporte/$cid/status" status=apagado | cut -d' ' -f1)" "303"
pagina "$gj" "$g/suporte/$cid" >/dev/null
contem "gerente ve em atendimento" "$tmp/pagina.html" 'Em atendimento'
esperar "responde e resolve junto" "$(enviar "$aj" "$p/suporte/$cid" "$p/suporte/$cid/responder" "mensagem=Trocamos o cabo, resolvido." resolver=1 | cut -d' ' -f1)" "303"
pagina "$gj" "$g/suporte/$cid" >/dev/null
contem "gerente ve a resposta final" "$tmp/pagina.html" 'Trocamos o cabo'
pagina "$aj" "$p/suporte?filtro=resolvidos" >/dev/null
contem "chamado resolvido na plataforma" "$tmp/pagina.html" "Impressora CI $id"
esperar "sem CSRF nao responde (303 sem mudar)" "$(curl -s -o /dev/null -b "$aj" -w '%{http_code}' --data 'mensagem=sem token' "$p/suporte/$cid/responder")" "303"
pagina "$aj" "$p/suporte/$cid" >/dev/null
nao_contem "mensagem sem token nao gravada" "$tmp/pagina.html" 'sem token'

echo "Isolamento entre restaurantes"
enviar "$aj" "$p/planos" "$p/planos" "nome=Suporte CI $id" periodo=vitalicio valor=0 visibilidade=privado ativo=1 >/dev/null
pid="$(curl -s -b "$aj" "$p/planos" | php -r '$h = stream_get_contents(STDIN); if (preg_match("~id=\"plano-(\d+)\".{0,400}?" . preg_quote($argv[1], "~") . "~s", $h, $m)) echo $m[1];' "Suporte CI $id")"
doc="$(php -r '$b = str_pad((string) random_int(10000000, 99999999), 8, "0") . "0001"; $c = static function (string $s, array $p): int { $t = 0; foreach ($p as $i => $w) { $t += (int) $s[$i] * $w; } $r = $t % 11; return $r < 2 ? 0 : 11 - $r; }; $b .= $c($b, [5,4,3,2,9,8,7,6,5,4,3,2]); $b .= $c($b, [6,5,4,3,2,9,8,7,6,5,4,3,2]); echo $b;')"
enviar "$aj" "$p/restaurantes/novo" "$p/restaurantes" "plano_id=$pid" "nome_restaurante=Outro Suporte $id" telefone=85988887777 "cnpj=$doc" \
  nome=Bia sobrenome=Teste "email=bia.$id@example.test" "usuario=bia.$id" senha=Praia-milho-4821 >/dev/null
r="$(login "$bj" "bia.$id" Praia-milho-4821)"
bslug="$(printf '%s' "$r" | sed -E 's#.*/([^/]+)/gerencia$#\1#')"
[[ -n "$bslug" && "$bslug" != "$r" ]] && ok "outro restaurante criado ($bslug)" || falha "outro restaurante nao entrou ($r)"
esperar "outro gerente nao abre o chamado (volta)" "$(pagina "$bj" "$base/$bslug/gerencia/suporte/$cid")" "303"
aviso "$bj" "$base/$bslug/gerencia/suporte" > "$tmp/msg.txt"
contem "explica chamado nao encontrado" "$tmp/msg.txt" 'Chamado não encontrado'
nao_contem "lista do outro nao mostra o chamado" "$tmp/aviso.html" "Impressora CI $id"
esperar "outro gerente nao responde (volta)" "$(enviar "$bj" "$base/$bslug/gerencia/suporte" "$base/$bslug/gerencia/suporte/$cid/responder" "mensagem=Mensagem intrusa de outro restaurante." | cut -d' ' -f1)" "303"
esperar "outro gerente nao usa o slug alheio (403)" "$(pagina "$bj" "$g/suporte/$cid")" "403"
pagina "$aj" "$p/suporte/$cid" >/dev/null
nao_contem "mensagem intrusa nao gravada" "$tmp/pagina.html" 'Mensagem intrusa'

echo "Faturas"
esperar "administrador abre Faturas" "$(pagina "$aj" "$p/faturas")" "200"
nao_contem "faturas nao esta mais em construcao" "$tmp/pagina.html" 'Em construção'
contem "indicador recebido no mes" "$tmp/pagina.html" 'Recebido em'
contem "indicador vencido" "$tmp/pagina.html" 'Vencido'
esperar "filtros estranhos nao quebram" "$(pagina "$aj" "$p/faturas?situacao=xx&mes=2026-13&busca=%25_&ambiente=zz&pagina=-1")" "200"
esperar "filtro por situacao e mes" "$(pagina "$aj" "$p/faturas?situacao=paga&mes=2026-11")" "200"
esperar "faturas de teste" "$(pagina "$aj" "$p/faturas?ambiente=sandbox")" "200"
esperar "gerente nao abre faturas (403)" "$(pagina "$gj" "$p/faturas")" "403"
esperar "gerente nao atualiza faturas (403)" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -X POST "$p/faturas/atualizar")" "403"
esperar "atualizar agora responde" "$(enviar "$aj" "$p/faturas" "$p/faturas/atualizar" | cut -d' ' -f1)" "303"

echo "E-mail de teste"
esperar "configuracoes abre" "$(pagina "$aj" "$p/configuracoes")" "200"
contem "bloco de e-mail" "$tmp/pagina.html" 'E-mail do sistema'
contem "mostra o modo de envio" "$tmp/pagina.html" 'Somente registro'
nao_contem "nunca mostra senha" "$tmp/pagina.html" 'MAIL_PASSWORD'
esperar "e-mail invalido nao envia" "$(enviar "$aj" "$p/configuracoes" "$p/configuracoes/email-teste" para=nao-e-email)" "303 $p/configuracoes#email"
aviso "$aj" "$p/configuracoes" > "$tmp/msg.txt"
contem "explica o e-mail invalido" "$tmp/msg.txt" 'e-mail válido'
esperar "envia o teste" "$(enviar "$aj" "$p/configuracoes" "$p/configuracoes/email-teste" "para=teste.$id@example.test")" "303 $p/configuracoes#email"
aviso "$aj" "$p/configuracoes" > "$tmp/msg.txt"
contem "aviso de teste enviado" "$tmp/msg.txt" "teste.$id@example.test"
if grep -q "Para: teste.$id@example.test | Assunto: Teste de e-mail" "$maillog"; then ok "teste registrado no log"; else falha "teste nao registrado no log"; fi
esperar "gerente nao manda e-mail de teste (403)" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -X POST "$p/configuracoes/email-teste")" "403"

echo
if [[ "$falhas" -gt 0 ]]; then echo "FALHOU: $falhas verificacao(oes) do suporte."; exit 1; fi
echo "OK: suporte, faturas e e-mail de teste."
