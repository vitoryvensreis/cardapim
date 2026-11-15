/* Modo cozinha: atualização silenciosa e somente leitura. Nunca toca sons, vibra ou mostra alertas. */
(() => {
  "use strict";

  const corpo = document.body;
  const url = corpo.dataset.cozinhaUrl;
  const board = document.querySelector("[data-cozinha-board]");
  if (!url || !board || !window.fetch) return;

  const total = document.querySelector("[data-cozinha-total]");
  const totalTexto = document.querySelector("[data-cozinha-total-texto]");
  const CANAIS = new Set(["pedidos", "cozinha", "mesas"]);
  const INTERVALO = 20000;
  let carregando = false;
  let pendente = false;
  let ultimoHtml = board.innerHTML;

  function atualizarTempos() {
    const agora = Math.floor(Date.now() / 1000);
    board.querySelectorAll("[data-kds-tempo]").forEach((item) => {
      const criado = Number(item.dataset.kdsTempo);
      if (!Number.isFinite(criado) || criado <= 0) return;
      const minutos = Math.max(0, Math.floor((agora - criado) / 60));
      item.textContent = minutos < 60 ? `há ${minutos} min` : `há ${Math.floor(minutos / 60)}h ${String(minutos % 60).padStart(2, "0")}min`;
    });
  }

  async function atualizar() {
    if (document.visibilityState === "hidden") return;
    if (carregando) { pendente = true; return; }
    carregando = true;
    try {
      const resposta = await fetch(url, { credentials: "same-origin", cache: "no-store", headers: { Accept: "text/html" } });
      if (resposta.status === 401 || resposta.status === 403 || !resposta.ok) return;
      const html = await resposta.text();
      if (html !== ultimoHtml) {
        const rolagem = window.scrollY;
        board.innerHTML = html; // HTML escapado na view, recebido da rota autenticada do próprio sistema
        ultimoHtml = html;
        if (total && totalTexto) {
          const quantidade = board.querySelectorAll(".kds-card").length;
          total.textContent = String(quantidade);
          totalTexto.textContent = quantidade === 1 ? "pedido" : "pedidos";
        }
        window.scrollTo(0, rolagem);
      }
      atualizarTempos();
    } catch (erro) {
      // Sem notificações: conserva os pedidos já apresentados e tenta novamente.
    } finally {
      carregando = false;
      if (pendente) { pendente = false; atualizar(); }
    }
  }

  document.addEventListener("cardapim:tempo-real", (evento) => {
    const mensagem = evento.detail || {};
    // Suprime o aviso genérico "Atualizar agora" do tempo-real.js nesta tela.
    evento.preventDefault();
    if (CANAIS.has(mensagem.canal) || mensagem.tipo === "sistema.recarregar") atualizar();
  });
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") atualizar();
  });
  window.addEventListener("online", atualizar);
  window.setInterval(atualizar, INTERVALO);
  window.setInterval(atualizarTempos, 30000);
  atualizarTempos();
})();
