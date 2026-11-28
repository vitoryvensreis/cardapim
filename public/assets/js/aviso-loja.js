/* Aviso da plataforma para o gerente: abre o pop-up ao carregar a tela, registra o clique no botao. */
(() => {
  "use strict";
  const dialogo = document.querySelector("[data-aviso-loja]");
  if (!dialogo || typeof dialogo.showModal !== "function") return;

  const fechar = () => dialogo.close();
  dialogo.querySelectorAll("[data-aviso-fechar]").forEach((b) => b.addEventListener("click", fechar));
  dialogo.addEventListener("click", (ev) => {
    if (ev.target === dialogo) fechar(); /* clique fora da caixa */
  });

  const acao = dialogo.querySelector("[data-aviso-acao]");
  if (acao) {
    acao.addEventListener("click", () => {
      const dados = new FormData();
      dados.append("_csrf", dialogo.dataset.csrf || "");
      /* sendBeacon sobrevive a troca de pagina; sem ele, fetch com keepalive */
      if (!(navigator.sendBeacon && navigator.sendBeacon(dialogo.dataset.clique, dados))) {
        fetch(dialogo.dataset.clique, { method: "POST", body: dados, credentials: "same-origin", keepalive: true }).catch(() => {});
      }
      if (acao.target === "_blank") fechar();
    });
  }

  dialogo.showModal();
})();
