#!/usr/bin/env bash
# Instala na VPS o mapa gerado por mapa-gerar.sh (enviado pelo CI para shared/mapas/ceara.pmtiles.novo).
# Troca o arquivo de uma vez (mv), liga na release atual e confere que o site entrega pedacos (HTTP 206).
# Executado pelo usuario do site. Sem o mapa, ou com falha aqui, as telas usam o OpenStreetMap (mapa-base.js).
set -Eeuo pipefail
umask 022

root="/home/escotilhaprodutora-cardapim/apps/cardapim"
pasta="$root/shared/mapas"
novo="$pasta/ceara.pmtiles.novo"
final="$pasta/ceara.pmtiles"
[[ "$(id -un)" == "escotilhaprodutora-cardapim" ]] || { echo "Usuario inesperado"; exit 1; }
[[ -f "$novo" && -f "$novo.sha256" ]] || { echo "Arquivo novo nao recebido"; exit 1; }

# Confere que chegou inteiro e que e um PMTiles v3.
[[ "$(sha256sum "$novo" | cut -d' ' -f1)" == "$(tr -d '[:space:]' < "$novo.sha256")" ]] || { echo "Arquivo corrompido no envio"; exit 1; }
[[ "$(head -c 7 "$novo")" == "PMTiles" ]] || { echo "Nao e um PMTiles"; exit 1; }
[[ "$(head -c 8 "$novo" | tail -c 1 | od -An -tu1 | tr -d ' ')" == "3" ]] || { echo "Versao de PMTiles inesperada"; exit 1; }

chmod 644 "$novo"
mv -f "$novo" "$final"
rm -f "$novo.sha256"
echo "Mapa instalado: $final ($(( $(stat -c %s "$final") / 1024 / 1024 )) MB)"

# Liga na release atual (link fisico: o Nginx le pela pasta public da release, sem depender das permissoes de shared/).
release="$(readlink -f "$root/current")"
[[ -d "$release/public" ]] || { echo "Release atual nao encontrada"; exit 1; }
mkdir -p "$release/public/mapas"
chmod 755 "$release/public/mapas"
ln -f "$final" "$release/public/mapas/ceara.pmtiles"

# O mapa so funciona se o servidor (Nginx/Varnish) responder a pedidos de pedacos do arquivo.
site="$(sed -n 's/^APP_URL=//p' "$root/shared/.env" | tr -d '"'"'"'\r' | head -n 1)"
[[ "$site" =~ ^https://[A-Za-z0-9.-]+/?$ ]] || { echo "APP_URL invalida em shared/.env"; exit 1; }
resposta="$(curl -sS --max-time 20 -r 0-126 -o /tmp/cardapim-mapa-teste.bin -w '%{http_code} %{size_download}' "${site%/}/mapas/ceara.pmtiles")"
rm -f /tmp/cardapim-mapa-teste.bin
echo "Teste de pedaco do arquivo: $resposta"
if [[ "$resposta" != "206 127" ]]; then
  echo "ATENCAO: o site nao entregou so o pedaco pedido (esperado '206 127'). As telas vao usar o OpenStreetMap"
  echo "ate o Nginx/Varnish aceitarem HTTP Range em /mapas/. Ver docs/architecture/mapa.md."
  exit 1
fi
echo "Mapa proprio no ar."
