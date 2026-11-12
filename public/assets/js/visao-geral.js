/*
 * Visao geral: os numeros se atualizam sozinhos, sem recarregar a pagina.
 * - Quando chega um evento ao vivo de pedidos, caixa, mesas ou estoque, busca a tela de novo e troca so o conteudo.
 * - Sem conexao ao vivo, atualiza a cada 60 s enquanto a aba estiver visivel.
 */
(() => {
  "use strict";

  const SELETOR = "[data-visao-geral]";
  const CANAIS = ["pedidos", "caixa", "mesas", "estoque"];
  const INTERVALO = 60000;
  let espera = null;
  let buscando = false;
  let ultima = Date.now();

  async function atualizar() {
    const atual = document.querySelector(SELETOR);
    if (!atual || buscando) return;
    buscando = true;
    try {
      const resp = await fetch(window.location.href, { credentials: "same-origin", headers: { Accept: "text/html" }, cache: "no-store" });
      if (!resp.ok || resp.redirected) return;
      const doc = new DOMParser().parseFromString(await resp.text(), "text/html");
      const novo = doc.querySelector(SELETOR);
      if (!novo) return;
      atual.replaceWith(novo);
      ultima = Date.now();
    } catch (e) {
      // Sem rede: tenta de novo no proximo evento ou no proximo intervalo.
    } finally {
      buscando = false;
    }
  }

  function agendar(ms) {
    clearTimeout(espera);
    espera = setTimeout(atualizar, ms);
  }

  document.addEventListener("cardapim:tempo-real", (ev) => {
    const msg = ev.detail || {};
    if (CANAIS.indexOf(msg.canal) === -1) return;
    ev.preventDefault(); // esta tela se atualiza sozinha: nao precisa do aviso "Atualizar agora"
    agendar(1200);
  });

  setInterval(() => {
    if (document.visibilityState === "visible" && Date.now() - ultima >= INTERVALO) atualizar();
  }, 15000);

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && Date.now() - ultima >= INTERVALO) agendar(300);
  });
})();
