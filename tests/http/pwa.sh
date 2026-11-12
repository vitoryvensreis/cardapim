#!/usr/bin/env bash
# Teste HTTP do app instalavel (PWA): manifesto, service worker, pagina sem conexao e onde o app e oferecido.
# Uso: bash tests/http/pwa.sh <base> <slug>
set -Eeuo pipefail
base="$1"
slug="$2"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
falhas=0
ok() { printf '  ok  %s\n' "$1"; }
falha() { printf '  FALHOU %s\n' "$1"; falhas=$((falhas + 1)); }
esperar() { if [[ "$2" == "$3" ]]; then ok "$1"; else falha "$1 (obtido: $2, esperado: $3)"; fi; }
contem() { if grep -qi -- "$3" "$2"; then ok "$1"; else falha "$1 (texto ausente: $3)"; fi; }
nao_contem() { if grep -qi -- "$3" "$2"; then falha "$1 (texto presente: $3)"; else ok "$1"; fi; }
json() { php -r '$d = json_decode(file_get_contents($argv[1]), true); $v = $d; foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; } echo is_scalar($v) ? $v : json_encode($v);' "$1" "$2"; }

echo "Manifesto"
curl -s -D "$tmp/h" -o "$tmp/m.json" "$base/pwa/manifesto"
contem "tipo application/manifest+json" "$tmp/h" 'content-type: application/manifest+json'
esperar "abre no login" "$(json "$tmp/m.json" start_url)" "/login?origem=app"
esperar "tela cheia" "$(json "$tmp/m.json" display)" "standalone"
esperar "escopo do site todo" "$(json "$tmp/m.json" scope)" "/"
esperar "cor vinho" "$(json "$tmp/m.json" theme_color)" "#651424"
for i in 0 1 2; do
  src="$(json "$tmp/m.json" "icons.$i.src")"
  esperar "icone $src existe" "$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$base$src")" "200 image/png"
done
esperar "icone maskable" "$(json "$tmp/m.json" icons.2.purpose)" "maskable"

echo "Service worker"
curl -s -D "$tmp/h" -o "$tmp/sw.js" "$base/pwa/sw"
contem "tipo javascript" "$tmp/h" 'content-type: text/javascript'
contem "sem cache" "$tmp/h" 'cache-control: no-cache'
contem "escopo liberado" "$tmp/h" 'service-worker-allowed: /'
contem "pagina offline no service worker" "$tmp/sw.js" '/offline.html'
nao_contem "nao guarda a API" "$tmp/sw.js" 'api/v1'
esperar "slug pwa reservado (nao vira restaurante)" "$(curl -s -o /dev/null -w '%{http_code}' "$base/pwa")" "404"
esperar "pagina sem conexao" "$(curl -s -o "$tmp/off.html" -w '%{http_code}' "$base/offline.html")" "200"
contem "pagina sem conexao explica" "$tmp/off.html" 'Você está sem internet'
esperar "apple-touch-icon" "$(curl -s -o /dev/null -w '%{http_code}' "$base/assets/img/pwa/apple-touch-icon.png")" "200"

echo "Onde o app e oferecido"
for pagina in /login /cadastro; do
  curl -s "$base$pagina" -o "$tmp/p.html"
  contem "$pagina liga o manifesto" "$tmp/p.html" 'rel="manifest" href="/pwa/manifesto"'
  contem "$pagina tem o convite para instalar" "$tmp/p.html" 'data-pwa-instalar'
  contem "$pagina carrega pwa.js" "$tmp/p.html" '/assets/js/pwa.js'
  contem "$pagina tem icone do iPhone" "$tmp/p.html" 'apple-touch-icon'
done
curl -s "$base/" -o "$tmp/p.html"
nao_contem "landing nao vira app" "$tmp/p.html" 'rel="manifest"'
contem "landing sai do app instalado (vai ao login)" "$tmp/p.html" '/assets/js/so-no-navegador.js'
curl -s "$base/assets/js/so-no-navegador.js" -o "$tmp/sn.js"
contem "confere o modo app (display-mode)" "$tmp/sn.js" 'display-mode'
contem "manda para o login do app" "$tmp/sn.js" '/login?origem=app'
curl -s "$base/login" -o "$tmp/p.html"
nao_contem "login nao redireciona no app" "$tmp/p.html" 'so-no-navegador.js'
curl -s "$base/$slug" -o "$tmp/p.html"
nao_contem "cardapio do cliente nao vira app da equipe" "$tmp/p.html" 'rel="manifest"'

if [[ "$falhas" -gt 0 ]]; then
  echo "$falhas falha(s) no app instalavel."
  exit 1
fi
echo "OK: app instalavel (manifesto, service worker, pagina sem conexao e convite no login/cadastro)."
