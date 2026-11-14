/*
 * Cardapim - camada de ruas dos mapas (Leaflet).
 * Usa o mapa proprio (arquivo PMTiles servido pela nossa VPS, desenhado no aparelho pela protomaps-leaflet)
 * quando ele existe e cobre os pontos da tela. Senao, ou se algo falhar, usa o OpenStreetMap publico,
 * para nunca deixar o cliente sem mapa. Detalhes: docs/architecture/mapa.md.
 *
 * Uso: window.CardapimMapa.base(mapaLeaflet, urlDoArquivo, [{ latitude, longitude }, ...]) -> Promise<"proprio"|"osm">
 */
(() => {
  "use strict";

  const usarOsm = (mapa) => {
    window.L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(mapa);
    return "osm";
  };

  // O cabecalho do arquivo (area coberta e zoom maximo) e lido uma vez por pagina.
  const leituras = new Map();
  const abrir = (url) => {
    if (!leituras.has(url)) {
      const fonte = new window.protomapsL.PmtilesSource(url, true);
      leituras.set(url, fonte.p.getHeader().then((cabecalho) => ({ arquivo: fonte.p, cabecalho })));
    }
    return leituras.get(url);
  };

  const cobre = (c, p) => p.latitude >= c.minLat && p.latitude <= c.maxLat && p.longitude >= c.minLon && p.longitude <= c.maxLon;

  const base = (mapa, url, pontos) => {
    const validos = (pontos || []).filter((p) => p && Number.isFinite(p.latitude) && Number.isFinite(p.longitude));
    if (!url || !window.protomapsL || validos.length === 0) return Promise.resolve(usarOsm(mapa));
    return abrir(url)
      .then(({ arquivo, cabecalho }) => {
        if (!validos.every((p) => cobre(cabecalho, p))) return usarOsm(mapa); // ex.: restaurante de outro estado
        window.protomapsL.leafletLayer({
          url: arquivo,
          flavor: "light",
          lang: "pt",
          maxDataZoom: cabecalho.maxZoom,
          maxZoom: 19,
        }).addTo(mapa);
        return "proprio";
      })
      .catch(() => usarOsm(mapa)); // arquivo indisponivel ou servidor sem HTTP Range
  };

  window.CardapimMapa = { base };
})();
