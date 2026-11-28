/*
 * Popup "Confirmar pagamento Pix" no pedido do delivery (Pix pela chave do restaurante), carregado com defer.
 * - Abre sozinho logo depois do "Aceitar" (dialog com data-abrir) e tira o ?confirmar-pix=1 do endereco,
 *   para nao abrir de novo ao recarregar.
 * - O botao "Confirmar pagamento Pix" da tela abre de novo; "Confirmar depois", o X e o toque no fundo fecham.
 */
(() => {
  "use strict";

  const abrir = (dialogo) => {
    if (!dialogo) return;
    if (typeof dialogo.showModal === "function") {
      if (!dialogo.open) dialogo.showModal();
    } else {
      dialogo.setAttribute("open", "");
    }
    const principal = dialogo.querySelector("button[type=submit]");
    if (principal) principal.focus();
  };

  const fechar = (dialogo) => {
    if (!dialogo) return;
    if (typeof dialogo.close === "function") dialogo.close(); else dialogo.removeAttribute("open");
  };

  document.addEventListener("click", (ev) => {
    const botao = ev.target.closest("[data-confirmar-pix-abrir]");
    if (botao) {
      abrir(document.getElementById(botao.dataset.confirmarPixAbrir));
      return;
    }
    const x = ev.target.closest("[data-confirmar-pix] [data-fechar-dialogo]");
    if (x) {
      fechar(x.closest("dialog"));
      return;
    }
    if (ev.target instanceof HTMLDialogElement && ev.target.matches("[data-confirmar-pix]")) fechar(ev.target); // toque no fundo
  });

  const inicial = document.querySelector("[data-confirmar-pix][data-abrir]");
  if (inicial) {
    abrir(inicial);
    const url = new URL(window.location.href);
    if (url.searchParams.has("confirmar-pix")) {
      url.searchParams.delete("confirmar-pix");
      window.history.replaceState(null, "", url.pathname + url.search + url.hash);
    }
  }
})();
