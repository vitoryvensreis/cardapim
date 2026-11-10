/* Cardapim - painel do gerente: menu lateral em gaveta no celular e tablet. */
(() => {
  "use strict";

  const menu = document.querySelector("[data-painel-menu]");
  const abrir = document.querySelector("[data-painel-abrir]");
  const fechar = document.querySelector("[data-painel-fechar]");
  const overlay = document.querySelector("[data-painel-overlay]");
  if (!menu || !abrir || !overlay) return;

  const desktop = window.matchMedia("(min-width: 64rem)");

  const setAberto = (aberto) => {
    menu.classList.toggle("is-open", aberto);
    abrir.setAttribute("aria-expanded", String(aberto));
    overlay.hidden = !aberto;
    document.body.style.overflow = aberto ? "hidden" : "";
    if (aberto) {
      (menu.querySelector('[aria-current="page"]') || menu.querySelector("a, button"))?.focus();
    }
  };

  abrir.addEventListener("click", () => setAberto(true));
  fechar?.addEventListener("click", () => { setAberto(false); abrir.focus(); });
  overlay.addEventListener("click", () => setAberto(false));
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && menu.classList.contains("is-open")) {
      setAberto(false);
      abrir.focus();
    }
  });
  menu.querySelectorAll("a").forEach((link) => link.addEventListener("click", () => setAberto(false)));
  desktop.addEventListener("change", (event) => { if (event.matches) setAberto(false); });
})();
