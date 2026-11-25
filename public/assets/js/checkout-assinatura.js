/* Abre apenas o checkout autorizado e renderizado pelo servidor. */
(() => {
  "use strict";
  const dialogo = document.querySelector("[data-checkout-assinatura]");
  if (!dialogo || typeof dialogo.showModal !== "function") return;
  const pagar = dialogo.querySelector("[data-checkout-pagar]");
  const fechar = () => { if (dialogo.open) dialogo.close(); };
  dialogo.querySelectorAll("[data-checkout-fechar]").forEach((botao) => {
    botao.addEventListener("click", fechar);
  });
  // Preserva a abertura normal do link Asaas em nova aba, sem criar cobrança.
  pagar?.addEventListener("click", fechar);
  dialogo.addEventListener("close", () => {
    const original = document.querySelector(".fatura-destaque a");
    if (original instanceof HTMLElement) original.focus({ preventScroll: true });
  });
  // Ao voltar pelo histórico do navegador, não reabrir um checkout já exibido.
  window.addEventListener("pagehide", fechar);
  window.addEventListener("pageshow", (evento) => { if (evento.persisted) fechar(); });
  dialogo.showModal();
  pagar?.focus({ preventScroll: true });
})();
