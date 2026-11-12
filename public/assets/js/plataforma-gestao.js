/* Painel da plataforma > Usuarios: administrador nao pertence a restaurante (esconde a escolha do restaurante). */
(() => {
  "use strict";
  const form = document.querySelector("[data-conta-adm]");
  if (!form) return;
  const campo = form.querySelector("[data-campo-restaurante]");
  const select = campo ? campo.querySelector("select") : null;
  const atualizar = () => {
    const marcado = form.querySelector("[data-cargo]:checked");
    const adm = !!marcado && marcado.value === "adm_master";
    if (!campo) return;
    campo.hidden = adm;
    if (select) select.disabled = adm;
  };
  form.querySelectorAll("[data-cargo]").forEach((r) => r.addEventListener("change", atualizar));
  atualizar();
})();
