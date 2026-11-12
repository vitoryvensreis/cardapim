#!/usr/bin/env bash
# Teste HTTP das abas do painel do gerente (estoque, equipe, perfil, assinatura, minha conta e 2FA).
# Uso: bash tests/http/gerencia.sh http://127.0.0.1:18080 <slug>
# Requer as contas ci_* (tests/http/criar-contas-ci.php) e o servidor com MAIL_DRIVER=log.
# Somente banco de CI/local: cria, altera e apaga dados de teste.
set -Eeuo pipefail
base="$1"
slug="$2"
g="$base/$slug/gerencia"
senha='Senha#CI2026'
raiz="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0

ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -q -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -q -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }

token() { # jar url
  curl -s -c "$1" -b "$1" "$2" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"$//'
}
login() { # jar usuario [senha] -> "codigo location"
  local t
  rm -f "$1"
  t="$(token "$1" "$base/login")"
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{http_code} %{redirect_url}' \
    --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=${3:-$senha}" "$base/login"
}
enviar() { # jar caminho [campo=valor ...] -> "codigo location" (corpo em $tmp/resp.html)
  local jar="$1" caminho="$2" t args=()
  shift 2
  t="$(token "$jar" "$g/estoque/categorias")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$g$caminho"
}
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -w '%{http_code}' "$2"; }
flash() { curl -s -b "$1" "$2" | grep -o 'painel-flash-[a-z]*' | head -1; }
id_por_link() { # arquivo prefixo_url nome -> id do item cujo link contem o nome
  grep -o "href=\"/$slug/gerencia$2/[0-9]*\"><strong>$3<" "$1" | head -1 | sed 's/.*\/\([0-9]*\)".*/\1/'
}

echo "Acesso"
gj="$tmp/gerente"
esperar "gerente entra" "$(login "$gj" ci_gerente)" "303 $g"
for caminho in /estoque /estoque/produtos /estoque/insumos /estoque/categorias /estoque/produtos/novo /estoque/insumos/novo /equipe /equipe/nova /perfil /assinatura /minha-conta /minha-conta/dois-fatores/app; do
  esperar "GET $caminho" "$(pagina "$gj" "$g$caminho")" "200"
  # Todo formulario e link interno aponta para a gerencia do restaurante (nunca para caminhos do servidor).
  if grep -oE '(action|href)="[^"]*"' "$tmp/pagina.html" | grep -vE '="(/'"$slug"'["/]|/assets/|/sair|/login|/minha-conta|/painel|/midia/|/pwa/|#|https://)' | grep -q .; then
    falha "$caminho tem link ou formulario fora do padrao: $(grep -oE '(action|href)="[^"]*"' "$tmp/pagina.html" | grep -vE '="(/'"$slug"'["/]|/assets/|/sair|/login|/minha-conta|/painel|/midia/|/pwa/|#|https://)' | head -2 | tr '\n' ' ')"
  fi
done
pagina "$gj" "$g/estoque/insumos/novo" >/dev/null
contem "formulario do insumo envia para a rota certa" "$tmp/pagina.html" "action=\"/$slug/gerencia/estoque/insumos\""

cj="$tmp/caixa"
login "$cj" ci_caixa >/dev/null
esperar "caixa nao abre o estoque" "$(pagina "$cj" "$g/estoque")" "403"
oj="$tmp/outro"
login "$oj" ci_outro_gerente >/dev/null
esperar "gerente de outro restaurante nao abre" "$(pagina "$oj" "$g/estoque/insumos")" "403"
esperar "POST sem CSRF volta com erro" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' --data 'nome=Hack&unidade=un' "$g/estoque/insumos")" "303"
pagina "$gj" "$g/estoque/insumos" >/dev/null
nao_contem "insumo sem CSRF nao foi criado" "$tmp/pagina.html" '>Hack<'

echo "Estoque"
esperar "cadastrar insumo" "$(enviar "$gj" /estoque/insumos nome='CI Alface' unidade=kg estoque_atual='1,5' estoque_minimo='2' custo_unitario='12,00' ativo=1)" "303 $g/estoque/insumos"
pagina "$gj" "$g/estoque/insumos" >/dev/null
contem "insumo aparece na lista" "$tmp/pagina.html" 'CI Alface'
insumo="$(id_por_link "$tmp/pagina.html" '/estoque/insumos' 'CI Alface')"
[[ -n "$insumo" ]] && ok "id do insumo: $insumo" || falha "id do insumo"
esperar "nome repetido recusado (422)" "$(enviar "$gj" /estoque/insumos nome='ci alface' unidade=kg | cut -d' ' -f1)" "422"
contem "mensagem de nome repetido" "$tmp/resp.html" 'Já existe um insumo com este nome'
esperar "editar insumo" "$(enviar "$gj" "/estoque/insumos/$insumo" nome='CI Alface Crespa' unidade=kg estoque_atual='1,5' estoque_minimo='2' ativo=1)" "303 $g/estoque/insumos"
pagina "$gj" "$g/estoque" >/dev/null
contem "resumo mostra o insumo abaixo do minimo" "$tmp/pagina.html" 'CI Alface Crespa'
esperar "repor estoque" "$(enviar "$gj" "/estoque/insumos/$insumo/repor" quantidade='3' | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/insumos/$insumo" >/dev/null
contem "estoque somado (4,5)" "$tmp/pagina.html" 'value="4,5"'
esperar "criar categoria" "$(enviar "$gj" /estoque/categorias nome='CI Saladas')" "303 $g/estoque/categorias"
esperar "produto com ficha e categoria nova" "$(enviar "$gj" /estoque/produtos nome='CI Salada' preco='19,90' categoria=nova nova_categoria='CI Leves' controle=estoque disponivel=1 "ficha_insumo[]=$insumo" 'ficha_quantidade[]=0,150')" "303 $g/estoque/produtos"
pagina "$gj" "$g/estoque/produtos" >/dev/null
contem "produto na lista com preco" "$tmp/pagina.html" 'R$ 19,90'
produto="$(id_por_link "$tmp/pagina.html" '/estoque/produtos' 'CI Salada')"
[[ -n "$produto" ]] && ok "id do produto: $produto" || falha "id do produto"
esperar "produto sem ficha com controle de estoque recusado" "$(enviar "$gj" /estoque/produtos nome='CI Vazio' preco='5' controle=estoque | cut -d' ' -f1)" "422"
esperar "insumo em ficha nao pode ser excluido" "$(enviar "$gj" "/estoque/insumos/$insumo/excluir" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/insumos" >/dev/null
contem "insumo em uso continua" "$tmp/pagina.html" 'CI Alface Crespa'
esperar "pausar produto" "$(enviar "$gj" "/estoque/produtos/$produto/pausar" | cut -d' ' -f1)" "303"
pagina "$gj" "$g/estoque/produtos?filtro=indisponiveis" >/dev/null
contem "produto pausado no filtro fora de venda" "$tmp/pagina.html" 'CI Salada'
esperar "excluir produto" "$(enviar "$gj" "/estoque/produtos/$produto/excluir")" "303 $g/estoque/produtos"
esperar "excluir insumo livre" "$(enviar "$gj" "/estoque/insumos/$insumo/excluir")" "303 $g/estoque/insumos"
pagina "$gj" "$g/estoque/insumos" >/dev/null
nao_contem "insumo excluido some" "$tmp/pagina.html" '<strong>CI Alface Crespa<'
esperar "outro gerente nao exclui item daqui" "$(curl -s -o /dev/null -b "$oj" -w '%{http_code}' -X POST "$g/estoque/insumos/1/excluir")" "403"

echo "Equipe"
esperar "criar garcom" "$(enviar "$gj" /equipe cargo=garcom nome=Carla sobrenome=Teste usuario=ci_http_garcom senha='Mesa-sol-1234' whatsapp='(11) 91234-5678')" "303 $g/equipe"
pagina "$gj" "$g/equipe" >/dev/null
contem "garcom na lista" "$tmp/pagina.html" 'Carla Teste'
pessoa="$(grep -o "href=\"/$slug/gerencia/equipe/[0-9]*\"><strong>Carla Teste<" "$tmp/pagina.html" | head -1 | sed 's/.*\/\([0-9]*\)".*/\1/')"
[[ -n "$pessoa" ]] && ok "id da pessoa: $pessoa" || falha "id da pessoa"
nj="$tmp/novo"
esperar "garcom novo consegue entrar" "$(login "$nj" ci_http_garcom 'Mesa-sol-1234' | cut -d' ' -f1)" "303"
esperar "usuario repetido recusado" "$(enviar "$gj" /equipe cargo=caixa nome=Dup sobrenome=Teste usuario=ci_http_garcom senha='Outra-senha-1' | cut -d' ' -f1)" "422"
esperar "nao promove para gerente" "$(enviar "$gj" "/equipe/$pessoa" cargo=gerente nome=Carla sobrenome=Teste usuario=ci_http_garcom | cut -d' ' -f1)" "422"
esperar "bloquear acesso" "$(enviar "$gj" "/equipe/$pessoa/acesso" | cut -d' ' -f1)" "303"
esperar "bloqueada e desconectada" "$(pagina "$nj" "$base/minha-conta")" "302"
esperar "bloqueada nao entra" "$(login "$nj" ci_http_garcom 'Mesa-sol-1234' | cut -d' ' -f1)" "403"
enviar "$gj" "/equipe/$pessoa/acesso" >/dev/null
esperar "trocar senha da pessoa" "$(enviar "$gj" "/equipe/$pessoa/senha" senha='Nova-senha-777' | cut -d' ' -f1)" "303"
esperar "entra com a senha nova" "$(login "$nj" ci_http_garcom 'Nova-senha-777' | cut -d' ' -f1)" "303"
esperar "remover da equipe" "$(enviar "$gj" "/equipe/$pessoa/excluir")" "303 $g/equipe"
esperar "removida nao entra" "$(login "$nj" ci_http_garcom 'Nova-senha-777' | cut -d' ' -f1)" "401"

echo "Perfil"
esperar "salvar dados e endereco" "$(enviar "$gj" /perfil nome='Restaurante Modelo Cardapim' telefone='(11) 3333-4444' cep='01310-100' logradouro='Avenida Paulista' numero='1000' bairro='Bela Vista' cidade='São Paulo' estado='SP')" "303 $g/perfil"
esperar "dados invalidos (422)" "$(enviar "$gj" /perfil nome='X' telefone='1' cep='9' | cut -d' ' -f1)" "422"
esperar "horarios" "$(enviar "$gj" /perfil/horarios modo=semana 'dia[1][aberto]=1' 'dia[1][abre1]=11:00' 'dia[1][fecha1]=15:00' 'dia[1][turno2]=1' 'dia[1][abre2]=18:00' 'dia[1][fecha2]=23:00' 'dia[5][aberto]=1' 'dia[5][abre1]=18:00' 'dia[5][fecha1]=02:00')" "303 $g/perfil#horarios"
pagina "$gj" "$g/perfil" >/dev/null
contem "horario salvo" "$tmp/pagina.html" 'value="23:00"'
esperar "horario invalido (422)" "$(enviar "$gj" /perfil/horarios modo=semana 'dia[2][aberto]=1' 'dia[2][abre1]=10:00' 'dia[2][fecha1]=10:00' | cut -d' ' -f1)" "422"
# PNG 20x20 valido (gerado por PHP, sem depender de arquivo externo)
php -r '$i=imagecreatetruecolor(40,40); imagefill($i,0,0,imagecolorallocate($i,101,20,36)); imagepng($i,$argv[1]);' "$tmp/logo.png"
printf '<?php echo 1;' > "$tmp/falso.png"
t="$(token "$gj" "$g/perfil")"
esperar "enviar logo" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -F "_csrf=$t" -F "logo=@$tmp/logo.png;type=image/png" "$g/perfil/logo")" "303"
# Endereco SEM extensao (o Nginx do CloudPanel responde 404 a qualquer .png/.webp que nao exista em public/).
logo="$(curl -s -b "$gj" "$g/perfil" | grep -o '/midia/logos/[a-f0-9]\{32\}-[a-z]*' | head -1)"
[[ -n "$logo" ]] && ok "logo publicada em $logo" || falha "logo nao aparece no perfil"
esperar "logo servida" "$(curl -s -o /dev/null -w '%{http_code}' "$base$logo")" "200"
esperar "endereco antigo (com extensao) continua valendo" "$(curl -s -o /dev/null -w '%{http_code}' "$base${logo%-*}.${logo##*-}")" "200"
esperar "perfil sem endereco de logo com extensao" "$(curl -s -b "$gj" "$g/perfil" | grep -c '/midia/logos/[a-f0-9]\{32\}\.')" "0"
curl -s -o /dev/null -b "$gj" -F "_csrf=$t" -F "logo=@$tmp/falso.png;type=image/png" "$g/perfil/logo"
esperar "arquivo falso recusado" "$(flash "$gj" "$g/perfil")" "painel-flash-erro"
esperar "caminho invalido em /midia" "$(curl -s -o /dev/null -w '%{http_code}' "$base/midia/logos/..%2F..%2F.env")" "404"

echo "Assinatura"
plano="$(curl -s -b "$gj" "$g/assinatura" | grep -o 'name="plano" value="[0-9]*"' | head -1 | sed 's/.*value="//; s/"$//')"
[[ -n "$plano" ]] && ok "plano disponivel: $plano" || falha "nenhum plano para assinar"
esperar "assinar/trocar plano" "$(enviar "$gj" /assinatura/assinar plano="$plano")" "303 $g/assinatura"
esperar "assinatura com sucesso" "$(flash "$gj" "$g/assinatura")" "painel-flash-sucesso"
esperar "cancelar" "$(enviar "$gj" /assinatura/cancelar)" "303 $g/assinatura"
pagina "$gj" "$g/assinatura" >/dev/null
contem "mostra cancelada" "$tmp/pagina.html" 'Desfazer cancelamento'
esperar "desfazer cancelamento" "$(enviar "$gj" /assinatura/reativar)" "303 $g/assinatura"

echo "Minha conta"
esperar "salvar meus dados" "$(enviar "$gj" /minha-conta/dados nome=CI sobrenome=Gerente whatsapp='(11) 98888-7777')" "303 $g/minha-conta"
esperar "senha atual errada (422)" "$(enviar "$gj" /minha-conta/senha senha_atual=errada senha_nova='Outra#Senha1' senha_confirmacao='Outra#Senha1' | cut -d' ' -f1)" "422"
esperar "trocar senha" "$(enviar "$gj" /minha-conta/senha senha_atual="$senha" senha_nova='Senha#CI2099' senha_confirmacao='Senha#CI2099' | cut -d' ' -f1)" "303"
esperar "voltar a senha" "$(enviar "$gj" /minha-conta/senha senha_atual='Senha#CI2099' senha_nova="$senha" senha_confirmacao="$senha" | cut -d' ' -f1)" "303"

echo "Verificacao em duas etapas (aplicativo)"
pagina "$gj" "$g/minha-conta/dois-fatores/app" >/dev/null
segredo="$(grep -o 'data-copiar="[A-Z2-7]\{32\}"' "$tmp/pagina.html" | head -1 | sed 's/.*="//; s/"$//')"
[[ -n "$segredo" ]] && ok "segredo exibido para configurar" || falha "segredo nao encontrado"
contem "QR Code preparado" "$tmp/pagina.html" 'data-qr="otpauth://totp/'
totp() { php -r 'require $argv[1] . "/bootstrap/app.php"; echo \Cardapim\Shared\Seguranca\Totp::codigo($argv[2]);' "$raiz" "$segredo"; }
esperar "codigo errado nao liga" "$(enviar "$gj" /minha-conta/dois-fatores/app codigo=000001)" "303 $g/minha-conta/dois-fatores/app"
esperar "codigo certo liga" "$(enviar "$gj" /minha-conta/dois-fatores/app codigo="$(totp)")" "303 $g/minha-conta/codigos"
curl -s -b "$gj" "$g/minha-conta/codigos" > "$tmp/codigos.html"
recuperacao="$(grep -o '<li>[a-z0-9]\{4\}-[a-z0-9]\{4\}</li>' "$tmp/codigos.html" | head -1 | sed 's/<[^>]*>//g')"
[[ -n "$recuperacao" ]] && ok "codigos de recuperacao exibidos" || falha "codigos nao exibidos"
esperar "codigos aparecem uma vez so" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' "$g/minha-conta/codigos")" "303"
dj="$tmp/dois"
esperar "login pede o codigo" "$(login "$dj" ci_gerente)" "303 $base/login/verificacao"
esperar "sem codigo nao entra" "$(pagina "$dj" "$g")" "302"
t="$(token "$dj" "$base/login/verificacao")"
esperar "codigo errado (401)" "$(curl -s -o /dev/null -c "$dj" -b "$dj" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode 'codigo=000001' "$base/login/verificacao")" "401"
esperar "codigo do app entra" "$(curl -s -o /dev/null -c "$dj" -b "$dj" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "codigo=$(totp)" "$base/login/verificacao")" "303 $g"
rj="$tmp/recup"
login "$rj" ci_gerente >/dev/null
t="$(token "$rj" "$base/login/verificacao")"
esperar "codigo de recuperacao entra" "$(curl -s -o /dev/null -c "$rj" -b "$rj" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "codigo=$recuperacao" "$base/login/verificacao")" "303"
login "$rj" ci_gerente >/dev/null
t="$(token "$rj" "$base/login/verificacao")"
esperar "codigo de recuperacao usado nao vale de novo" "$(curl -s -o /dev/null -c "$rj" -b "$rj" -w '%{http_code}' --data-urlencode "_csrf=$t" --data-urlencode "codigo=$recuperacao" "$base/login/verificacao")" "401"
esperar "desligar com senha errada" "$(enviar "$gj" /minha-conta/dois-fatores/desativar senha=errada | cut -d' ' -f1)" "303"
esperar "continua ligada" "$(login "$tmp/x" ci_gerente)" "303 $base/login/verificacao"
esperar "desligar com a senha" "$(enviar "$gj" /minha-conta/dois-fatores/desativar senha="$senha" | cut -d' ' -f1)" "303"
esperar "login volta a pedir so a senha" "$(login "$tmp/y" ci_gerente)" "303 $g"

echo "Verificacao em duas etapas (e-mail)"
correio="$raiz/storage/logs/mail.log"
esperar "enviar codigo de ativacao" "$(enviar "$gj" /minha-conta/dois-fatores/email)" "303 $g/minha-conta/dois-fatores/email"
ultimo_codigo() { grep -oE '^    [0-9]{6}$' "$correio" | tail -1 | tr -d ' '; }
esperar "confirmar codigo do e-mail" "$(enviar "$gj" /minha-conta/dois-fatores/email/confirmar codigo="$(ultimo_codigo)")" "303 $g/minha-conta/codigos"
ej="$tmp/email"
esperar "login pede codigo do e-mail" "$(login "$ej" ci_gerente)" "303 $base/login/verificacao"
t="$(token "$ej" "$base/login/verificacao")"
esperar "codigo do e-mail entra" "$(curl -s -o /dev/null -c "$ej" -b "$ej" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "codigo=$(ultimo_codigo)" "$base/login/verificacao")" "303 $g"
esperar "desligar e-mail" "$(enviar "$gj" /minha-conta/dois-fatores/desativar senha="$senha" | cut -d' ' -f1)" "303"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no teste das abas da gerencia."
  exit 1
fi
echo "OK: abas da gerencia (estoque, equipe, perfil, assinatura, minha conta e 2FA)."
