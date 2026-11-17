/* Situação pública da loja em abas já abertas. Não consulta a API do iFood. */
(() => {
  "use strict";
  const slug = document.body.dataset.cardapio;
  const badge = document.querySelector("[data-estado-loja]");
  if (!slug || !badge) return;
  const rotulo = badge.querySelector("[data-estado-texto]");
  const aviso = document.querySelector("[data-aviso-loja]");
  const texto = aviso?.querySelector("[data-aviso-loja-texto]");
  let consultando = false;
  const conferir = async () => {
    if (consultando || document.hidden) return;
    consultando = true;
    try {
      const resposta = await fetch("/api/v1/restaurantes/" + encodeURIComponent(slug) + "/situacao", {
        credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" },
      });
      if (!resposta.ok) return;
      const info = await resposta.json();
      if (typeof info.aberto !== "boolean" || typeof info.fechado_integracao !== "boolean") return;
      const fechado = !info.aberto;
      const tipo = info.fechado_integracao ? "fechado" : (fechado ? "fechado" : "aberto");
      badge.classList.remove("cd-status-neutro", "cd-status-aberto", "cd-status-fechado");
      badge.classList.add("cd-status-" + tipo);
      if (rotulo) rotulo.textContent = info.fechado_integracao ? "Fechado temporariamente" : (fechado ? "Fechado agora" : "Aberto agora");
      const use = badge.querySelector("svg use");
      if (use) {
        const href = use.getAttribute("href") || "/assets/icons/lucide.svg#clock";
        use.setAttribute("href", href.split("#")[0] + "#" + (info.fechado_integracao ? "circle-pause" : (fechado ? "clock" : "circle-check")));
      }
      if (aviso) {
        aviso.hidden = !fechado;
        if (texto) texto.textContent = info.fechado_integracao
          ? "O restaurante está temporariamente fechado. Você pode montar sua sacola e pedir quando ele abrir."
          : "O restaurante está fechado agora. Você pode montar sua sacola e pedir quando ele abrir.";
      }
    } catch {
      // Sem resposta não inventa abertura nem descarta o estado da última consulta.
    } finally {
      consultando = false;
    }
  };
  document.addEventListener("visibilitychange", () => { if (!document.hidden) conferir(); });
  window.setInterval(conferir, 10000);
})();
