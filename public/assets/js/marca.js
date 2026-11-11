/* Configuracoes da marca: mostra a logo escolhida antes de enviar e libera o botao "Enviar". */
(() => {
  "use strict";

  document.querySelectorAll("[data-logo-form]").forEach((form) => {
    const input = form.querySelector("input[type=file]");
    const previa = form.closest("[data-logo]")?.querySelector("[data-logo-previa]");
    const botao = form.querySelector("[data-logo-enviar]");
    const nome = form.querySelector("[data-logo-nome]");
    if (!input) return;
    let url = null;
    input.addEventListener("change", () => {
      const arquivo = input.files?.[0];
      if (url) URL.revokeObjectURL(url);
      url = null;
      if (botao) botao.disabled = !arquivo;
      if (nome) nome.textContent = arquivo ? arquivo.name : "";
      if (arquivo && previa && arquivo.size <= 2 * 1024 * 1024) {
        url = URL.createObjectURL(arquivo);
        previa.src = url;
      }
    });
  });
})();
