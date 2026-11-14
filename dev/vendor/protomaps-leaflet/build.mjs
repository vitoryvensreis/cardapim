// Gera public/assets/vendor/protomaps-leaflet/protomaps-leaflet.js a partir do codigo-fonte (sem npm).
//
// Por que existe: o mapa do cliente desenha as ruas a partir de um arquivo PMTiles servido pela nossa VPS
// (docs/architecture/mapa.md). A biblioteca protomaps-leaflet e suas dependencias sao clonadas do GitHub
// nas versoes exatas abaixo e juntadas num unico arquivo IIFE com a global `protomapsL`, igual ao build
// oficial. So precisa do Node e do TypeScript (`npm i -g typescript` ou o que ja vier na maquina).
//
// Uso:
//   bash dev/vendor/protomaps-leaflet/clonar.sh /tmp/protomaps-src
//   node dev/vendor/protomaps-leaflet/build.mjs /tmp/protomaps-src public/assets/vendor/protomaps-leaflet
import { createRequire } from "node:module";
import { execSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const [src, saida] = process.argv.slice(2);
if (!src || !saida) {
  console.error("Uso: node build.mjs <pasta-dos-fontes> <pasta-de-saida>");
  process.exit(1);
}

const requireGlobal = createRequire(path.join(execSync("npm root -g").toString().trim(), "noop.js"));
const ts = requireGlobal("typescript");

const r = (...p) => path.resolve(src, ...p);
// Pacote -> arquivo de entrada (versoes em clonar.sh).
const pacotes = {
  "protomaps-leaflet": r("protomaps-leaflet/src/index.ts"),
  pmtiles: r("PMTiles/js/index.ts"),
  fflate: r("fflate/src/index.ts"),
  "@protomaps/basemaps": r("basemaps/styles/src/index.ts"),
  "@mapbox/point-geometry": r("point-geometry/index.js"),
  "@mapbox/vector-tile": r("vector-tile/index.js"),
  pbf: r("pbf/index.js"),
  color2k: r("color2k/src/index.ts"),
  potpack: r("potpack/index.mjs"),
  rbush: r("rbush/index.js"),
  quickselect: r("quickselect/index.js"),
};

const extensoes = ["", ".ts", ".js", ".mjs", "/index.ts", "/index.js"];
const resolver = (de, pedido) => {
  if (pacotes[pedido]) return pacotes[pedido];
  if (!pedido.startsWith(".")) throw new Error(`Dependencia nao mapeada: ${pedido} (em ${de})`);
  const base = path.resolve(path.dirname(de), pedido);
  for (const ext of extensoes) {
    const f = base + ext;
    if (fs.existsSync(f) && fs.statSync(f).isFile()) return f;
  }
  throw new Error(`Nao encontrei ${pedido} a partir de ${de}`);
};

const modulos = new Map(); // arquivo -> { id, codigo }
const fila = [pacotes["protomaps-leaflet"]];
while (fila.length) {
  const arquivo = fila.shift();
  if (modulos.has(arquivo)) continue;
  const fonte = fs.readFileSync(arquivo, "utf8");
  const saidaTs = ts.transpileModule(fonte, {
    fileName: arquivo.replace(/\.mjs$/, ".js"), // .mjs sairia como ES module; queremos tudo em CommonJS

    reportDiagnostics: true,
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2017,
      esModuleInterop: true,
      allowJs: true,
      removeComments: true,
    },
  });
  const erros = (saidaTs.diagnostics || []).filter((d) => d.category === ts.DiagnosticCategory.Error);
  if (erros.length) throw new Error(`${arquivo}: ${ts.flattenDiagnosticMessageText(erros[0].messageText, "\n")}`);
  let codigo = saidaTs.outputText;
  const id = modulos.size;
  modulos.set(arquivo, { id, codigo: "" });
  // Troca require("x") pelo numero do modulo; "leaflet" so tem tipos (a biblioteca usa a global L).
  codigo = codigo.replace(/require\("([^"]+)"\)/g, (todo, pedido) => {
    if (pedido === "leaflet") return "window.L";
    const alvo = resolver(arquivo, pedido);
    fila.push(alvo);
    return `__req(${JSON.stringify(alvo)})`;
  });
  modulos.get(arquivo).codigo = codigo;
}

const idDe = (arquivo) => modulos.get(arquivo).id;
const partes = [...modulos.entries()].map(([arquivo, { id, codigo }]) => {
  const final = codigo.replace(/__req\(("[^"]+")\)/g, (_, json) => `__req(${idDe(JSON.parse(json))})`);
  return `/* ${path.relative(src, arquivo)} */\n${id}: function (module, exports, __req) {\n${final}\n}`;
});

const versoes = fs.readFileSync(path.join(path.dirname(new URL(import.meta.url).pathname), "clonar.sh"), "utf8")
  .split("\n").filter((l) => l.startsWith("clonar ")).map((l) => " *   " + l.replace(/^clonar /, "")).join("\n");

const bundle = `/*!
 * protomaps-leaflet (BSD-3-Clause) e dependencias, juntados por dev/vendor/protomaps-leaflet/build.mjs.
 * Licencas em public/assets/vendor/protomaps-leaflet/LICENSES.txt. Versoes (repositorio, ref, pasta):
${versoes}
 */
var protomapsL = (function () {
  "use strict";
  var defs = {
${partes.join(",\n")}
  };
  var cache = {};
  function __req(id) {
    if (cache[id]) return cache[id].exports;
    var module = (cache[id] = { exports: {} });
    defs[id](module, module.exports, __req);
    return module.exports;
  }
  return __req(0);
})();
`;

fs.mkdirSync(saida, { recursive: true });
fs.writeFileSync(path.join(saida, "protomaps-leaflet.js"), bundle);
console.log(`${modulos.size} modulos, ${(bundle.length / 1024).toFixed(0)} KB -> ${path.join(saida, "protomaps-leaflet.js")}`);
