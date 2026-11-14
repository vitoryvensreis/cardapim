#!/usr/bin/env bash
# Baixa o codigo-fonte da protomaps-leaflet e das dependencias nas versoes usadas pelo Cardapim.
# Depois rode build.mjs (ver comentario no topo dele). Para atualizar, troque as refs abaixo.
set -Eeuo pipefail
destino="${1:?Uso: clonar.sh <pasta-de-destino>}"
mkdir -p "$destino"

# clonar <pasta> <repositorio GitHub> <tag ou commit>
clonar() {
  local pasta="$destino/$1"
  [[ -d "$pasta/.git" ]] && return 0
  git init -q "$pasta"
  git -C "$pasta" fetch -q --depth 1 "https://github.com/$2" "$3"
  git -C "$pasta" -c advice.detachedHead=false checkout -q FETCH_HEAD
}

clonar protomaps-leaflet protomaps/protomaps-leaflet 5a153e7d5f49ec903e60eba9700a8ededd5a750e
clonar PMTiles protomaps/PMTiles d7357088c5b971b6d756a43b798182bb6777043d
clonar basemaps protomaps/basemaps 7caa0f50ab2b7215f7e497dd41ab9b4a5067e955
clonar fflate 101arrowz/fflate v0.8.2
clonar point-geometry mapbox/point-geometry v1.1.0
clonar vector-tile mapbox/vector-tile-js v2.0.3
clonar pbf mapbox/pbf v4.0.1
clonar color2k ricokahler/color2k v2.0.3
clonar potpack mapbox/potpack v1.0.2
clonar rbush mourner/rbush v3.0.1
clonar quickselect mourner/quickselect v2.0.0
echo "Fontes prontos em $destino"
