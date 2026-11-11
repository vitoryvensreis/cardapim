/*
 * Modo caixa (carregado com defer).
 * - Fila de delivery no menu: atualiza sozinha (tempo real + a cada 60 s) e toca um aviso quando chega pedido novo.
 * - Telas com [data-regiao]: quando algo muda (outro aparelho, pedido novo), busca a tela de novo e troca so essas partes.
 * - Formularios [data-ajax]: enviam sem recarregar a pagina (a comanda continua no mesmo lugar) e mostram o aviso.
 * - Comanda: folha do produto (quantidade e observacao), cancelar item com motivo, busca, categorias e abas no celular.
 * - Receber: troco na hora, notas rapidas e divisao.
 * Sem JavaScript tudo continua funcionando com formularios normais.
 */
(() => {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const reduzir = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- Dinheiro ---------- */
  const paraCentavos = (texto) => {
    const limpo = String(texto || "").replace(/[^\d,]/g, "");
    if (!limpo) return 0;
    const [inteiro, fracao = ""] = limpo.split(",");
    return parseInt(inteiro || "0", 10) * 100 + parseInt((fracao + "00").slice(0, 2), 10);
  };
  const campoDinheiro = (c) => {
    const n = Math.max(0, Math.round(c));
    const inteiro = Math.floor(n / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    return `${inteiro},${String(n % 100).padStart(2, "0")}`;
  };
  const reais = (c) => `R$ ${campoDinheiro(c)}`;

  /* ---------- Aviso (toast) ---------- */
  let toast = null;
  let toastTempo = 0;
  const avisar = (texto, tipo = "sucesso") => {
    if (!texto) return;
    if (!toast) {
      toast = document.createElement("div");
      toast.className = "caixa-toast";
      toast.setAttribute("role", "status");
      toast.setAttribute("aria-live", "polite");
      document.body.append(toast);
    }
    toast.textContent = texto;
    toast.dataset.tipo = tipo;
    toast.hidden = false;
    toast.classList.remove("is-saindo");
    window.clearTimeout(toastTempo);
    toastTempo = window.setTimeout(() => {
      toast.classList.add("is-saindo");
      window.setTimeout(() => { toast.hidden = true; }, 220);
    }, tipo === "erro" ? 5200 : 3200);
  };

  /* ---------- Som de pedido novo (so depois de um toque na tela: regra dos navegadores) ---------- */
  let audio = null;
  document.addEventListener("pointerdown", () => {
    if (!audio && window.AudioContext) {
      try { audio = new window.AudioContext(); } catch (e) { audio = null; }
    }
  }, { once: true });
  const tocar = () => {
    if (!audio) return;
    try {
      [0, 0.18].forEach((atraso, i) => {
        const osc = audio.createOscillator();
        const vol = audio.createGain();
        osc.type = "sine";
        osc.frequency.value = i === 0 ? 880 : 1175;
        vol.gain.setValueAtTime(0.0001, audio.currentTime + atraso);
        vol.gain.exponentialRampToValueAtTime(0.25, audio.currentTime + atraso + 0.02);
        vol.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + atraso + 0.16);
        osc.connect(vol).connect(audio.destination);
        osc.start(audio.currentTime + atraso);
        osc.stop(audio.currentTime + atraso + 0.17);
      });
    } catch (e) { /* sem som: segue so o aviso visual */ }
  };

  /* ---------- Trocar partes da tela ---------- */
  const regioesDe = (doc) => {
    const mapa = new Map();
    $$("[data-regiao]", doc).forEach((el) => mapa.set(el.dataset.regiao, el));
    return mapa;
  };
  const ocupado = () => {
    const ativo = document.activeElement;
    if ($$("dialog[open]").length > 0) return true;
    return !!(ativo && ativo.matches("input, textarea, select") && ativo.closest("[data-regiao]"));
  };
  const trocar = (doc) => {
    const novas = regioesDe(doc);
    $$("[data-regiao]").forEach((el) => {
      const nova = novas.get(el.dataset.regiao);
      if (nova) {
        const abertos = $$("details[open]", el).map((d) => d.querySelector("summary")?.textContent || "");
        el.replaceWith(nova);
        $$("details", nova).forEach((d) => {
          if (abertos.includes(d.querySelector("summary")?.textContent || "")) d.open = true;
        });
      }
    });
    if (window.CardapimMascaras) window.CardapimMascaras(document);
  };
  let atualizando = false;
  let pendente = false;
  const atualizarTela = async () => {
    if (!$("[data-regiao]")) return;
    if (atualizando || ocupado()) { pendente = true; return; }
    atualizando = true;
    try {
      const r = await fetch(window.location.href, { headers: { Accept: "text/html" }, credentials: "same-origin", cache: "no-store" });
      if (r.ok && new URL(r.url).pathname === window.location.pathname) {
        trocar(new DOMParser().parseFromString(await r.text(), "text/html"));
      }
    } catch (e) { /* sem conexao: tenta no proximo aviso */ }
    atualizando = false;
    if (pendente) { pendente = false; window.setTimeout(atualizarTela, 400); }
  };
  document.addEventListener("focusout", () => { if (pendente) window.setTimeout(() => { if (!ocupado()) { pendente = false; atualizarTela(); } }, 300); });

  /* ---------- Fila de delivery no menu ---------- */
  const filaUrl = () => $("[data-fila]")?.dataset.filaUrl;
  const marcarMenu = () => {
    const novos = $$('[data-fila-status="recebido"]').length;
    const link = $$(".painel-link").find((a) => /\/caixa\/delivery$/.test(a.getAttribute("href") || ""));
    if (!link) return;
    let tag = $(".painel-tag", link);
    if (novos === 0) { tag?.remove(); return; }
    if (!tag) {
      tag = document.createElement("span");
      tag.className = "painel-tag";
      link.append(tag);
    }
    tag.textContent = `${novos} novo${novos > 1 ? "s" : ""}`;
  };
  const atualizarFila = async (novo) => {
    const url = filaUrl();
    if (!url) return;
    try {
      const r = await fetch(url, { headers: { Accept: "text/html" }, credentials: "same-origin", cache: "no-store" });
      if (!r.ok) return;
      const doc = new DOMParser().parseFromString(await r.text(), "text/html");
      const nova = $("[data-fila]", doc);
      const atual = $("[data-fila]");
      if (nova && atual) {
        atual.replaceWith(nova);
        marcarMenu();
        if (novo && !reduzir) {
          nova.classList.add("is-novo");
          window.setTimeout(() => nova.classList.remove("is-novo"), 3200);
        }
      }
    } catch (e) { /* tenta de novo no proximo ciclo */ }
  };
  if (filaUrl()) window.setInterval(() => { if (document.visibilityState === "visible") atualizarFila(false); }, 60000);

  document.addEventListener("cardapim:tempo-real", (ev) => {
    const msg = ev.detail || {};
    if (!["pedidos", "mesas", "caixa"].includes(msg.canal)) return;
    ev.preventDefault(); // esta tela trata sozinha (sem a faixa "Atualizar agora")
    if (msg.canal === "pedidos") {
      const novo = msg.tipo === "pedido.novo";
      atualizarFila(novo);
      if (novo) {
        tocar();
        avisar(`Pedido novo #${(msg.dados && msg.dados.numero) || ""} chegou no delivery.`);
      }
    }
    atualizarTela();
  });

  /* ---------- Formularios sem recarregar ---------- */
  const enviarAjax = async (form, submitter) => {
    const botoes = $$('button[type="submit"]', form);
    botoes.forEach((b) => { b.disabled = true; });
    form.setAttribute("aria-busy", "true");
    try {
      const dados = new FormData(form, submitter || undefined);
      const r = await fetch(form.action, { method: "POST", body: dados, credentials: "same-origin", headers: { Accept: "text/html" } });
      const destino = new URL(r.url);
      if (destino.pathname !== window.location.pathname) {
        window.location.assign(r.url);
        return;
      }
      const doc = new DOMParser().parseFromString(await r.text(), "text/html");
      const flash = $("[data-flash]", doc);
      const dialogo = form.closest("dialog");
      if (dialogo) dialogo.close();
      trocar(doc);
      if (flash) {
        const erro = flash.classList.contains("painel-flash-erro");
        avisar(flash.textContent.trim(), erro ? "erro" : "sucesso");
      }
      form.reset();
    } catch (e) {
      form.submit(); // sem conexao com o fetch: envia do jeito normal
    } finally {
      botoes.forEach((b) => { b.disabled = false; });
      form.removeAttribute("aria-busy");
    }
  };
  document.addEventListener("submit", (ev) => {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement) || !form.matches("[data-ajax]") || ev.defaultPrevented) return;
    ev.preventDefault();
    enviarAjax(form, ev.submitter);
  });

  /* ---------- Dialogos (folhas) ---------- */
  const abrirDialogo = (d) => {
    if (!d) return;
    if (typeof d.showModal === "function") d.showModal(); else d.setAttribute("open", "");
  };
  document.addEventListener("click", (ev) => {
    const fechar = ev.target.closest("[data-fechar-dialogo]");
    if (fechar) { fechar.closest("dialog")?.close(); return; }
    if (ev.target instanceof HTMLDialogElement) ev.target.close(); // toque no fundo
  });

  /* ---------- Comanda ---------- */
  const dlgProduto = $("[data-dialogo-produto]");
  if (dlgProduto) {
    const campoQtd = $("[data-qtd-campo]", dlgProduto);
    const botaoLancar = $("[data-produto-lancar]", dlgProduto);
    let preco = 0;
    const qtd = () => Math.min(99, Math.max(1, parseInt(campoQtd.value, 10) || 1));
    const desenhar = () => { botaoLancar.textContent = `Lançar ${qtd()}x · ${reais(preco * qtd())}`; };
    document.addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-produto-abrir]");
      if (!b || b.disabled) return;
      ev.preventDefault();
      preco = parseInt(b.dataset.produtoCentavos, 10) || 0;
      $("[data-produto-titulo]", dlgProduto).textContent = b.dataset.produtoNome || "Produto";
      $("[data-produto-campo]", dlgProduto).value = b.dataset.produtoId;
      $("[name=observacao]", dlgProduto).value = "";
      campoQtd.value = "1";
      desenhar();
      abrirDialogo(dlgProduto);
      window.setTimeout(() => botaoLancar.focus(), 50);
    });
    $$("[data-qtd]", dlgProduto).forEach((b) => b.addEventListener("click", () => {
      campoQtd.value = String(Math.min(99, Math.max(1, qtd() + parseInt(b.dataset.qtd, 10))));
      desenhar();
    }));
    campoQtd.addEventListener("input", desenhar);
  }

  const dlgCancelar = $("[data-dialogo-cancelar]");
  if (dlgCancelar) {
    document.addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-cancelar-item]");
      if (!b) return;
      const form = $("[data-form-cancelar]", dlgCancelar);
      form.action = b.dataset.cancelarItem;
      $("[data-cancelar-nome]", dlgCancelar).textContent = b.dataset.itemNome || "o item";
      abrirDialogo(dlgCancelar);
    });
  }

  // Busca e categorias do cardapio da comanda.
  const busca = $("[data-produto-busca]");
  if (busca) {
    let categoria = "";
    const semAcento = (t) => t.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
    const filtrar = () => {
      const termo = semAcento(busca.value.trim());
      let visiveis = 0;
      $$("[data-produto-item]").forEach((li) => {
        const ok = (!categoria || li.dataset.categoria === categoria) && (!termo || semAcento(li.dataset.nome).includes(termo));
        li.hidden = !ok;
        if (ok) visiveis++;
      });
      const nenhum = $("[data-produtos-nenhum]");
      if (nenhum) nenhum.hidden = visiveis > 0;
    };
    busca.addEventListener("input", filtrar);
    $$("[data-produto-categorias] button").forEach((b) => b.addEventListener("click", () => {
      categoria = b.dataset.categoria;
      $$("[data-produto-categorias] button").forEach((x) => x.setAttribute("aria-pressed", String(x === b)));
      filtrar();
    }));
  }

  // Abas "Cardapio" e "Conta" no celular/tablet.
  const pagina = $("[data-comanda]");
  const abas = $("[data-comanda-abas]");
  if (pagina && abas) {
    pagina.classList.add("tem-abas");
    abas.hidden = false;
    const mostrar = (qual) => {
      $$("[data-comanda-aba]", abas).forEach((b) => b.setAttribute("aria-pressed", String(b.dataset.comandaAba === qual)));
      // Classe (e nao "hidden"): no computador as duas partes aparecem lado a lado.
      $$("[data-comanda-parte]", pagina).forEach((p) => p.classList.toggle("is-fora-da-aba", p.dataset.comandaParte !== qual));
    };
    $$("[data-comanda-aba]", abas).forEach((b) => b.addEventListener("click", () => {
      mostrar(b.dataset.comandaAba);
      window.scrollTo({ top: 0, behavior: reduzir ? "auto" : "smooth" });
    }));
    const inicial = $('[data-comanda-aba][aria-pressed="true"]', abas);
    mostrar(inicial ? inicial.dataset.comandaAba : "cardapio");
  }

  // Filtro das mesas (todas / livres / ocupadas).
  const filtroMesas = $("[data-mesas-filtro]");
  if (filtroMesas) {
    filtroMesas.addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-filtro-mesa]");
      if (!b) return;
      $$("[data-filtro-mesa]", filtroMesas).forEach((x) => x.setAttribute("aria-pressed", String(x === b)));
      let visiveis = 0;
      $$("[data-mesa-estado]").forEach((li) => {
        const ok = b.dataset.filtroMesa === "todas" || li.dataset.mesaEstado === b.dataset.filtroMesa;
        li.hidden = !ok;
        if (ok) visiveis++;
      });
      const nenhuma = $("[data-mesas-nenhuma]");
      if (nenhuma) nenhuma.hidden = visiveis > 0;
    });
  }

  /* ---------- Receber ---------- */
  const pagar = $("[data-pagar]");
  if (pagar) {
    const restante = parseInt($("[data-pagar-restante]")?.dataset.pagarRestante || "0", 10);
    const valor = $("[data-pagar-valor]", pagar);
    const recebido = $("[data-pagar-recebido]", pagar);
    const campoRecebido = $("[data-pagar-recebido-campo]", pagar);
    const notas = $("[data-pagar-notas]", pagar);
    const troco = $("[data-pagar-troco]", pagar);
    const trocoRotulo = $("[data-pagar-troco-rotulo]", pagar);
    const trocoValor = $("[data-pagar-troco-valor]", pagar);
    const textoEnviar = $("[data-pagar-enviar-texto]", pagar);
    const forma = () => ($("[data-pagar-forma]:checked", pagar) || {}).value || "dinheiro";
    const desenhar = () => {
      const dinheiro = forma() === "dinheiro";
      campoRecebido.hidden = !dinheiro;
      if (notas) notas.hidden = !dinheiro;
      const v = Math.min(paraCentavos(valor.value), restante);
      const r = dinheiro ? paraCentavos(recebido.value) : 0;
      // Mostra enquanto digita: troco (verde, grande) ou quanto ainda falta (vermelho).
      troco.hidden = !dinheiro || r <= 0;
      troco.classList.toggle("is-falta", r > 0 && r < v);
      trocoRotulo.textContent = r >= v ? "Troco" : "Ainda falta";
      trocoValor.textContent = reais(r >= v ? r - v : v - r);
      textoEnviar.textContent = v > 0 ? `Receber ${reais(v)}${v >= restante ? " e fechar a conta" : ""}` : "Receber";
    };
    pagar.addEventListener("input", desenhar);
    pagar.addEventListener("change", desenhar);
    $$("[data-pagar-preencher]", pagar).forEach((b) => b.addEventListener("click", () => {
      valor.value = campoDinheiro(parseInt(b.dataset.pagarPreencher, 10));
      desenhar();
    }));
    $$("[data-pagar-nota]", pagar).forEach((b) => b.addEventListener("click", () => {
      recebido.value = campoDinheiro(parseInt(b.dataset.pagarNota, 10));
      desenhar();
    }));
    desenhar();
  }

  /* ---------- Imprimir ---------- */
  document.addEventListener("click", (ev) => {
    if (ev.target.closest("[data-imprimir]")) window.print();
  });
})();
