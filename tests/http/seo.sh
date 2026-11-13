#!/usr/bin/env bash
# Teste HTTP de Google e redes sociais: titulo, descricao, imagem do link (Open Graph e Twitter Card), endereco oficial,
# dados estruturados, robots.txt, sitemap.xml, imagens automaticas dos links e a tela do adm_master que edita tudo.
# Uso: bash tests/http/seo.sh <base>
set -Eeuo pipefail
base="$1"
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
  curl -s -o /dev/null -c "$1" -b "$1" -w '%{redirect_url}' --data-urlencode "_csrf=$t" --data-urlencode "identificacao=$2" --data-urlencode "senha=$3" "$base/login"
}
enviar() { # jar url campos... -> "codigo location"
  local jar="$1" url="$2" t args=(); shift 2
  t="$(token "$jar" "$p/configuracoes")"
  for campo in "$@"; do args+=(--data-urlencode "$campo"); done
  curl -s -o "$tmp/resp.html" -c "$jar" -b "$jar" -w '%{http_code} %{redirect_url}' --data-urlencode "_csrf=$t" "${args[@]}" "$url"
}
pagina() { curl -s -o "$tmp/pagina.html" -b "$1" -c "$1" -w '%{http_code}' "$2"; }
meta() { php -r '$h = file_get_contents($argv[1]); if (preg_match("~<meta (?:property|name)=\"" . preg_quote($argv[2], "~") . "\" content=\"([^\"]*)\"~", $h, $m)) echo html_entity_decode($m[1], ENT_QUOTES);' "$1" "$2"; }
# Endereco completo (APP_URL) -> caminho local, para baixar a imagem do servidor de teste.
local_de() { php -r '$u = parse_url($argv[1]); echo ($u["path"] ?? "/") . (isset($u["query"]) ? "?" . $u["query"] : "");' "$1"; }
tamanho_jpg() { php -r '$i = @getimagesize($argv[1]); echo $i ? $i[0] . "x" . $i[1] . " " . $i["mime"] : "invalida";' "$1"; }

aj="$tmp/adm"; gj="$tmp/gerente"
login "$aj" ci_master "$senha" >/dev/null
slug="$(login "$gj" ci_gerente "$senha" | sed -E 's~^https?://[^/]+~~; s~^/([^/]+)/.*~\1~')"
[[ -n "$slug" ]] && ok "restaurante do gerente: $slug" || falha "nao achou o restaurante do gerente"
# Comeca dos textos padrao.
enviar "$aj" "$p/configuracoes/seo" seo_titulo= seo_descricao= frase_compartilhamento= google_verificacao= >/dev/null
enviar "$aj" "$p/configuracoes/compartilhamento/padrao" >/dev/null

echo "Pagina inicial"
curl -s "$base/" > "$tmp/home.html"
contem "titulo padrao da pagina inicial" "$tmp/home.html" '<title>[^<]*Sistema para restaurantes, bares e delivery</title>'
contem "descricao padrao" "$tmp/home.html" 'name="description" content="Organize pedidos, mesas, comandas'
contem "og:locale pt_BR" "$tmp/home.html" 'property="og:locale" content="pt_BR"'
contem "cartao grande no X/Twitter" "$tmp/home.html" 'name="twitter:card" content="summary_large_image"'
contem "endereco oficial (canonical)" "$tmp/home.html" '<link rel="canonical" href="http'
contem "og:url" "$tmp/home.html" 'property="og:url" content="http'
contem "tamanho da imagem informado" "$tmp/home.html" 'property="og:image:width" content="1200"'
contem "dados estruturados da organizacao" "$tmp/home.html" '"@type":"Organization"'
contem "dados estruturados do site" "$tmp/home.html" '"@type":"WebSite"'
contem "icone PNG para buscadores" "$tmp/home.html" 'rel="icon" type="image/png" sizes="192x192"'
contem "icone do iPhone em toda pagina publica" "$tmp/home.html" 'rel="apple-touch-icon"'
img="$(meta "$tmp/home.html" og:image)"
[[ "$img" == http*://*/midia/cartao\?v=* ]] && ok "imagem automatica com endereco completo" || falha "og:image inesperada: $img"
esperar "imagem automatica abre" "$(curl -s -o "$tmp/cartao.jpg" -D "$tmp/h.txt" -w '%{http_code}' "$base$(local_de "$img")")" "200"
esperar "imagem automatica em JPG 1200x630" "$(tamanho_jpg "$tmp/cartao.jpg")" "1200x630 image/jpeg"
contem "imagem com cache publico" "$tmp/h.txt" 'public, max-age'
[[ "$(stat -c %s "$tmp/cartao.jpg")" -lt 300000 ]] && ok "imagem leve (WhatsApp)" || falha "imagem pesada demais"

echo "Cardapio do restaurante"
curl -s "$base/$slug?mesa=1" > "$tmp/cardapio.html"
contem "titulo com o nome do restaurante" "$tmp/cardapio.html" '<title>[^<]*Cardápio online'
contem "og:type de restaurante" "$tmp/cardapio.html" 'property="og:type" content="restaurant.restaurant"'
contem "canonical sem parametros" "$tmp/cardapio.html" "<link rel=\"canonical\" href=\"http[^\"]*/$slug\">"
contem "dados estruturados do restaurante" "$tmp/cardapio.html" '"@type":"Restaurant"'
contem "cardapio nos dados estruturados" "$tmp/cardapio.html" '"hasMenu":"http'
imgr="$(meta "$tmp/cardapio.html" og:image)"
[[ "$imgr" == *"/midia/cartao/$slug?v="* ]] && ok "imagem propria do restaurante" || falha "og:image do restaurante: $imgr"
esperar "imagem do restaurante abre" "$(curl -s -o "$tmp/rest.jpg" -w '%{http_code}' "$base$(local_de "$imgr")")" "200"
esperar "imagem do restaurante em JPG 1200x630" "$(tamanho_jpg "$tmp/rest.jpg")" "1200x630 image/jpeg"
esperar "restaurante inexistente: 404" "$(curl -s -o /dev/null -w '%{http_code}' "$base/midia/cartao/nao-existe-$RANDOM")" "404"
esperar "endereco invalido: 404" "$(curl -s -o /dev/null -w '%{http_code}' "$base/midia/cartao/..%2Fetc")" "404"

echo "Paginas fora do Google"
curl -s "$base/login" > "$tmp/login.html"
contem "login fora do Google" "$tmp/login.html" 'content="noindex, nofollow"'
nao_contem "login sem canonical" "$tmp/login.html" 'rel="canonical"'
contem "login ainda tem imagem do link" "$tmp/login.html" 'property="og:image" content="http'

echo "robots.txt e sitemap.xml"
esperar "robots.txt abre" "$(curl -s -o "$tmp/robots.txt" -D "$tmp/h.txt" -w '%{http_code}' "$base/robots.txt")" "200"
contem "robots em texto" "$tmp/h.txt" 'text/plain'
contem "painel fora dos buscadores" "$tmp/robots.txt" 'Disallow: /painel'
contem "gerencia fora dos buscadores" "$tmp/robots.txt" 'Disallow: /\*/gerencia'
contem "robots aponta o sitemap" "$tmp/robots.txt" 'Sitemap: http.*/sitemap.xml'
esperar "sitemap abre" "$(curl -s -o "$tmp/sitemap.xml" -D "$tmp/h.txt" -w '%{http_code}' "$base/sitemap.xml")" "200"
contem "sitemap em XML" "$tmp/h.txt" 'application/xml'
esperar "sitemap e XML valido" "$(php -r 'echo @simplexml_load_file($argv[1]) ? "valido" : "invalido";' "$tmp/sitemap.xml")" "valido"
contem "sitemap tem o catalogo" "$tmp/sitemap.xml" '/catalogo</loc>'
contem "sitemap tem o restaurante" "$tmp/sitemap.xml" "/$slug</loc>"
nao_contem "sitemap sem o painel" "$tmp/sitemap.xml" '/painel'

echo "Painel: Google e redes sociais"
esperar "configuracoes abrem" "$(pagina "$aj" "$p/configuracoes")" "200"
contem "secao Google e redes sociais" "$tmp/pagina.html" 'Google e redes sociais'
contem "previa do link no WhatsApp" "$tmp/pagina.html" 'Assim o link aparece no WhatsApp'
contem "previa usa a imagem automatica" "$tmp/pagina.html" 'src="/midia/cartao?v='
esperar "codigo do Google invalido recusado (422)" "$(enviar "$aj" "$p/configuracoes/seo" "google_verificacao=abc def" | cut -d' ' -f1)" "422"
contem "mensagem do codigo invalido" "$tmp/resp.html" 'Cole o código que o Google mostra'
esperar "titulo longo demais recusado (422)" "$(enviar "$aj" "$p/configuracoes/seo" "seo_titulo=$(printf 'x%.0s' {1..80})" | cut -d' ' -f1)" "422"
esperar "salvar textos" "$(enviar "$aj" "$p/configuracoes/seo" 'seo_titulo=Titulo CI <script>alert(1)</script>' 'seo_descricao=Descricao CI para o Google e as redes sociais.' 'frase_compartilhamento=Frase CI do cartao' 'google_verificacao=<meta name="google-site-verification" content="CodigoCI_123-abc" />' | cut -d'#' -f1)" "303 $p/configuracoes"
curl -s "$base/" > "$tmp/home2.html"
contem "titulo novo na pagina inicial" "$tmp/home2.html" '<title>Titulo CI &lt;script&gt;'
nao_contem "titulo escapado (sem script)" "$tmp/home2.html" '<script>alert(1)'
contem "descricao nova" "$tmp/home2.html" 'content="Descricao CI para o Google e as redes sociais."'
contem "verificacao do Google so com o codigo" "$tmp/home2.html" 'name="google-site-verification" content="CodigoCI_123-abc"'
img2="$(meta "$tmp/home2.html" og:image)"
[[ "$img2" != "$img" ]] && ok "imagem automatica muda com a frase (endereco novo)" || falha "endereco da imagem nao mudou"
esperar "gerente nao salva (403)" "$(enviar "$gj" "$p/configuracoes/seo" seo_titulo=Invasor | cut -d' ' -f1)" "403"

echo "Imagem do link enviada pelo adm"
php -r '$i = imagecreatetruecolor(1600, 900); imagefill($i, 0, 0, imagecolorallocate($i, 255, 88, 37)); imagepng($i, $argv[1]);' "$tmp/arte.png"
php -r '$i = imagecreatetruecolor(300, 200); imagepng($i, $argv[1]);' "$tmp/pequena.png"
printf 'nao sou imagem' > "$tmp/falsa.png"
envio() { local t; t="$(token "$aj" "$p/configuracoes")"; curl -s -o /dev/null -b "$aj" -c "$aj" -w '%{http_code}' -F "_csrf=$t" -F "arquivo=@$1;type=image/png" "$p/configuracoes/compartilhamento"; }
flash() { curl -s -b "$aj" -c "$aj" "$p/configuracoes" | grep -o 'painel-flash-[a-z]*' | head -1; }
esperar "imagem pequena recusada" "$(envio "$tmp/pequena.png")" "303"
esperar "aviso de imagem pequena" "$(flash)" "painel-flash-erro"
esperar "arquivo falso recusado" "$(envio "$tmp/falsa.png")" "303"
esperar "aviso de arquivo falso" "$(flash)" "painel-flash-erro"
esperar "enviar arte 1600x900" "$(envio "$tmp/arte.png")" "303"
esperar "arte enviada com sucesso" "$(flash)" "painel-flash-sucesso"
curl -s "$base/" > "$tmp/home3.html"
img3="$(meta "$tmp/home3.html" og:image)"
[[ "$img3" =~ /midia/marca/[a-f0-9]{32}-jpg$ ]] && ok "link usa a arte enviada (sem extensao no endereco)" || falha "og:image da arte: $img3"
curl -s -o "$tmp/arte.jpg" "$base$(local_de "$img3")"
esperar "arte recortada para 1200x630 em JPG" "$(tamanho_jpg "$tmp/arte.jpg")" "1200x630 image/jpeg"
esperar "endereco automatico leva a arte" "$(curl -s -o /dev/null -w '%{http_code}' "$base/midia/cartao")" "302"
imgr2="$(curl -s "$base/$slug" > "$tmp/cardapio2.html"; meta "$tmp/cardapio2.html" og:image)"
[[ "$imgr2" == *"/midia/cartao/$slug?v="* ]] && ok "restaurante continua com a imagem propria" || falha "og:image do restaurante mudou: $imgr2"
pagina "$aj" "$p/configuracoes" >/dev/null
contem "painel mostra que a imagem foi enviada" "$tmp/pagina.html" 'Imagem enviada por você'
esperar "voltar a imagem automatica" "$(enviar "$aj" "$p/configuracoes/compartilhamento/padrao" | cut -d'#' -f1)" "303 $p/configuracoes"
curl -s "$base/" > "$tmp/home4.html"
[[ "$(meta "$tmp/home4.html" og:image)" == *"/midia/cartao?v="* ]] && ok "imagem automatica de volta" || falha "nao voltou para a automatica"

# Deixa como estava (textos padrao).
enviar "$aj" "$p/configuracoes/seo" seo_titulo= seo_descricao= frase_compartilhamento= google_verificacao= >/dev/null
curl -s "$base/" > "$tmp/home5.html"
nao_contem "verificacao do Google some quando apagada" "$tmp/home5.html" 'google-site-verification'

echo
if (( falhas > 0 )); then
  echo "$falhas verificacao(oes) falharam."
  exit 1
fi
echo "Google e redes sociais: tudo certo."
