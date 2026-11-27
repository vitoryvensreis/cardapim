#!/usr/bin/env bash
# Popup do cardapio (Gerencia > Clientes > Popup do cardapio): texto e imagem 1:1, periodo, desligar, remover imagem,
# acesso so da gerencia e o popup no link de pedidos.
# Uso: bash tests/http/popup-cardapio.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
g="$base/$slug/gerencia"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
senha='Senha#CI2026'
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
salvar() { # campos (multipart: -F) -> codigo; resposta em $tmp/resp.html
  local t args=()
  t="$(token "$gj" "$g/popup")"
  for campo in "$@"; do args+=(-F "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$gj" -b "$gj" -w '%{http_code}' -F "_csrf=$t" "${args[@]}" "$g/popup"
}
cardapio() { curl -s "$base/$slug" -o "$tmp/cardapio.html"; }

gj="$tmp/gerente"; cx="$tmp/caixa"
esperar "gerente autenticado" "$(login "$gj" ci_gerente)" "303"
esperar "caixa autenticado" "$(login "$cx" ci_caixa)" "303"

echo "Tela da gerencia"
esperar "tela do popup abre" "$(curl -s -o "$tmp/tela.html" -b "$gj" -w '%{http_code}' "$g/popup")" "200"
contem "menu com Popup do cardapio" "$tmp/tela.html" 'Popup do cardápio'
contem "previa ao lado" "$tmp/tela.html" 'Como o cliente vê'
esperar "caixa nao abre a tela do popup" "$(curl -s -o /dev/null -b "$cx" -w '%{http_code}' "$g/popup")" "403"
esperar "salvar sem CSRF recusado" "$(curl -s -o /dev/null -b "$gj" -w '%{http_code}' -F ativo=1 "$g/popup")" "303"

echo "Texto"
esperar "texto ligado sem titulo recusado" "$(salvar ativo=1 tipo=texto titulo= texto=Oi)" "422"
contem "erro do titulo" "$tmp/resp.html" 'Digite o título do popup.'
esperar "fim antes do inicio recusado" "$(salvar ativo=1 tipo=texto titulo=Promo texto=Oi inicio=2026-12-10 fim=2026-12-01)" "422"
esperar "popup de texto salvo" "$(salvar ativo=1 tipo=texto 'titulo=Pizza em dobro' 'texto=Hoje a segunda pizza sai pela metade.')" "303"
cardapio
contem "cardapio abre o popup" "$tmp/cardapio.html" 'data-popup '
contem "popup mostra o titulo" "$tmp/cardapio.html" 'Pizza em dobro'
contem "popup carrega o script" "$tmp/cardapio.html" '/assets/js/cardapio-popup.js'
contem "cardapio continua com o script dele" "$tmp/cardapio.html" '/assets/js/cardapio.js'
esperar "periodo no futuro salvo" "$(salvar ativo=1 tipo=texto 'titulo=Pizza em dobro' 'texto=Oi' inicio=2099-01-01)" "303"
cardapio
nao_contem "fora do periodo: sem popup" "$tmp/cardapio.html" 'data-popup '
esperar "desligado salvo" "$(salvar ativo= tipo=texto 'titulo=Pizza em dobro' 'texto=Oi')" "303"
cardapio
nao_contem "desligado: sem popup" "$tmp/cardapio.html" 'data-popup '

echo "Imagem 1:1"
php -r '$i = imagecreatetruecolor(1600, 1000); imagefill($i, 0, 0, imagecolorallocate($i, 255, 88, 37)); imagepng($i, $argv[1]);' "$tmp/banner.png"
printf 'nao sou imagem' > "$tmp/falso.png"
esperar "arquivo que nao e imagem recusado" "$(salvar ativo=1 tipo=imagem "imagem=@$tmp/falso.png;type=image/png")" "422"
esperar "imagem ligada sem arquivo recusada" "$(salvar ativo=1 tipo=imagem)" "422"
esperar "popup de imagem salvo" "$(salvar ativo=1 tipo=imagem "imagem=@$tmp/banner.png;type=image/png")" "303"
cardapio
img="$(php -r 'if (preg_match("~class=\"cd-popup-imagem\" src=\"([^\"]+)\"~", file_get_contents($argv[1]), $m)) echo $m[1];' "$tmp/cardapio.html")"
[[ "$img" == /midia/popups/* ]] && ok "cardapio mostra a imagem do popup" || falha "imagem do popup ausente ($img)"
esperar "imagem servida" "$(curl -s -o "$tmp/servida" -w '%{http_code}' "$base$img")" "200"
esperar "imagem recortada em quadrado (1000 x 1000)" "$(php -r '$s = getimagesize($argv[1]); echo $s[0], "x", $s[1];' "$tmp/servida")" "1000x1000"
t="$(token "$gj" "$g/popup")"
esperar "remover a imagem" "$(curl -s -o /dev/null -b "$gj" -c "$gj" -w '%{http_code}' --data-urlencode "_csrf=$t" "$g/popup/imagem/remover")" "303"
cardapio
nao_contem "sem imagem: popup de imagem some" "$tmp/cardapio.html" 'data-popup '
esperar "arquivo antigo apagado" "$(curl -s -o /dev/null -w '%{http_code}' "$base$img")" "404"

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no popup do cardapio."
  exit 1
fi
echo "Popup do cardapio validado (texto, imagem 1:1, periodo, desligar, remover e acesso)."
