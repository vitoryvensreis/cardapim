#!/usr/bin/env bash
# Teste HTTP do painel da plataforma > RESTAURANTES e USUARIOS: acesso so do administrador, cadastrar restaurante com gerente
# e plano (inclusive privado), editar, suspender/liberar, trocar plano, excluir (so sem movimento) e as contas: criar, editar,
# senha, bloquear/liberar, 2FA, excluir e as protecoes (a propria conta, ultimo administrador).
# Uso: bash tests/http/adm-gestao.sh <base> <slug-do-restaurante-modelo>
set -Eeuo pipefail
base="$1"
slug="$2"
senha='Senha#CI2026'
p="$base/painel"
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
login() { # jar usuario senha -> "codigo location"
  local t; rm -f "$1"; t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$3" "$base/login"
}
enviar() { # jar url campos... -> "codigo location"
  local jar="$1" url="$2" t args=(); shift 2
  t="$(token "$jar" "$p/usuarios")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -c "$1" -w '%{http_code}' "$2"; }
aviso() { curl -s -c "$1" -b "$1" "$2" > "$tmp/aviso.html"; php -r 'if (preg_match("~data-flash>.*?<p>(.*?)</p>~s", file_get_contents($argv[1]), $m)) echo html_entity_decode($m[1]);' "$tmp/aviso.html"; }
cnpj() { php -r '$b = str_pad((string) random_int(10000000, 99999999), 8, "0") . "0001"; $c = static function (string $s, array $p): int { $t = 0; foreach ($p as $i => $w) { $t += (int) $s[$i] * $w; } $r = $t % 11; return $r < 2 ? 0 : 11 - $r; }; $b .= $c($b, [5,4,3,2,9,8,7,6,5,4,3,2]); $b .= $c($b, [6,5,4,3,2,9,8,7,6,5,4,3,2]); echo $b;'; }
plano_id() { curl -s -b "$aj" "$p/planos" | php -r '$h = stream_get_contents(STDIN); if (preg_match("~id=\"plano-(\d+)\".{0,400}?" . preg_quote($argv[1], "~") . "~s", $h, $m)) echo $m[1];' "$1"; }

aj="$tmp/adm"; gj="$tmp/gerente"; nj="$tmp/novo"
login "$aj" ci_master "$senha" >/dev/null
login "$gj" ci_gerente "$senha" >/dev/null

echo "Acesso"
esperar "administrador abre restaurantes" "$(pagina "$aj" "$p/restaurantes")" "200"
nao_contem "restaurantes nao esta mais em construcao" "$tmp/pagina.html" 'Em construção'
contem "lista mostra o restaurante modelo" "$tmp/pagina.html" "/$slug"
esperar "administrador abre usuarios" "$(pagina "$aj" "$p/usuarios")" "200"
nao_contem "usuarios nao esta mais em construcao" "$tmp/pagina.html" 'Em construção'
contem "lista mostra o gerente de teste" "$tmp/pagina.html" 'ci_gerente'
esperar "gerente nao abre restaurantes (403)" "$(pagina "$gj" "$p/restaurantes")" "403"
esperar "gerente nao abre usuarios (403)" "$(pagina "$gj" "$p/usuarios/1")" "403"
esperar "gerente nao cria conta (403)" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -X POST "$p/usuarios")" "403"
esperar "busca por restaurante" "$(pagina "$aj" "$p/restaurantes?busca=modelo&status=ativo")" "200"
contem "busca acha o modelo" "$tmp/pagina.html" "/$slug"
esperar "busca estranha nao quebra" "$(pagina "$aj" "$p/usuarios?busca=%25_%27&cargo=xx&pagina=-3")" "200"

echo "Restaurantes: cadastrar"
enviar "$aj" "$p/planos" "nome=Gestao CI $id" periodo=vitalicio valor=0 visibilidade=privado ativo=1 >/dev/null
enviar "$aj" "$p/planos" "nome=Gestao CI B $id" periodo=vitalicio valor=0 visibilidade=privado ativo=1 >/dev/null
pa="$(plano_id "Gestao CI $id")"; pb="$(plano_id "Gestao CI B $id")"
[[ -n "$pa" && -n "$pb" ]] && ok "planos privados de teste criados" || falha "planos de teste nao encontrados"
esperar "sem dados nao cadastra (422)" "$(enviar "$aj" "$p/restaurantes" "plano_id=$pa" nome_restaurante= | cut -d' ' -f1)" "422"
contem "explica o que falta" "$tmp/resp.html" 'Digite o nome do restaurante'
doc="$(cnpj)"
r="$(enviar "$aj" "$p/restaurantes" "plano_id=$pa" "nome_restaurante=Cantina Gestao $id" telefone=85988887777 "cnpj=$doc" \
  nome=Maria sobrenome=Teste "email=maria.$id@example.test" "usuario=maria.$id" senha=Praia-milho-4821)"
rid="$(printf '%s' "$r" | grep -o '/painel/restaurantes/[0-9]*' | grep -o '[0-9]*$')"
[[ -n "$rid" ]] && ok "restaurante cadastrado (id $rid)" || falha "cadastro nao redirecionou ($r)"
aviso "$aj" "$p/restaurantes/$rid" > "$tmp/msg.txt"
contem "aviso de cadastro" "$tmp/msg.txt" "Restaurante \"Cantina Gestao $id\" cadastrado"
contem "tela do restaurante mostra o gerente" "$tmp/aviso.html" "maria.$id"
contem "plano privado aplicado" "$tmp/aviso.html" "Gestao CI $id"
rslug="$(grep -o '/cantina-gestao-[0-9a-z-]*' "$tmp/aviso.html" | head -1 | tr -d '/')"
esperar "gerente novo entra no proprio painel" "$(login "$nj" "maria.$id" Praia-milho-4821)" "303 $base/$rslug/gerencia"
esperar "CNPJ repetido nao cadastra (422)" "$(enviar "$aj" "$p/restaurantes" "plano_id=$pa" "nome_restaurante=Outra $id" telefone=85988887777 "cnpj=$doc" nome=Joao sobrenome=Teste "email=joao.$id@example.test" "usuario=joao.$id" senha=Praia-milho-4821 | cut -d' ' -f1)" "422"
contem "explica o CNPJ" "$tmp/resp.html" 'já possui um restaurante'

echo "Restaurantes: editar, situacao, plano"
esperar "CNPJ invalido na edicao (422)" "$(enviar "$aj" "$p/restaurantes/$rid" "nome=Cantina Gestao $id" telefone=85988887777 cnpj=11111111111111 | cut -d' ' -f1)" "422"
esperar "edita o nome" "$(enviar "$aj" "$p/restaurantes/$rid" "nome=Cantina Editada $id" telefone=85988887777 "cnpj=$doc")" "303 $p/restaurantes/$rid"
pagina "$aj" "$p/restaurantes/$rid" >/dev/null
contem "nome novo" "$tmp/pagina.html" "Cantina Editada $id"
esperar "suspender exige motivo" "$(enviar "$aj" "$p/restaurantes/$rid/status" status=suspenso motivo= >/dev/null; aviso "$aj" "$p/restaurantes/$rid")" "Escreva o motivo (aparece no histórico da plataforma)."
esperar "suspende" "$(enviar "$aj" "$p/restaurantes/$rid/status" status=suspenso "motivo=Teste CI" | cut -d' ' -f1)" "303"
esperar "gerente suspenso perde a sessao (volta ao login)" "$(curl -s -o /dev/null -b "$nj" -w '%{http_code}' "$base/$rslug/gerencia")" "302"
esperar "gerente suspenso nao consegue entrar" "$(login "$tmp/susp" "maria.$id" Praia-milho-4821 | cut -d' ' -f1)" "403"
esperar "cardapio suspenso fora do ar" "$(curl -s -o /dev/null -w '%{http_code}' "$base/$rslug")" "404"
esperar "situacao invalida" "$(enviar "$aj" "$p/restaurantes/$rid/status" status=pendente motivo=x >/dev/null; aviso "$aj" "$p/restaurantes/$rid")" "Escolha uma situação válida."
enviar "$aj" "$p/restaurantes/$rid/status" status=ativo >/dev/null; aviso "$aj" "$p/restaurantes/$rid" > "$tmp/msg.txt"
contem "libera de novo" "$tmp/msg.txt" "liberado: a equipe já pode entrar"
esperar "troca para outro plano privado" "$(enviar "$aj" "$p/restaurantes/$rid/plano" "plano_id=$pb" | cut -d' ' -f1)" "303"
pagina "$aj" "$p/restaurantes/$rid" >/dev/null
contem "plano novo na tela" "$tmp/pagina.html" "<strong>Gestao CI B $id</strong>"

echo "Usuarios"
rmodelo="$(curl -s -b "$aj" "$p/restaurantes?busca=$slug" | grep -o '/painel/restaurantes/[0-9]*"' | head -1 | grep -o '[0-9]*')"
esperar "conta sem restaurante (422)" "$(enviar "$aj" "$p/usuarios" cargo=caixa id_restaurante= nome=Ana sobrenome=Caixa "usuario=ana.$id" senha=Praia-milho-4821 | cut -d' ' -f1)" "422"
contem "pede o restaurante" "$tmp/resp.html" 'Escolha o restaurante desta pessoa'
esperar "administrador sem e-mail (422)" "$(enviar "$aj" "$p/usuarios" cargo=adm_master nome=Adm sobrenome=Novo "usuario=adm.$id" senha=Praia-milho-4821 | cut -d' ' -f1)" "422"
contem "pede o e-mail" "$tmp/resp.html" 'obrigatório para administrador e gerente'
r="$(enviar "$aj" "$p/usuarios" cargo=caixa "id_restaurante=$rid" nome=Ana sobrenome=Caixa "usuario=ana.$id" senha=Praia-milho-4821)"
uid="$(printf '%s' "$r" | grep -o '/painel/usuarios/[0-9]*' | grep -o '[0-9]*$')"
[[ -n "$uid" ]] && ok "conta de caixa criada (id $uid)" || falha "conta nao criada ($r)"
aj2="$tmp/ana"
esperar "caixa nova entra no caixa" "$(login "$aj2" "ana.$id" Praia-milho-4821)" "303 $base/$rslug/caixa"
esperar "muda para garcom" "$(enviar "$aj" "$p/usuarios/$uid" cargo=garcom "id_restaurante=$rid" nome=Ana sobrenome=Garcom "usuario=ana.$id" | cut -d' ' -f1)" "303"
pagina "$aj" "$p/usuarios/$uid" >/dev/null
contem "funcao nova" "$tmp/pagina.html" 'value="garcom" checked'
esperar "troca a senha" "$(enviar "$aj" "$p/usuarios/$uid/senha" senha=Outra-senha-77 | cut -d' ' -f1)" "303"
esperar "entra com a senha nova" "$(login "$aj2" "ana.$id" Outra-senha-77)" "303 $base/$rslug/garcom"
esperar "2FA ja desligada avisa" "$(enviar "$aj" "$p/usuarios/$uid/dois-fatores" >/dev/null; aviso "$aj" "$p/usuarios/$uid")" "A verificação em duas etapas já está desligada nesta conta."
esperar "bloqueia" "$(enviar "$aj" "$p/usuarios/$uid/acesso" | cut -d' ' -f1)" "303"
esperar "bloqueada nao entra" "$(login "$aj2" "ana.$id" Outra-senha-77 | cut -d' ' -f1)" "403"
esperar "libera" "$(enviar "$aj" "$p/usuarios/$uid/acesso" >/dev/null; aviso "$aj" "$p/usuarios/$uid")" "Ana pode entrar de novo."
eu="$(curl -s -b "$aj" "$p/usuarios?busca=ci_master" | grep -o '/painel/usuarios/[0-9]*"' | head -1 | grep -o '[0-9]*')"
esperar "nao bloqueia a propria conta" "$(enviar "$aj" "$p/usuarios/$eu/acesso" >/dev/null; aviso "$aj" "$p/usuarios/$eu")" "Você não pode bloquear a própria conta."
esperar "nao exclui a propria conta" "$(enviar "$aj" "$p/usuarios/$eu/excluir" >/dev/null; aviso "$aj" "$p/usuarios/$eu")" "Você não pode excluir a própria conta."
pagina "$aj" "$p/usuarios/$eu" >/dev/null
nao_contem "a propria conta nao mostra excluir" "$tmp/pagina.html" '/excluir"'
esperar "cria administrador" "$(enviar "$aj" "$p/usuarios" cargo=adm_master nome=Adm sobrenome=Novo "usuario=adm.$id" "email=adm.$id@example.test" senha=Praia-milho-4821 | cut -d' ' -f1)" "303"
adm="$(curl -s -b "$aj" "$p/usuarios?busca=adm.$id" | grep -o '/painel/usuarios/[0-9]*"' | head -1 | grep -o '[0-9]*')"
esperar "administrador novo entra no /painel" "$(login "$tmp/adm2" "adm.$id" Praia-milho-4821)" "303 $base/painel"
esperar "exclui o administrador novo" "$(enviar "$aj" "$p/usuarios/$adm/excluir")" "303 $p/usuarios"
esperar "conta da caixa vai para o restaurante" "$(enviar "$aj" "$p/usuarios/$uid/excluir")" "303 $p/usuarios"
esperar "conta excluida nao abre" "$(curl -s -o /dev/null -b "$aj" -w '%{http_code} %{redirect_url}' "$p/usuarios/$uid")" "303 $p/usuarios"

echo "Restaurantes: excluir"
pagina "$aj" "$p/restaurantes/$rmodelo" >/dev/null
contem "restaurante com pedidos nao pode excluir" "$tmp/pagina.html" 'use &quot;Desativar&quot; em vez de excluir\|use "Desativar" em vez de excluir'
enviar "$aj" "$p/restaurantes/$rid/excluir" confirmacao=errado >/dev/null; aviso "$aj" "$p/restaurantes/$rid" > "$tmp/msg.txt"
contem "excluir com endereco errado" "$tmp/msg.txt" "Para excluir, digite exatamente o endereço do restaurante: $rslug"
esperar "exclui o restaurante sem movimento" "$(enviar "$aj" "$p/restaurantes/$rid/excluir" "confirmacao=$rslug")" "303 $p/restaurantes"
esperar "restaurante excluido nao abre" "$(curl -s -o /dev/null -b "$aj" -w '%{http_code} %{redirect_url}' "$p/restaurantes/$rid")" "303 $p/restaurantes"
esperar "gerente do restaurante excluido nao entra" "$(login "$nj" "maria.$id" Praia-milho-4821 | cut -d' ' -f1)" "401"
esperar "cardapio excluido some" "$(curl -s -o /dev/null -w '%{http_code}' "$base/$rslug")" "404"
for pl in "$pa" "$pb"; do enviar "$aj" "$p/planos/$pl/excluir" >/dev/null; done

if (( falhas > 0 )); then
  echo "Restaurantes e usuarios da plataforma: $falhas falha(s)."
  exit 1
fi
echo "Restaurantes e usuarios da plataforma: tudo certo."
