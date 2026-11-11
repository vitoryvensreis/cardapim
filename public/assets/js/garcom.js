/* =========================================================================
   Painel do garcom: filtro e atualizacao das mesas, folha do cardapio (quantidades, busca e observacao),
   fechar conta (10%, dividir, forma, troco), Pix com QR Code (consulta ate pagar) e consulta do cardapio.
   Tudo funciona sem JavaScript (formularios comuns); aqui so fica mais rapido e visual.
   ========================================================================= */
(() => {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const reais = (c) => "R$ " + (Math.max(0, c) / 100).toFixed(2).replace(".", ",").replace(/\B(?=(\d{3})+(?!\d))/g, ".");
  const centavos = (texto) => {
    const limpo = String(texto || "").replace(/[^\d,]/g, "");
    if (!limpo) return 0;
    const [inteiro, frac = ""] = limpo.split(",");
    return parseInt(inteiro || "0", 10) * 100 + parseInt((frac + "00").slice(0, 2), 10);
  };
  const campo = (c) => (c / 100).toFixed(2).replace(".", ",").replace(/\B(?=(\d{3})+(?!\d))/g, ".");
  const semAcento = (t) => String(t || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
  const visivel = () => document.visibilityState === "visible";

  /* ---------- Avisos que somem sozinhos ---------- */
  $$("[data-flash]").forEach((f) => {
    if (!f.classList.contains("painel-flash-erro")) window.setTimeout(() => { f.hidden = true; }, 6000);
  });

  /* ---------- Atualizar um pedaco da tela (outro garcom/caixa mexeu) ---------- */
  const trocarRegiao = async (nome) => {
    const atual = $(`[data-regiao="${nome}"]`);
    if (!atual) return;
    try {
      const r = await fetch(window.location.pathname, { headers: { Accept: "text/html" }, credentials: "same-origin", cache: "no-store" });
      if (!r.ok || r.redirected) {
        if (r.redirected) window.location.href = r.url;
        return;
      }
      const doc = new DOMParser().parseFromString(await r.text(), "text/html");
      const nova = $(`[data-regiao="${nome}"]`, doc);
      if (nova) {
        atual.replaceWith(nova);
        iniciarFiltroMesas();
      }
    } catch (e) { /* tenta no proximo ciclo */ }
  };

  /* ---------- Mesas: filtro e atualizacao ---------- */
  let filtroAtual = "todas";
  function iniciarFiltroMesas() {
    const nav = $("[data-mesas-filtro]");
    if (!nav) return;
    const aplicar = () => {
      let mostradas = 0;
      $$("[data-mesa-estado]").forEach((li) => {
        const mostra = filtroAtual === "todas" || li.dataset.mesaEstado === filtroAtual;
        li.hidden = !mostra;
        if (mostra) mostradas++;
      });
      $$("[data-filtro-mesa]", nav).forEach((b) => b.setAttribute("aria-pressed", String(b.dataset.filtroMesa === filtroAtual)));
      const nenhuma = $("[data-mesas-nenhuma]");
      if (nenhuma) nenhuma.hidden = mostradas > 0;
    };
    $$("[data-filtro-mesa]", nav).forEach((b) => b.addEventListener("click", () => { filtroAtual = b.dataset.filtroMesa; aplicar(); }));
    aplicar();
  }
  iniciarFiltroMesas();

  const folhaAberta = () => { const f = $("[data-folha]"); return f && (f.classList.contains("is-aberta") || window.location.hash === "#adicionar"); };
  const regiaoViva = $("[data-regiao='mesas']") ? "mesas" : ($("[data-regiao='comanda']") ? "comanda" : null);
  if (regiaoViva) {
    document.addEventListener("cardapim:tempo-real", (ev) => {
      const msg = ev.detail || {};
      if (msg.canal !== "mesas") return;
      ev.preventDefault(); // esta tela se atualiza sozinha
      if (regiaoViva === "comanda") {
        const id = Number(($("[data-comanda]") || {}).dataset?.comanda || 0);
        if (!msg.dados || Number(msg.dados.id) !== id || folhaAberta()) return;
      }
      trocarRegiao(regiaoViva);
    });
    window.setInterval(() => { if (visivel() && !folhaAberta()) trocarRegiao(regiaoViva); }, 30000);
  }

  /* ---------- Folha do cardapio: escolher quantidades ---------- */
  const folha = $("[data-folha]");
  if (folha) {
    const form = $("[data-lancar]", folha);
    const botao = $("[data-lancar-enviar]", folha);
    const texto = $("[data-lancar-texto]", folha);
    const lista = $("[data-folha-lista]", folha);

    const abrir = (ev) => {
      if (ev) ev.preventDefault();
      folha.classList.add("is-aberta");
      if (window.location.hash !== "#adicionar") history.pushState({ folha: true }, "", "#adicionar");
      document.body.style.overflow = "hidden";
      const busca = $("[data-busca-produto]", folha);
      window.setTimeout(() => (busca && window.matchMedia("(min-width: 48rem)").matches ? busca : folha).focus({ preventScroll: true }), 50);
    };
    const fechar = (ev) => {
      if (ev) ev.preventDefault();
      if (window.location.hash === "#adicionar" && history.state && history.state.folha) {
        history.back();
        return;
      }
      folha.classList.remove("is-aberta");
      document.body.style.overflow = "";
      if (window.location.hash === "#adicionar") history.replaceState(null, "", window.location.pathname);
    };
    $$("[data-abrir-folha]").forEach((a) => a.addEventListener("click", abrir));
    $$("[data-fechar-folha]", folha).forEach((a) => a.addEventListener("click", fechar));
    window.addEventListener("popstate", () => {
      if (window.location.hash !== "#adicionar") {
        folha.classList.remove("is-aberta");
        document.body.style.overflow = "";
      }
    });
    if (window.location.hash === "#adicionar") abrir();
    document.addEventListener("keydown", (ev) => { if (ev.key === "Escape" && folhaAberta()) fechar(ev); });

    const atualizarTotal = () => {
      let itens = 0;
      let soma = 0;
      $$("[data-produto]", folha).forEach((li) => {
        const inp = $("[data-qtd]", li);
        if (!inp) return;
        const q = Math.max(0, Math.min(Number(inp.max || 99), parseInt(inp.value || "0", 10) || 0));
        itens += q;
        soma += q * Number(li.dataset.preco || 0);
        li.classList.toggle("is-escolhido", q > 0);
        const passo = $("[data-passo]", li);
        passo.classList.toggle("is-zero", q === 0);
        $("[data-menos]", li).hidden = q === 0;
        const obs = $("[data-obs]", li);
        if (obs) obs.hidden = q === 0;
      });
      botao.disabled = itens === 0;
      texto.textContent = itens === 0 ? "Escolha os itens" : `Lançar ${itens} ${itens === 1 ? "item" : "itens"} · ${reais(soma)}`;
    };
    lista.addEventListener("click", (ev) => {
      const mais = ev.target.closest("[data-mais]");
      const menos = ev.target.closest("[data-menos]");
      if (!mais && !menos) return;
      const inp = $("[data-qtd]", ev.target.closest("[data-produto]"));
      const atual = parseInt(inp.value || "0", 10) || 0;
      const max = Number(inp.max || 99);
      if (mais && atual >= max) {
        inp.closest("[data-produto]").animate?.([{ transform: "translateX(-4px)" }, { transform: "translateX(4px)" }, { transform: "none" }], { duration: 200 });
        return;
      }
      inp.value = String(Math.max(0, Math.min(max, atual + (mais ? 1 : -1))));
      if (navigator.vibrate) navigator.vibrate(8);
      atualizarTotal();
    });
    lista.addEventListener("input", (ev) => { if (ev.target.matches("[data-qtd]")) atualizarTotal(); });
    form.addEventListener("submit", () => {
      // Nao manda os produtos com zero (formulario menor).
      $$("[data-qtd]", folha).forEach((inp) => {
        if ((parseInt(inp.value || "0", 10) || 0) === 0) {
          inp.disabled = true;
          const obs = $("[data-obs] input", inp.closest("[data-produto]"));
          if (obs) obs.disabled = true;
        }
      });
      botao.disabled = true;
      texto.textContent = "Lançando...";
    });
    atualizarTotal();

    // Busca e atalhos de categoria
    const busca = $("[data-busca-produto]", folha);
    if (busca) busca.addEventListener("input", () => filtrarProdutos(folha, busca.value, "todos"));
    $$("[data-ir-categoria]", folha).forEach((a) => a.addEventListener("click", (ev) => {
      ev.preventDefault();
      const alvo = $(a.getAttribute("href"), folha);
      if (alvo) alvo.scrollIntoView({ behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "start" });
    }));
  }

  /* ---------- Busca/filtro de produtos (folha e consulta) ---------- */
  function filtrarProdutos(raiz, termo, filtro) {
    const t = semAcento(termo).trim();
    let algum = 0;
    $$("[data-grupo]", raiz).forEach((g) => {
      let noGrupo = 0;
      $$("[data-produto]", g).forEach((li) => {
        const okTexto = t === "" || semAcento(li.dataset.nome).includes(t);
        const okFiltro = filtro === "todos" || (filtro === "tem" ? li.dataset.tem === "1" : li.dataset.tem === "0");
        li.hidden = !(okTexto && okFiltro);
        if (!li.hidden) noGrupo++;
      });
      g.hidden = noGrupo === 0;
      algum += noGrupo;
    });
    const nada = $("[data-busca-nenhum]", raiz);
    if (nada) nada.hidden = algum > 0;
  }

  const consulta = $("[data-consulta]");
  if (consulta) {
    const busca = $("[data-busca-produto]", consulta);
    let filtro = "todos";
    const aplicar = () => filtrarProdutos(consulta, busca ? busca.value : "", filtro);
    if (busca) busca.addEventListener("input", aplicar);
    $$("[data-filtro-produto]", consulta).forEach((b) => b.addEventListener("click", () => {
      filtro = b.dataset.filtroProduto;
      $$("[data-filtro-produto]", consulta).forEach((x) => x.setAttribute("aria-pressed", String(x === b)));
      aplicar();
    }));
  }

  /* ---------- Fechar conta ---------- */
  const servico = $("[data-servico]");
  if (servico) servico.addEventListener("submit", () => {
    const btn = $("[role='switch']", servico);
    btn.setAttribute("aria-checked", btn.getAttribute("aria-checked") === "true" ? "false" : "true");
  });

  const restanteEl = $("[data-restante]");
  const restante = restanteEl ? Number(restanteEl.dataset.restante || 0) : 0;
  let pessoas = 1;
  const dividir = $("[data-dividir]");
  const cadaPessoa = () => Math.ceil(restante / pessoas);
  if (dividir && restante > 0) {
    dividir.hidden = false;
    const saida = $("[data-dividir-pessoas]", dividir);
    const cada = $("[data-dividir-cada]", dividir);
    const umaPessoa = $("[data-preencher-pessoa]");
    const mostrar = () => {
      saida.textContent = String(pessoas);
      cada.textContent = pessoas > 1 ? `${reais(cadaPessoa())} cada` : "";
      if (umaPessoa) {
        umaPessoa.hidden = pessoas < 2;
        umaPessoa.dataset.preencher = String(cadaPessoa());
        umaPessoa.textContent = `1 pessoa · ${reais(cadaPessoa())}`;
      }
    };
    $("[data-dividir-mais]", dividir).addEventListener("click", () => { pessoas = Math.min(30, pessoas + 1); mostrar(); });
    $("[data-dividir-menos]", dividir).addEventListener("click", () => { pessoas = Math.max(1, pessoas - 1); mostrar(); });
    mostrar();
  }

  const pagar = $("[data-pagar-garcom]");
  const formas = $("[data-formas]");
  if (formas) {
    const formaCampo = pagar ? $("[data-forma-campo]", pagar) : null;
    const trocar = () => {
      const escolhida = ($("[data-forma]:checked", formas) || {}).value || "dinheiro";
      $$("[data-painel-forma]").forEach((p) => { p.hidden = (p.dataset.painelForma === "pix_qr") !== (escolhida === "pix_qr"); });
      if (formaCampo && escolhida !== "pix_qr") formaCampo.value = escolhida;
      if (pagar) {
        $("[data-so-dinheiro]", pagar).hidden = escolhida !== "dinheiro";
        atualizarPagamento();
      }
    };
    $$("[data-forma]", formas).forEach((r) => r.addEventListener("change", trocar));
    trocar();
  }

  function atualizarPagamento() {
    if (!pagar) return;
    const valor = centavos($("[data-valor]", pagar).value);
    const dinheiro = $("[data-forma-campo]", pagar).value === "dinheiro";
    const recebido = centavos(($("[data-recebido]", pagar) || {}).value);
    const troco = $("[data-troco]", pagar);
    const enviar = $("[data-pagar-texto]", pagar);
    if (dinheiro && recebido > 0) {
      const dif = recebido - Math.min(valor, restante);
      troco.hidden = false;
      troco.classList.toggle("is-falta", dif < 0);
      $("[data-troco-rotulo]", troco).textContent = dif < 0 ? "Ainda falta" : "Troco";
      $("[data-troco-valor]", troco).textContent = reais(Math.abs(dif));
    } else if (troco) {
      troco.hidden = true;
    }
    enviar.textContent = valor > 0 ? `Recebi ${reais(dinheiro ? Math.min(valor, restante) : valor)}` : "Digite o valor";
  }
  if (pagar) {
    pagar.addEventListener("input", atualizarPagamento);
    pagar.addEventListener("click", (ev) => {
      const preencher = ev.target.closest("[data-preencher]");
      const nota = ev.target.closest("[data-nota]");
      if (preencher) $("[data-valor]", pagar).value = campo(Number(preencher.dataset.preencher));
      if (nota) $("[data-recebido]", pagar).value = campo(Number(nota.dataset.nota));
      if (preencher || nota) atualizarPagamento();
    });
    pagar.addEventListener("submit", (ev) => {
      const b = $("[data-pagar-enviar]", pagar);
      if (b.dataset.enviando) { ev.preventDefault(); return; }
      b.dataset.enviando = "1";
      window.setTimeout(() => { delete b.dataset.enviando; }, 4000);
    });
    atualizarPagamento();
  }

  /* ---------- Pix: QR Code, copiar e esperar o pagamento ---------- */
  const qr = $("[data-qr]");
  if (qr && typeof window.qrcode === "function") {
    try {
      const codigo = window.qrcode(0, "M");
      codigo.addData(qr.dataset.qr);
      codigo.make();
      qr.innerHTML = codigo.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    } catch (e) { /* o codigo copia e cola continua na tela */ }
  }
  $$("[data-copiar]").forEach((b) => b.addEventListener("click", async () => {
    const t = $("[data-copiar-texto]", b);
    try {
      await navigator.clipboard.writeText(b.dataset.copiar);
      t.textContent = "Copiado";
    } catch (e) {
      const sel = window.getSelection();
      const r = document.createRange();
      r.selectNodeContents($("[data-pix-codigo]"));
      sel.removeAllRanges();
      sel.addRange(r);
      t.textContent = "Selecionado: copie";
    }
    window.setTimeout(() => { t.textContent = "Copiar código"; }, 2500);
  }));

  const pix = $("[data-pix]");
  if (pix) {
    let parar = false;
    const conferir = async () => {
      if (parar || !visivel()) return;
      try {
        const r = await fetch(pix.dataset.pix, { headers: { Accept: "application/json" }, credentials: "same-origin", cache: "no-store" });
        if (!r.ok) return;
        const d = await r.json();
        if (d.status === "pago") {
          parar = true;
          $("[data-pix-esperando]").hidden = true;
          $("[data-pix-trocar]").hidden = true;
          $("[data-pix-pago]").hidden = false;
          if (navigator.vibrate) navigator.vibrate([30, 60, 30]);
          window.setTimeout(() => { window.location.href = d.url_conta; }, 1800);
        } else if (d.status === "cancelado") {
          parar = true;
          window.location.href = d.url_conta;
        }
      } catch (e) { /* sem internet agora: tenta de novo */ }
    };
    window.setInterval(conferir, 3000);
    document.addEventListener("visibilitychange", conferir);
    document.addEventListener("cardapim:tempo-real", (ev) => {
      const msg = ev.detail || {};
      if (msg.canal !== "mesas") return;
      ev.preventDefault();
      conferir();
    });
  }
})();
