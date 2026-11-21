/*
 * Imprimir pelo Deliveryzim Agent (caixa e garcom), carregado com defer.
 * Formularios [data-imprimir-agent]: enviam sem sair da pagina, o servidor monta o cupom e o Agent do
 * computador da impressora imprime. Nunca abre a janela de impressao do navegador.
 * Depois do envio pergunta a situacao (Imprimindo / Impresso / falha) por ate 30 s e mostra no aviso.
 * Sem JavaScript o formulario envia normalmente e a pagina volta com o aviso.
 */
(() => {
  "use strict";

  const PERGUNTAR_MS = 1500;
  const LIMITE_MS = 30000;

  let aviso = null;
  let avisoTempo = 0;
  const avisar = (texto, tipo, fixo = false) => {
    if (!aviso) {
      aviso = document.createElement("div");
      aviso.className = "caixa-toast impressao-aviso";
      aviso.setAttribute("role", "status");
      aviso.setAttribute("aria-live", "polite");
      document.body.append(aviso);
    }
    aviso.textContent = texto;
    aviso.dataset.tipo = tipo;
    aviso.hidden = false;
    aviso.classList.remove("is-saindo");
    window.clearTimeout(avisoTempo);
    if (fixo) return;
    avisoTempo = window.setTimeout(() => {
      aviso.classList.add("is-saindo");
      window.setTimeout(() => { aviso.hidden = true; }, 220);
    }, tipo === "erro" ? 7000 : 3500);
  };

  const lerJson = async (resposta) => {
    try { return await resposta.json(); } catch (_) { return {}; }
  };

  const acompanhar = async (url) => {
    const inicio = Date.now();
    while (Date.now() - inicio < LIMITE_MS) {
      await new Promise((ok) => window.setTimeout(ok, PERGUNTAR_MS));
      let dados = {};
      try {
        const r = await fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin", cache: "no-store" });
        if (!r.ok) continue;
        dados = await lerJson(r);
      } catch (_) {
        continue; // internet oscilou: tenta de novo
      }
      if (dados.status === "impresso") {
        avisar(dados.mensagem || "Impresso.", "sucesso");
        document.dispatchEvent(new CustomEvent("cardapim:impresso")); // ex.: caixa e garcom atualizam a comanda
        return;
      }
      if (dados.status === "erro" || dados.status === "expirada") { avisar(dados.mensagem || "Não foi possível imprimir.", "erro"); return; }
      avisar(dados.mensagem || "Imprimindo...", "info", true);
    }
    avisar("O computador da impressora não confirmou. Confira se o cupom saiu.", "erro");
  };

  // Escolha da impressora: fecha ao tocar fora ou com Esc.
  document.addEventListener("click", (ev) => {
    document.querySelectorAll("[data-imprimir-escolha][open]").forEach((d) => {
      if (!d.contains(ev.target)) d.removeAttribute("open");
    });
  });
  document.addEventListener("keydown", (ev) => {
    if (ev.key !== "Escape") return;
    document.querySelectorAll("[data-imprimir-escolha][open]").forEach((d) => {
      d.removeAttribute("open");
      d.querySelector("summary")?.focus();
    });
  });

  document.addEventListener("submit", async (ev) => {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement) || !form.matches("[data-imprimir-agent]")) return;
    ev.preventDefault();
    if (form.dataset.enviando === "1") return;
    const botao = ev.submitter instanceof HTMLButtonElement ? ev.submitter : form.querySelector("button[type=submit]");
    const dados = new FormData(form);
    if (botao && botao.name) dados.set(botao.name, botao.value);
    form.dataset.enviando = "1";
    form.querySelectorAll("button").forEach((b) => { b.disabled = true; });
    form.setAttribute("aria-busy", "true");
    avisar("Enviando para a impressora...", "info", true);
    try {
      const r = await fetch(form.action, {
        method: "POST",
        body: dados,
        headers: { Accept: "application/json", "X-Requested-With": "fetch" },
        credentials: "same-origin",
      });
      const resposta = await lerJson(r);
      if (!r.ok || !resposta.ok) {
        avisar(resposta.erro || "Não foi possível enviar para a impressora.", "erro");
        return;
      }
      form.closest("details")?.removeAttribute("open");
      avisar(resposta.mensagem || "Enviado para a impressora.", "info", true);
      if (resposta.situacao) await acompanhar(resposta.situacao);
    } catch (_) {
      avisar("Sem conexão com o sistema. Confira a internet e tente de novo.", "erro");
    } finally {
      delete form.dataset.enviando;
      form.removeAttribute("aria-busy");
      form.querySelectorAll("button").forEach((b) => { if (!b.hasAttribute("data-sempre-desligado")) b.disabled = false; });
    }
  });
})();
