/*
 * Cardapim - "Seus pedidos" do cliente final.
 * - Folha da conta: historico (neste restaurante ou em todos), 10 por vez, cada pedido abre o acompanhamento.
 * - Cardapio: aviso "Acompanhar" para cada pedido em andamento neste restaurante (volta pelo link do restaurante).
 * Dados de GET /api/v1/cliente/pedidos (so do cliente conectado). Depende de window.CardapimCliente (cliente.js).
 */
(() => {
  "use strict";

  const Cliente = window.CardapimCliente;
  const slug = document.body.dataset.cardapio || "";
  if (!Cliente || !slug) return;

  const reais = (c) => (c / 100).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const sprite = document.querySelector("svg use")?.getAttribute("href")?.split("#")[0] || "/assets/icons/lucide.svg";
  const icone = (nome, classe = "icon") => {
    const ns = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(ns, "svg");
    svg.setAttribute("class", classe);
    svg.setAttribute("aria-hidden", "true");
    const use = document.createElementNS(ns, "use");
    use.setAttribute("href", `${sprite}#${nome}`);
    svg.append(use);
    return svg;
  };
  const el = (tag, classe, texto) => {
    const n = document.createElement(tag);
    if (classe) n.className = classe;
    if (texto !== undefined) n.textContent = texto;
    return n;
  };

  // Icone e estilo de cada etapa (o texto vem do servidor: status_rotulo).
  const ESTILO = {
    aguardando_pagamento: ["qr-code", "is-pix"],
    recebido: ["receipt", "is-andamento"],
    em_preparo: ["chef-hat", "is-andamento"],
    pronto: ["package-check", "is-andamento"],
    saiu_entrega: ["bike", "is-andamento"],
    concluido: ["circle-check", "is-ok"],
    cancelado: ["circle-x", "is-cancelado"],
  };
  const selo = (p) => {
    const [nome, classe] = ESTILO[p.status] || ["receipt", ""];
    const s = el("span", `mp-status ${classe}`);
    s.append(icone(nome), document.createTextNode(p.status_rotulo));
    return s;
  };

  const cache = { aqui: null, todos: null };

  /* ---------- Aviso "pedido em andamento" no cardapio ---------- */
  const ativos = document.querySelector("[data-pedidos-ativos]");
  let relogio = null;

  const desenharAtivos = (lista) => {
    if (!ativos) return;
    ativos.replaceChildren();
    lista.forEach((p) => {
      const a = el("a", "cd-ativo");
      a.href = p.url;
      const [nome] = ESTILO[p.status] || ["receipt"];
      const ic = el("span", "cd-ativo-icone");
      ic.setAttribute("aria-hidden", "true");
      ic.append(icone(nome));
      const texto = el("span", "cd-ativo-texto");
      texto.append(el("small", "", `Pedido #${p.numero} · ${p.quando}`), el("strong", "", p.status_rotulo), el("span", "cd-ativo-dica", "Toque para acompanhar"));
      const acao = el("span", "cd-ativo-acao");
      acao.append(el("span", "cd-ativo-acao-texto", "Acompanhar"), icone("chevron-right", "icon icon-sm"));
      a.setAttribute("aria-label", `Acompanhar pedido ${p.numero}: ${p.status_rotulo}`);
      a.append(ic, texto, acao);
      ativos.append(a);
    });
    ativos.hidden = lista.length === 0;
  };

  const atualizarAtivos = async () => {
    window.clearTimeout(relogio);
    if (!Cliente.atual() || !ativos) {
      desenharAtivos([]);
      return;
    }
    const r = await Cliente.api(`/api/v1/cliente/pedidos?restaurante=${encodeURIComponent(slug)}`);
    if (r.http === 200) {
      desenharAtivos(r.em_andamento || []);
      cache.aqui = { pedidos: r.pedidos || [], proximo: r.proximo ?? null };
    }
    // Enquanto houver pedido andando, confere de novo a cada 30 s (so com a aba visivel).
    if ((r.em_andamento || []).length > 0) relogio = window.setTimeout(atualizarAtivos, 30000);
  };

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") atualizarAtivos();
    else window.clearTimeout(relogio);
  });

  /* ---------- Historico na folha da conta ---------- */
  const secao = document.querySelector("[data-meus-pedidos]");
  const dConta = document.querySelector("[data-dialogo-conta]");
  let filtro = "aqui";
  let carregando = false;

  const $ = (sel) => secao.querySelector(sel);
  const estado = (nome, texto) => {
    $("[data-mp-carregando]").hidden = nome !== "carregando";
    $("[data-mp-erro]").hidden = nome !== "erro";
    $("[data-mp-vazio]").hidden = nome !== "vazio";
    if (nome === "erro" && texto) $("[data-mp-erro-texto]").textContent = texto;
  };

  const item = (p) => {
    const li = el("li", `mp-item${p.final ? "" : " is-ativo"}`);
    const a = el("a");
    a.href = p.url;
    const linha = el("span", "mp-linha1");
    linha.append(el("span", "mp-numero", `Pedido #${p.numero}`), el("span", "mp-quando", p.quando));
    a.append(linha, el("span", "mp-total", reais(p.total_centavos)));
    if (filtro === "todos") {
      const loja = el("span", "mp-loja");
      loja.append(icone("store", "icon icon-sm"), document.createTextNode(p.restaurante.nome));
      a.append(loja);
    }
    if (p.resumo) a.append(el("span", "mp-resumo", p.resumo));
    const rodape = el("span", "mp-rodape");
    const ver = el("span", "mp-ver", p.final ? "Ver detalhes" : "Acompanhar");
    ver.append(icone("chevron-right", "icon icon-sm"));
    rodape.append(selo(p), ver);
    a.append(rodape);
    a.setAttribute("aria-label", `Pedido ${p.numero}, ${p.quando}, ${p.status_rotulo}, ${reais(p.total_centavos)}`);
    li.append(a);
    return li;
  };

  const desenharLista = () => {
    const dados = cache[filtro];
    const lista = $("[data-mp-lista]");
    lista.replaceChildren(...(dados?.pedidos || []).map(item));
    $("[data-mp-mais]").hidden = !dados || dados.proximo === null;
    if (dados && dados.pedidos.length === 0) {
      $("[data-mp-vazio-texto]").textContent = filtro === "aqui"
        ? "Você ainda não fez pedidos neste restaurante."
        : "Você ainda não fez nenhum pedido pelo Cardapim.";
      estado("vazio");
    } else {
      estado("");
    }
  };

  const carregar = async (maisAntigos = false) => {
    if (carregando) return;
    const atual = cache[filtro];
    if (!maisAntigos && atual) {
      desenharLista();
      return;
    }
    carregando = true;
    const qual = filtro;
    const params = new URLSearchParams();
    if (qual === "aqui") params.set("restaurante", slug);
    if (maisAntigos && atual?.proximo) params.set("antes", String(atual.proximo));
    const botaoMais = $("[data-mp-mais]");
    if (maisAntigos) botaoMais.disabled = true;
    else {
      $("[data-mp-lista]").replaceChildren();
      botaoMais.hidden = true;
      estado("carregando");
    }
    const r = await Cliente.api(`/api/v1/cliente/pedidos?${params}`);
    carregando = false;
    botaoMais.disabled = false;
    if (r.http !== 200) {
      if (r.http === 401) {
        window.CardapimUI?.fechar(dConta);
        return;
      }
      estado("erro", r.mensagem || "Não foi possível carregar seus pedidos.");
      return;
    }
    cache[qual] = maisAntigos && atual
      ? { pedidos: [...atual.pedidos, ...(r.pedidos || [])], proximo: r.proximo ?? null }
      : { pedidos: r.pedidos || [], proximo: r.proximo ?? null };
    if (qual === "aqui" && !maisAntigos) desenharAtivos(r.em_andamento || []);
    if (qual === filtro) desenharLista();
  };

  if (secao && dConta) {
    secao.querySelectorAll("[data-mp-filtro]").forEach((b) => b.addEventListener("click", () => {
      filtro = b.dataset.mpFiltro;
      secao.querySelectorAll("[data-mp-filtro]").forEach((x) => x.setAttribute("aria-pressed", String(x === b)));
      carregar();
    }));
    $("[data-mp-mais]").addEventListener("click", () => carregar(true));
    $("[data-mp-tentar]").addEventListener("click", () => carregar());
    $("[data-mp-cardapio]").addEventListener("click", () => {
      window.CardapimUI?.fechar(dConta);
      if (!document.getElementById("cardapio")) window.location.assign(`/${encodeURIComponent(slug)}`);
    });
    // Toda vez que a conta abre, mostra a lista atualizada (o status muda ao longo do pedido).
    new MutationObserver(() => {
      if (!dConta.open) return;
      cache.aqui = null;
      cache.todos = null;
      carregar();
    }).observe(dConta, { attributes: true, attributeFilter: ["open"] });
  }

  /* ---------- Entrou ou saiu da conta ---------- */
  let ultimoId = undefined;
  Cliente.ouvir((c) => {
    const id = c ? c.id ?? c.whatsapp : null;
    if (id === ultimoId) return;
    ultimoId = id;
    cache.aqui = null;
    cache.todos = null;
    atualizarAtivos();
  });
  Cliente.pronto.then(() => {
    if (ultimoId === undefined) {
      ultimoId = null;
      atualizarAtivos();
    }
  });
})();
