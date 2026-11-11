/* Financeiro: no formulario de conta, mostra so as categorias do tipo escolhido (pagar ou receber). */
(() => {
  "use strict";

  const form = document.querySelector("[data-conta-form]");
  if (!form) return;

  const grupos = form.querySelectorAll("[data-categorias]");
  const trocar = (tipo) => {
    grupos.forEach((grupo) => {
      const ativo = grupo.dataset.categorias === tipo;
      grupo.hidden = !ativo;
      const select = grupo.querySelector("select");
      if (!select) return;
      select.disabled = !ativo;
      if (!ativo) select.value = "";
    });
  };

  form.querySelectorAll("[data-tipo-conta]").forEach((radio) => {
    radio.addEventListener("change", () => {
      if (radio.checked) trocar(radio.value);
    });
  });
})();
