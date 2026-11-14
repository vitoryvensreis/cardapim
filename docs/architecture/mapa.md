# Mapa proprio (PMTiles na VPS)

Mapa do cliente acompanhando a entrega (`/{slug}/pedido/{id}`) sem depender dos servidores voluntarios do
OpenStreetMap (`tile.openstreetmap.org`), que bloqueiam sites com uso alto ("Access blocked ... tile usage policy")
e nao tem garantia. Sem custo por uso e sem chave de API.

## Como funciona

- **Arquivo:** `ceara.pmtiles` (ruas, bairros, agua e nomes; esquema Protomaps v4, dados OpenStreetMap/ODbL),
  recorte do Ceara com ~0,3 grau de margem nas divisas, zoom ate 15 (o aparelho amplia ate 19).
- **Servidor:** o Nginx entrega so os pedacos pedidos do arquivo (HTTP Range, resposta 206). Nao passa pelo PHP
  nem pelo MySQL; o processamento e o de um arquivo estatico. O que pesa e disco (centenas de MB) e trafego.
- **Aparelho:** `public/assets/vendor/protomaps-leaflet/protomaps-leaflet.js` desenha as ruas no canvas, dentro do
  Leaflet que ja usavamos. Credito "Protomaps (c) OpenStreetMap" no canto do mapa (obrigatorio pela ODbL).
- **Escolha da camada:** `public/assets/js/mapa-base.js` (`CardapimMapa.base(mapa, url, pontos)`) le o cabecalho
  do arquivo e usa o mapa proprio se todos os pontos da tela (restaurante, casa, moto) estao dentro da area dele.
  Caso contrario, ou se o arquivo falhar (404, servidor sem HTTP Range), usa o OpenStreetMap publico. O cliente
  nunca fica sem mapa.
- **PHP:** `Services\Geo\MapaBase::url()` devolve `/mapas/ceara.pmtiles?v=<data>` so quando o arquivo existe em
  `public/mapas/`. A view poe isso em `data-mapa-arquivo` na area do mapa; sem arquivo o atributo nao aparece.

## Onde fica na VPS

- Original: `shared/mapas/ceara.pmtiles` (fora das releases, nao e apagado no deploy).
- Cada release recebe um link fisico em `public/mapas/ceara.pmtiles` (`activate-release.sh`). Link fisico, e nao
  simbolico, para o Nginx ler pela pasta `public` da release sem precisar atravessar `shared/`.
- `public/mapas/` e `*.pmtiles` estao no `.gitignore`.

## Gerar, instalar e atualizar

Commit na `main` com **`[cardapim-mapa]`** na mensagem. Depois do deploy do codigo, o GitHub Actions:

1. instala o `pmtiles` (`go install github.com/protomaps/go-pmtiles@v1.31.2`, conferido pelo sum.golang.org);
2. `deploy/scripts/mapa-gerar.sh`: acha o build diario mais recente em `build.protomaps.com`, recorta o Ceara
   (`pmtiles extract --bbox --maxzoom=15`), roda `pmtiles verify` e confere tipo (mvt) e tamanho;
3. envia por rsync para `shared/mapas/ceara.pmtiles.novo` (+ sha256);
4. `deploy/scripts/mapa-instalar.sh` na VPS: confere sha256 e cabecalho, troca o arquivo de uma vez (`mv`),
   liga na release atual e testa no site um pedaco de 127 bytes (espera `206 127`).

Para atualizar as ruas (sugestao: a cada 3 a 6 meses), basta outro commit com `[cardapim-mapa]`
(ex.: `git commit --allow-empty -m "Atualiza o mapa [cardapim-mapa]"`). Releases antigas seguram o arquivo
anterior ate serem apagadas.

**Outros estados:** trocar o `bbox` em `mapa-gerar.sh` (ex.: Nordeste) e ajustar o limite de tamanho. O codigo
nao muda: a area coberta vem do cabecalho do arquivo.

## Se o teste de pedaco falhar

`mapa-instalar.sh` sai com erro se o site nao responder `206` a um pedido com `Range`. As telas continuam com o
OpenStreetMap. Conferir no Nginx/Varnish (CloudPanel) se `/mapas/` aceita Range; no Varnish, de preferencia
nao guardar `/mapas/` em cache (`return (pass)` para `req.url ~ "^/mapas/"`), porque o arquivo e grande.

## Biblioteca (sem npm)

`protomaps-leaflet.js` e montado a partir do codigo-fonte por `dev/vendor/protomaps-leaflet/`:

```bash
bash dev/vendor/protomaps-leaflet/clonar.sh /tmp/protomaps-src
node dev/vendor/protomaps-leaflet/build.mjs /tmp/protomaps-src public/assets/vendor/protomaps-leaflet
```

Versoes fixas em `clonar.sh` (protomaps-leaflet 5.1.0, pmtiles 3.2.1, @protomaps/basemaps 5.0.0 e dependencias);
licencas em `public/assets/vendor/protomaps-leaflet/LICENSES.txt` (BSD-3, MIT e ISC).

## Fora do escopo (proximos passos)

- Os links "Ver no mapa" do caixa e da gerencia abrem o site openstreetmap.org (uso normal do site, permitido).
- Endereco -> coordenadas continua no Nominatim publico (1 consulta/s, cache de 30 dias; `Services/Geo/Geocodificador`).
  Com muito volume, precisa de solucao propria.
