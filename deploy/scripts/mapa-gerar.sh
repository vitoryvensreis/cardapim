#!/usr/bin/env bash
# Gera o mapa proprio do Ceara (PMTiles, esquema Protomaps v4) a partir do build diario publico do Protomaps
# (dados OpenStreetMap, licenca ODbL). Roda no GitHub Actions, nunca na VPS: o recorte baixa so os pedacos
# da area pedida e o resultado vai para a VPS por rsync (mapa-instalar.sh). Gatilho: [cardapim-mapa].
# Uso: mapa-gerar.sh <pasta-de-saida>   (precisa do pmtiles: go install github.com/protomaps/go-pmtiles@v1.31.2)
set -Eeuo pipefail

saida="${1:?Uso: mapa-gerar.sh <pasta-de-saida>}"
# Ceara com ~0,3 grau de margem nas divisas (PI, RN, PB, PE): min_lon,min_lat,max_lon,max_lat
bbox="-41.75,-8.20,-36.90,-2.45"
zoom_max=15
arquivo="$saida/ceara.pmtiles"

command -v pmtiles >/dev/null || { echo "pmtiles nao instalado"; exit 1; }
mkdir -p "$saida"

# Build mais recente disponivel (sai um por dia; procura ate 14 dias para tras).
origem=""
for dias in $(seq 0 14); do
  data="$(date -u -d "-$dias day" +%Y%m%d)"
  if curl -fsSI --max-time 20 "https://build.protomaps.com/$data.pmtiles" >/dev/null 2>&1; then
    origem="https://build.protomaps.com/$data.pmtiles"
    break
  fi
done
[[ -n "$origem" ]] || { echo "Nenhum build do Protomaps encontrado nos ultimos 14 dias"; exit 1; }
echo "Origem: $origem"

pmtiles extract "$origem" "$arquivo" --bbox="$bbox" --maxzoom="$zoom_max" --download-threads=4
pmtiles verify "$arquivo"
pmtiles show "$arquivo" | tee "$saida/ceara.txt"
grep -qi 'tile type: *mvt\|tile type: *vector' "$saida/ceara.txt" || { echo "Arquivo nao e de vetores (MVT)"; exit 1; }

tamanho="$(stat -c %s "$arquivo")"
# Menos de 5 MB = recorte vazio ou errado; mais de 2 GB = recorte maior que o Ceara.
(( tamanho > 5*1024*1024 && tamanho < 2*1024*1024*1024 )) || { echo "Tamanho inesperado: $tamanho bytes"; exit 1; }
sha256sum "$arquivo" | cut -d' ' -f1 > "$arquivo.sha256"
echo "Mapa pronto: $arquivo ($((tamanho / 1024 / 1024)) MB, origem $(basename "$origem"))"
