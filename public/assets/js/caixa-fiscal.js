/**
 * Atualiza a etiqueta da nota fiscal quando chega o evento "fiscal.documento" pelo tempo real,
 * sem recarregar a página. Carregado só nas telas que mostram a nota.
 */
(() => {
  "use strict";

  const bloco = document.querySelector("[data-nota-fiscal]");
  if (!bloco) return;

  let rotulos = {};
  try { rotulos = JSON.parse(bloco.dataset.notaRotulos || "{}"); } catch (_) { rotulos = {}; }

  const classes = { autorizada: "etiqueta-ok", rejeitada: "etiqueta-alerta", bloqueada: "etiqueta-alerta", cancelada: "etiqueta-neutra" };
  const textos = {
    pendente: "A nota está sendo emitida. Não precisa fazer nada.",
    enviando: "A nota está sendo emitida. Não precisa fazer nada.",
    incerta: "Aguardando a confirmação da Sefaz. O sistema confere sozinho.",
    autorizada: "Nota emitida. Atualize a página para ver o número.",
    rejeitada: "A Sefaz recusou a nota. Atualize a página para ver o motivo.",
    bloqueada: "Falta acertar o cadastro fiscal. Atualize a página para ver o que falta.",
    cancelada: "A nota desta venda foi cancelada.",
  };

  document.addEventListener("cardapim:tempo-real", (ev) => {
    const msg = ev.detail || {};
    if (msg.tipo !== "fiscal.documento") return;
    ev.preventDefault();
    const dados = msg.dados || {};
    if (String(dados.pedido) !== bloco.dataset.notaFiscal || typeof dados.status !== "string") return;

    const etiqueta = bloco.querySelector("[data-nota-etiqueta]");
    const rotulo = bloco.querySelector("[data-nota-rotulo]");
    const texto = bloco.querySelector("[data-nota-texto]");
    if (etiqueta) {
      etiqueta.classList.remove("etiqueta-ok", "etiqueta-alerta", "etiqueta-neutra", "etiqueta-atencao");
      etiqueta.classList.add(classes[dados.status] || "etiqueta-atencao");
    }
    if (rotulo && rotulos[dados.status]) rotulo.textContent = rotulos[dados.status];
    if (texto && textos[dados.status]) texto.textContent = textos[dados.status];
    const reenviar = bloco.querySelector("[data-nota-reenviar]");
    if (reenviar && dados.status !== "rejeitada" && dados.status !== "bloqueada") reenviar.hidden = true;
  });
})();
