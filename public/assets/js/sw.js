/*
 * Cardapim - service worker do app instalavel (servido em /pwa/sw pelo PwaController, com Service-Worker-Allowed: / para valer no site todo).
 * Regra de seguranca: NUNCA guarda paginas logadas nem respostas da API (dados do restaurante e de clientes).
 * - Paginas: sempre da internet. Sem conexao, mostra /offline.html (pagina estatica, sem dados de ninguem).
 * - Guarda so os arquivos da pagina offline. O resto do site segue o cache normal do navegador.
 * Ao mudar a lista abaixo ou a pagina offline, troque a VERSAO para o navegador baixar de novo.
 */
const VERSAO = "cardapim-offline-v2";
const OFFLINE = "/offline.html";
const ARQUIVOS = [
  OFFLINE,
  "/assets/css/core.css",
  "/assets/css/pages.css",
  "/assets/icons/lucide.svg",
  "/assets/img/logo-preta.svg",
];

self.addEventListener("install", (evento) => {
  evento.waitUntil(
    caches.open(VERSAO)
      .then((cache) => cache.addAll(ARQUIVOS.map((url) => new Request(url, { cache: "reload" }))))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (evento) => {
  evento.waitUntil(
    caches.keys()
      .then((nomes) => Promise.all(nomes.filter((n) => n.startsWith("cardapim-") && n !== VERSAO).map((n) => caches.delete(n))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (evento) => {
  const pedido = evento.request;
  if (pedido.method !== "GET") return;
  const url = new URL(pedido.url);
  if (url.origin !== self.location.origin) return;

  // Abrir uma pagina: internet primeiro; sem conexao, a pagina offline.
  if (pedido.mode === "navigate") {
    evento.respondWith(
      fetch(pedido).catch(() => caches.match(OFFLINE, { ignoreSearch: true }).then((r) => r || Response.error())),
    );
    return;
  }

  // Arquivos da pagina offline: internet primeiro, guardado so como reserva.
  if (ARQUIVOS.includes(url.pathname)) {
    evento.respondWith(fetch(pedido).catch(() => caches.match(url.pathname).then((r) => r || Response.error())));
  }
});
