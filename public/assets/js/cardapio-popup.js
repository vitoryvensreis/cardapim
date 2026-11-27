/*
 * Popup do cardapio (carregado com defer): abre uma vez por visita. A chave tem a versao do popup, entao quando o
 * restaurante muda o popup ele aparece de novo. Fecha pelo X, por "Ver cardapio", Esc ou toque fora da caixa.
 */
(() => {
  "use strict";

  const popup = document.querySelector("[data-popup]");
  if (!popup || typeof popup.showModal !== "function") return;
  const chave = `cardapim-popup:${popup.dataset.popupChave || ""}`;

  const jaViu = () => {
    try { return window.sessionStorage.getItem(chave) === "1"; } catch (e) { return false; }
  };
  const marcar = () => {
    try { window.sessionStorage.setItem(chave, "1"); } catch (e) { /* sem armazenamento: abre so nesta carga */ }
  };

  const fechar = () => { if (popup.open) popup.close(); };
  popup.addEventListener("click", (ev) => {
    if (ev.target.closest("[data-popup-fechar]") || ev.target === popup) fechar(); // toque no fundo escuro
  });

  if (jaViu()) return;
  window.setTimeout(() => {
    // Nao abre por cima de outra janela do cardapio (produto, sacola, entrar).
    if (document.querySelector("dialog[open]")) return;
    marcar();
    popup.showModal();
    popup.querySelector(".cd-popup-ok")?.focus();
  }, 350);
})();
