/*
 * "Trocar forma de pagamento" no delivery (motoboy e caixa), carregado com defer.
 * - Abre o popup (dialog) do pedido e mostra, para a forma escolhida, a taxa e o "Receber" (valores do servidor).
 * - Dinheiro: campo opcional "Troco para". Pix por QR Code sem CPF salvo: pede o CPF.
 * - Tela do Pix da entrega: desenha o QR Code e consulta a situacao a cada 4 s ate o Pix cair.
 * Eventos delegados no document: o painel do entregador troca a lista sem recarregar a pagina.
 */
(() => {
  "use strict";
  const dinheiro = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
  const reais = (c) => dinheiro.format(c / 100).replace(/ /g, " ");

  document.addEventListener("click", (ev) => {
    const abrir = ev.target.closest("[data-trocar-forma-abrir]");
    if (abrir) {
      const d = document.getElementById(abrir.dataset.trocarFormaAbrir);
      if (!d) return;
      if (typeof d.showModal === "function") d.showModal(); else d.setAttribute("open", "");
      const primeira = d.querySelector("input[name=escolha]:not([disabled])");
      if (primeira) primeira.focus();
      return;
    }
    const fechar = ev.target.closest("[data-troca-forma] [data-fechar-dialogo]");
    if (fechar) { fechar.closest("dialog").close(); return; }
    if (ev.target instanceof HTMLDialogElement && ev.target.matches("[data-troca-forma]")) ev.target.close(); // toque no fundo
  });

  document.addEventListener("change", (ev) => {
    const radio = ev.target;
    if (!(radio instanceof HTMLInputElement) || radio.name !== "escolha") return;
    const d = radio.closest("[data-troca-forma]");
    if (!d) return;
    const taxa = parseInt(radio.dataset.taxa || "0", 10);
    const total = parseInt(radio.dataset.total || "0", 10);
    const taxaAtual = parseInt(d.dataset.taxaAtual || "0", 10);
    const forma = radio.dataset.forma;
    const linha = d.querySelector("[data-troca-taxa-linha]");
    if (taxa > 0) {
      linha.textContent = `${radio.dataset.taxaRotulo || "Taxa"} + ${reais(taxa)}`;
      linha.hidden = false;
    } else if (taxaAtual > 0) {
      linha.textContent = `A taxa de ${reais(taxaAtual)} sai.`;
      linha.hidden = false;
    } else {
      linha.hidden = true;
    }
    d.querySelector("[data-troca-total]").textContent = reais(total);
    d.querySelector("[data-troca-resumo]").hidden = false;
    const troco = d.querySelector("[data-troca-troco]");
    if (troco) troco.hidden = forma !== "dinheiro";
    const cpf = d.querySelector("[data-troca-cpf]");
    if (cpf) {
      cpf.hidden = forma !== "pix_online";
      cpf.querySelector("input").required = forma === "pix_online";
    }
    const dica = d.querySelector("[data-troca-qr-dica]");
    if (dica) dica.hidden = forma !== "pix_online";
    const enviar = d.querySelector("[data-troca-enviar]");
    if (enviar) enviar.lastChild.textContent = forma === "pix_online" ? "Gerar QR Code do Pix" : "Confirmar troca";
  });

  document.addEventListener("submit", (ev) => {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement) || !form.matches("[data-troca-form]")) return;
    const botao = form.querySelector("[data-troca-enviar]");
    if (botao) {
      if (botao.dataset.enviando) { ev.preventDefault(); return; }
      botao.dataset.enviando = "1";
      botao.setAttribute("aria-busy", "true");
    }
  });

  /* ---------- Tela do Pix da entrega (motoboy) ---------- */
  const qr = document.querySelector("[data-qr]");
  if (qr && typeof window.qrcode === "function") {
    try {
      const codigo = window.qrcode(0, "M");
      codigo.addData(qr.dataset.qr);
      codigo.make();
      qr.innerHTML = codigo.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    } catch (e) {
      qr.textContent = "Use o código copia e cola abaixo.";
    }
  }
  const copiar = document.querySelector("[data-copiar]");
  if (copiar) {
    copiar.addEventListener("click", async () => {
      try { await navigator.clipboard.writeText(copiar.dataset.copiar); } catch (e) { /* sem area de transferencia */ }
      const texto = copiar.querySelector("[data-copiar-texto]");
      if (texto) {
        texto.textContent = "Copiado";
        window.setTimeout(() => { texto.textContent = "Copiar código"; }, 2500);
      }
    });
  }
  const pix = document.querySelector("[data-pix-entrega]");
  if (pix) {
    const consultar = async () => {
      try {
        const r = await fetch(pix.dataset.pixEntrega, { headers: { Accept: "application/json" }, credentials: "same-origin", cache: "no-store" });
        const j = await r.json();
        if (j.status === "pago") {
          document.querySelector("[data-pix-esperando]").hidden = true;
          document.querySelector("[data-pix-pago]").hidden = false;
          if (navigator.vibrate) navigator.vibrate([150, 80, 150]);
          window.setTimeout(() => { window.location.href = j.url; }, 2500);
          return;
        }
        if (j.status === "sem_qr") { window.location.href = j.url; return; }
      } catch (e) { /* tenta de novo */ }
      window.setTimeout(consultar, 4000);
    };
    window.setTimeout(consultar, 4000);
  }
})();
