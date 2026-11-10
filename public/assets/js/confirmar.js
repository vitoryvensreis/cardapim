/* Cardapim - confirmacao de acoes criticas e avisos do painel.
   Formularios com data-confirm abrem uma janela antes de enviar. Sem JS, o envio acontece direto
   (o servidor valida tudo de qualquer forma). */
(() => {
  "use strict";

  const dialogo = document.querySelector("[data-confirmar-dialogo]");
  const titulo = document.querySelector("[data-confirmar-titulo]");
  const texto = document.querySelector("[data-confirmar-texto]");
  const ok = document.querySelector("[data-confirmar-ok]");

  if (dialogo && typeof dialogo.showModal === "function") {
    let pendente = null;
    document.addEventListener("submit", (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmado === "1") return;
      event.preventDefault();
      pendente = form;
      titulo.textContent = form.dataset.confirmTitulo || "Tem certeza?";
      texto.textContent = form.dataset.confirm;
      ok.textContent = form.dataset.confirmAcao || "Confirmar";
      ok.classList.toggle("is-perigo", form.dataset.confirmPerigo === "1");
      dialogo.showModal();
    });
    dialogo.addEventListener("close", () => {
      if (dialogo.returnValue === "confirmar" && pendente) {
        pendente.dataset.confirmado = "1";
        pendente.requestSubmit ? pendente.requestSubmit() : pendente.submit();
      }
      pendente = null;
    });
  }

  /* Fechar avisos */
  document.querySelectorAll("[data-flash-fechar]").forEach((botao) => {
    botao.addEventListener("click", () => botao.closest("[data-flash]")?.remove());
  });

  /* Filtro instantaneo de listas: input[data-filtro="seletor da lista"] filtra linhas por texto. */
  document.querySelectorAll("[data-filtro]").forEach((input) => {
    const lista = document.querySelector(input.dataset.filtro);
    const vazio = document.querySelector(input.dataset.filtroVazio || "");
    if (!lista) return;
    const normalizar = (t) => t.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
    input.addEventListener("input", () => {
      const busca = normalizar(input.value.trim());
      let visiveis = 0;
      lista.querySelectorAll("[data-filtro-item]").forEach((item) => {
        const mostra = busca === "" || normalizar(item.dataset.filtroItem).includes(busca);
        item.hidden = !mostra;
        if (mostra) visiveis += 1;
      });
      if (vazio) vazio.hidden = visiveis > 0;
    });
  });
})();
