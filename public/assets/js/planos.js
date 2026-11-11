/* Planos da plataforma: copiar o link de convite e esconder o preco quando o plano e vitalicio (sempre gratis). */
(() => {
  "use strict";

  document.querySelectorAll("[data-copiar]").forEach((botao) => {
    botao.addEventListener("click", async () => {
      const aviso = botao.closest(".convite-caixa")?.querySelector("[data-copiado]");
      try {
        await navigator.clipboard.writeText(botao.dataset.copiar);
        if (aviso) aviso.textContent = "Link copiado! Agora é só colar no WhatsApp ou e-mail.";
      } catch {
        if (aviso) aviso.textContent = "Não deu para copiar. Selecione o link e copie manualmente.";
      }
    });
  });

  const form = document.querySelector("[data-plano-form]");
  if (!form) return;
  const campoValor = form.querySelector("[data-campo-valor]");
  const aviso = form.querySelector("[data-aviso-vitalicio]");
  const atualizar = () => {
    const vitalicio = form.querySelector("[data-periodo]:checked")?.value === "vitalicio";
    if (campoValor) campoValor.hidden = vitalicio;
    if (aviso) aviso.hidden = !vitalicio;
  };
  form.querySelectorAll("[data-periodo]").forEach((r) => r.addEventListener("change", atualizar));
  atualizar();
})();
